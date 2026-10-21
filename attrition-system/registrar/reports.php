<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/grades.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';

// Admin's only job is User Management — no access to student-data pages at all.
denyAdmin();                           // the Admin has no student pages

$activePage = 'reports';
$flash = null;
if (!empty($_SESSION['req_flash'])) { $flash = $_SESSION['req_flash']; unset($_SESSION['req_flash']); }   // left by request_form.php
$readOnly = !canManageRequests();   // the Program Head records and manages the students' requests (PIN); nobody else changes them

function dateOk(string $d): bool {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

// The school year of the whole page — the current one unless another is chosen.
$years        = array_reverse(AY_ENTRY_OPTIONS);
$currentAy    = currentSchoolYear($pdo)['ay'];
$selectedYear = in_array($_GET['ay'] ?? '', $years, true) ? $_GET['ay'] : (in_array($currentAy, $years, true) ? $currentAy : $years[0]);

// A student belongs to a school year if they have a year record in it.
$inAy = "EXISTS (SELECT 1 FROM student_records x WHERE x.student_id = s.student_id AND x.academic_year = ?)";
$scopeIdsR = scopeStudentIds($pdo);                                   // a professor: only his or her students
$scopeS = scopeInSql($pdo, 's.student_id', $scopeIdsR);
$scopeC = scopeInSql($pdo, 'c.student_id', $scopeIdsR);
$scopeP = scopeInSql($pdo, 'student_id', $scopeIdsR);
if ($scopeS !== '') { $inAy .= " AND $scopeS"; }
$latestPred = "LEFT JOIN predictions p ON p.student_id = s.student_id AND p.predicted_at = (
                   SELECT MAX(predicted_at) FROM predictions WHERE student_id = s.student_id)";
$currentRec = "LEFT JOIN student_records sr ON sr.student_id = s.student_id AND sr.academic_year = " . $pdo->quote($selectedYear);

// ------------------------------------------------------------
// File / edit / re-enroll / delete a request — Program Head only, always behind the PIN.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRequestPermission();
    $action = $_POST['action'] ?? '';

    $check = checkCredential($pdo, 'pin', $_POST['credential'] ?? '');
    if (!$check['ok']) {
        if (!empty($check['logout'])) { endSessionAndRedirectLocked(); }
        $flash = ['type' => 'error', 'msg' => $check['error']];
        $action = null;
    }

    if ($action === 'reenroll') {
        $id = (int) ($_POST['id'] ?? 0);
        $cs = $pdo->prepare("SELECT * FROM student_cases WHERE id = ?");
        $cs->execute([$id]);
        $case = $cs->fetch();
        if (!$case) {
            $flash = ['type' => 'error', 'msg' => 'That request could not be found.'];
        } elseif ($case['case_type'] !== 'loa') {
            $flash = ['type' => 'error', 'msg' => 'Only a student on leave of absence is re-enrolled here. A dropped student is taken back by the professor who recorded it.'];
        } else {
            $pdo->prepare("UPDATE student_cases SET returned_on = CURDATE() WHERE id = ?")->execute([$id]);
            $pdo->prepare("UPDATE students SET status = 'enrolled' WHERE student_id = ? AND status = ?")
                ->execute([$case['student_id'], CASE_TYPES[$case['case_type']]['status']]);
            $flash = ['type' => 'success', 'msg' => 'Student re-enrolled — their status is Enrolled again.'];
        }
    }

    if ($action === 'delete_case') {
        $id = (int) ($_POST['id'] ?? 0);
        $cs = $pdo->prepare("SELECT * FROM student_cases WHERE id = ?");
        $cs->execute([$id]);
        $case = $cs->fetch();
        if (!$case) {
            $flash = ['type' => 'error', 'msg' => 'That request could not be found.'];
        } elseif (!in_array($case['case_type'], REQUEST_FORM_TYPES, true)) {
            $flash = ['type' => 'error', 'msg' => 'Only leave of absence and honorable dismissal requests are deleted here; a dropped student is recorded by the professor.'];
        } else {
            $pdo->prepare("DELETE FROM student_cases WHERE id = ?")->execute([$id]);
            // If it was the request holding their status, put them back to Enrolled.
            $other = $pdo->prepare("SELECT COUNT(*) FROM student_cases WHERE student_id = ? AND case_type = ? AND returned_on IS NULL");
            $other->execute([$case['student_id'], $case['case_type']]);
            if ((int) $other->fetchColumn() === 0) {
                $pdo->prepare("UPDATE students SET status = 'enrolled' WHERE student_id = ? AND status = ?")
                    ->execute([$case['student_id'], CASE_TYPES[$case['case_type']]['status']]);
            }
            $flash = ['type' => 'success', 'msg' => 'Request deleted.'];
        }
    }
}


// ------------------------------------------------------------
// CSV Export (every role that can open Reports)
// ------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare("
        SELECT s.student_id, s.full_name, sr.section, s.year_level, s.status,
               sr.gwa AS latest_gwa, sr.attendance_rate AS latest_attendance, sr.units_failed AS latest_units_failed,
               p.risk_level, p.probability, p.predicted_at
        FROM students s
        $currentRec
        $latestPred
        WHERE $inAy
        ORDER BY s.full_name
    ");
    $stmt->execute([$selectedYear]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attrition_report_' . $selectedYear . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads special characters correctly
    fputcsv($out, ['Student ID', 'Full Name', 'Section', 'Current Year Level', 'Status', 'Latest GWA', 'Latest Attendance Rate', 'Latest Units Failed', 'Risk Level', 'Probability (%)', 'Date Predicted']);
    foreach ($rows as $r) {
        // Wrapped as ="..." so Excel shows the text instead of turning it into "#####".
        $dateText = $r['predicted_at'] ? date('M d, Y g:i A', strtotime($r['predicted_at'])) : '';
        $dateCell = $dateText !== '' ? '="' . $dateText . '"' : '';
        fputcsv($out, [
            $r['student_id'], $r['full_name'], $r['section'] ?? '', $r['year_level'], STUDENT_STATUSES[$r['status']] ?? $r['status'],
            $r['latest_gwa'], $r['latest_attendance'], $r['latest_units_failed'],
            $r['risk_level'] ?? 'Not scored', $r['probability'] ?? '', $dateCell,
        ]);
    }
    fclose($out);
    exit;
}


$riskCols = "COUNT(*) AS total,
             SUM(CASE WHEN p.risk_level = 'High' THEN 1 ELSE 0 END) AS high,
             SUM(CASE WHEN p.risk_level = 'Moderate' THEN 1 ELSE 0 END) AS moderate,
             SUM(CASE WHEN p.risk_level = 'Low' THEN 1 ELSE 0 END) AS low";

// ------------------------------------------------------------
// Breakdown by current section
// ------------------------------------------------------------
$bySection = $pdo->prepare("
    SELECT COALESCE(sr.section, 'No section') AS section, $riskCols
    FROM students s
    $currentRec
    $latestPred
    WHERE $inAy
    GROUP BY sr.section
    ORDER BY sr.section
");
$bySection->execute([$selectedYear]);
$sectionStats = $bySection->fetchAll();

// ------------------------------------------------------------
// Breakdown by year level
// ------------------------------------------------------------
$byYearLevel = $pdo->prepare("
    SELECT sr.year_level AS year_level, $riskCols
    FROM students s
    $currentRec
    $latestPred
    WHERE $inAy
    GROUP BY sr.year_level
    ORDER BY sr.year_level
");
$byYearLevel->execute([$selectedYear]);
$yearLevelStats = $byYearLevel->fetchAll();

// ------------------------------------------------------------
// Status by year level 
// ------------------------------------------------------------
$statusMatrix = [];
foreach (array_keys(STUDENT_STATUSES) as $k) { $statusMatrix[$k] = [1 => 0, 2 => 0, 3 => 0, 4 => 0]; }
$stm = $pdo->prepare("SELECT s.status, sr.year_level AS year_level, COUNT(*) AS cnt FROM students s $currentRec WHERE $inAy GROUP BY s.status, sr.year_level");
$stm->execute([$selectedYear]);
foreach ($stm->fetchAll() as $r) {
    if (isset($statusMatrix[$r['status']]) && $r['year_level'] >= 1 && $r['year_level'] <= 4) {
        $statusMatrix[$r['status']][(int) $r['year_level']] = (int) $r['cnt'];
    }
}

// Requests filed in this school year, by reason (every typed "Other" is counted under the one row "Others")
$caseMatrix = [];
try {
    $cm = $pdo->prepare("SELECT case_type, reason, COUNT(*) AS cnt FROM student_cases WHERE academic_year = ? GROUP BY case_type, reason");
    $cm->execute([$selectedYear]);
    foreach ($cm->fetchAll() as $row) { $caseMatrix[$row['reason']][$row['case_type']] = (int) $row['cnt']; }
} catch (PDOException $e) { /* student_cases not created yet */ }
uksort($caseMatrix, function ($x, $y) use ($caseMatrix) {
    if (($x === 'Others') !== ($y === 'Others')) { return $x === 'Others' ? 1 : -1; }
    return array_sum($caseMatrix[$y]) <=> array_sum($caseMatrix[$x]);
});

// ------------------------------------------------------------
// High risk students (quick referral list)
// ------------------------------------------------------------
$highRisk = $pdo->prepare("
    SELECT s.student_id, s.full_name, sr.section, sr.year_level AS year_level,
           (SELECT z.gwa FROM student_records z WHERE z.student_id = s.student_id AND z.gwa IS NOT NULL ORDER BY z.year_level DESC LIMIT 1) AS gwa,
           (SELECT z.attendance_rate FROM student_records z WHERE z.student_id = s.student_id AND z.gwa IS NOT NULL ORDER BY z.year_level DESC LIMIT 1) AS attendance_rate,
           p.probability
    FROM students s
    INNER JOIN predictions p ON p.student_id = s.student_id AND p.predicted_at = (
        SELECT MAX(predicted_at) FROM predictions WHERE student_id = s.student_id)
    $currentRec
    WHERE $inAy AND p.risk_level = 'High'
    ORDER BY p.probability DESC
");
$highRisk->execute([$selectedYear]);
$highRiskStudents = $highRisk->fetchAll();

$typeFilter   = array_key_exists($_GET['type'] ?? '', CASE_TYPES) ? $_GET['type'] : '';
$ayFilter     = $selectedYear;                       // Requests follow the school year chosen at the top of Reports
$stateFilter  = in_array($_GET['state'] ?? '', ['away', 'returned'], true) ? $_GET['state'] : '';
$search       = trim($_GET['q'] ?? '');
$reasonFilter = trim($_GET['reason'] ?? '');

$tableMissing = false;
$typeCounts = array_fill_keys(array_keys(CASE_TYPES), 0);
$reasonRows = [];
$cases = [];
$graphCases = [];
try {
    // Folder counts (per school year, when one is chosen)
    $tw = []; if ($ayFilter !== '') { $tw[] = 'academic_year = ?'; } if ($scopeP !== '') { $tw[] = $scopeP; }
    $q = $pdo->prepare("SELECT case_type, COUNT(*) AS cnt FROM student_cases" . ($tw ? " WHERE " . implode(' AND ', $tw) : "") . " GROUP BY case_type");
    $q->execute($ayFilter !== '' ? [$ayFilter] : []);
    foreach ($q->fetchAll() as $r) { $typeCounts[$r['case_type']] = (int) $r['cnt']; }

    // Reasons graph: every typed "Other" is counted under the one bar "Others"
    $gw = []; $gp = [];
    if ($typeFilter !== '') { $gw[] = 'case_type = ?'; $gp[] = $typeFilter; }
    if ($ayFilter !== '')   { $gw[] = 'academic_year = ?'; $gp[] = $ayFilter; }
    if ($scopeP !== '')     { $gw[] = $scopeP; }
    $q = $pdo->prepare("SELECT reason, COUNT(*) AS cnt FROM student_cases" . ($gw ? " WHERE " . implode(' AND ', $gw) : "") . " GROUP BY reason");
    $q->execute($gp);
    $reasonRows = $q->fetchAll();
    usort($reasonRows, function ($a, $b) {
        if (($a['reason'] === 'Others') !== ($b['reason'] === 'Others')) { return $a['reason'] === 'Others' ? 1 : -1; }
        return ((int) $b['cnt'] <=> (int) $a['cnt']) ?: strcmp($a['reason'], $b['reason']);
    });

    // The list itself — alphabetical by student name
    $where = []; $params = [];
    if ($typeFilter !== '')   { $where[] = 'c.case_type = ?'; $params[] = $typeFilter; }
    if ($ayFilter !== '')     { $where[] = 'c.academic_year = ?'; $params[] = $ayFilter; }
    if ($reasonFilter !== '') { $where[] = 'c.reason = ?'; $params[] = $reasonFilter; }
    if ($stateFilter === 'away')     { $where[] = 'c.returned_on IS NULL'; }
    if ($stateFilter === 'returned') { $where[] = 'c.returned_on IS NOT NULL'; }
    if ($search !== '') { $where[] = '(s.full_name LIKE ? OR s.student_id LIKE ?)'; array_push($params, "%$search%", "%$search%"); }
    if ($scopeC !== '') { $where[] = $scopeC; }
    $q = $pdo->prepare("
        SELECT c.*, s.full_name, s.year_level, sr.section
        FROM student_cases c
        INNER JOIN students s ON s.student_id = c.student_id
        LEFT JOIN student_records sr ON sr.student_id = s.student_id AND sr.year_level = s.year_level
        " . ($where ? "WHERE " . implode(' AND ', $where) : "") . "
        ORDER BY s.full_name ASC, c.filed_on DESC
        LIMIT 300
    ");
    $q->execute($params);
    $cases = $q->fetchAll();

    // Everyone behind each bar of the graph (for the pop-up), whatever the list filters below say
    $gw2 = []; $gp2 = [];
    if ($typeFilter !== '') { $gw2[] = 'c.case_type = ?'; $gp2[] = $typeFilter; }
    if ($ayFilter !== '')   { $gw2[] = 'c.academic_year = ?'; $gp2[] = $ayFilter; }
    if ($scopeC !== '')     { $gw2[] = $scopeC; }
    $gc = $pdo->prepare("SELECT c.id, c.case_type, c.academic_year, c.semester, c.reason, c.reason_other, c.details, c.filed_on, s.full_name, s.student_id
                         FROM student_cases c INNER JOIN students s ON s.student_id = c.student_id"
                         . ($gw2 ? " WHERE " . implode(' AND ', $gw2) : "") . " ORDER BY s.full_name ASC");
    $gc->execute($gp2);
    $graphCases = $gc->fetchAll();
} catch (PDOException $e) {
    $tableMissing = true;
}

$studentPick = $pdo->query("
    SELECT s.student_id, s.full_name, sr.section
    FROM students s
    LEFT JOIN student_records sr ON sr.student_id = s.student_id AND sr.year_level = s.year_level
    WHERE s.status <> 'graduated'
    ORDER BY s.full_name ASC
")->fetchAll();

$maxReason = 1;
foreach ($reasonRows as $rr) { $maxReason = max($maxReason, (int) $rr['cnt']); }
$totalShown = array_sum($typeCounts);
$baseQuery = array_filter(['ay' => $ayFilter, 'state' => $stateFilter, 'q' => $search]);
$urlFor = function (array $extra) use ($baseQuery) {
    return 'reports.php?' . http_build_query(array_filter(array_merge($baseQuery, $extra), function ($v) { return $v !== '' && $v !== null; })) . '#requests';
};
$reasonMap = [];
foreach (CASE_TYPES as $k => $t) { $reasonMap[$k] = $t['reasons']; }
$activeColor = $typeFilter !== '' ? CASE_TYPES[$typeFilter]['color'] : '#0798b5';


// ---- Overview numbers (the whole picture of the school year) ----
$statusTotals = [];
foreach (STUDENT_STATUSES as $k => $_lbl) { $statusTotals[$k] = array_sum($statusMatrix[$k]); }
$statusMax = max(1, max($statusTotals));
$typeMax   = max(1, max($typeCounts));
$riskYearRows = [1 => ['high' => 0, 'moderate' => 0, 'low' => 0], 2 => ['high' => 0, 'moderate' => 0, 'low' => 0], 3 => ['high' => 0, 'moderate' => 0, 'low' => 0], 4 => ['high' => 0, 'moderate' => 0, 'low' => 0]];
foreach ($yearLevelStats as $yr) { if (isset($riskYearRows[(int) $yr['year_level']])) { $riskYearRows[(int) $yr['year_level']] = ['high' => (int) $yr['high'], 'moderate' => (int) $yr['moderate'], 'low' => (int) $yr['low']]; } }
$riskYearMax = 1;
foreach ($riskYearRows as $rr) { $riskYearMax = max($riskYearMax, max($rr)); }
$statusShort = ['enrolled' => 'Enrolled', 'loa' => 'On leave', 'hd' => 'Dismissed', 'dropped' => 'Dropped', 'shifted' => 'Shifted', 'graduated' => 'Graduated'];
$ord = ['1st', '2nd', '3rd', '4th'];
$popupCases = $graphCases;

// ---- attrition: who actually left, and whether the predictions came true ----
$attr      = attritionStats($pdo);
$outcomes  = riskOutcomes($pdo, $selectedYear);
$attrRate  = [];
foreach ($attr as $ayKey => $v) { $attrRate[$ayKey] = $v['total'] ? round(($v['du'] + $v['hd']) / $v['total'] * 100, 1) : 0.0; }
$attrMaxRate = max(1, $attrRate ? max($attrRate) : 1);
$attrYear  = $attr[$selectedYear] ?? ['total' => 0, 'du' => 0, 'hd' => 0, 'byYear' => [1 => ['du' => 0, 'hd' => 0], 2 => ['du' => 0, 'hd' => 0], 3 => ['du' => 0, 'hd' => 0], 4 => ['du' => 0, 'hd' => 0]]];
$why = attritionReasons($pdo, $selectedYear);
$attrYearMax = 1; foreach ($attrYear['byYear'] as $v) { $attrYearMax = max($attrYearMax, $v['du'], $v['hd']); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
    <style>
        .case-form label.f { display: block; font-size: 13.5px; font-weight: 600; color: #455a64; margin: 14px 0 6px; }
        .case-form select, .case-form input[type="text"], .case-form input[type="date"], .case-form textarea {
            width: 100%; padding: 11px 12px; border: 1px solid #d8d8d8; border-radius: 8px; font-size: 14.5px; box-sizing: border-box; font-family: inherit; min-height: 44px;
        }
        .case-form textarea { min-height: 70px; resize: vertical; }
        .case-form .two { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media(max-width: 700px) { .case-form .two { grid-template-columns: 1fr; } }
        .away-pill { display:inline-block; font-size:12px; font-weight:700; padding:3px 10px; border-radius:20px; background:#fff1d6; color:#8a5a00; }
        .back-pill { display:inline-block; font-size:12px; font-weight:700; padding:3px 10px; border-radius:20px; background:#e6f6ee; color:#188447; }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <h1>Reports</h1>
            <p>The whole picture of the school year first — how many students dropped out (attrition), why, and whether the predictions were right — then the requests and the risk breakdown.</p>
        </div>
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <select name="ay" class="filter-select" onchange="this.form.submit()" aria-label="School year">
                <?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $y === $selectedYear ? 'selected' : '' ?>>School year <?= $y ?><?= $y === $currentAy ? ' (current)' : '' ?></option><?php endforeach; ?>
            </select>
            <a class="btn-primary" style="text-decoration:none;" href="reports.php?export=csv&ay=<?= urlencode($selectedYear) ?>"><i class="fa-solid fa-file-arrow-down"></i> Export CSV</a>
        </form>
    </section>

    <?php if ($flash): ?>
        <div class="alert-banner alert-<?= $flash['type'] ?>">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if ($tableMissing): ?>
        <div class="alert-banner alert-error"><i class="fa-solid fa-triangle-exclamation"></i>
            The <strong>student_cases</strong> table doesn't exist yet. Import the new <code>schema.sql</code>, or run the migration SQL in the README.</div>
    <?php endif; ?>

    <?php if (!$readOnly && !hasStudentDataPin($pdo)): ?>
        <div class="alert-banner" style="background:#fff1d6;color:#8a5a00;margin:0 30px 18px;">
            <i class="fa-solid fa-shield-halved"></i> You haven't set up your PIN yet — you'll need it to file or change a request.
            <a href="privacy_settings.php" style="color:#8a5a00;font-weight:700;text-decoration:underline;margin-left:4px;">Set it up now</a>
        </div>
    <?php endif; ?>

    <details class="fc-how" style="margin:0 30px 14px;">
        <summary>What do the words on this page mean?</summary>
        <p>
            <strong>Attrition</strong> — a student who stopped before finishing the program: they <em>dropped (unofficially)</em> (stopped coming without the official withdrawal) or were <em>honorably dismissed</em> (released, usually to transfer to another school).
            <strong>Graduates</strong> finished the program, <strong>shifted</strong> students moved to another program of the University, and students <strong>on leave</strong> (Leave of Absence) may come back — none of them are attrition.<br>
            <strong>Attrition rate</strong> — the attrition cases of a school year as a <em>percentage</em> of all its students. 5% means 5 out of every 100 students.<br>
            <strong>Risk: High / Moderate / Low</strong> — how likely a student is to drop out, predicted by the system from their grades and attendance (High = 70% or more, Moderate = 40–69%, Low = below 40%).
        </p>
    </details>

    <nav class="rp-nav" aria-label="Sections of this page">
        <?php if (!isFaculty()): ?>
        <a href="#overview"><i class="fa-solid fa-chart-pie"></i> Overview</a>
        <a href="#requests"><i class="fa-solid fa-clipboard-list"></i> Requests</a>
        <?php endif; ?>
        <a href="#risk"><i class="fa-solid fa-table-list"></i> Risk breakdown</a>
    </nav>

    <!-- ============ OVERVIEW: the data and graphs of the whole school year ============ -->
    <?php if (!isFaculty()): ?>
    <section id="overview">
        <section class="gcards" style="margin-top:0;">
            <div class="gcard g-teal">
                <div class="gcard-top"><span class="gcard-title">Student Status</span><i class="fa-solid fa-users gcard-ico"></i></div>
                <span class="basis"><i class="fa-solid fa-circle-info"></i> Where this school year's students are now</span>
                <div class="mbars">
                    <?php foreach (STUDENT_STATUSES as $k => $lbl): $n = $statusTotals[$k]; $h = $n > 0 ? max(6, (int) round($n / $statusMax * 100)) : 0; ?>
                        <div class="mbar" title="<?= htmlspecialchars($lbl) ?>: <?= $n ?>"><em><?= $n ?></em><span style="height:<?= $h ?>%"></span><small><?= $statusShort[$k] ?></small></div>
                    <?php endforeach; ?>
                </div>
                <div class="gcard-foot">Where each of the <?= array_sum($statusTotals) ?> students of <?= htmlspecialchars($selectedYear) ?> stands today. <strong>On leave</strong> = Leave of Absence · <strong>Dismissed</strong> = Honorable Dismissal.</div>
            </div>

            <div class="gcard g-sky">
                <div class="gcard-top"><span class="gcard-title">Attrition Rate per School Year</span><i class="fa-solid fa-person-walking-arrow-right gcard-ico"></i></div>
                <span class="basis"><i class="fa-solid fa-circle-info"></i> Out of every 100 students, how many dropped out</span>
                <?php if ($attrRate): ?>
                <div class="mbars">
                    <?php foreach ($attrRate as $ayKey => $rate): $h = $rate > 0 ? max(6, (int) round($rate / $attrMaxRate * 100)) : 0; $lv = $attr[$ayKey]['du'] + $attr[$ayKey]['hd']; ?>
                        <div class="mbar" title="<?= htmlspecialchars($ayKey) ?>: <?= $lv ?> of <?= $attr[$ayKey]['total'] ?> students dropped out or were dismissed"><em><?= number_format($rate, 1) ?>%</em><span style="height:<?= $h ?>%"></span><small><?= substr($ayKey, 2, 2) ?>-<?= substr($ayKey, 7, 2) ?></small></div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><div class="gcard-empty">No records yet.</div><?php endif; ?>
                <div class="gcard-foot"><strong>Attrition rate</strong> = students who <em>dropped (unofficially)</em> or were <em>honorably dismissed</em>, as a percentage of all students of that school year. Example: 5% means 5 out of every 100 students. Graduates, shifted students and students on leave are <strong>not</strong> counted.</div>
            </div>

            <div class="gcard g-ocean">
                <div class="gcard-top"><span class="gcard-title">Attrition Cases by Year Level</span><i class="fa-solid fa-user-minus gcard-ico"></i></div>
                <span class="basis"><i class="fa-solid fa-calendar"></i> Dropped or dismissed in <?= htmlspecialchars($selectedYear) ?></span>
                <?php if ($attrYear['du'] + $attrYear['hd'] > 0): ?>
                <div class="lgroups">
                    <?php foreach ([1, 2, 3, 4] as $y): $v = $attrYear['byYear'][$y]; ?>
                        <div class="lgroup"><div class="lbars">
                            <?php foreach (['du' => ['#ffffff', 'Dropped (Unofficially)'], 'hd' => ['#ffe08a', 'Honorable Dismissal']] as $kk => $meta): $n = $v[$kk]; $h = $n > 0 ? max(10, (int) round($n / $attrYearMax * 100)) : 0; ?>
                                <span class="lbar" style="height:<?= $h ?>%;background:<?= $meta[0] ?>" title="<?= $ord[$y - 1] ?> Year · <?= $meta[1] ?>: <?= $n ?>"><?= $n > 0 ? '<em>' . $n . '</em>' : '' ?></span>
                            <?php endforeach; ?>
                        </div><small><?= $ord[$y - 1] ?> Year</small></div>
                    <?php endforeach; ?>
                </div>
                <div class="lleg"><span><i style="background:#fff"></i>Dropped (Unofficially)</span><span><i style="background:#ffe08a"></i>Honorable Dismissal</span></div>
                <?php else: ?><div class="gcard-empty">No student dropped out or was dismissed in <?= htmlspecialchars($selectedYear) ?> so far.</div><?php endif; ?>
                <div class="gcard-foot"><?= $attrYear['du'] + $attrYear['hd'] ?> attrition case<?= $attrYear['du'] + $attrYear['hd'] === 1 ? '' : 's' ?> out of <?= $attrYear['total'] ?> students (<?= $attrYear['total'] ? number_format(($attrYear['du'] + $attrYear['hd']) / $attrYear['total'] * 100, 1) : 0 ?>%). Each bar counts the students who were in that year level.</div>
            </div>
        </section>

        <?php
            $pctOf = function (array $o): ?float { return $o['n'] ? $o['left'] / $o['n'] * 100 : null; };
            $pHigh = $pctOf($outcomes['High']); $pLow = $pctOf($outcomes['Low']);
        ?>
        <div class="panel" style="margin:0 30px 22px;">
            <div class="panel-header"><div><h2>Why students dropped out</h2>
                <p>From the letters of the students who <em>dropped (unofficially)</em> or were <em>honorably dismissed</em> in <?= htmlspecialchars($selectedYear) ?> — the reasons the College can act on.</p></div></div>
            <?php if (!$why['total']): ?>
                <div class="chart-empty">No dropped or dismissed student with a recorded reason in <?= htmlspecialchars($selectedYear) ?>.</div>
            <?php else: $whyMax = max($why['reasons']); $semMax = max(1, max($why['sem'])); ?>
                <div class="fc-grid">
                    <div><h3 class="fc-title">Reasons given (number of students)</h3>
                        <?php foreach ($why['reasons'] as $reasonName => $n): ?>
                            <div class="hbar-row" style="grid-template-columns: 1fr 130px 30px;"><span style="line-height:1.25;"><?= htmlspecialchars($reasonName === 'Others' ? 'Others (written by the student)' : $reasonName) ?></span>
                                <span class="hbar-track"><i class="hbar-low" style="width:<?= round($n / $whyMax * 100, 1) ?>%"></i></span><strong><?= $n ?></strong></div>
                        <?php endforeach; ?></div>
                    <div><h3 class="fc-title">When it happened (number of students)</h3>
                        <?php foreach ($why['sem'] as $sm => $n): ?>
                            <div class="hbar-row" style="grid-template-columns: 1fr 130px 30px;"><span><?= htmlspecialchars($sm) ?></span>
                                <span class="hbar-track"><i class="hbar-mod" style="width:<?= round($n / $semMax * 100, 1) ?>%"></i></span><strong><?= $n ?></strong></div>
                        <?php endforeach; ?></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel" style="margin:0 30px 22px;">
            
            <?php if ($pHigh !== null && $pLow !== null): ?>
                <div class="insight" style="margin:0 0 14px;"><i class="fa-solid fa-lightbulb"></i>
                    Students predicted <strong>High risk</strong> dropped out or were dismissed <strong><?= number_format($pHigh, 0) ?>%</strong> of the time, compared with <strong><?= number_format($pLow, 0) ?>%</strong> of students predicted <strong>Low risk</strong>.</div>
            <?php endif; ?>
            <div class="table-container"><table>
                <thead><tr><th>Risk the system gave</th><th>Students in the group</th><th>Later dropped out or dismissed</th><th>Percent of the group</th></tr></thead>
                <tbody>
                    <?php foreach (['High', 'Moderate', 'Low'] as $lvl): $o = $outcomes[$lvl]; $pct = $o['n'] ? $o['left'] / $o['n'] * 100 : 0; ?>
                        <tr><td><span class="badge <?= ['High' => 'high-badge', 'Moderate' => 'moderate-badge', 'Low' => 'low-badge'][$lvl] ?>"><?= $lvl ?></span></td>
                            <td><?= $o['n'] ?></td><td><?= $o['left'] ?></td>
                            <td style="min-width:200px;"><div class="cmp-line" style="grid-template-columns:1fr 54px;"><span class="t"><i style="width:<?= round($pct, 1) ?>%;background:<?= ['High' => '#e5534b', 'Moderate' => '#f0b429', 'Low' => '#3fb5c9'][$lvl] ?>"></i></span><strong><?= $o['n'] ? number_format($pct, 0) . '%' : '—' ?></strong></div></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <p style="font-size:12.5px;color:#8a959b;margin-top:8px;">Only <em>dropped (unofficially)</em> and <em>honorably dismissed</em> students count as dropped out. Graduates, shifted students and students on leave do not.</p>
        </div>

        <div class="panel" style="margin:0 30px 22px;">
            <div class="panel-header"><div><h2>Student Status by Year Level</h2><p>How many students of <?= htmlspecialchars($selectedYear) ?> are Enrolled, on Leave of Absence, on Honorable Dismissal, Dropped (Unofficially), Shifted or Graduated — per year level.</p></div></div>
            <div class="table-container">
                <table>
                    <thead><tr><th>Status</th><th>1st Year</th><th>2nd Year</th><th>3rd Year</th><th>4th Year</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach (STUDENT_STATUSES as $k => $lbl): ?>
                            <tr><td><span class="status-pill" style="--c: <?= STATUS_COLORS[$k] ?>;"><?= htmlspecialchars($lbl) ?></span></td>
                                <?php for ($y = 1; $y <= 4; $y++): ?><td><?= $statusMatrix[$k][$y] ?></td><?php endfor; ?>
                                <td><strong><?= $statusTotals[$k] ?></strong></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============ REQUESTS (formerly Status Records) ============ -->
    <?php if (!isFaculty()): ?>
    <section id="requests">
        <div class="section-bar">
            <div>
                <h2><i class="fa-solid fa-clipboard-list"></i> Requests — School year <?= htmlspecialchars($selectedYear) ?></h2>
                <p>Leave of Absence and Honorable Dismissal are recorded here by the Program Head from the students’ letters. Dropped (Unofficially) is recorded by the professor of the class, and Shifted is set on the Students page; both show up in this list and in the graphs.</p>
            </div>
            <?php if (!$readOnly): ?>
                <a class="btn-primary" href="request_form.php"><i class="fa-solid fa-file-circle-plus"></i> Request form</a>
            <?php endif; ?>
        </div>

    <h2 class="folder-heading" style="margin-top:6px;">Choose a type of request</h2>
    <div class="folder-grid" style="padding-bottom:10px;">
        <a class="folder-card <?= $typeFilter === '' ? 'active' : '' ?>" style="--c:#0798b5;--bg:#e6f6fa;" href="<?= htmlspecialchars($urlFor([])) ?>">
            <span class="folder-icon"><i class="fa-solid fa-folder-open"></i></span>
            <span class="folder-text"><strong>All requests</strong><small><?= $totalShown ?> request<?= $totalShown === 1 ? '' : 's' ?></small></span>
        </a>
        <?php foreach (CASE_TYPES as $key => $t): ?>
            <a class="folder-card <?= $typeFilter === $key ? 'active' : '' ?> <?= $typeCounts[$key] === 0 ? 'empty' : '' ?>" style="--c:<?= $t['color'] ?>;--bg:<?= $t['bg'] ?>;"
               href="<?= htmlspecialchars($urlFor(['type' => $key])) ?>">
                <span class="folder-icon"><i class="fa-solid <?= $t['icon'] ?>"></i></span>
                <span class="folder-text"><strong><?= htmlspecialchars($t['label']) ?></strong><small><?= $typeCounts[$key] ?> request<?= $typeCounts[$key] === 1 ? '' : 's' ?></small></span>
            </a>
        <?php endforeach; ?>
    </div>


        <form method="GET" class="toolbar" action="reports.php#requests">
            <input type="hidden" name="ay" value="<?= htmlspecialchars($ayFilter) ?>">
            <?php if ($typeFilter !== ''): ?><input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>"><?php endif; ?>
            <?php if ($reasonFilter !== ''): ?><input type="hidden" name="reason" value="<?= htmlspecialchars($reasonFilter) ?>"><?php endif; ?>
            <div class="search-box">
                <button type="submit" class="search-submit-btn" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
                <input type="text" name="q" placeholder="Search student name or ID" value="<?= htmlspecialchars($search) ?>">
            </div>
            <select name="state" class="filter-select" onchange="this.form.submit()">
                <option value="">Away &amp; Returned</option>
                <option value="away" <?= $stateFilter === 'away' ? 'selected' : '' ?>>Still away</option>
                <option value="returned" <?= $stateFilter === 'returned' ? 'selected' : '' ?>>Returned</option>
            </select>
            <?php if ($reasonFilter !== ''): ?>
                <span class="chip-clear">Reason: <?= htmlspecialchars($reasonFilter) ?> <a href="<?= htmlspecialchars($urlFor(['type' => $typeFilter])) ?>" title="Clear this filter">✕</a></span>
            <?php endif; ?>
        </form>

    <?php if ($reasonRows): ?>
    <div class="panel" style="margin:0 30px 20px;">
        <div class="panel-header">
            <div>
                <h2>Reasons<?= $typeFilter !== '' ? ' — ' . htmlspecialchars(CASE_TYPES[$typeFilter]['label']) : '' ?></h2>
                <p>Click a bar to see the students in a pop-up. Everything students wrote in their own words is grouped as <strong>Others</strong> — open it to read what each one wrote.</p>
            </div>
        </div>
        <div class="status-bars">
            <?php foreach ($reasonRows as $rr):
                $n = (int) $rr['cnt'];
                $w = max(3, (int) round($n / $maxReason * 100));
            ?>
                <button type="button" class="status-row" onclick='openReasonModal(<?= jsonAttr($rr['reason']) ?>)' title="Show these students">
                    <span class="status-label"><?= htmlspecialchars($rr['reason']) ?></span>
                    <span class="status-track"><span class="status-fill" style="width:<?= $w ?>%;background:<?= $rr['reason'] === 'Others' ? '#8a959b' : $activeColor ?>;"></span></span>
                    <span class="status-value"><strong><?= $n ?></strong></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($reasonFilter === 'Others'): ?>
        <div class="others-note"><i class="fa-solid fa-pen-to-square"></i> These students chose <strong>Others</strong> — the <em>Reason</em> column shows exactly what each one wrote.</div>
    <?php endif; ?>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student</th><th>Request</th><th>School Year</th><th>Semester</th><th>Reason</th><th>Filed</th><th>Recorded by</th><th>Status</th>
                    <?php if (!$readOnly): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($cases)): ?>
                    <tr><td colspan="9">No requests found.</td></tr>
                <?php endif; ?>
                <?php foreach ($cases as $c): $t = CASE_TYPES[$c['case_type']]; ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($c['full_name']) ?></strong>
                            <span class="small-ay"><?= htmlspecialchars($c['student_id']) ?><?= $c['section'] ? ' · ' . htmlspecialchars($c['section']) : '' ?></span></td>
                        <td><span class="status-pill" style="--c:<?= $t['color'] ?>;"><?= htmlspecialchars($t['short']) ?></span>
                            <?php if ($c['shifted_to']): ?><span class="small-ay">→ <?= htmlspecialchars($c['shifted_to']) ?></span><?php endif; ?></td>
                        <td><?= htmlspecialchars($c['academic_year']) ?></td>
                        <td><?= htmlspecialchars($c['semester']) ?></td>
                        <td>
                            <?php if ($c['reason'] === 'Others'): ?>
                                <span class="others-tag">Others</span><span class="others-text">“<?= htmlspecialchars($c['reason_other'] ?? '') ?>”</span>
                            <?php else: ?><?= htmlspecialchars($c['reason']) ?><?php endif; ?>
                            <?php if ($c['details']): ?><span class="small-ay">Remarks: <?= htmlspecialchars($c['details']) ?></span><?php endif; ?>
                        </td>
                        <td><?= date('M d, Y', strtotime($c['filed_on'])) ?></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($c['recorded_by'] ?? '—') ?></td>
                        <td>
                            <?php if ($c['returned_on']): ?><span class="back-pill">Returned <?= date('M d, Y', strtotime($c['returned_on'])) ?></span>
                            <?php elseif (in_array($c['case_type'], ['loa', 'du'], true)): ?><span class="away-pill">Still away</span>
                            <?php else: ?><span class="small-ay" style="margin:0;">—</span><?php endif; ?>
                        </td>
                        <?php if (!$readOnly): ?>
                        <td>
                            <?php if (in_array($c['case_type'], REQUEST_FORM_TYPES, true)): ?>
                            <a class="btn-icon" title="Edit" href="request_form.php?id=<?= (int) $c['id'] ?>"><i class="fa-solid fa-pen"></i></a>
                            <?php if (!$c['returned_on'] && $c['case_type'] === 'loa'): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirmWithCredential(this, {title: 'Re-enroll student', message: 'This marks the student as returned and sets their status back to Enrolled. Enter your PIN to continue.', confirmText: 'Re-enroll'});">
                                <input type="hidden" name="action" value="reenroll"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="credential" class="cred-field">
                                <button type="submit" class="btn-icon" title="Re-enroll (returned to school)"><i class="fa-solid fa-rotate-left"></i></button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline" onsubmit="return confirmWithCredential(this, {title: 'Delete request', message: 'This removes the request. If it was holding the student\'s status, they go back to Enrolled. Enter your PIN to continue.', confirmText: 'Delete', danger: true});">
                                <input type="hidden" name="action" value="delete_case"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="credential" class="cred-field">
                                <button type="submit" class="btn-icon btn-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            <?php else: ?><span class="small-ay" style="margin:0;">Recorded by the professor</span><?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>


    </section>
    <?php endif; ?>

    <!-- ============ RISK BREAKDOWN ============ -->
    <section id="risk">
        <div class="section-bar"><div><h2><i class="fa-solid fa-table-list"></i> Risk breakdown — School year <?= htmlspecialchars($selectedYear) ?></h2>
            <p>Predicted from grades and attendance.</p></div></div>

        <div class="panel" style="margin:0 30px 20px;">
            <div class="panel-header"><div><h2>By Section</h2></div></div>
            <div class="table-container"><table>
                <thead><tr><th>Section</th><th>Students</th><th>High</th><th>Moderate</th><th>Low</th></tr></thead>
                <tbody>
                    <?php if (!$sectionStats): ?><tr><td colspan="5">No data for this school year.</td></tr><?php endif; ?>
                    <?php foreach ($sectionStats as $r): ?>
                        <tr><td><strong><?= htmlspecialchars($r['section']) ?></strong></td><td><?= (int) $r['total'] ?></td>
                            <td><span class="badge high-badge"><?= (int) $r['high'] ?></span></td><td><span class="badge moderate-badge"><?= (int) $r['moderate'] ?></span></td><td><span class="badge low-badge"><?= (int) $r['low'] ?></span></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>

        <div class="panel" style="margin:0 30px 20px;">
            <div class="panel-header">
                <div><h2>High Risk Students — Referral List</h2><p>Students most likely to leave. The College decides on the support; the system only points them out.</p></div>
            </div>
            <div class="table-container"><table>
                <thead><tr><th>Student</th><th>Section · Year</th><th>GWA</th><th>Attendance</th><th>Risk</th><th></th></tr></thead>
                <tbody>
                    <?php if (!$highRiskStudents): ?><tr><td colspan="6">No high-risk students for this school year.</td></tr><?php endif; ?>
                    <?php foreach ($highRiskStudents as $r): ?>
                        <tr><td><strong><?= htmlspecialchars($r['full_name']) ?></strong><span class="small-ay"><?= htmlspecialchars($r['student_id']) ?></span></td>
                            <td><?= htmlspecialchars($r['section'] ?? '—') ?><span class="small-ay"><?= $ord[(int) $r['year_level'] - 1] ?? '' ?> Year</span></td>
                            <td><?= $r['gwa'] !== null ? fmtGrade((float) $r['gwa']) : '—' ?></td><td><?= $r['attendance_rate'] !== null ? number_format((float) $r['attendance_rate'], 1) . '%' : '—' ?></td>
                            <td><span class="badge high-badge"><?= number_format((float) $r['probability'], 0) ?>%</span></td>
                            <td><a class="btn-secondary" style="padding:7px 12px;" href="student.php?id=<?= urlencode($r['student_id']) ?>"><i class="fa-solid fa-eye"></i> View</a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </section>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<?php if (!$readOnly) { include __DIR__ . '/includes/credential_modal.php'; } ?>
<?php include __DIR__ . '/includes/reason_popup.php'; ?>

</body>
</html>
