<?php
/**
 * The recording endpoint of Classes. Every attendance mark and every score is saved here the moment it is typed (JSON in, JSON out),
 * the student's grades are recalculated, copied into their records, and the risk is predicted again.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';
require_once __DIR__ . '/../config/grades.php';
require_once __DIR__ . '/../config/masterlist.php';

header('Content-Type: application/json; charset=utf-8');
function out(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (!isFaculty()) { out(['ok' => false, 'error' => 'Only the professor of a class records in it.'], 403); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') { out(['ok' => false, 'error' => 'Bad request.'], 400); }
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { $in = []; }
$action = (string) ($in['action'] ?? '');

// One PIN check unlocks recording for an hour — a PIN for every mark would make real-time recording impossible.
if ($action === 'unlock') {
    $check = checkCredential($pdo, 'pin', (string) ($in['credential'] ?? ''));
    if (!$check['ok']) {
        if (!empty($check['logout'])) { $_SESSION = []; session_destroy(); out(['ok' => false, 'error' => $check['error'], 'redirect' => '../index.php?error=locked']); }
        out(['ok' => false, 'error' => $check['error']]);
    }
    $_SESSION['record_unlock_until'] = time() + 3600;
    out(['ok' => true]);
}
if (!recordUnlocked()) { out(['ok' => false, 'locked' => true, 'error' => 'Enter your Student Data PIN to record.']); }

$cid  = (int) ($in['class_id'] ?? 0);
$data = $cid ? classLoadData($pdo, $cid) : null;
if (!$data) { out(['ok' => false, 'error' => 'That class was not found.']); }
if (!canEditClass($data['class'])) { out(['ok' => false, 'error' => 'This class is not assigned to you — you can only record in the classes the Program Head assigned to you.']); }
$rosterIds = array_map(function ($s) { return $s['student_id']; }, $data['students']);

/** Saves the changes, copies the results to the students' records, predicts again, and answers with the new numbers. */
function syncAndReply(PDO $pdo, int $cid, array $ids, bool $reload = false): void {
    $cache = [];
    syncStudentsFromClasses($pdo, $ids, $cache);
    if ($reload) { $pdo->commit(); out(['ok' => true, 'reload' => true]); }
    $fresh = classLoadData($pdo, $cid);
    $risks = latestRisks($pdo, $ids);
    $payload = [];
    foreach ($ids as $sid) { $payload[] = studentPayload($fresh, $sid, $risks); }
    $pdo->commit();
    out(['ok' => true, 'students' => $payload]);
}
function belongs(PDO $pdo, string $sql, array $params): bool { $q = $pdo->prepare($sql); $q->execute($params); return (bool) $q->fetchColumn(); }

try {
    $pdo->beginTransaction();

    if ($action === 'set_attendance') {
        $mid = (int) ($in['meeting_id'] ?? 0); $sid = (string) ($in['student_id'] ?? ''); $st = (string) ($in['status'] ?? 'P');
        if (!isset(ABSENCE_POINTS[$st])) { throw new RuntimeException('Attendance can be / (present), A, L or E.'); }
        if (!belongs($pdo, "SELECT 1 FROM class_meetings WHERE id = ? AND class_id = ?", [$mid, $cid]) || !in_array($sid, $rosterIds, true)) { throw new RuntimeException('That meeting or student is not in this class.'); }
        $pdo->prepare("INSERT INTO class_attendance (meeting_id, student_id, status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status)")->execute([$mid, $sid, $st]);
        syncAndReply($pdo, $cid, [$sid]);
    }
    elseif ($action === 'all_present') {
        $mid = (int) ($in['meeting_id'] ?? 0);
        if (!belongs($pdo, "SELECT 1 FROM class_meetings WHERE id = ? AND class_id = ?", [$mid, $cid])) { throw new RuntimeException('That meeting is not in this class.'); }
        $pdo->prepare("INSERT INTO class_attendance (meeting_id, student_id, status) SELECT ?, student_id, 'P' FROM class_students WHERE class_id = ? AND excluded = 0
                       ON DUPLICATE KEY UPDATE status = 'P'")->execute([$mid, $cid]);
        syncAndReply($pdo, $cid, $rosterIds);
    }
    elseif ($action === 'add_meeting') {
        $date = (string) ($in['date'] ?? ''); $term = (int) ($in['term'] ?? 1);
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) { throw new RuntimeException('Please choose the date of the meeting.'); }
        if ($term < 1 || $term > 3) { throw new RuntimeException('Choose the term (Prelim, Midterm or Pre-Finals).'); }
        if (belongs($pdo, "SELECT 1 FROM class_meetings WHERE class_id = ? AND meeting_date = ?", [$cid, $date])) { throw new RuntimeException('There is already a meeting on that date.'); }
        $pdo->prepare("INSERT INTO class_meetings (class_id, meeting_date, term) VALUES (?,?,?)")->execute([$cid, $date, $term]);
        $mid = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO class_attendance (meeting_id, student_id, status) SELECT ?, student_id, 'P' FROM class_students WHERE class_id = ? AND excluded = 0")->execute([$mid, $cid]);
        syncAndReply($pdo, $cid, $rosterIds, true);
    }
    elseif ($action === 'delete_meeting') {
        $mid = (int) ($in['meeting_id'] ?? 0);
        if (!belongs($pdo, "SELECT 1 FROM class_meetings WHERE id = ? AND class_id = ?", [$mid, $cid])) { throw new RuntimeException('That meeting is not in this class.'); }
        $pdo->prepare("DELETE FROM class_meetings WHERE id = ?")->execute([$mid]);
        syncAndReply($pdo, $cid, $rosterIds, true);
    }
    elseif ($action === 'add_activity') {
        $term = (int) ($in['term'] ?? 0);
        $component = trim((string) ($in['component'] ?? ''));
        $title = trim((string) ($in['title'] ?? ''));
        $items = $in['items'] ?? '';
        if ($term < 1 || $term > 3) { throw new RuntimeException('Choose the term.'); }
        if ($component === '' || mb_strlen($component) > 40) { throw new RuntimeException('Choose what kind of column this is (Quiz, Activity, Recitation, Reporting …) or type its name.'); }
        if (strcasecmp($component, 'Exam') === 0) { throw new RuntimeException('The exam of each term is already there — it is the last column of the term.'); }
        if (!is_numeric($items) || (float) $items <= 0 || (float) $items > 1000 || !isHalfStep($items)) { throw new RuntimeException('Type how many items it has — a whole number or .5 (e.g. 10).'); }
        if ($title === '') {
            $n = $pdo->prepare("SELECT COUNT(*) FROM class_activities WHERE class_id = ? AND term = ? AND LOWER(component) = LOWER(?) AND is_exam = 0");
            $n->execute([$cid, $term, $component]);
            $title = $component . ' ' . ((int) $n->fetchColumn() + 1);
        }
        $pdo->prepare("INSERT INTO class_activities (class_id, term, component, title, items, is_exam) VALUES (?,?,?,?,?,0)")->execute([$cid, $term, $component, mb_substr($title, 0, 80), (float) $items]);
        $pdo->commit();
        out(['ok' => true, 'reload' => true]);
    }
    elseif ($action === 'delete_activity') {
        $aid = (int) ($in['activity_id'] ?? 0);
        if (!belongs($pdo, "SELECT 1 FROM class_activities WHERE id = ? AND class_id = ? AND is_exam = 0", [$aid, $cid])) { throw new RuntimeException('That column can not be removed.'); }
        $pdo->prepare("DELETE FROM class_activities WHERE id = ?")->execute([$aid]);
        syncAndReply($pdo, $cid, $rosterIds, true);
    }
    elseif ($action === 'set_items') {
        $aid = (int) ($in['activity_id'] ?? 0); $items = $in['items'] ?? '';
        if (!belongs($pdo, "SELECT 1 FROM class_activities WHERE id = ? AND class_id = ?", [$aid, $cid])) { throw new RuntimeException('That column is not in this class.'); }
        if (!is_numeric($items) || (float) $items <= 0 || (float) $items > 1000 || !isHalfStep($items)) { throw new RuntimeException('The number of items must be more than 0 — a whole number or .5.'); }
        $pdo->prepare("UPDATE class_activities SET items = ? WHERE id = ?")->execute([(float) $items, $aid]);
        syncAndReply($pdo, $cid, $rosterIds, true);
    }
    elseif ($action === 'set_score') {
        $aid = (int) ($in['activity_id'] ?? 0); $sid = (string) ($in['student_id'] ?? ''); $raw = $in['score'] ?? '';
        $q = $pdo->prepare("SELECT items FROM class_activities WHERE id = ? AND class_id = ?");
        $q->execute([$aid, $cid]);
        $items = $q->fetchColumn();
        if ($items === false || !in_array($sid, $rosterIds, true)) { throw new RuntimeException('That column or student is not in this class.'); }
        if ($raw === '' || $raw === null) {
            $pdo->prepare("DELETE FROM class_scores WHERE activity_id = ? AND student_id = ?")->execute([$aid, $sid]);
        } else {
            if (!isHalfStep($raw)) { throw new RuntimeException('A score can only be a whole number or .5 (like 8 or 8.5).'); }
            if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > (float) $items) { throw new RuntimeException('The score must be from 0 to ' . rtrim(rtrim(number_format((float) $items, 2, '.', ''), '0'), '.') . '.'); }
            $pdo->prepare("INSERT INTO class_scores (activity_id, student_id, score) VALUES (?,?,?) ON DUPLICATE KEY UPDATE score = VALUES(score)")->execute([$aid, $sid, round((float) $raw, 2)]);
        }
        syncAndReply($pdo, $cid, [$sid]);
    }
    elseif ($action === 'set_weights') {
        // how much each kind of activity counts in the class standing (every professor grades differently); empty = total score method
        $given = isset($in['weights']) && is_array($in['weights']) ? $in['weights'] : [];
        $known = [];
        $q = $pdo->prepare("SELECT DISTINCT component FROM class_activities WHERE class_id = ? AND is_exam = 0");
        $q->execute([$cid]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $c) { $known[normComponent($c)] = $c; }
        $pdo->prepare("DELETE FROM class_weights WHERE class_id = ?")->execute([$cid]);
        $ins = $pdo->prepare("INSERT INTO class_weights (class_id, component, weight) VALUES (?,?,?)");
        foreach ($given as $comp => $w) {
            $k = normComponent((string) $comp);
            if (!isset($known[$k]) || $w === '' || $w === null) { continue; }
            if (!is_numeric($w) || (float) $w < 0 || (float) $w > 100) { throw new RuntimeException('A weight must be from 0 to 100.'); }
            if ((float) $w > 0) { $ins->execute([$cid, $known[$k], (float) $w]); }
        }
        syncAndReply($pdo, $cid, $rosterIds, true);
    }
    elseif ($action === 'add_student') {
        $sid = (string) ($in['student_id'] ?? '');
        if (!belongs($pdo, "SELECT 1 FROM students WHERE student_id = ?", [$sid])) { throw new RuntimeException('Pick the student from the list.'); }
        $pdo->prepare("INSERT INTO class_students (class_id, student_id, excluded) VALUES (?,?,0) ON DUPLICATE KEY UPDATE excluded = 0")->execute([$cid, $sid]);
        $pdo->prepare("INSERT IGNORE INTO class_attendance (meeting_id, student_id, status) SELECT id, ?, 'P' FROM class_meetings WHERE class_id = ?")->execute([$sid, $cid]);
        syncAndReply($pdo, $cid, [$sid], true);
    }
    elseif ($action === 'preview_students' || $action === 'import_students') {
        // the class list (masterlist): pasted from the faculty portal / Excel, or typed one by one
        $text = (string) ($in['text'] ?? '');
        if (mb_strlen($text) > 200000) { throw new RuntimeException('That list is too long — paste it in parts.'); }
        $rows = classifyMasterlist($pdo, parseMasterlistText($text), $cid);
        if (!$rows) { throw new RuntimeException('Paste the list first — one student per line, with the student number.'); }
        if ($action === 'preview_students') { $pdo->commit(); out(['ok' => true, 'rows' => $rows]); }
        $class = $data['class'];
        $year = classSectionYear($class['section']);
        $insStu = $pdo->prepare("INSERT INTO students (student_id, full_name, program, gender, age, year_level, academic_year, status) VALUES (?,?,?,?,?,?,?,'enrolled')");
        $insRec = $pdo->prepare("INSERT INTO student_records (student_id, year_level, academic_year, section, gwa, attendance_rate, units_failed) VALUES (?,?,?,?,NULL,NULL,0)
                                 ON DUPLICATE KEY UPDATE section = section");
        $hasRec = $pdo->prepare("SELECT 1 FROM student_records WHERE student_id = ? AND year_level = ?");
        $addCls = $pdo->prepare("INSERT INTO class_students (class_id, student_id, excluded) VALUES (?,?,0) ON DUPLICATE KEY UPDATE excluded = 0");
        $addAtt = $pdo->prepare("INSERT IGNORE INTO class_attendance (meeting_id, student_id, status) SELECT id, ?, 'P' FROM class_meetings WHERE class_id = ?");
        $added = 0; $created = 0; $skipped = 0; $touched = [];
        foreach ($rows as $r) {
            if (!in_array($r['status'], ['new', 'existing'], true)) { if ($r['status'] !== 'in_class') { $skipped++; } continue; }
            if ($r['status'] === 'new') {
                $insStu->execute([$r['id'], $r['name'], PROGRAM_NAME, 'Other', null, $year, $class['academic_year']]);
                $created++;
            }
            $hasRec->execute([$r['id'], $year]);
            if (!$hasRec->fetchColumn()) { $insRec->execute([$r['id'], $year, $class['academic_year'], $class['section']]); }
            $addCls->execute([$cid, $r['id']]);
            $addAtt->execute([$r['id'], $cid]);
            $touched[] = $r['id']; $added++;
        }
        if ($touched) { $cache = []; syncStudentsFromClasses($pdo, $touched, $cache); }
        $pdo->commit();
        out(['ok' => true, 'reload' => true, 'added' => $added, 'created' => $created, 'skipped' => $skipped]);
    }
    elseif ($action === 'remove_student') {
        $sid = (string) ($in['student_id'] ?? '');
        if (!in_array($sid, $rosterIds, true)) { throw new RuntimeException('That student is not in this class.'); }
        $pdo->prepare("UPDATE class_students SET excluded = 1 WHERE class_id = ? AND student_id = ?")->execute([$cid, $sid]);     // stays out when the class list refreshes itself
        $pdo->prepare("DELETE a FROM class_attendance a INNER JOIN class_meetings m ON m.id = a.meeting_id WHERE m.class_id = ? AND a.student_id = ?")->execute([$cid, $sid]);
        $pdo->prepare("DELETE sc FROM class_scores sc INNER JOIN class_activities x ON x.id = sc.activity_id WHERE x.class_id = ? AND sc.student_id = ?")->execute([$cid, $sid]);
        removeClassFromStudent($pdo, $cid, $sid, $data['class']);
        $pdo->commit();
        out(['ok' => true, 'reload' => true]);
    }
    else {
        throw new RuntimeException('Unknown action.');
    }
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    out(['ok' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    out(['ok' => false, 'error' => 'The change could not be saved (' . $e->getMessage() . ').']);
}
