<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/predict.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';
require_once __DIR__ . '/../config/grades.php';

// Admin's only job is User Management — no access to student-data pages at all.
denyAdmin();                           // the Admin has no student pages

$activePage = 'students';
$flash = null;
$readOnly = true;   // nobody edits students here: the Program Head only views, a professor records in Classes (and may import PEFs)

// FAILING_GRADE_THRESHOLD, STUDENT_STATUSES and CASE_TYPES live in config/academic.php

// ------------------------------------------------------------
// Arriving from a notification: mark it read and remember whose breakdown to open.
// ------------------------------------------------------------
if (!empty($_GET['mark_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([(int) $_GET['mark_read']]);
}
$openSubjectsForStudent = $_GET['open_subjects'] ?? null;
if ($openSubjectsForStudent) { header('Location: student.php?id=' . urlencode((string) $openSubjectsForStudent)); exit; }   // old links

// ------------------------------------------------------------
// Add / Edit / Delete — Faculty only, and always behind their own Student Data PIN.
// A wrong PIN never loses anything on screen: the page JS checks it first (verify.php) and
// shows the error inside the open window. This server-side check is the real boundary.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireManagePermission();
    $action = $_POST['action'] ?? '';

    $check = checkCredential($pdo, 'pin', $_POST['credential'] ?? '');
    if (!$check['ok']) {
        if (!empty($check['logout'])) { endSessionAndRedirectLocked(); }
        $flash = ['type' => 'error', 'msg' => $check['error']];
        $action = null;
    }

    if ($action === 'save') {
        $id        = $_POST['id'] ?? '';
        $studentId = trim($_POST['student_id'] ?? '');
        $fullName  = formatStudentName($_POST['last_name'] ?? '', $_POST['first_name'] ?? '', $_POST['middle_initial'] ?? '');
        $gender    = in_array($_POST['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $_POST['gender'] : 'Other';
        $age       = (int) ($_POST['age'] ?? 0);
        $yearLevel = max(1, min(4, (int) ($_POST['year_level'] ?? 1)));
        // Leave / dismissal / drop / shift are filed under Reports → Requests, not typed in here.
        $status    = in_array($_POST['status'] ?? '', ['enrolled', 'graduated'], true) ? $_POST['status'] : 'enrolled';
        if ($id) {
            $curSt = $pdo->prepare("SELECT status FROM students WHERE id = ?");
            $curSt->execute([$id]);
            $curStatus = $curSt->fetchColumn();
            if ($curStatus !== false && in_array($curStatus, CASE_STATUSES, true)) { $status = $curStatus; }   // keep it
        }

        try {
            if ($studentId === '' || trim($_POST['last_name'] ?? '') === '' || trim($_POST['first_name'] ?? '') === '') {
                throw new RuntimeException('Student ID and full name are required.');
            }
            // The student's "current" school year is simply the school year of their latest year level.
            $academicYear = trim($_POST["year{$yearLevel}_ay"] ?? '');
            if (!in_array($academicYear, AY_ENTRY_OPTIONS, true)) {
                throw new RuntimeException("Please choose the school year for Year $yearLevel.");
            }

            $pdo->beginTransaction();

            if ($id) {
                $pdo->prepare("UPDATE students SET student_id=?, full_name=?, gender=?, age=?, year_level=?, academic_year=?, status=? WHERE id=?")
                    ->execute([$studentId, $fullName, $gender, $age, $yearLevel, $academicYear, $status, $id]);
            } else {
                $pdo->prepare("INSERT INTO students (student_id, full_name, program, gender, age, year_level, academic_year, status) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$studentId, $fullName, PROGRAM_NAME, $gender, $age, $yearLevel, $academicYear, $status]);
            }

            $deleteSubjects = $pdo->prepare("DELETE FROM subject_records WHERE student_id = ? AND year_level = ? AND class_id IS NULL");   // rows recorded in Classes are never overwritten here
            $insertSubject  = $pdo->prepare("INSERT INTO subject_records (student_id, year_level, semester, subject_name, grade, attendance_rate) VALUES (?,?,?,?,?,?)");
            $upsertYear = $pdo->prepare("
                INSERT INTO student_records (student_id, year_level, academic_year, section, gwa, attendance_rate, units_failed)
                VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE academic_year = VALUES(academic_year), section = VALUES(section), gwa = VALUES(gwa),
                                         attendance_rate = VALUES(attendance_rate), units_failed = VALUES(units_failed)
            ");

            // A school year that has already ended is VIEW ONLY: its record (used for the prediction) is never changed from here.
            $editableAy = currentSchoolYear($pdo)['ay'];
            $existingYear = $pdo->prepare("SELECT academic_year FROM student_records WHERE student_id = ? AND year_level = ?");
            $lastProcessedAy = '';
            for ($y = 1; $y <= $yearLevel; $y++) {
                $existingYear->execute([$studentId, $y]);
                $savedAy = $existingYear->fetchColumn();
                if ($savedAy !== false && $savedAy !== $editableAy) { continue; }
                $yAy      = trim($_POST["year{$y}_ay"] ?? '');
                $ySection = trim($_POST["year{$y}_section"] ?? '');

                // Collect this year's subjects across BOTH semesters (max 2 per year).
                $subjects = [];
                for ($sem = 1; $sem <= 2; $sem++) {
                    $names  = $_POST["year{$y}_sem{$sem}_subject_name"]  ?? [];
                    $grades = $_POST["year{$y}_sem{$sem}_subject_grade"] ?? [];
                    $attend = $_POST["year{$y}_sem{$sem}_subject_att"]   ?? [];
                    if (!is_array($names)) { $names = []; }
                    foreach ($names as $i => $nRaw) {
                        $n = trim((string) $nRaw);
                        $g = trim((string) ($grades[$i] ?? ''));
                        $a = trim((string) ($attend[$i] ?? ''));
                        if ($n === '' && $g === '') { continue; }
                        if ($n === '' || $g === '') {
                            throw new RuntimeException("Year $y, semester $sem: every subject needs both a name and a grade.");
                        }
                        if (!is_numeric($g) || $g < 0 || $g > 100) {
                            throw new RuntimeException("Year $y, semester $sem: grades must be numbers from 0 to 100.");
                        }
                        if ($a !== '' && (!is_numeric($a) || $a < 0 || $a > 100)) {
                            throw new RuntimeException("Year $y, semester $sem: attendance must be from 0 to 100.");
                        }
                        $subjects[] = ['sem' => $sem, 'name' => $n, 'grade' => (float) $g, 'att' => $a === '' ? 100.0 : (float) $a];
                    }
                }

                if (empty($subjects)) {
                    continue; // nothing entered for this year — leave it untouched
                }
                if (!in_array($yAy, AY_ENTRY_OPTIONS, true)) {
                    throw new RuntimeException("Please choose the school year for Year $y.");
                }
                if ($lastProcessedAy !== '' && strcmp($yAy, $lastProcessedAy) <= 0) {
                    throw new RuntimeException("School years must move forward: Year $y ($yAy) has to be a later school year than the year before it ($lastProcessedAy).");
                }
                $lastProcessedAy = $yAy;
                // Section format: BSIT-<year level 1-4><block 1-5>, e.g. BSIT-41 = 4th year, block 1.
                // The first digit must match the year it is recorded under.
                if (!preg_match('/^BSIT-([1-4])([1-8])$/', $ySection, $secMatch) || (int) $secMatch[1] !== $y) {
                    throw new RuntimeException("Year $y: choose a section from BSIT-{$y}1 to BSIT-{$y}5.");
                }

                $deleteSubjects->execute([$studentId, $y]);
                foreach ($subjects as $sub) {
                    $insertSubject->execute([$studentId, $y, $sub['sem'], $sub['name'], $sub['grade'], $sub['att']]);
                }
                // the year's GWA / attendance / failed subjects count EVERY subject of the year, including those recorded in Classes
                $agg = $pdo->prepare("SELECT AVG(grade) AS g, AVG(attendance_rate) AS a, SUM(CASE WHEN grade < ? THEN 1 ELSE 0 END) AS f FROM subject_records WHERE student_id = ? AND year_level = ?");
                $agg->execute([FAILING_GRADE_THRESHOLD, $studentId, $y]);
                $x = $agg->fetch();
                $upsertYear->execute([$studentId, $y, $yAy, $ySection !== '' ? $ySection : null,
                                      $x['g'] !== null ? round((float) $x['g'], 2) : null, $x['a'] !== null ? round((float) $x['a'], 2) : null, (int) $x['f']]);
            }

            $pdo->commit();
            try { refreshAllRosters($pdo, $academicYear); } catch (PDOException $e) { /* Classes tables not created yet */ }

            // Risk is only calculated for students who are currently enrolled.
            $result = ($status === 'enrolled') ? runPredictionForStudent($pdo, $studentId) : null;
            if ($status !== 'enrolled') {
                $flash = ['type' => 'success', 'msg' => "Student $studentId saved as " . STUDENT_STATUSES[$status] . " — risk is only calculated for enrolled students."];
            } elseif ($result) {
                [$prob, $risk] = $result;
                $flash = ['type' => 'success', 'msg' => "Student $studentId saved. Risk auto-calculated: $risk risk ($prob%)."];
            } else {
                $flash = ['type' => 'success', 'msg' => "Student $studentId saved. Add at least one year's subjects to auto-calculate risk."];
            }
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $flash = ['type' => 'error', 'msg' => "Could not save student: " . ($e->errorInfo[1] == 1062 ? "Student ID already exists." : $e->getMessage())];
        }
    }

    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$_POST['id'] ?? 0]);
        $flash = ['type' => 'success', 'msg' => "Student record deleted."];
    }
}

// ------------------------------------------------------------
// Search / filters (everyone)
//   - Section filter works on each student's CURRENT section only, so "BSIT-11 (2025-2026)" lists
//     the current first-year batch — a student who was BSIT-11 two years ago but is now BSIT-23 shows
//     up under BSIT-23, never under BSIT-11 (their old records stay visible inside their profile).
//   - Sorted High risk -> Moderate -> Low (unscored last), then by section, then name.
// ------------------------------------------------------------
// The Students page shows the CURRENT school year only (older school years are in History).
$cur       = currentSchoolYear($pdo);
$currentAy = $cur['ay'];
$semNow    = $cur['sem'] === 1 ? '1st Semester' : '2nd Semester';

$search        = mb_substr(trim($_GET['q'] ?? ''), 0, 60);
$yearFilter    = in_array((int) ($_GET['year'] ?? 0), [1, 2, 3, 4], true) ? (int) $_GET['year'] : 0;
$sectionFilter = preg_match('/^BSIT-[1-4][1-8]$/', $_GET['section'] ?? '') ? $_GET['section'] : '';
$riskFilter    = in_array($_GET['risk'] ?? '', ['High', 'Moderate', 'Low'], true) ? $_GET['risk'] : '';
$listedStatuses = isProgramHead() ? ['enrolled', 'loa', 'shifted'] : ['enrolled', 'loa'];     // the Program Head also sees (and can bring back) the shifted students
$statusFilter  = in_array($_GET['status'] ?? '', $listedStatuses, true) ? $_GET['status'] : '';
if ($sectionFilter !== '' && $yearFilter && (int) $sectionFilter[5] !== $yearFilter) { $sectionFilter = ''; }   // a section belongs to one year level
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

// Everyone who has a record in the current school year (their year level and section of THIS school year).
$ayQ   = $pdo->quote($currentAy);
$where = ["sr.id IS NOT NULL", "s.status IN ('" . implode("','", $listedStatuses) . "')"];   // graduated, dropped, dismissed and shifted students are in History
$params = [];
if ($search !== '')        { $where[] = "(s.full_name LIKE ? OR s.student_id LIKE ?)"; array_push($params, "%$search%", "%$search%"); }
if ($yearFilter)           { $where[] = "sr.year_level = ?"; $params[] = $yearFilter; }
if ($sectionFilter !== '') { $where[] = "sr.section = ?";    $params[] = $sectionFilter; }
if ($riskFilter !== '')    { $where[] = "latest_risk.risk_level = ?"; $params[] = $riskFilter; }
if (($scopeSql = scopeInSql($pdo, 's.student_id', scopeStudentIds($pdo, $currentAy))) !== '') { $where[] = $scopeSql; }   // a professor: only the students of his or her classes
if ($statusFilter !== '')  { $where[] = "s.status = ?";      $params[] = $statusFilter; }
$whereSql = "WHERE " . implode(" AND ", $where);

$joinSql = "
    FROM students s
    INNER JOIN student_records sr ON sr.student_id = s.student_id AND sr.academic_year = $ayQ
    LEFT JOIN (
        SELECT p.student_id, p.risk_level,
               CASE p.risk_level WHEN 'High' THEN 1 WHEN 'Moderate' THEN 2 WHEN 'Low' THEN 3 ELSE 4 END AS risk_rank
        FROM predictions p
        INNER JOIN (SELECT student_id, MAX(predicted_at) AS latest FROM predictions GROUP BY student_id) m
            ON m.student_id = p.student_id AND m.latest = p.predicted_at
    ) latest_risk ON latest_risk.student_id = s.student_id
";
$joinSql = str_replace("WHERE", "", $joinSql);

$countStmt = $pdo->prepare("SELECT COUNT(*) $joinSql $whereSql");
$countStmt->execute($params);
$totalRows  = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$listStmt = $pdo->prepare("
    SELECT s.*, sr.section AS rec_section, sr.year_level AS rec_year, sr.gwa AS rec_gwa
    $joinSql $whereSql
    ORDER BY s.full_name ASC
    LIMIT $perPage OFFSET $offset
");
$listStmt->execute($params);
$students = $listStmt->fetchAll();

// the classes (subjects) each of these students is in this school year — they are put there automatically
$classMap = [];
if ($students) {
    $ids = array_map(function ($x) { return $x['student_id']; }, $students);
    try {
        $cq = $pdo->prepare("SELECT cs.student_id, c.id, c.subject_name FROM class_students cs INNER JOIN classes c ON c.id = cs.class_id
                             WHERE cs.excluded = 0 AND c.academic_year = ?" . (isFaculty() ? " AND c.owner_user_id = ?" : "") . " AND cs.student_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY c.subject_name");
        $cq->execute(array_merge([$currentAy], isFaculty() ? [$_SESSION['user_id']] : [], $ids));
        foreach ($cq->fetchAll() as $c) { $classMap[$c['student_id']][] = $c; }
    } catch (PDOException $e) { /* Classes tables not created yet */ }
}

// Section choices follow the year level picked
$optStmt = $pdo->prepare("SELECT DISTINCT section FROM student_records WHERE academic_year = ? AND section IS NOT NULL AND section <> ''"
    . ($yearFilter ? " AND year_level = ?" : "") . " ORDER BY section ASC");
$optStmt->execute($yearFilter ? [$currentAy, $yearFilter] : [$currentAy]);
$sectionOptions = $optStmt->fetchAll(PDO::FETCH_COLUMN);

// Average grade of each student per semester of this school year (the 2nd semester appears once it has grades)
$semGwa = []; $hasSem2 = false;
$gq = $pdo->prepare("SELECT sub.student_id, sub.semester, AVG(sub.grade) AS g FROM subject_records sub
                     INNER JOIN student_records sr ON sr.student_id = sub.student_id AND sr.year_level = sub.year_level
                     WHERE sr.academic_year = ? GROUP BY sub.student_id, sub.semester");
$gq->execute([$currentAy]);
foreach ($gq->fetchAll() as $g) { $semGwa[$g['student_id']][(int) $g['semester']] = (float) $g['g']; if ((int) $g['semester'] === 2) { $hasSem2 = true; } }

$pageUrl = function (int $p) use ($search, $yearFilter, $sectionFilter, $riskFilter, $statusFilter): string {
    return '?' . http_build_query(array_filter(['q' => $search, 'year' => $yearFilter ?: '', 'section' => $sectionFilter, 'risk' => $riskFilter,
                                                'status' => $statusFilter, 'page' => $p > 1 ? $p : ''], function ($v) { return $v !== ''; }));
};

// Historical records per student (and per year), plus subject rows per year (each tagged with its semester).
$recordsByStudent = [];
$recordsByYear = [];
foreach ($pdo->query("SELECT * FROM student_records ORDER BY student_id, year_level ASC") as $r) {
    $recordsByStudent[$r['student_id']][] = $r;
    $recordsByYear[$r['student_id']][$r['year_level']] = $r;
}
$subjectsByStudent = [];
foreach ($pdo->query("SELECT * FROM subject_records ORDER BY student_id, year_level, semester, id ASC") as $sub) {
    $subjectsByStudent[$sub['student_id']][$sub['year_level']][] = $sub;
}

// Latest leave / dismissal / drop / shift request per student (shown in the Status column and in View).
$caseByStudent = [];
try {
    foreach ($pdo->query("SELECT * FROM student_cases ORDER BY student_id, id ASC") as $cs) { $caseByStudent[$cs['student_id']] = $cs; }
} catch (PDOException $e) { /* student_cases not created yet (migration not run) */ }

$riskMap = [];
$riskRows = $pdo->query("
    SELECT p.student_id, p.risk_level, p.probability
    FROM predictions p
    INNER JOIN (SELECT student_id, MAX(predicted_at) AS latest FROM predictions GROUP BY student_id) l
        ON p.student_id = l.student_id AND p.predicted_at = l.latest
")->fetchAll();
foreach ($riskRows as $r) { $riskMap[$r['student_id']] = $r; }
$badgeClass = ['High' => 'high-badge', 'Moderate' => 'moderate-badge', 'Low' => 'low-badge'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
    <style>
        .year-block { border: 1px solid #e5eaed; border-radius: 10px; padding: 14px; margin-bottom: 14px; background: #fafcfe; }
        .year-block-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .year-block h4 { font-size: 14px; color: #087eaa; }
        .year-remove-btn { border: none; background: #ffe4e4; color: #c62828; border-radius: 6px; padding: 5px 10px; font-size: 12px; cursor: pointer; }
        .sem-block { border-left: 3px solid #bfe0ee; padding-left: 12px; margin-bottom: 12px; }
        .sem-title { font-size: 12.5px; font-weight: 700; color: #234b64; margin-bottom: 8px; }
        .subject-row { display: grid; grid-template-columns: 1fr 90px 90px 36px; gap: 8px; align-items: center; margin-bottom: 8px; }
        .subject-row input { padding: 8px 10px; border: 1px solid #d8d8d8; border-radius: 6px; font-size: 13px; min-height: 38px; }
        .subject-row-header { display: grid; grid-template-columns: 1fr 90px 90px 36px; gap: 8px; font-size: 11px; color: #8a959b; font-weight: 700; margin-bottom: 6px; }
        .subject-remove-btn { border: none; background: #ffe4e4; color: #c62828; border-radius: 6px; width: 32px; height: 32px; cursor: pointer; font-size: 12px; }
        .subject-add-btn { background: white; border: 1px dashed #7fb7d4; color: #087eaa; border-radius: 7px; padding: 8px 12px; font-size: 12.5px; font-weight: 600; cursor: pointer; margin-bottom: 10px; display: inline-flex; align-items: center; gap: 6px; }
        .year-summary { display: flex; gap: 14px; flex-wrap: wrap; background: #eef7fb; border-radius: 7px; padding: 8px 12px; font-size: 12px; color: #234b64; font-weight: 600; }
        .view-only-tag { display: inline-flex; align-items: center; gap: 6px; background: #eef2f5; color: #55636b; font-size: 12px; padding: 6px 12px; border-radius: 20px; margin-left: 10px; }
        .view-year-title { font-size: 14px; color: #087eaa; margin: 18px 0 6px; font-weight: 700; }
        .view-sem-title { font-size: 12.5px; color: #234b64; margin: 8px 0 4px; font-weight: 700; }
        .small-ay { display:block; font-size: 11px; color:#8a959b; }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <h1>Students
                <?php if ($readOnly): ?><span class="view-only-tag"><i class="fa-solid fa-eye"></i> View Only</span><?php endif; ?>
            </h1>
            <p>The students enrolled (or on leave) this school year, A–Z by last name. Graduates and earlier school years are kept in <a href="history.php" style="color:#087fa7;font-weight:600;">History</a>.</p>
        </div>
    </section>

    <?php renderRolloverBanner($cur); ?>
    <div class="term-banner">
        <strong><i class="fa-solid fa-calendar-check"></i> School year <?= htmlspecialchars($currentAy) ?></strong>
        <span><?= $semNow ?><?= $hasSem2 ? ' · 1st and 2nd Semester grades shown' : ' — the 2nd Semester column fills in once its grades are imported' ?></span>
        <?php if ($cur['fallback']): ?>
            <span style="color:#8a5a00;"><i class="fa-solid fa-circle-info"></i> No grades for <?= htmlspecialchars($cur['calendar']) ?> yet — showing the latest school year with data.</span>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
        <div class="alert-banner alert-<?= $flash['type'] ?>">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if (!$readOnly && !hasStudentDataPin($pdo)): ?>
        <div class="alert-banner" style="background:#fff1d6;color:#8a5a00;margin:0 30px 18px;">
            <i class="fa-solid fa-shield-halved"></i>
            You haven't set up your Student Data PIN yet — you'll need it to save any changes.
            <a href="privacy_settings.php" style="color:#8a5a00;font-weight:700;text-decoration:underline;margin-left:4px;">Set it up now</a>
        </div>
    <?php endif; ?>

    <!-- Filters update the list in place — the page never reloads. -->
    <form method="GET" class="toolbar" id="filterForm" data-base="students.php">
        <div class="search-box">
            <button type="submit" class="search-submit-btn" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
            <input type="text" name="q" placeholder="Search student name or ID" value="<?= htmlspecialchars($search) ?>" oninput="applyFilters(this)" autocomplete="off">
        </div>

        <select name="year" class="filter-select" data-resets="section" onchange="applyFilters(this)">
            <option value="">All Year Levels</option>
            <?php foreach ([1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'] as $n => $lbl): ?>
                <option value="<?= $n ?>" <?= $yearFilter === $n ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>

        <select name="section" class="filter-select" data-live-options onchange="applyFilters(this)">
            <option value="">All Sections</option>
            <?php foreach ($sectionOptions as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $sectionFilter ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="status" class="filter-select" onchange="applyFilters(this)">
            <option value="">All Statuses</option>
            <?php foreach (array_intersect_key(STUDENT_STATUSES, array_flip($listedStatuses)) as $statusKey => $statusLabel): ?>
                <option value="<?= $statusKey ?>" <?= $statusFilter === $statusKey ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="risk" class="filter-select" onchange="applyFilters(this)">
            <option value="">All Risk Levels</option>
            <option value="High" <?= $riskFilter === 'High' ? 'selected' : '' ?>>High Risk</option>
            <option value="Moderate" <?= $riskFilter === 'Moderate' ? 'selected' : '' ?>>Moderate Risk</option>
            <option value="Low" <?= $riskFilter === 'Low' ? 'selected' : '' ?>>Low Risk</option>
        </select>

        <?php if (canManageData()): ?>
            <div style="margin-left:auto;display:flex;gap:10px;flex-wrap:wrap;">
                <a href="add_students.php" class="btn-primary" style="text-decoration:none;"><i class="fa-solid fa-user-plus"></i> Add students</a>
                <a href="import.php" class="btn-secondary" style="text-decoration:none;"><i class="fa-solid fa-file-import"></i> Import PEF (optional)</a>
            </div>
        <?php endif; ?>
    </form>

<div id="viewArea" data-live>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Section · Year</th>
                    <th>1st Sem GWA</th>
                    <th>2nd Sem GWA</th>
                    <th>Risk</th>
                    <th>Status</th>
                    <th>Classes</th>
                    <th>Records</th>
                    <?php if (!$readOnly): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="9">No students found for <?= htmlspecialchars($currentAy) ?> with these filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($students as $s): ?>
                    <?php
                        $sid = $s['student_id'];
                        $risk = $riskMap[$sid] ?? null;
                        $studentSubjects = $subjectsByStudent[$sid] ?? new stdClass();
                        $studentRecordsByYear = $recordsByYear[$sid] ?? new stdClass();
                        $stColor = STATUS_COLORS[$s['status']] ?? '#8a959b';
                        $stLabel = STUDENT_STATUSES[$s['status']] ?? ucfirst($s['status']);
                        $caseRow = (in_array($s['status'], CASE_STATUSES, true) ? ($caseByStudent[$sid] ?? null) : null);
                        $g1 = $semGwa[$sid][1] ?? null; $g2 = $semGwa[$sid][2] ?? null;
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($s['full_name']) ?></strong><span class="small-ay"><?= htmlspecialchars($sid) ?></span></td>
                        <td><?= htmlspecialchars($s['rec_section'] ?: '—') ?><span class="small-ay"><?= yearLabel((int) $s['rec_year']) ?></span></td>
                        <td><?= $g1 !== null ? fmtGrade((float) $g1) : '—' ?></td>
                        <td><?= $g2 !== null ? fmtGrade((float) $g2) : '<span style="color:#8a959b;" title="Not started or not imported yet">—</span>' ?></td>
                        <td>
                            <?php if ($risk && $s['status'] === 'enrolled'): ?>
                                <span class="badge <?= $badgeClass[$risk['risk_level']] ?>"><?= $risk['risk_level'] ?> (<?= number_format($risk['probability'], 0) ?>%)</span>
                            <?php else: ?>
                                <span class="badge" style="background:#eef2f5;color:#8a959b;"><?= $s['status'] === 'enrolled' ? 'No data yet' : 'Not scored' ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (isProgramHead() && in_array($s['status'], ['enrolled', 'shifted'], true)): ?>
                                <select class="st-class" data-sid="<?= htmlspecialchars($sid) ?>" data-name="<?= htmlspecialchars($s['full_name']) ?>" data-cur="<?= $s['status'] ?>"
                                        style="--c: <?= $stColor ?>;" title="Classify this student: enrolled, or shifted to another program">
                                    <option value="enrolled" <?= $s['status'] === 'enrolled' ? 'selected' : '' ?>>Enrolled</option>
                                    <option value="shifted" <?= $s['status'] === 'shifted' ? 'selected' : '' ?>>Shifted</option>
                                </select>
                            <?php else: ?>
                            <span class="status-pill" style="--c: <?= $stColor ?>;"><?= htmlspecialchars($stLabel) ?></span>
                            <?php endif; ?>
                            <?php if ($caseRow): ?><span class="small-ay"><?= htmlspecialchars(caseReasonText($caseRow)) ?></span><?php endif; ?>
                        </td>
                        <td style="max-width:230px;white-space:normal;line-height:1.7;">
                            <?php $mine = $classMap[$sid] ?? []; if (!$mine): ?><span style="color:#8a959b;font-size:12.5px;">—</span><?php endif; ?>
                            <?php foreach (array_slice($mine, 0, 3) as $cl): ?><a class="chip-class" href="<?= isFaculty() ? 'class.php?id=' . (int) $cl['id'] : 'student.php?id=' . urlencode($sid) . '#grades' ?>" title="<?= isFaculty() ? 'Open this class' : 'See the grades' ?>"><?= htmlspecialchars($cl['subject_name']) ?></a> <?php endforeach; ?>
                            <?php if (count($mine) > 3): ?><span class="small-ay" title="<?= htmlspecialchars(implode(', ', array_map(function ($x) { return $x['subject_name']; }, $mine))) ?>">+<?= count($mine) - 3 ?> more</span><?php endif; ?>
                        </td>
                        <td>
                            <a class="btn-secondary" style="padding:8px 12px;" href="student.php?id=<?= urlencode($sid) ?>"><i class="fa-solid fa-eye"></i> View</a>
                        </td>
                        <?php if (!$readOnly): ?>
                        <td>
                            <button class="btn-icon" title="Edit"
                                onclick='openEditModal(<?= jsonAttr($s + ['status_label' => $stLabel, 'status_color' => $stColor, 'name_parts' => splitStudentName($s['full_name'])]) ?>, <?= jsonAttr($studentRecordsByYear) ?>, <?= jsonAttr($studentSubjects) ?>)'>
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <form method="POST" style="display:inline" onsubmit="return confirmWithCredential(this, {title: 'Delete student record', message: 'This permanently deletes the student and all of their records. Enter your Student Data PIN to confirm.'});">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <input type="hidden" name="credential" class="cred-field">
                                <button type="submit" class="btn-icon btn-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a data-nav href="<?= htmlspecialchars($pageUrl($i)) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    </div>
</div>

    <!-- View modal — read-only, every role -->
    <div class="modal-overlay" id="viewSubjectsModal">
        <div class="modal-box" style="width:560px;">
            <h2 id="viewSubjectsTitle">Student Records</h2>
            <div id="viewSubjectsBody"></div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeViewSubjectsModal()">Close</button>
            </div>
        </div>
    </div>

    <?php if (!$readOnly): ?>
    <!-- Add / Edit modal — Faculty only -->
    <div class="modal-overlay" id="studentModal">
        <div class="modal-box" style="width:680px;">
            <h2 id="modalTitle">Add Student</h2>
            <form method="POST" id="studentForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="f_id">
                <input type="hidden" name="year_level" id="f_year_level" value="1">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Student ID</label>
                        <input type="text" name="student_id" id="f_student_id" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" id="f_last_name" required placeholder="e.g. Dela Cruz">
                    </div>
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" id="f_first_name" required placeholder="e.g. Juan">
                    </div>
                    <div class="form-group">
                        <label>Middle Initial</label>
                        <input type="text" name="middle_initial" id="f_middle_initial" maxlength="2" placeholder="e.g. M">
                    </div>
                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender" id="f_gender">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Age</label>
                        <input type="number" name="age" id="f_age" min="15" max="60" required>
                    </div>
                    <div class="form-group full">
                        <label>Status</label>
                        <select name="status" id="f_status">
                            <option value="enrolled">Enrolled</option>
                            <option value="graduated">Graduated</option>
                        </select>
                        <div id="statusLocked" class="status-locked" style="display:none;">
                            <span class="status-pill" id="statusLockedPill"></span>
                            <input type="hidden" name="status" id="f_status_locked" disabled>
                            <span>Leave, dismissal, drops and shifts are filed on the <a href="reports.php#requests" style="color:#087fa7;font-weight:600;">Reports</a> page, so this can't be changed here.</span>
                        </div>
                    </div>
                </div>

                <h3 style="font-size:13px;color:#173f5f;margin:15px 0 6px;">
                    <i class="fa-solid fa-clock-rotate-left"></i> Year-by-Year Records
                </h3>
                <p style="font-size:12px;color:#8a959b;margin-bottom:12px;">
                    Each year has its own school year, section, and up to 2 semesters of subjects. When the student moves up, press <strong>Add Year</strong> — earlier years stay as they are. A year is saved once it has at least one subject.
                </p>

                <div id="yearBlocksContainer"></div>

                <button type="button" class="subject-add-btn" id="addYearBtn" onclick="addYear()">
                    <i class="fa-solid fa-plus"></i> Add Year
                </button>

                <div class="form-group" style="max-width:260px;margin-top:10px;">
                    <label><i class="fa-solid fa-shield-halved"></i> Your Student Data PIN</label>
                    <input type="password" name="credential" id="f_credential" class="pin-input" inputmode="numeric" maxlength="6"
                           placeholder="6-digit PIN" autocomplete="off" required>
                    <div class="inline-error" id="f_cred_error"></div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Student &amp; Auto-Predict Risk</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<?php if (!$readOnly) { include __DIR__ . '/includes/credential_modal.php'; } ?>

<script src="../assets/live-filter.js?v=1"></script>

<script>
    // ---------- Read-only view (every role) ----------
    const viewSubjectsModal = document.getElementById('viewSubjectsModal');
    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function openViewSubjectsModal(studentName, subjectsByYear, recordsByYear, info) {
        subjectsByYear = subjectsByYear || {};
        recordsByYear = recordsByYear || {};
        document.getElementById('viewSubjectsTitle').textContent = studentName + ' — Records';
        const body = document.getElementById('viewSubjectsBody');
        const years = Array.from(new Set(Object.keys(subjectsByYear).concat(Object.keys(recordsByYear)))).sort(function (a, b) { return a - b; });

        if (years.length === 0) {
            body.innerHTML = '<p style="font-size:13px;color:#8a959b;">No records on file yet for this student.</p>';
        } else {
            body.innerHTML = years.map(function (y) {
                const rec = recordsByYear[y] || {};
                const subs = subjectsByYear[y] || [];
                let title = 'Year ' + y;
                if (rec.academic_year) title += ' (' + esc(rec.academic_year) + ')';
                if (rec.section) title += ' · ' + esc(rec.section);
                if (rec.gwa) title += ' · GWA ' + parseFloat(rec.gwa).toFixed(2);

                let semHtml = '';
                [1, 2].forEach(function (sem) {
                    const rows = subs.filter(function (s) { return parseInt(s.semester || 1, 10) === sem; });
                    if (!rows.length) return;
                    semHtml += '<div class="view-sem-title">' + (sem === 1 ? '1st' : '2nd') + ' Semester</div>' +
                        '<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="color:#8a959b;text-align:left;">' +
                        '<th style="padding:6px 4px;">Subject</th><th style="padding:6px 4px;text-align:center;">Grade</th><th style="padding:6px 4px;text-align:center;">Attendance</th></tr></thead><tbody>' +
                        rows.map(function (s) {
                            const failing = parseFloat(s.grade) < <?= FAILING_GRADE_THRESHOLD ?>;
                            return '<tr><td style="padding:7px 4px;">' + esc(s.subject_name) + '</td>' +
                                   '<td style="padding:7px 4px;text-align:center;' + (failing ? 'color:#c62828;font-weight:700;' : '') + '">' + parseFloat(s.grade).toFixed(2) + '</td>' +
                                   '<td style="padding:7px 4px;text-align:center;">' + parseFloat(s.attendance_rate).toFixed(1) + '%</td></tr>';
                        }).join('') + '</tbody></table>';
                });
                if (!semHtml) semHtml = '<p style="font-size:12.5px;color:#8a959b;">No subjects recorded for this year.</p>';
                return '<div class="view-year-title">' + title + '</div>' + semHtml;
            }).join('');
        }
        if (info) {
            let head = '<div class="view-status"><span class="status-pill" style="--c:' + esc(info.color || '#087fa7') + ';">' + esc(info.label) + '</span>';
            if (info.case) {
                const c = info.case;
                const why = c.reason === 'Others' ? 'Others — ' + (c.reason_other || '') : c.reason;
                head += '<div style="margin-top:8px;font-size:13px;color:#455a64;line-height:1.7;">School year: <strong>' + esc(c.academic_year) + '</strong><br>Semester: <strong>' + esc(c.semester) + '</strong><br>Filed: ' + esc(c.filed_on || '') +
                        '<br>Reason: <strong>' + esc(why) + '</strong>' + (c.shifted_to ? '<br>Shifted to: ' + esc(c.shifted_to) : '') +
                        (c.details ? '<br>Remarks: ' + esc(c.details) : '') +
                        (c.recorded_by ? '<br><span style="color:#8a959b;">Recorded by ' + esc(c.recorded_by) + '</span>' : '') + '</div>';
            }
            body.innerHTML = head + '</div>' + body.innerHTML;
        }
        viewSubjectsModal.classList.add('show');
    }
    function closeViewSubjectsModal() { viewSubjectsModal.classList.remove('show'); }
    viewSubjectsModal.addEventListener('click', function (e) { if (e.target === viewSubjectsModal) closeViewSubjectsModal(); });

    <?php if ($openSubjectsForStudent):
        $targetStmt = $pdo->prepare("SELECT full_name FROM students WHERE student_id = ? LIMIT 1");
        $targetStmt->execute([$openSubjectsForStudent]);
        $targetFullName = $targetStmt->fetchColumn() ?: 'Student';
    ?>
    // Arrived from a notification — open that student's records automatically.
    openViewSubjectsModal(<?= jsonAttr($targetFullName) ?>, <?= jsonAttr($subjectsByStudent[$openSubjectsForStudent] ?? new stdClass()) ?>, <?= jsonAttr($recordsByYear[$openSubjectsForStudent] ?? new stdClass()) ?>);
    <?php endif; ?>
</script>

<?php if (!$readOnly): ?>
<script>
    // ---------- Add / Edit (Faculty) ----------
    const modal = document.getElementById('studentModal');
    const container = document.getElementById('yearBlocksContainer');
    const FAILING_THRESHOLD = <?= FAILING_GRADE_THRESHOLD ?>;
    const AY_CHOICES = <?= jsonAttr(AY_ENTRY_OPTIONS) ?>;
    const MAX_YEARS = 4;
    const CURRENT_AY = <?= jsonAttr($currentAy) ?>;   // only this school year can be edited

    guardForm(document.getElementById('studentForm'), document.getElementById('f_credential'), document.getElementById('f_cred_error'));
    document.getElementById('f_credential').addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });

    function subjectRowHtml(y, s, subj) {
        subj = subj || {};
        const fromClass = subj.class_id ? ' disabled' : '';       // recorded in Classes: look, don't type
        const grade = subj.grade != null && subj.grade !== '' ? parseFloat(subj.grade) : '';
        const att = subj.attendance_rate != null && subj.attendance_rate !== '' ? parseFloat(subj.attendance_rate) : '';
        return '<div class="subject-row"' + (fromClass ? ' title="Recorded in Classes — change it there"' : '') + '>' +
            '<input type="text"' + fromClass + ' name="year' + y + '_sem' + s + '_subject_name[]" placeholder="e.g. Data Structures" value="' + esc(subj.subject_name) + '" oninput="recomputeYear(' + y + ')">' +
            '<input type="number"' + fromClass + ' step="0.01" min="0" max="100" name="year' + y + '_sem' + s + '_subject_grade[]" placeholder="e.g. 87" value="' + grade + '" oninput="recomputeYear(' + y + ')">' +
            '<input type="number"' + fromClass + ' step="0.1" min="0" max="100" name="year' + y + '_sem' + s + '_subject_att[]" placeholder="0-100" value="' + att + '" oninput="recomputeYear(' + y + ')">' +
            (fromClass ? '<span title="Recorded in Classes"><i class="fa-solid fa-lock" style="color:#8a959b;"></i></span>' : '') + (fromClass ? '' :
            '<button type="button" class="subject-remove-btn" title="Remove subject" onclick="this.closest(\'.subject-row\').remove(); recomputeYear(' + y + ');"><i class="fa-solid fa-xmark"></i></button>') +
            '</div>';
    }

    function addSubjectRow(y, s, subj) {
        document.getElementById('y' + y + 's' + s + '_subjects').insertAdjacentHTML('beforeend', subjectRowHtml(y, s, subj));
    }

    function appendSemester(y, s, subs) {
        const wrap = document.getElementById('year' + y + '_sems');
        const sem = document.createElement('div');
        sem.className = 'sem-block';
        sem.id = 'y' + y + 's' + s;
        sem.innerHTML =
            '<div class="sem-title">' + (s === 1 ? '1st' : '2nd') + ' Semester</div>' +
            '<div class="subject-row-header"><span>Subject</span><span>Grade</span><span>Attendance %</span><span></span></div>' +
            '<div id="y' + y + 's' + s + '_subjects"></div>' +
            '<button type="button" class="subject-add-btn" onclick="addSubjectRow(' + y + ',' + s + ')"><i class="fa-solid fa-plus"></i> Add Subject</button>';
        wrap.appendChild(sem);
        if (subs && subs.length) { subs.forEach(function (x) { addSubjectRow(y, s, x); }); } else { addSubjectRow(y, s); }
    }

    function toggleAddSemBtn(y) {
        document.getElementById('addSemBtn' + y).style.display = document.getElementById('y' + y + 's2') ? 'none' : 'inline-flex';
    }

    function addSemester(y) {
        if (document.getElementById('y' + y + 's2')) return;   // 2 semesters per year, no more
        appendSemester(y, 2, []);
        toggleAddSemBtn(y);
        recomputeYear(y);
    }

    function updateYearTitle(y) {
        const ay = document.getElementById('yearAy' + y).value;
        document.getElementById('yearTitle' + y).textContent = 'Year ' + y + (ay ? ' (' + ay + ')' : '');
    }

    function syncYearLevel() {
        const count = container.querySelectorAll('.year-block').length;
        document.getElementById('f_year_level').value = count;
        document.getElementById('addYearBtn').style.display = count >= MAX_YEARS ? 'none' : 'inline-flex';
    }

    function recomputeYear(y) {
        const block = document.getElementById('yearBlock' + y);
        if (!block) return;
        let sumG = 0, sumA = 0, failed = 0, count = 0;
        const sem = { 1: { g: 0, c: 0 }, 2: { g: 0, c: 0 } };
        block.querySelectorAll('.sem-block').forEach(function (sb) {
            const s = sb.id.endsWith('s2') ? 2 : 1;
            sb.querySelectorAll('.subject-row').forEach(function (row) {
                const inputs = row.querySelectorAll('input');
                const g = parseFloat(inputs[1].value), a = parseFloat(inputs[2].value);
                if (inputs[0].value.trim() !== '' && !isNaN(g)) {
                    count++; sumG += g; sumA += isNaN(a) ? 100 : a;
                    if (g < FAILING_THRESHOLD) failed++;
                    sem[s].g += g; sem[s].c++;
                }
            });
        });
        const el = document.getElementById('year' + y + '_summary');
        if (!count) { el.textContent = "Add at least one subject to save this year."; return; }
        let html = '<span>Year GWA: ' + (sumG / count).toFixed(2) + '</span><span>Attendance: ' + (sumA / count).toFixed(1) + '%</span><span>Subjects Failed: ' + failed + '</span>';
        [1, 2].forEach(function (s) { if (sem[s].c) html += '<span>Sem ' + s + ' GWA: ' + (sem[s].g / sem[s].c).toFixed(2) + '</span>'; });
        el.innerHTML = html;
    }

    // Section = BSIT-<year><block>: first digit is the year level (1-4), second is the block (1-5).
    function sectionOptionsHtml(y, current) {
        let html = '<option value="">Select section</option>';
        let found = false;
        for (let b = 1; b <= 8; b++) {
            const sec = 'BSIT-' + y + b;
            if (sec === current) found = true;
            html += '<option value="' + sec + '"' + (sec === current ? ' selected' : '') + '>' + sec + '</option>';
        }
        if (current && !found) {
            html += '<option value="' + esc(current) + '" selected>' + esc(current) + ' (not valid - please pick one)</option>';
        }
        return html;
    }

    function appendYearBlock(y, rec, subs, isNew) {
        rec = rec || {};
        subs = subs || [];
        const sems = Array.from(new Set(subs.map(function (x) { return parseInt(x.semester || 1, 10); }))).sort();
        if (sems.indexOf(1) === -1) sems.unshift(1);

        const block = document.createElement('div');
        block.className = 'year-block';
        block.id = 'yearBlock' + y;
        block.innerHTML =
            '<div class="year-block-head"><h4 id="yearTitle' + y + '"></h4>' +
            (isNew ? '<button type="button" class="year-remove-btn" onclick="removeYear(' + y + ')">Remove this year</button>' : '') + '</div>' +
            '<div class="form-grid" style="margin-bottom:10px;">' +
                '<div class="form-group"><label>School Year</label><select name="year' + y + '_ay" id="yearAy' + y + '" onchange="updateYearTitle(' + y + ')">' +
                    '<option value="">Select school year</option>' + AY_CHOICES.map(function (a) { return '<option value="' + a + '">' + a + '</option>'; }).join('') +
                '</select></div>' +
                '<div class="form-group"><label>Section</label><select name="year' + y + '_section" id="yearSection' + y + '">' + sectionOptionsHtml(y, rec.section) + '</select></div>' +
            '</div>' +
            '<div id="year' + y + '_sems"></div>' +
            '<button type="button" class="subject-add-btn" id="addSemBtn' + y + '" onclick="addSemester(' + y + ')"><i class="fa-solid fa-plus"></i> Add Semester</button>' +
            '<div class="year-summary" id="year' + y + '_summary"></div>';
        container.appendChild(block);

        document.getElementById('yearAy' + y).value = rec.academic_year || '';
        sems.forEach(function (s) {
            appendSemester(y, s, subs.filter(function (x) { return parseInt(x.semester || 1, 10) === s; }));
        });
        updateYearTitle(y);
        toggleAddSemBtn(y);
        recomputeYear(y);
        syncYearLevel();
        // an earlier school year can be looked at (it shows what the prediction was based on) but not changed
        if (!isNew && rec.academic_year && rec.academic_year !== CURRENT_AY) { lockYearBlock(y); }
    }

    function lockYearBlock(y) {
        const block = document.getElementById('yearBlock' + y);
        block.classList.add('year-locked');
        block.querySelectorAll('input, select').forEach(function (el) { el.disabled = true; });
        block.querySelectorAll('button').forEach(function (b) { b.style.display = 'none'; });
        block.querySelector('.year-block-head').insertAdjacentHTML('afterend',
            '<div class="lock-note"><i class="fa-solid fa-lock"></i> Earlier school year — <strong>view only</strong>. These records were used for the prediction and cannot be changed.</div>');
    }

    function addYear() {
        const next = container.querySelectorAll('.year-block').length + 1;
        if (next > MAX_YEARS) return;
        appendYearBlock(next, {}, [], true);
    }

    function removeYear(y) {
        const count = container.querySelectorAll('.year-block').length;
        if (y !== count) return;          // only the last (newly added) year can be taken back
        document.getElementById('yearBlock' + y).remove();
        syncYearLevel();
    }

    function resetCredential() {
        document.getElementById('f_credential').value = '';
        document.getElementById('f_cred_error').style.display = 'none';
    }

    function openAddModal() {
        document.getElementById('modalTitle').textContent = 'Add Student';
        document.getElementById('studentForm').reset();
        document.getElementById('f_id').value = '';
        container.innerHTML = '';
        appendYearBlock(1, {}, [], false);
        setStatusUI('enrolled', '', '');
        resetCredential();
        modal.classList.add('show');
    }

    function openEditModal(student, recordsByYear, subjectsByYear) {
        recordsByYear = recordsByYear || {};
        subjectsByYear = subjectsByYear || {};
        document.getElementById('modalTitle').textContent = 'Edit Student';
        document.getElementById('f_id').value = student.id;
        document.getElementById('f_student_id').value = student.student_id;
        const np = student.name_parts || {};
        document.getElementById('f_last_name').value = np.last || '';
        document.getElementById('f_first_name').value = np.first || '';
        document.getElementById('f_middle_initial').value = np.mi || '';
        document.getElementById('f_gender').value = student.gender;
        document.getElementById('f_age').value = student.age;
        setStatusUI(student.status, student.status_label || '', student.status_color || '#087fa7');

        container.innerHTML = '';
        const years = Math.max(1, parseInt(student.year_level, 10) || 1);
        for (let y = 1; y <= years; y++) {
            appendYearBlock(y, recordsByYear[y], subjectsByYear[y], false);
        }
        resetCredential();
        modal.classList.add('show');
    }

    // Enrolled / Graduated can be set here. Any other status comes from a request filed under Reports → Requests, so it is shown but locked.
    function setStatusUI(status, label, color) {
        const locked = ['loa', 'hd', 'dropped', 'shifted'].indexOf(status) !== -1;
        const sel = document.getElementById('f_status');
        sel.style.display = locked ? 'none' : '';
        sel.disabled = locked;
        document.getElementById('statusLocked').style.display = locked ? 'flex' : 'none';
        const hidden = document.getElementById('f_status_locked');
        hidden.disabled = !locked;
        if (locked) {
            hidden.value = status;
            const pill = document.getElementById('statusLockedPill');
            pill.textContent = label;
            pill.style.setProperty('--c', color);
        } else {
            sel.value = status === 'graduated' ? 'graduated' : 'enrolled';
        }
    }

    function closeModal() { modal.classList.remove('show'); }
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
</script>
<?php endif; ?>

<?php if (isProgramHead()): ?>
<script>
// The Program Head classifies a student as Enrolled or Shifted (no form for it), with the PIN pop-up.
document.querySelectorAll('select.st-class').forEach(function (sel) {
    sel.addEventListener('change', function () {
        var to = sel.value, cur = sel.dataset.cur, name = sel.dataset.name;
        var msg = to === 'shifted' ? name + ' will be marked as Shifted to another program. A shifted student is not counted as attrition and is no longer scored.'
                                   : name + ' will be marked as Enrolled again and scored from the records.';
        askSecret({ title: 'Classify student', message: msg, type: 'pin', confirmText: 'Save' }).then(function (pin) {
            if (pin === null) { sel.value = cur; return; }
            fetch('status_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
                body: JSON.stringify({ action: 'set_class', student_id: sel.dataset.sid, to: to, credential: pin }) })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.redirect) { location.href = res.redirect; return; }
                    if (!res.ok) { sel.value = cur; toast(res.error || 'Could not save.', 'bad'); return; }
                    toast(res.message, 'ok'); setTimeout(function () { location.reload(); }, 700);
                }).catch(function () { sel.value = cur; toast('No connection to the server.', 'bad'); });
        });
    });
});
</script>
<?php endif; ?>
</body>
</html>
