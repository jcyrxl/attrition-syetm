<?php
/**
 * Grades and attendance recorded IN the system (Classes).
 *
 *   Term grade     = 60% class standing + 40% exam                (Prelim, Midterm, Pre-Finals)
 *   Class standing = total score earned / total items of every quiz, activity, recitation, reporting ... the professor added
 *                    — or, if the professor sets weights ("Quiz 40%, Activity 30%, Recitation 30%"), the weighted average of those kinds
 *   FINAL GRADE    = 30% Prelim + 30% Midterm + 40% Pre-Finals — shown only when all three terms are complete.
 *   GRADE SO FAR   = the same calculation over the terms that exist; it flags a failing student at once and feeds the prediction.
 *   Attendance     = every meeting is marked  /  present · A absent (1) · L late (1/2) · E excused (1/2)
 *                    absences ADD UP over the whole semester: 3 or more = Dropped (Unofficially); an alert comes at 2.
 *
 * It works while the semester is still going: a term with no exam yet is calculated from the class standing so far, and the
 * final grade is calculated from the terms that exist (their weights scaled up to 100%).
 * Every change is copied into subject_records / student_records and the student's risk is predicted again straight away.
 */
require_once __DIR__ . '/academic.php';
require_once __DIR__ . '/predict.php';

const TERM_LABELS  = [1 => 'Prelim', 2 => 'Midterm', 3 => 'Pre-Finals'];
const TERM_WEIGHTS = [1 => 0.30, 2 => 0.30, 3 => 0.40];
const CLASS_STANDING_WEIGHT = 0.60;
const EXAM_WEIGHT = 0.40;
const LOW_SCORE_PERCENT = 50.0;        // a score below half of the items needs attention (e.g. below 5 out of 10)
const ATTENTION_LOW_COUNT = 2;         // ...and two or more of them mark the student as needing attention
const ATTENDANCE_WARN_PERCENT = 80.0;
const ATTENDANCE_MIN_MEETINGS = 3;
const ABSENCE_POINTS = ['P' => 0.0, 'A' => 1.0, 'L' => 0.5, 'E' => 0.5];   // University rule: absent 1, late 1/2, excused 1/2
const ABSENCE_ALERT_AT = 2.0;          // alert already at 2 ...
const ABSENCE_DROP_AT  = 3.0;          // ... 3 or more = Dropped (Unofficially), counting prelim to pre-finals together
const ATTENDANCE_LABELS = ['P' => '/', 'A' => 'A', 'L' => 'L', 'E' => 'E'];
const ACTIVITY_TYPES = ['Quiz', 'Activity', 'Recitation', 'Reporting', 'Seatwork', 'Assignment', 'Laboratory', 'Project'];

function fmtPoints(float $p): string { return rtrim(rtrim(number_format($p, 1, '.', ''), '0'), '.'); }
function normComponent(string $c): string { return strtolower(trim($c)); }

/**
 * Class standing, exam and term grade of ONE term.
 * $scores = [[score, items, component], ...]  $exam = [score, items] or null  $weights = [component(lower case) => weight] or null (= total score)
 */
function computeTermGrade(array $scores, ?array $exam, ?array $weights = null): array {
    $cs = null;
    if ($weights) {
        $by = [];
        foreach ($scores as $s) {
            $k = normComponent($s[2]);
            if (!isset($by[$k])) { $by[$k] = [0.0, 0.0]; }
            $by[$k][0] += $s[0]; $by[$k][1] += $s[1];
        }
        $num = 0.0; $den = 0.0;
        foreach ($by as $k => $e) {
            $w = $weights[$k] ?? 0;
            if ($w > 0 && $e[1] > 0) { $num += $w * ($e[0] / $e[1] * 100); $den += $w; }
        }
        $cs = $den > 0 ? $num / $den : null;
    } else {
        $earned = 0.0; $items = 0.0;
        foreach ($scores as $s) { $earned += $s[0]; $items += $s[1]; }
        $cs = $items > 0 ? $earned / $items * 100 : null;
    }
    $ex = ($exam !== null && $exam[1] > 0) ? $exam[0] / $exam[1] * 100 : null;
    // every figure is kept to a whole number or .5 — what is shown is what is counted
    if ($cs !== null) { $cs = roundHalf($cs); }
    if ($ex !== null) { $ex = roundHalf($ex); }
    if ($cs !== null && $ex !== null) { return ['cs' => $cs, 'exam' => $ex, 'grade' => roundHalf(CLASS_STANDING_WEIGHT * $cs + EXAM_WEIGHT * $ex), 'prov' => false]; }
    if ($cs !== null)                 { return ['cs' => $cs, 'exam' => null, 'grade' => $cs, 'prov' => true]; }
    if ($ex !== null)                 { return ['cs' => null, 'exam' => $ex, 'grade' => $ex, 'prov' => true]; }
    return ['cs' => null, 'exam' => null, 'grade' => null, 'prov' => true];
}

/** FINAL GRADE from the term grades [1 => .., 2 => .., 3 => ..] (null = nothing yet). Missing terms are left out and the rest scaled to 100%. */
function computeFinalGrade(array $termGrades): ?float {
    $sum = 0.0; $w = 0.0;
    foreach (TERM_WEIGHTS as $t => $weight) {
        if (isset($termGrades[$t]) && $termGrades[$t] !== null) { $sum += $weight * $termGrades[$t]; $w += $weight; }
    }
    return $w > 0 ? roundHalf($sum / $w) : null;
}

function classSectionYear(string $section): int { return (int) substr($section, 5, 1); }       // BSIT-32 -> 3
function recordUnlocked(): bool { return !empty($_SESSION['record_unlock_until']) && $_SESSION['record_unlock_until'] > time(); }

/** Only the professor the Admin assigned the class to may record in it. Nobody else — not even the Program Head or the Admin — changes class records. */
function canEditClass(array $class): bool {
    if (function_exists('isClassArchived') && isClassArchived($class)) { return false; }     // a class of an earlier semester is archived: view only
    return isFaculty() && !empty($class['owner_user_id']) && $class['owner_user_id'] === ($_SESSION['user_id'] ?? null);
}
/** Only the assigned professor opens a class (there is no Classes page for the Program Head: everything is under Students). */
function canViewClass(array $class): bool {
    return isFaculty() && !empty($class['owner_user_id']) && $class['owner_user_id'] === ($_SESSION['user_id'] ?? null);
}
/**
 * The students a professor may see: those in the classes assigned to him or her (null = everybody, for the Program Head).
 * $ay limits it to one school year's classes.
 */
function scopeStudentIds(PDO $pdo, ?string $ay = null): ?array {
    if (!isFaculty()) { return null; }
    $classIds = ownedClassIds($pdo, $ay);
    if (!$classIds) { return []; }
    $q = $pdo->prepare("SELECT DISTINCT student_id FROM class_students WHERE excluded = 0 AND class_id IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")");
    $q->execute($classIds);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}
/** "col IN ('a','b')" for a professor's students; '' for everybody. An empty scope matches nobody. */
function scopeInSql(PDO $pdo, string $col, ?array $ids): string {
    if ($ids === null) { return ''; }
    if (!$ids) { return '1 = 0'; }
    return $col . ' IN (' . implode(',', array_map(function ($i) use ($pdo) { return $pdo->quote((string) $i); }, $ids)) . ')';
}

/** The ids of the classes assigned to the logged-in professor. */
function ownedClassIds(PDO $pdo, ?string $ay = null): array {
    $q = $pdo->prepare("SELECT id FROM classes WHERE owner_user_id = ?" . ($ay !== null ? " AND academic_year = ?" : ""));
    $q->execute($ay !== null ? [$_SESSION['user_id'], $ay] : [$_SESSION['user_id']]);
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

/** Everything about one class, in plain arrays. */
function classLoadData(PDO $pdo, int $classId): ?array {
    $q = $pdo->prepare("SELECT c.*, u.full_name AS owner_name FROM classes c LEFT JOIN users u ON u.user_id = c.owner_user_id WHERE c.id = ?");
    $q->execute([$classId]);
    $class = $q->fetch();
    if (!$class) { return null; }

    $q = $pdo->prepare("SELECT s.student_id, s.full_name, s.status FROM class_students cs INNER JOIN students s ON s.student_id = cs.student_id WHERE cs.class_id = ? AND cs.excluded = 0 ORDER BY s.full_name ASC");
    $q->execute([$classId]);
    $students = $q->fetchAll();

    $q = $pdo->prepare("SELECT id, meeting_date, term FROM class_meetings WHERE class_id = ? ORDER BY meeting_date ASC, id ASC");
    $q->execute([$classId]);
    $meetings = $q->fetchAll();

    $att = [];
    $q = $pdo->prepare("SELECT a.meeting_id, a.student_id, a.status FROM class_attendance a INNER JOIN class_meetings m ON m.id = a.meeting_id WHERE m.class_id = ?");
    $q->execute([$classId]);
    foreach ($q->fetchAll() as $r) { $att[(int) $r['meeting_id']][$r['student_id']] = isset(ABSENCE_POINTS[$r['status']]) ? $r['status'] : 'P'; }

    $byTerm = [1 => ['cs' => [], 'exam' => null], 2 => ['cs' => [], 'exam' => null], 3 => ['cs' => [], 'exam' => null]];
    $q = $pdo->prepare("SELECT * FROM class_activities WHERE class_id = ? ORDER BY term ASC, is_exam ASC, id ASC");
    $q->execute([$classId]);
    foreach ($q->fetchAll() as $a) {
        $t = (int) $a['term'];
        if (!isset($byTerm[$t])) { continue; }
        if ((int) $a['is_exam'] === 1) { if ($byTerm[$t]['exam'] === null) { $byTerm[$t]['exam'] = $a; } }
        else { $byTerm[$t]['cs'][] = $a; }
    }

    $scores = [];
    $q = $pdo->prepare("SELECT sc.activity_id, sc.student_id, sc.score FROM class_scores sc INNER JOIN class_activities a ON a.id = sc.activity_id WHERE a.class_id = ?");
    $q->execute([$classId]);
    foreach ($q->fetchAll() as $r) { $scores[(int) $r['activity_id']][$r['student_id']] = (float) $r['score']; }

    $weights = [];
    $q = $pdo->prepare("SELECT component, weight FROM class_weights WHERE class_id = ?");
    $q->execute([$classId]);
    foreach ($q->fetchAll() as $r) { $weights[normComponent($r['component'])] = (float) $r['weight']; }

    return ['class' => $class, 'students' => $students, 'meetings' => $meetings, 'att' => $att, 'byTerm' => $byTerm, 'scores' => $scores, 'weights' => $weights];
}

/** What the calculation says about one student of a loaded class. */
function classStudentResult(array $data, string $sid): array {
    $terms = []; $pct = []; $low = 0;
    $weights = $data['weights'] ?: null;
    foreach ([1, 2, 3] as $t) {
        $list = [];
        foreach ($data['byTerm'][$t]['cs'] as $a) {
            $aid = (int) $a['id'];
            if (!isset($data['scores'][$aid][$sid])) { continue; }
            $sc = $data['scores'][$aid][$sid]; $items = (float) $a['items'];
            $list[] = [$sc, $items, $a['component']];
            if ($items > 0) { $pct[$aid] = $sc / $items * 100; if ($pct[$aid] < LOW_SCORE_PERCENT) { $low++; } }
        }
        $exam = null;
        $ea = $data['byTerm'][$t]['exam'];
        if ($ea !== null && isset($data['scores'][(int) $ea['id']][$sid])) {
            $exam = [$data['scores'][(int) $ea['id']][$sid], (float) $ea['items']];
            if ($exam[1] > 0) { $pct[(int) $ea['id']] = $exam[0] / $exam[1] * 100; if ($pct[(int) $ea['id']] < LOW_SCORE_PERCENT) { $low++; } }
        }
        $terms[$t] = computeTermGrade($list, $exam, $weights);
    }
    // GRADE SO FAR uses whatever terms exist (even a first quiz) — it is what flags a student early and feeds the prediction.
    // The FINAL GRADE is shown only when Prelim, Midterm and Pre-Finals are all complete (class standing AND exam in each).
    $running = computeFinalGrade(array_map(function ($x) { return $x['grade']; }, $terms));
    $complete = true;
    foreach ($terms as $x) { if ($x['grade'] === null || $x['prov']) { $complete = false; } }
    $final = $complete ? $running : null;

    // attendance: the absences of ALL meetings of the semester add up
    $count = ['P' => 0, 'A' => 0, 'L' => 0, 'E' => 0]; $total = 0; $points = 0.0;
    foreach ($data['meetings'] as $m) {
        $st = $data['att'][(int) $m['id']][$sid] ?? null;
        if ($st === null) { continue; }
        $total++; $count[$st]++; $points += ABSENCE_POINTS[$st];
    }
    $attPct = $total > 0 ? max(0.0, ($total - $points) / $total * 100) : null;
    $alert = $points >= ABSENCE_DROP_AT ? 'drop' : ($points >= ABSENCE_ALERT_AT ? 'warn' : null);

    $reasons = [];
    if ($alert === 'drop')      { $reasons[] = fmtPoints($points) . ' absences — Dropped (Unofficially) limit reached'; }
    elseif ($alert === 'warn')  { $reasons[] = fmtPoints($points) . ' absences — ' . fmtPoints(ABSENCE_DROP_AT - $points) . ' more and Dropped (Unofficially)'; }
    elseif ($attPct !== null && $total >= ATTENDANCE_MIN_MEETINGS && $attPct < ATTENDANCE_WARN_PERCENT) { $reasons[] = 'attendance ' . round($attPct) . '% (below ' . (int) ATTENDANCE_WARN_PERCENT . '%)'; }
    if ($low >= ATTENTION_LOW_COUNT) { $reasons[] = $low . ' scores below ' . (int) LOW_SCORE_PERCENT . '%'; }
    // a failing term is flagged at once — even when the term is not finished yet
    foreach (TERM_LABELS as $t => $label) {
        $tg = $terms[$t]['grade'];
        if ($tg !== null && $tg < FAILING_GRADE_THRESHOLD) { $reasons[] = $label . ' grade ' . ($terms[$t]['prov'] ? 'so far ' : '') . round($tg) . '% (below ' . (int) FAILING_GRADE_THRESHOLD . '%)'; }
    }

    return ['terms' => $terms, 'pct' => $pct, 'low' => $low, 'running' => $running, 'final' => $final,
            'att' => ['total' => $total, 'points' => $points, 'pct' => $attPct, 'absent' => $count['A'], 'late' => $count['L'], 'excused' => $count['E']],
            'alert' => $alert, 'attention' => $reasons];
}

/** Latest predicted risk of the given students: [student_id => ['level' =>, 'prob' =>]]. */
function latestRisks(PDO $pdo, array $studentIds): array {
    if (!$studentIds) { return []; }
    $in = implode(',', array_fill(0, count($studentIds), '?'));
    $q = $pdo->prepare("SELECT p.student_id, p.risk_level, p.probability FROM predictions p
                        INNER JOIN (SELECT student_id, MAX(predicted_at) AS latest FROM predictions WHERE student_id IN ($in) GROUP BY student_id) m
                            ON m.student_id = p.student_id AND m.latest = p.predicted_at");
    $q->execute($studentIds);
    $out = [];
    foreach ($q->fetchAll() as $r) { $out[$r['student_id']] = ['level' => $r['risk_level'], 'prob' => (float) $r['probability']]; }
    return $out;
}

/** The numbers of one student, ready to send to the page as JSON. */
function studentPayload(array $data, string $sid, array $risks): array {
    $r = classStudentResult($data, $sid);
    $terms = [];
    foreach ($r['terms'] as $t => $x) { $terms[$t] = ['cs' => $x['cs'], 'exam' => $x['exam'], 'grade' => $x['grade'], 'prov' => $x['prov']]; }
    return ['sid' => $sid, 'terms' => $terms, 'pct' => $r['pct'], 'running' => $r['running'], 'final' => $r['final'], 'att' => $r['att'], 'alert' => $r['alert'],
            'attention' => $r['attention'], 'risk' => $risks[$sid] ?? null];
}

// ------------------------------------------------------------------ the class list builds itself, student by student
/**
 * Puts every enrolled student of the class's school year into the class where it belongs:
 *   - a student whose enrolled subjects are known (imported PEF) is in the class of each subject he or she takes — even from another section;
 *   - a student with no subject list for that semester follows the classes of his or her section.
 * A student taken out by hand stays out. Students who join late are marked present for the meetings already held.
 */
function refreshClassRoster(PDO $pdo, int $classId): void {
    $pdo->prepare("INSERT IGNORE INTO class_students (class_id, student_id, excluded)
        SELECT c.id, s.student_id, 0
        FROM classes c
        INNER JOIN student_records sr ON sr.academic_year = c.academic_year
        INNER JOIN students s ON s.student_id = sr.student_id AND s.status = 'enrolled'
        WHERE c.id = ?
          AND ( EXISTS (SELECT 1 FROM student_subjects ss WHERE ss.student_id = s.student_id AND ss.academic_year = c.academic_year AND ss.semester = c.semester
                        AND ((c.subject_code IS NOT NULL AND ss.subject_code = c.subject_code) OR LOWER(ss.subject_name) = LOWER(c.subject_name)))
                OR ( sr.section = c.section
                     AND NOT EXISTS (SELECT 1 FROM student_subjects ss2 WHERE ss2.student_id = s.student_id AND ss2.academic_year = c.academic_year AND ss2.semester = c.semester) ) )")
        ->execute([$classId]);
    $pdo->prepare("INSERT IGNORE INTO class_attendance (meeting_id, student_id, status)
                   SELECT m.id, cs.student_id, 'P' FROM class_meetings m
                   INNER JOIN class_students cs ON cs.class_id = m.class_id AND cs.excluded = 0 WHERE m.class_id = ?")->execute([$classId]);
}
function refreshAllRosters(PDO $pdo, string $ay): void {
    $q = $pdo->prepare("SELECT id FROM classes WHERE academic_year = ?");
    $q->execute([$ay]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) { refreshClassRoster($pdo, (int) $id); }
}

/** Creates a class and its three term exams. Its class list is filled in automatically (unless $refresh is false). Returns the id. */
function createClass(PDO $pdo, string $subject, string $section, string $ay, int $semester, ?string $ownerId, ?string $code = null, bool $refresh = true): int {
    $pdo->prepare("INSERT INTO classes (owner_user_id, subject_code, subject_name, section, academic_year, semester) VALUES (?,?,?,?,?,?)")->execute([$ownerId, $code, $subject, $section, $ay, $semester]);
    $id = (int) $pdo->lastInsertId();
    $ex = $pdo->prepare("INSERT INTO class_activities (class_id, term, component, title, items, is_exam) VALUES (?, ?, 'Exam', ?, 50, 1)");
    foreach (TERM_LABELS as $t => $label) { $ex->execute([$id, $t, $label . ' Exam']); }
    if ($refresh) { refreshClassRoster($pdo, $id); }
    return $id;
}
/** The class of a subject for a section — made when it does not exist yet (used when PEFs are imported). */
function ensureClass(PDO $pdo, string $subject, ?string $code, string $section, string $ay, int $semester): int {
    $q = $pdo->prepare("SELECT id FROM classes WHERE subject_name = ? AND section = ? AND academic_year = ? AND semester = ?");
    $q->execute([$subject, $section, $ay, $semester]);
    $id = $q->fetchColumn();
    return $id ? (int) $id : createClass($pdo, $subject, $section, $ay, $semester, null, $code, false);
}

/** Students with 2 or more absences in a class of this school year (3 or more = Dropped (Unofficially)). $classIds limits it to some classes (a professor's own). */
function attendanceAlerts(PDO $pdo, string $ay, int $limit = 12, ?array $classIds = null): array {
    if ($classIds !== null && !$classIds) { return []; }
    try {
        $params = [$ay];
        $in = '';
        if ($classIds !== null) { $in = ' AND c.id IN (' . implode(',', array_fill(0, count($classIds), '?')) . ')'; $params = array_merge($params, $classIds); }
        $q = $pdo->prepare("SELECT s.student_id, s.full_name, c.id AS class_id, c.subject_name, c.section,
                                   SUM(CASE a.status WHEN 'A' THEN 1 WHEN 'L' THEN 0.5 WHEN 'E' THEN 0.5 ELSE 0 END) AS pts
                            FROM class_attendance a
                            INNER JOIN class_meetings m ON m.id = a.meeting_id
                            INNER JOIN classes c ON c.id = m.class_id AND c.academic_year = ?$in
                            INNER JOIN class_students cs ON cs.class_id = c.id AND cs.student_id = a.student_id AND cs.excluded = 0
                            INNER JOIN students s ON s.student_id = a.student_id AND s.status = 'enrolled'
                            GROUP BY s.student_id, s.full_name, c.id, c.subject_name, c.section
                            HAVING pts >= " . (float) ABSENCE_ALERT_AT . "
                            ORDER BY pts DESC, s.full_name ASC LIMIT " . (int) $limit);
        $q->execute($params);
        return $q->fetchAll();
    } catch (PDOException $e) { return []; }
}

/** One class for the lists: the loaded data and how many students need attention / are at 2 or 3 absences. */
function classSummary(PDO $pdo, int $classId): ?array {
    $d = classLoadData($pdo, $classId);
    if (!$d) { return null; }
    $attn = 0; $drop = 0; $warn = 0;
    foreach ($d['students'] as $s) {
        $r = classStudentResult($d, $s['student_id']);
        if ($r['attention']) { $attn++; }
        if ($r['alert'] === 'drop') { $drop++; } elseif ($r['alert'] === 'warn') { $warn++; }
    }
    return ['d' => $d, 'attention' => $attn, 'drop' => $drop, 'warn' => $warn, 'comp' => classCompletion($d)];
}

/**
 * The Dashboard's section graph: for the semester in progress, the average term grade of each section, term by term
 * (Prelim; then Prelim + Midterm; then all three). $classIds limits it to a professor's own classes.
 * Returns ['sem' => n, 'current' => 0-3 (the latest term with any data), 'sections' => [section => ['terms' => [t => avg|null], 'students' => n, 'attention' => n, 'alerts' => n]]]
 * ordered with the section that needs attention first.
 */
function termStatsBySection(PDO $pdo, string $ay, int $preferredSem, ?array $classIds = null): array {
    $out = ['sem' => $preferredSem, 'current' => 0, 'sections' => []];
    if ($classIds !== null && !$classIds) { return $out; }
    try {
        $sql = "SELECT id, semester FROM classes WHERE academic_year = ?";
        $params = [$ay];
        if ($classIds !== null) { $sql .= " AND id IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")"; $params = array_merge($params, $classIds); }
        $q = $pdo->prepare($sql);
        $q->execute($params);
        $rows = $q->fetchAll();
    } catch (PDOException $e) { return $out; }
    if (!$rows) { return $out; }
    $bySem = [];
    foreach ($rows as $r) { $bySem[(int) $r['semester']][] = (int) $r['id']; }
    $sem = isset($bySem[$preferredSem]) ? $preferredSem : max(array_keys($bySem));
    $out['sem'] = $sem;

    $sum = []; $cnt = []; $people = []; $attn = []; $alerts = []; $current = 0;
    foreach ($bySem[$sem] as $cid) {
        $d = classLoadData($pdo, $cid);
        if (!$d) { continue; }
        $sec = $d['class']['section'];
        foreach ($d['meetings'] as $m) { $current = max($current, (int) $m['term']); }
        foreach ($d['byTerm'] as $t => $x) {
            $acts = $x['cs']; if ($x['exam']) { $acts[] = $x['exam']; }
            foreach ($acts as $a) { if (!empty($d['scores'][(int) $a['id']])) { $current = max($current, (int) $t); } }
        }
        foreach ($d['students'] as $s) {
            $sid = $s['student_id'];
            $r = classStudentResult($d, $sid);
            $people[$sec][$sid] = true;
            if ($r['attention']) { $attn[$sec][$sid] = true; }
            if ($r['alert'])     { $alerts[$sec][$sid] = true; }
            foreach ([1, 2, 3] as $t) {
                if ($r['terms'][$t]['grade'] !== null) { $sum[$sec][$t] = ($sum[$sec][$t] ?? 0) + $r['terms'][$t]['grade']; $cnt[$sec][$t] = ($cnt[$sec][$t] ?? 0) + 1; }
            }
        }
    }
    $out['current'] = $current;
    foreach ($people as $sec => $_) {
        $terms = [];
        foreach ([1, 2, 3] as $t) { $terms[$t] = !empty($cnt[$sec][$t]) ? $sum[$sec][$t] / $cnt[$sec][$t] : null; }
        $out['sections'][$sec] = ['terms' => $terms, 'students' => count($people[$sec]), 'attention' => count($attn[$sec] ?? []), 'alerts' => count($alerts[$sec] ?? [])];
    }
    $key = max(1, $current);
    uasort($out['sections'], function ($a, $b) use ($key) {
        $x = $a['terms'][$key]; $y = $b['terms'][$key];
        if ($x === null && $y === null) { return 0; } if ($x === null) { return 1; } if ($y === null) { return -1; }
        return $x <=> $y;
    });
    return $out;
}

/** Students needing attention in these classes (a professor's own), worst first: [name, id, subject, section, reasons[], risk]. */
function attentionList(PDO $pdo, array $classIds, int $limit = 12): array {
    $rows = [];
    foreach ($classIds as $cid) {
        $d = classLoadData($pdo, (int) $cid);
        if (!$d) { continue; }
        $risks = latestRisks($pdo, array_map(function ($s) { return $s['student_id']; }, $d['students']));
        foreach ($d['students'] as $s) {
            $r = classStudentResult($d, $s['student_id']);
            if (!$r['attention']) { continue; }
            $rows[] = ['name' => $s['full_name'], 'sid' => $s['student_id'], 'class_id' => (int) $cid, 'subject' => $d['class']['subject_name'], 'section' => $d['class']['section'],
                       'reasons' => $r['attention'], 'risk' => $risks[$s['student_id']] ?? null, 'weight' => count($r['attention']) + ($r['alert'] === 'drop' ? 5 : 0)];
        }
    }
    usort($rows, function ($a, $b) { return ($b['weight'] <=> $a['weight']) ?: strcasecmp($a['name'], $b['name']); });
    return array_slice($rows, 0, $limit);
}

/** Risk counts and the highest-risk students among the students of these classes (for a professor's Dashboard). */
function riskForClasses(PDO $pdo, array $classIds): array {
    $out = ['High' => 0, 'Moderate' => 0, 'Low' => 0, 'total' => 0];
    if (!$classIds) { return $out; }
    $in = implode(',', array_fill(0, count($classIds), '?'));
    $q = $pdo->prepare("SELECT DISTINCT cs.student_id FROM class_students cs WHERE cs.excluded = 0 AND cs.class_id IN ($in)");
    $q->execute($classIds);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    $out['total'] = count($ids);
    foreach (latestRisks($pdo, $ids) as $r) { $out[$r['level']]++; }
    return $out;
}

// ------------------------------------------------------------------ copying the class results into the student's records
/** Recomputes a student's year record (GWA, attendance, failed subjects) from all of that year's subject rows. */
function recomputeYearRecord(PDO $pdo, string $sid, int $year, string $ay, string $section): void {
    $q = $pdo->prepare("SELECT AVG(grade) AS g, AVG(attendance_rate) AS a, SUM(CASE WHEN grade < ? THEN 1 ELSE 0 END) AS f, COUNT(*) AS n FROM subject_records WHERE student_id = ? AND year_level = ?");
    $q->execute([FAILING_GRADE_THRESHOLD, $sid, $year]);
    $x = $q->fetch();
    if ((int) $x['n'] === 0) { return; }
    $pdo->prepare("INSERT INTO student_records (student_id, year_level, academic_year, section, gwa, attendance_rate, units_failed) VALUES (?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE gwa = VALUES(gwa), attendance_rate = VALUES(attendance_rate), units_failed = VALUES(units_failed)")
        ->execute([$sid, $year, $ay, $section, $x['g'] !== null ? round((float) $x['g'], 2) : null, $x['a'] !== null ? round((float) $x['a'], 2) : null, (int) $x['f']]);
}

/**
 * Copies the class results of these students into subject_records / student_records, then predicts their risk again.
 * $cache keeps the classes that were already loaded, so a whole class can be refreshed without reading it again and again.
 */
function syncStudentsFromClasses(PDO $pdo, array $studentIds, array &$cache = []): void {
    $classesOf = $pdo->prepare("SELECT class_id FROM class_students WHERE student_id = ? AND excluded = 0");
    $recAy     = $pdo->prepare("SELECT academic_year FROM student_records WHERE student_id = ? AND year_level = ?");
    $delOwn    = $pdo->prepare("DELETE FROM subject_records WHERE student_id = ? AND class_id = ?");
    $delDup    = $pdo->prepare("DELETE FROM subject_records WHERE student_id = ? AND year_level = ? AND semester = ? AND subject_name = ? AND class_id IS NULL");
    $insSub    = $pdo->prepare("INSERT INTO subject_records (student_id, year_level, semester, subject_name, grade, attendance_rate, class_id) VALUES (?,?,?,?,?,?,?)");

    foreach (array_unique($studentIds) as $sid) {
        $years = [];
        $classesOf->execute([$sid]);
        foreach ($classesOf->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $cid = (int) $cid;
            if (!isset($cache[$cid])) { $cache[$cid] = classLoadData($pdo, $cid); }
            $data = $cache[$cid];
            if ($data === null) { continue; }
            $class = $data['class'];
            $year = classSectionYear($class['section']);
            // never touch the record of a different school year for the same year level
            $recAy->execute([$sid, $year]);
            $existing = $recAy->fetchColumn();
            if ($existing !== false && $existing !== $class['academic_year']) { continue; }

            $res = classStudentResult($data, $sid);
            $delOwn->execute([$sid, $cid]);
            $delDup->execute([$sid, $year, (int) $class['semester'], $class['subject_name']]);
            if ($res['running'] !== null || $res['att']['pct'] !== null) {
                $insSub->execute([$sid, $year, (int) $class['semester'], $class['subject_name'],
                                  $res['running'] !== null ? round($res['running'], 2) : null,
                                  $res['att']['pct'] !== null ? round($res['att']['pct'], 2) : null, $cid]);
            }
            $years[$year] = [$class['academic_year'], $class['section']];
        }
        foreach ($years as $year => $info) { recomputeYearRecord($pdo, $sid, (int) $year, $info[0], $info[1]); }
        runPredictionForStudent($pdo, $sid);
    }
}

/** Takes one class out of a student's records (the class was deleted, or the student left it), then predicts again. */
function removeClassFromStudent(PDO $pdo, int $classId, string $sid, array $class): void {
    $pdo->prepare("DELETE FROM subject_records WHERE student_id = ? AND class_id = ?")->execute([$sid, $classId]);
    $year = classSectionYear($class['section']);
    $rec = $pdo->prepare("SELECT academic_year FROM student_records WHERE student_id = ? AND year_level = ?");
    $rec->execute([$sid, $year]);
    if ($rec->fetchColumn() !== false) {
        $q = $pdo->prepare("SELECT AVG(grade) AS g, AVG(attendance_rate) AS a, SUM(CASE WHEN grade < ? THEN 1 ELSE 0 END) AS f FROM subject_records WHERE student_id = ? AND year_level = ?");
        $q->execute([FAILING_GRADE_THRESHOLD, $sid, $year]);
        $x = $q->fetch();
        $pdo->prepare("UPDATE student_records SET gwa = ?, attendance_rate = ?, units_failed = ? WHERE student_id = ? AND year_level = ?")
            ->execute([$x['g'] !== null ? round((float) $x['g'], 2) : null, $x['a'] !== null ? round((float) $x['a'], 2) : null, (int) $x['f'], $sid, $year]);
    }
    runPredictionForStudent($pdo, $sid);
}

// ------------------------------------------------------------------ the Dashboard's graphs (Program Head: everything · professor: own classes)
/** Enrolled students of a school year by their latest risk: ['High' => [rows], 'Moderate' => [...], 'Low' => [...]] (rows: id, name, prob, section). $ids = a professor's students (null = all). */
function riskLists(PDO $pdo, string $ay, ?array $ids): array {
    $out = ['High' => [], 'Moderate' => [], 'Low' => []];
    $scope = scopeInSql($pdo, 's.student_id', $ids);
    $q = $pdo->prepare("SELECT s.student_id, s.full_name, p.risk_level, p.probability, sr.section FROM students s
                        INNER JOIN predictions p ON p.student_id = s.student_id AND p.predicted_at = (SELECT MAX(predicted_at) FROM predictions WHERE student_id = s.student_id)
                        INNER JOIN student_records sr ON sr.student_id = s.student_id AND sr.academic_year = ?
                        WHERE s.status = 'enrolled'" . ($scope !== '' ? " AND $scope" : "") . " ORDER BY p.probability DESC, s.full_name ASC");
    $q->execute([$ay]);
    foreach ($q->fetchAll() as $r) { $out[$r['risk_level']][] = ['id' => $r['student_id'], 'name' => $r['full_name'], 'prob' => (float) $r['probability'], 'section' => $r['section']]; }
    return $out;
}

/**
 * The numbers behind the attendance, quiz and grade graphs of a school year (all its semesters, "from the start of the school year to today").
 * $classIds = a professor's own classes; null = every class (Program Head).
 * Returns ['classes' => n,
 *          'dates'    => [date => ['pts' => absence points, 'n' => marks, 'who' => [[name, id, status, subject], ...]]],
 *          'absent'   => [[name, id, subject, section, class_id, pts], ...]  most absences first,
 *          'activities' => [[label, section, term, class_id, avg, n, below => [[name, id, score, items, pct]]]] worst first,
 *          'grades'   => [[label, section, class_id, term_label, complete, terms => [t => avg], counts, bands => [fail|ok|good => [[name, id, grade]]]]]]
 */
function dashboardSeries(PDO $pdo, string $ay, ?array $classIds = null): array {
    $out = ['classes' => 0, 'dates' => [], 'absent' => [], 'activities' => [], 'grades' => [],
            'termAgg' => [1 => ['sum' => 0.0, 'n' => 0, 'fail' => []], 2 => ['sum' => 0.0, 'n' => 0, 'fail' => []], 3 => ['sum' => 0.0, 'n' => 0, 'fail' => []], 4 => ['sum' => 0.0, 'n' => 0, 'fail' => []]]];
    if ($classIds !== null && !$classIds) { return $out; }
    try {
        $sql = "SELECT id FROM classes WHERE academic_year = ?";
        $params = [$ay];
        if ($classIds !== null) { $sql .= " AND id IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")"; $params = array_merge($params, $classIds); }
        $q = $pdo->prepare($sql);
        $q->execute($params);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) { return $out; }

    foreach ($ids as $cid) {
        $d = classLoadData($pdo, (int) $cid);
        if (!$d) { continue; }
        $out['classes']++;
        $subject = $d['class']['subject_name']; $sec = $d['class']['section'];
        $names = [];
        foreach ($d['students'] as $s) { $names[$s['student_id']] = $s['full_name']; }

        // attendance, meeting by meeting
        foreach ($d['meetings'] as $m) {
            $date = $m['meeting_date'];
            if (!isset($out['dates'][$date])) { $out['dates'][$date] = ['pts' => 0.0, 'n' => 0, 'who' => []]; }
            foreach ($names as $sid => $nm) {
                $st = $d['att'][(int) $m['id']][$sid] ?? null;
                if ($st === null) { continue; }
                $out['dates'][$date]['n']++;
                $out['dates'][$date]['pts'] += ABSENCE_POINTS[$st];
                if ($st !== 'P') { $out['dates'][$date]['who'][] = [$nm, $sid, $st, $subject]; }
            }
        }
        // results per student
        $results = [];
        foreach ($names as $sid => $nm) { $results[$sid] = classStudentResult($d, $sid); }
        foreach ($results as $sid => $r) {
            foreach ([1, 2, 3] as $tt) {
                $gv = $r['terms'][$tt]['grade'];
                if ($gv === null) { continue; }
                $out['termAgg'][$tt]['sum'] += $gv; $out['termAgg'][$tt]['n']++;
                if ($gv < FAILING_GRADE_THRESHOLD) { $out['termAgg'][$tt]['fail'][] = [$names[$sid], $sid, $gv, $subject, $sec]; }
            }
            if ($r['final'] !== null) {
                $out['termAgg'][4]['sum'] += $r['final']; $out['termAgg'][4]['n']++;
                if ($r['final'] < FAILING_GRADE_THRESHOLD) { $out['termAgg'][4]['fail'][] = [$names[$sid], $sid, $r['final'], $subject, $sec]; }
            }
            if ($r['att']['points'] > 0) { $out['absent'][] = [$names[$sid], $sid, $subject, $sec, (int) $cid, $r['att']['points']]; }
        }
        // quizzes / activities
        foreach ($d['byTerm'] as $t => $x) {
            foreach ($x['cs'] as $a) {
                $aid = (int) $a['id']; $items = (float) $a['items']; $vals = []; $below = [];
                foreach ($names as $sid => $nm) {
                    if (!isset($d['scores'][$aid][$sid]) || $items <= 0) { continue; }
                    $sc = $d['scores'][$aid][$sid]; $pc = $sc / $items * 100; $vals[] = $pc;
                    if ($pc < LOW_SCORE_PERCENT) { $below[] = [$nm, $sid, $sc, $items, $pc]; }
                }
                if (!$vals) { continue; }
                usort($below, function ($p, $q) { return $p[4] <=> $q[4]; });
                $out['activities'][] = ['label' => $subject . ' · ' . $a['title'], 'title' => $a['title'], 'subject' => $subject, 'section' => $sec, 'term' => (int) $t, 'class_id' => (int) $cid,
                                        'avg' => array_sum($vals) / count($vals), 'n' => count($vals), 'below' => $below];
            }
        }
        // grades: the term in progress (or the Final once all three terms are complete)
        $cur = 0;
        foreach ($d['meetings'] as $m) { $cur = max($cur, (int) $m['term']); }
        foreach ($d['byTerm'] as $t => $x) {
            $acts = $x['cs']; if ($x['exam']) { $acts[] = $x['exam']; }
            foreach ($acts as $a) { if (!empty($d['scores'][(int) $a['id']])) { $cur = max($cur, (int) $t); } }
        }
        if ($cur === 0 || !$results) { continue; }
        $complete = true;
        foreach ($results as $r) { if ($r['final'] === null) { $complete = false; } }
        $bands = ['fail' => [], 'ok' => [], 'good' => []]; $sum = [1 => 0, 2 => 0, 3 => 0]; $cnt = [1 => 0, 2 => 0, 3 => 0];
        foreach ($results as $sid => $r) {
            $val = $complete ? $r['final'] : $r['terms'][$cur]['grade'];
            foreach ([1, 2, 3] as $t) { if ($r['terms'][$t]['grade'] !== null) { $sum[$t] += $r['terms'][$t]['grade']; $cnt[$t]++; } }
            if ($val === null) { continue; }
            $band = $val < FAILING_GRADE_THRESHOLD ? 'fail' : ($val < 90 ? 'ok' : 'good');
            $bands[$band][] = [$names[$sid], $sid, $val];
        }
        $terms = [];
        foreach ([1, 2, 3] as $t) { $terms[$t] = $cnt[$t] ? $sum[$t] / $cnt[$t] : null; }
        $out['grades'][] = ['label' => $subject, 'section' => $sec, 'class_id' => (int) $cid, 'term' => $cur, 'complete' => $complete,
                            'term_label' => $complete ? 'Final Grade' : TERM_LABELS[$cur] . ' grade', 'terms' => $terms, 'bands' => $bands];
    }
    ksort($out['dates']);
    usort($out['absent'], function ($a, $b) { return ($b[5] <=> $a[5]) ?: strcasecmp($a[0], $b[0]); });
    usort($out['activities'], function ($a, $b) { return $a['avg'] <=> $b['avg']; });
    usort($out['grades'], function ($a, $b) { return (count($b['bands']['fail']) <=> count($a['bands']['fail'])) ?: strcmp($a['label'], $b['label']); });
    return $out;
}

/**
 * Class results that were recorded straight into the database (a demo import, say) have not been copied into the students' records yet.
 * The first time a Dashboard is opened they are copied in and the students' risk is predicted again, so the graphs and the risk agree.
 * $classIds limits it to a professor's own classes. Done in batches, so a big import never makes one page slow.
 */
function syncUnsyncedClasses(PDO $pdo, ?array $classIds = null, int $limit = 80): void {
    if ($classIds !== null && !$classIds) { return; }
    try {
        $params = [];
        $in = '';
        if ($classIds !== null) { $in = ' AND c.id IN (' . implode(',', array_fill(0, count($classIds), '?')) . ')'; $params = $classIds; }
        $q = $pdo->prepare("SELECT c.id FROM classes c
                            WHERE (EXISTS (SELECT 1 FROM class_meetings m WHERE m.class_id = c.id)
                                   OR EXISTS (SELECT 1 FROM class_activities a INNER JOIN class_scores sc ON sc.activity_id = a.id WHERE a.class_id = c.id))
                              AND NOT EXISTS (SELECT 1 FROM subject_records sr WHERE sr.class_id = c.id)$in
                            LIMIT " . (int) $limit);
        $q->execute($params);
        $todo = $q->fetchAll(PDO::FETCH_COLUMN);
        if (!$todo) { return; }
        $students = [];
        $r = $pdo->prepare("SELECT student_id FROM class_students WHERE class_id = ? AND excluded = 0");
        foreach ($todo as $cid) { $r->execute([(int) $cid]); foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $sid) { $students[$sid] = true; } }
        if (!$students) { return; }
        $pdo->beginTransaction();
        $cache = [];
        syncStudentsFromClasses($pdo, array_keys($students), $cache);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
}

/**
 * Is every grade of a semester complete? (Every student of every class has a Final Grade — Prelim, Midterm and Pre-Finals all done.)
 * Only then may the Admin archive the semester. Returns ['classes' => n, 'students' => n, 'incomplete' => [[id, subject, section, professor, students, missing]]].
 */
function periodCompletion(PDO $pdo, string $ay, int $sem): array {
    $out = ['classes' => 0, 'students' => 0, 'incomplete' => []];
    $q = $pdo->prepare("SELECT id FROM classes WHERE academic_year = ? AND semester = ? ORDER BY subject_name, section");
    $q->execute([$ay, $sem]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $d = classLoadData($pdo, (int) $cid);
        if (!$d || !$d['students']) { continue; }
        $out['classes']++;
        $missing = 0;
        foreach ($d['students'] as $s) { if (classStudentResult($d, $s['student_id'])['final'] === null) { $missing++; } }
        $out['students'] += count($d['students']);
        if ($missing > 0) {
            $out['incomplete'][] = ['id' => (int) $cid, 'subject' => $d['class']['subject_name'], 'section' => $d['class']['section'],
                                    'professor' => $d['class']['owner_name'] ?: 'Not assigned', 'students' => count($d['students']), 'missing' => $missing];
        }
    }
    return $out;
}

// ------------------------------------------------------------------ end of semester: who has encoded what, clearing, archiving
/** How far a class is: students whose Prelim / Midterm / Pre-Finals / Final are complete. status: not_started | in_progress | complete | cleared | empty. */
function classCompletion(array $d): array {
    $n = count($d['students']);
    $terms = [1 => 0, 2 => 0, 3 => 0]; $final = 0;
    foreach ($d['students'] as $s) {
        $r = classStudentResult($d, $s['student_id']);
        foreach ([1, 2, 3] as $t) { if ($r['terms'][$t]['grade'] !== null && !$r['terms'][$t]['prov']) { $terms[$t]++; } }
        if ($r['final'] !== null) { $final++; }
    }
    $started = !empty($d['meetings']) || !empty($d['scores']);
    $status = $n === 0 ? 'empty' : ($final === $n ? 'complete' : ($started ? 'in_progress' : 'not_started'));
    if (!empty($d['class']['cleared_at'])) { $status = 'cleared'; }
    return ['n' => $n, 'terms' => $terms, 'final' => $final, 'started' => $started, 'status' => $status];
}

/**
 * The whole semester at a glance, grouped by professor: [
 *   'profs' => [user_id|'' => ['name', 'classes' => [[id, subject, section, n, terms, final, status]], 'status' => none_yet|in_progress|complete, 'done' => n, 'total' => n]],
 *   'total' => classes with students, 'complete' => complete or cleared, 'cleared' => n, 'ready' => bool (every class complete) ]
 */
function semesterStatus(PDO $pdo, string $ay, int $sem): array {
    $out = ['profs' => [], 'total' => 0, 'complete' => 0, 'cleared' => 0, 'ready' => true];
    $q = $pdo->prepare("SELECT id FROM classes WHERE academic_year = ? AND semester = ? ORDER BY subject_name, section");
    $q->execute([$ay, $sem]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $d = classLoadData($pdo, (int) $cid);
        if (!$d) { continue; }
        $c = classCompletion($d);
        if ($c['status'] === 'empty') { continue; }
        $key = (string) ($d['class']['owner_user_id'] ?? '');
        if (!isset($out['profs'][$key])) { $out['profs'][$key] = ['name' => $d['class']['owner_name'] ?: 'Not assigned to a professor', 'classes' => [], 'done' => 0, 'total' => 0, 'started' => 0]; }
        $out['profs'][$key]['classes'][] = ['id' => (int) $cid, 'subject' => $d['class']['subject_name'], 'section' => $d['class']['section'], 'n' => $c['n'],
                                            'terms' => $c['terms'], 'final' => $c['final'], 'status' => $c['status'], 'started' => $c['started']];
        $out['profs'][$key]['total']++; $out['total']++;
        if ($c['started']) { $out['profs'][$key]['started']++; }
        if ($c['status'] === 'complete' || $c['status'] === 'cleared') { $out['profs'][$key]['done']++; $out['complete']++; } else { $out['ready'] = false; }
        if ($c['status'] === 'cleared') { $out['cleared']++; }
    }
    foreach ($out['profs'] as $k => &$p) {
        $p['status'] = $p['done'] === $p['total'] ? 'complete' : ($p['started'] > 0 ? 'in_progress' : 'none_yet');
    }
    unset($p);
    uasort($out['profs'], function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    return $out;
}

/** The Program Head clears a class: its grades are complete and final, and it is archived (view only). Returns an error message or null. */
function clearClassById(PDO $pdo, int $id, string $by): ?string {
    $d = classLoadData($pdo, $id);
    if (!$d) { return 'That class no longer exists.'; }
    if (!empty($d['class']['cleared_at'])) { return 'That class is already cleared.'; }
    $c = classCompletion($d);
    if ($c['status'] !== 'complete') { return 'The grades are not complete yet — ' . ($c['n'] - $c['final']) . ' of ' . $c['n'] . ' students still have no Final Grade.'; }
    $pdo->prepare("UPDATE classes SET cleared_at = NOW(), cleared_by = ? WHERE id = ?")->execute([$by, $id]);
    return null;
}
function reopenClassById(PDO $pdo, int $id): ?string {
    $q = $pdo->prepare("UPDATE classes SET cleared_at = NULL, cleared_by = NULL WHERE id = ? AND cleared_at IS NOT NULL");
    $q->execute([$id]);
    return $q->rowCount() ? null : 'That class is not cleared.';
}
/**
 * Archives the current semester: every class must have complete grades. The classes are cleared, the system moves to the next
 * semester, and (end of the 2nd semester, if asked) the enrolled 4th-year students are marked Graduated. Returns [ok, message].
 */
function archiveCurrentSemester(PDO $pdo, string $by, bool $graduate): array {
    $cur = currentSchoolYear($pdo);
    $st = semesterStatus($pdo, $cur['ay'], (int) $cur['sem']);
    if (!$st['ready']) { return [false, 'The grades are not complete yet — ' . ($st['total'] - $st['complete']) . ' of ' . $st['total'] . ' classes are still missing grades. Nothing was archived.']; }
    [$nAy, $nSem] = nextPeriod($cur['ay'], (int) $cur['sem']);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE classes SET cleared_at = NOW(), cleared_by = ? WHERE academic_year = ? AND semester = ? AND cleared_at IS NULL")->execute([$by, $cur['ay'], (int) $cur['sem']]);
        $graduated = 0;
        if ((int) $cur['sem'] === 2 && $graduate) {
            $g = $pdo->prepare("UPDATE students s SET s.status = 'graduated' WHERE s.status = 'enrolled'
                                AND EXISTS (SELECT 1 FROM student_records r WHERE r.student_id = s.student_id AND r.academic_year = ? AND r.year_level = 4)");
            $g->execute([$cur['ay']]);
            $graduated = $g->rowCount();
        }
        setCurrentPeriod($pdo, $nAy, $nSem);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } return [false, 'Could not archive: ' . $e->getMessage()]; }
    return [true, $cur['ay'] . ' · ' . ((int) $cur['sem'] === 1 ? '1st' : '2nd') . ' Semester is archived. The system is now on ' . $nAy . ' · ' . ($nSem === 1 ? '1st' : '2nd') . ' Semester.' . ($graduated ? ' ' . $graduated . ' fourth-year student' . ($graduated === 1 ? ' was' : 's were') . ' marked Graduated.' : '')];
}
