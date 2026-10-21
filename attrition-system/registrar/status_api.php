<?php
/**
 * Quick status changes (JSON in, JSON out), every one behind the PIN:
 *   set_class  Program Head — classify a student as Enrolled or Shifted (there is no form for Shifted)
 *   mark_du    Faculty      — mark a student of the professor's own class as Dropped (Unofficially)
 *   undo_du    Faculty      — take back a Dropped (Unofficially) the same professor recorded
 * A professor's drop is saved as a request, so it shows in the Program Head's Reports, Requests, in the status graphs, and in the bell.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';
require_once __DIR__ . '/../config/grades.php';
require_once __DIR__ . '/../config/predict.php';

header('Content-Type: application/json; charset=utf-8');
function out(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (isAdmin()) { out(['ok' => false, 'error' => 'The Administrator has no student pages.'], 403); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') { out(['ok' => false, 'error' => 'Bad request.'], 400); }
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { $in = []; }
$action = (string) ($in['action'] ?? '');
$sid = trim((string) ($in['student_id'] ?? ''));

function checkPin(PDO $pdo, array $in): void {
    $check = checkCredential($pdo, 'pin', (string) ($in['credential'] ?? ''));
    if ($check['ok']) { return; }
    if (!empty($check['logout'])) { $_SESSION = []; session_destroy(); out(['ok' => false, 'error' => $check['error'], 'redirect' => '../index.php?error=locked']); }
    out(['ok' => false, 'error' => $check['error']]);
}
function studentRow(PDO $pdo, string $sid): ?array {
    $q = $pdo->prepare("SELECT student_id, full_name, status FROM students WHERE student_id = ?");
    $q->execute([$sid]);
    return $q->fetch() ?: null;
}

try {
    // ---------------------------------------------------------- Program Head: Enrolled <-> Shifted
    if ($action === 'set_class') {
        if (!canManageRequests()) { out(['ok' => false, 'error' => 'Only the Program Head classifies students.'], 403); }
        checkPin($pdo, $in);
        $to = (string) ($in['to'] ?? '');
        if (!in_array($to, ['enrolled', 'shifted'], true)) { out(['ok' => false, 'error' => 'A student can be classified as Enrolled or Shifted here.']); }
        $s = studentRow($pdo, $sid);
        if (!$s) { out(['ok' => false, 'error' => 'Student not found.']); }
        if (!in_array($s['status'], ['enrolled', 'shifted'], true)) {
            out(['ok' => false, 'error' => 'Only an enrolled or shifted student is classified here. Leave of absence and honorable dismissal are recorded in the request form; Dropped (Unofficially) is recorded by the professor.']);
        }
        if ($s['status'] !== $to) {
            $pdo->prepare("UPDATE students SET status = ? WHERE student_id = ?")->execute([$to, $sid]);
            if ($to === 'enrolled') { try { runPredictionForStudent($pdo, $sid); } catch (Throwable $e) { /* predicted at the next save */ } }
        }
        out(['ok' => true, 'message' => $s['full_name'] . ' is now ' . ($to === 'shifted' ? 'Shifted to another program.' : 'Enrolled.')]);
    }

    // ---------------------------------------------------------- Faculty: Dropped (Unofficially)
    if ($action === 'mark_du') {
        if (!isFaculty()) { out(['ok' => false, 'error' => 'Only the professor records Dropped (Unofficially).'], 403); }
        checkPin($pdo, $in);
        $cid  = (int) ($in['class_id'] ?? 0);
        $data = $cid ? classLoadData($pdo, $cid) : null;
        if (!$data || !canEditClass($data['class'])) { out(['ok' => false, 'error' => 'This class is not assigned to you.']); }
        $stu = null;
        foreach ($data['students'] as $row) { if ($row['student_id'] === $sid) { $stu = $row; break; } }
        if (!$stu) { out(['ok' => false, 'error' => 'That student is not in this class.']); }
        if ($stu['status'] !== 'enrolled') { out(['ok' => false, 'error' => $stu['full_name'] . ' is not an enrolled student any more (' . (STUDENT_STATUSES[$stu['status']] ?? $stu['status']) . ').']); }
        $reason = (string) ($in['reason'] ?? DU_DEFAULT_REASON);
        if (!in_array($reason, CASE_TYPES['du']['reasons'], true) || $reason === 'Others') { $reason = DU_DEFAULT_REASON; }
        $ay  = $data['class']['academic_year'];
        $sem = ((int) $data['class']['semester'] === 1) ? '1st Semester' : '2nd Semester';
        $who = $_SESSION['full_name'] ?? $_SESSION['user_id'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO student_cases (student_id, case_type, academic_year, semester, reason, details, filed_on, recorded_by, recorded_by_user)
                           VALUES (?, 'du', ?, ?, ?, ?, CURDATE(), ?, ?)")
                ->execute([$sid, $ay, $sem, $reason, 'Decided by the professor of ' . $data['class']['subject_name'] . ' (' . $data['class']['section'] . ').', $who, $_SESSION['user_id']]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            out(['ok' => false, 'error' => $e->errorInfo[1] == 1062 ? 'This student already has a Dropped (Unofficially) record for this school year and semester.' : 'Could not save: ' . $e->getMessage()]);
        }
        $pdo->prepare("UPDATE students SET status = 'dropped' WHERE student_id = ?")->execute([$sid]);
        $pdo->prepare("INSERT INTO notifications (student_id, prediction_id, probability, kind, message) VALUES (?, NULL, 0, 'du', ?)")
            ->execute([$sid, 'Marked Dropped (Unofficially) by ' . $who . ' — ' . $data['class']['subject_name']]);
        $pdo->commit();
        out(['ok' => true, 'message' => $stu['full_name'] . ' is marked Dropped (Unofficially). The Program Head can see it now.', 'reload' => true]);
    }

    if ($action === 'undo_du') {
        if (!isFaculty()) { out(['ok' => false, 'error' => 'Only the professor who recorded it can undo it.'], 403); }
        checkPin($pdo, $in);
        $caseId = (int) ($in['case_id'] ?? 0);
        $cs = $pdo->prepare("SELECT * FROM student_cases WHERE id = ? AND case_type = 'du' AND returned_on IS NULL AND recorded_by_user = ?");
        $cs->execute([$caseId, $_SESSION['user_id']]);
        $case = $cs->fetch();
        if (!$case) { out(['ok' => false, 'error' => 'That record is not yours, or it was already taken back.']); }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE student_cases SET returned_on = CURDATE() WHERE id = ?")->execute([$caseId]);
        $pdo->prepare("UPDATE students SET status = 'enrolled' WHERE student_id = ? AND status = 'dropped'")->execute([$case['student_id']]);
        $pdo->prepare("DELETE FROM notifications WHERE student_id = ? AND kind = 'du' AND is_read = 0")->execute([$case['student_id']]);
        $pdo->commit();
        try { runPredictionForStudent($pdo, $case['student_id']); } catch (Throwable $e) { /* predicted at the next save */ }
        out(['ok' => true, 'message' => 'Taken back — the student is Enrolled again.', 'reload' => true]);
    }

    out(['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    out(['ok' => false, 'error' => 'Could not save: ' . $e->getMessage()]);
}
