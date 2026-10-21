<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/predict.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/grades.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';

// Admin's only job is User Management — no access to student-data pages at all.
denyAdmin();                           // the Admin has no student pages

$activePage = 'prediction';
$flash = null;
$readOnly = true;      // predictions refresh by themselves every time something is recorded; nobody re-runs them by hand here

// Risk is calculated automatically when records are saved. This page shows who is at risk and why, and (Faculty) re-runs everyone.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireManagePermission();
    if (($_POST['action'] ?? '') === 'run_all') {
        $coefficients = getModelCoefficients($pdo);
        $count = 0;
        foreach ($pdo->query("SELECT student_id FROM students WHERE status = 'enrolled'")->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
            if (runPredictionForStudent($pdo, $studentId, $coefficients)) { $count++; }
        }
        $flash = ['type' => 'success', 'msg' => "Re-ran the prediction for $count enrolled students using the current model."];
    }
}

$cur       = currentSchoolYear($pdo);
$currentAy = $cur['ay'];
$ins       = predictionInsights($pdo, $currentAy, scopeStudentIds($pdo, $currentAy));   // a professor: only his or her students
$coef      = getModelCoefficients($pdo);

// ---- the list: only High and Moderate; the two groups stay together, each A–Z by last name ----
$search        = mb_substr(trim($_GET['q'] ?? ''), 0, 60);
$riskFilter    = in_array($_GET['risk'] ?? '', ['High', 'Moderate'], true) ? $_GET['risk'] : '';
$yearFilter    = in_array((int) ($_GET['year'] ?? 0), [1, 2, 3, 4], true) ? (int) $_GET['year'] : 0;
$sectionFilter = preg_match('/^BSIT-[1-4][1-8]$/', $_GET['section'] ?? '') ? $_GET['section'] : '';
if ($sectionFilter !== '' && $yearFilter && (int) $sectionFilter[5] !== $yearFilter) { $sectionFilter = ''; }

$atRisk = array_values(array_filter($ins['rows'], function ($r) { return $r['risk_level'] === 'High' || $r['risk_level'] === 'Moderate'; }));
usort($atRisk, function ($a, $b) { return ((float) $b['probability'] <=> (float) $a['probability']) ?: strcasecmp($a['full_name'], $b['full_name']); });

// the at-risk list as a file, to hand to the people who will act on it (the study ends at identifying who is at risk and why)
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="at-risk-students-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Student No.', 'Name', 'Section', 'Year level', 'Risk', 'Risk %', 'Change since last check (points)', 'Main reasons', 'Grades %', 'Attendance %', 'Failed subjects']);
    foreach ($atRisk as $r) {
        fputcsv($out, [$r['student_id'], $r['full_name'], $r['section'], $r['yl'], $r['risk_level'], round((float) $r['probability']),
                       $r['change'] === null ? '' : ($r['change'] > 0 ? '+' : '') . round($r['change']),
                       implode('; ', array_map(function ($x) { return $x['label']; }, $r['reasons'] ?? [])),
                       $r['gwa'] !== null ? fmtGrade((float) $r['gwa']) : '', $r['attendance_rate'] !== null ? round((float) $r['attendance_rate']) : '', $r['units_failed']]);
    }
    fclose($out);
    exit;
}
$sectionOptions = [];
foreach ($atRisk as $r) { if ($r['section'] && (!$yearFilter || (int) $r['yl'] === $yearFilter)) { $sectionOptions[$r['section']] = true; } }
$sectionOptions = array_keys($sectionOptions);
sort($sectionOptions);

$list = array_values(array_filter($atRisk, function ($r) use ($search, $riskFilter, $yearFilter, $sectionFilter) {
    if ($riskFilter !== '' && $r['risk_level'] !== $riskFilter) { return false; }
    if ($yearFilter && (int) $r['yl'] !== $yearFilter) { return false; }
    if ($sectionFilter !== '' && $r['section'] !== $sectionFilter) { return false; }
    if ($search !== '' && stripos($r['full_name'], $search) === false && stripos($r['student_id'], $search) === false) { return false; }
    return true;
}));
usort($list, function ($a, $b) {
    $ra = $a['risk_level'] === 'High' ? 0 : 1; $rb = $b['risk_level'] === 'High' ? 0 : 1;
    return ($ra <=> $rb) ?: strcasecmp($a['full_name'], $b['full_name']);
});

$notScored = $pdo->query("
    SELECT DISTINCT s.student_id FROM students s
    INNER JOIN student_records sr ON sr.student_id = s.student_id AND sr.gwa IS NOT NULL
    LEFT JOIN predictions p ON p.student_id = s.student_id
    WHERE p.id IS NULL AND s.status = 'enrolled'
")->fetchAll();

$classRisk = [];
if (isFaculty()) {
    $riskById = [];
    foreach ($ins['rows'] as $rr) { $riskById[$rr['student_id']] = $rr['risk_level']; }
    foreach (ownedClassIds($pdo, $currentAy) as $cid) {
        $d = classLoadData($pdo, (int) $cid);
        if (!$d) { continue; }
        $hi = 0; $mo = 0;
        foreach ($d['students'] as $s) { $lv = $riskById[$s['student_id']] ?? null; if ($lv === 'High') { $hi++; } elseif ($lv === 'Moderate') { $mo++; } }
        $classRisk[] = ['id' => (int) $cid, 'label' => $d['class']['subject_name'] . ' · ' . $d['class']['section'], 'High' => $hi, 'Moderate' => $mo, 'n' => count($d['students'])];
    }
    usort($classRisk, function ($a, $b) { return (($b['High'] * 2 + $b['Moderate']) <=> ($a['High'] * 2 + $a['Moderate'])) ?: strcmp($a['label'], $b['label']); });
}
$nHigh = $ins['avg']['High']['n']; $nMod = $ins['avg']['Moderate']['n']; $nLow = $ins['avg']['Low']['n'];
$ord = ['1st', '2nd', '3rd', '4th'];
$secMax = 1; foreach ($ins['bySection'] as $v) { $secMax = max($secMax, ($v['High'] ?? 0) + ($v['Moderate'] ?? 0)); }
$secSorted = $ins['bySection'];
uasort($secSorted, function ($a, $b) { return ((($b['High'] ?? 0) * 1000 + ($b['Moderate'] ?? 0)) <=> (($a['High'] ?? 0) * 1000 + ($a['Moderate'] ?? 0))); });
$subMax = $ins['subjects'] ? max($ins['subjects']) : 1;
$yearGroups = [];
foreach ([1, 2, 3, 4] as $y) { $yearGroups[$ord[$y - 1] . ' Year'] = $ins['byYear'][$y]; }
$oddsFail = exp((float) ($coef['coef_failed'] ?? 0));
$oddsGwa  = exp((float) ($coef['coef_gwa'] ?? 0));
$oddsAtt  = exp((float) ($coef['coef_att'] ?? $coef['coef_attendance'] ?? 0));
$cmp = [
    ['Average grade (%)', 'gwa', 100, 1], ['Average attendance (%)', 'att', 100, 1], ['Average failed subjects', 'failed', 5, 2],
];
$levelColor = ['High' => '#e5534b', 'Moderate' => '#f0b429', 'Low' => '#3fb5c9'];
$badgeClass = ['High' => 'high-badge', 'Moderate' => 'moderate-badge'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prediction | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
    <style>.small-ay { display:block; font-size: 11px; color:#8a959b; }</style>
</head>
<body>

<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <h1>Prediction</h1>
            <p>Which students are likely to leave in <strong><?= htmlspecialchars($currentAy) ?></strong>, where they are, and what sets them apart — predicted from grades and attendance.</p>
        </div>
        <?php if (!$readOnly): ?>
            <form method="POST"><input type="hidden" name="action" value="run_all">
                <button type="submit" class="btn-primary"><i class="fa-solid fa-rotate"></i> Re-run All Predictions</button></form>
        <?php endif; ?>
            <a class="btn-primary" style="text-decoration:none;" href="prediction.php?export=csv"><i class="fa-solid fa-file-arrow-down"></i> Download the at-risk list (CSV)</a>
    </section>

    <?php if ($flash): ?>
        <div class="alert-banner alert-<?= $flash['type'] ?>"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>
    <?php if (!$readOnly && !empty($notScored)): ?>
        <div class="alert-banner" style="background:#fff1d6;color:#a96b00;justify-content:space-between;">
            <span><i class="fa-solid fa-triangle-exclamation"></i> <?= count($notScored) ?> student(s) have grades but haven't been scored yet.</span>
            <form method="POST"><input type="hidden" name="action" value="run_all"><button type="submit" class="btn-primary" style="padding:6px 12px;"><i class="fa-solid fa-bolt"></i> Score Them Now</button></form>
        </div>
    <?php endif; ?>

    <?php if (!$atRisk && !$nLow): ?>
        <div class="chart-empty" style="margin:0 30px 20px;">No predictions yet for <?= htmlspecialchars($currentAy) ?>. They appear as soon as students' grades are imported.</div>
    <?php else: ?>

    <!-- ============ the graphs come first ============ -->
    <div class="pd-grid">
        <div class="panel">
            <?php if (isFaculty()): ?>
        <div class="panel">
            <div class="panel-header"><div><h2>At-risk in my classes</h2><p>How many students of each class are at risk — tap a class to open it</p></div></div>
            <?php if (!$classRisk): ?><div class="chart-empty">No class is assigned to you yet.</div><?php endif; ?>
            <?php foreach ($classRisk as $cr): $tot = max(1, $cr['n']); ?>
                <a class="cr-row" href="class.php?id=<?= (int) $cr['id'] ?>">
                    <span class="cr-l"><strong><?= htmlspecialchars($cr['label']) ?></strong><small><?= $cr['n'] ?> student<?= $cr['n'] === 1 ? '' : 's' ?></small></span>
                    <span class="cr-bar"><i class="h" style="width:<?= round($cr['High'] / $tot * 100, 1) ?>%"></i><i class="m" style="width:<?= round($cr['Moderate'] / $tot * 100, 1) ?>%"></i></span>
                    <b><?= $cr['High'] ?> high · <?= $cr['Moderate'] ?> mod.</b>
                </a>
            <?php endforeach; ?>
            <div class="legend-row" style="margin-top:8px;"><span><i class="dotl" style="background:#e5534b"></i>High</span><span><i class="dotl" style="background:#f0b429"></i>Moderate</span></div>
        </div>
        <?php else: ?>
        <div class="panel-header"><div><h2>At-risk by year level</h2><p><?= $nHigh ?> high · <?= $nMod ?> moderate</p></div></div>
        <?php endif; ?>
            <?= svgGroupedBars($yearGroups, ['High' => ['label' => 'High', 'color' => '#e5534b'], 'Moderate' => ['label' => 'Moderate', 'color' => '#f0b429']], 'At-risk students per year level') ?>
            <div class="legend-row" style="margin-top:6px;"><span><i class="dotl" style="background:#e5534b"></i>High</span><span><i class="dotl" style="background:#f0b429"></i>Moderate</span></div>
        </div>
        <div class="panel">
            <div class="panel-header"><div><h2>At-risk by section</h2><p>Where the College should look first</p></div></div>
            <?php if (!$secSorted): ?><div class="chart-empty">No at-risk students.</div><?php endif; ?>
            <?php foreach (array_slice($secSorted, 0, 9, true) as $sec => $v): $h = $v['High'] ?? 0; $m = $v['Moderate'] ?? 0; ?>
                <div class="hbar-row">
                    <span><?= htmlspecialchars($sec) ?></span>
                    <span class="hbar-track"><i class="hbar-high" style="width:<?= round($h / $secMax * 100, 1) ?>%"></i><i class="hbar-mod" style="width:<?= round($m / $secMax * 100, 1) ?>%"></i></span>
                    <strong><?= $h + $m ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="panel">
            <div class="panel-header"><div><h2>Subjects they failed most</h2><p>Failed subjects of the at-risk students</p></div></div>
            <?php if (!$ins['subjects']): ?><div class="chart-empty">No failed subjects recorded.</div><?php endif; ?>
            <?php foreach ($ins['subjects'] as $sub => $n): ?>
                <div class="hbar-row" style="grid-template-columns: 1fr 70px 30px;">
                    <span style="line-height:1.25;"><?= htmlspecialchars($sub) ?></span>
                    <span class="hbar-track"><i class="hbar-high" style="width:<?= round($n / $subMax * 100, 1) ?>%"></i></span>
                    <strong><?= $n ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="panel" style="margin:0 30px 20px;">
        <div class="panel-header"><div><h2>What sets the at-risk students apart</h2><p>Average of each group — compared with students who are not at risk</p></div>
            <div class="legend-row"><span><i class="dotl" style="background:#e5534b"></i>High</span><span><i class="dotl" style="background:#f0b429"></i>Moderate</span><span><i class="dotl" style="background:#3fb5c9"></i>Low</span></div></div>
        <div class="fc-grid" style="grid-template-columns: repeat(3, 1fr);">
            <?php foreach ($cmp as $c): ?>
                <div class="cmp-block"><h4><?= $c[0] ?></h4>
                    <?php foreach (['High', 'Moderate', 'Low'] as $lvl): $v = $ins['avg'][$lvl][$c[1]]; ?>
                        <div class="cmp-line"><span><?= $lvl ?></span>
                            <span class="t"><i style="width:<?= $v === null ? 0 : min(100, round($v / $c[2] * 100, 1)) ?>%;background:<?= $levelColor[$lvl] ?>"></i></span>
                            <strong><?= $v === null ? '—' : number_format($v, $c[3]) ?></strong></div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="insight"><i class="fa-solid fa-lightbulb"></i>
        <strong>What matters most:</strong> <strong>failing a subject</strong> raises a student's risk the most — each failed subject makes the odds of dropping out about <strong><?= number_format($oddsFail, 1) ?> times</strong> higher.
        Higher grades and better attendance lower the risk (each extra grade point by about <strong><?= number_format((1 - $oddsGwa) * 100) ?>%</strong>, each extra point of attendance by about <strong><?= number_format((1 - $oddsAtt) * 100) ?>%</strong>).
        So the best way to bring the risk down is to help students <em>pass every subject</em> and keep attending — start with the subjects in the third graph.
    </div>

    <div class="panel" style="margin:0 30px 20px;">
        <details class="fc-how" style="margin:0;">
            <summary>How is the risk predicted, and what is it based on?</summary>
            <p>
                <strong>What is used</strong> — four things calculated from <em>everything</em> on record for the student: the <strong>current school year</strong> (the grades and attendance recorded in Classes so far — quiz by quiz, meeting by meeting) <em>and</em> every earlier year, even when it is already in History: a 4th-year student is judged on year 1 up to year 4.
                <strong>Grades</strong> and <strong>attendance</strong> = 70% current + 30% average of the earlier years · <strong>failed subjects</strong> = all failed so far (this year + earlier years) · <strong>grade trend</strong> = current grades minus the average of the earlier years (going down raises the risk).
                The risk is predicted again every time attendance or a score is saved, so it moves while the semester is still going.<br>
                <strong>How it is calculated</strong> — a machine-learning method called <em>Logistic Regression</em> gives each of the four things a weight and adds them up:
                score = <?= number_format((float) ($coef['intercept'] ?? 0), 2) ?> + (<?= fmtGrade((float) $coef['coef_gwa']) ?> × grades) + (<?= number_format((float) $coef['coef_att'], 2) ?> × attendance) + (<?= number_format((float) $coef['coef_failed'], 2) ?> × failed subjects) + (<?= number_format((float) $coef['coef_trend'], 2) ?> × trend).
                The score is turned into a percentage chance of dropping out: <em>chance = 1 ÷ (1 + e<sup>−score</sup>)</em>.<br>
                <strong>The levels</strong> — <strong>High</strong> = 70% or more · <strong>Moderate</strong> = 40% to 69% · <strong>Low</strong> = below 40%.<br>
                <strong>Who is scored</strong> — enrolled students who have grades on record (recorded in Classes or imported). A student who only just enrolled is scored from the last semester he or she finished.<br>
                <strong>Where the weights come from</strong> — they are saved in the system settings. <code>python/train_model.py</code> learns them again from the College's own history of students who dropped out and students who stayed, as more school years are recorded.
                Grades imported from a PEF (4.00 scale) are converted to percentages; the PEF has no attendance, so attendance counts only once it is recorded in Classes.
            </p>
        </details>
    </div>

    <?php endif; ?>

    <!-- ============ the students to help (High and Moderate only) ============ -->
    <form method="GET" class="toolbar" id="filterForm" data-base="prediction.php">
        <div class="search-box">
            <button type="submit" class="search-submit-btn" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
            <input type="text" name="q" placeholder="Search student name or ID" value="<?= htmlspecialchars($search) ?>" oninput="applyFilters(this)" autocomplete="off">
        </div>
        <select name="risk" class="filter-select" onchange="applyFilters(this)">
            <option value="">High &amp; Moderate</option>
            <option value="High" <?= $riskFilter === 'High' ? 'selected' : '' ?>>High only</option>
            <option value="Moderate" <?= $riskFilter === 'Moderate' ? 'selected' : '' ?>>Moderate only</option>
        </select>
        <select name="year" class="filter-select" data-resets="section" onchange="applyFilters(this)">
            <option value="">All Year Levels</option>
            <?php foreach ([1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'] as $n => $lbl): ?><option value="<?= $n ?>" <?= $yearFilter === $n ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
        </select>
        <select name="section" class="filter-select" data-live-options onchange="applyFilters(this)">
            <option value="">All Sections</option>
            <?php foreach ($sectionOptions as $opt): ?><option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $sectionFilter ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option><?php endforeach; ?>
        </select>
    </form>

    <div id="viewArea" data-live>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student</th><th>Section · Year</th><th>GWA</th><th>Attendance</th><th>Failed subjects</th><th>Main reasons</th><th>Risk</th><th>Records</th></tr></thead>
                <tbody>
                    <?php if (!$list): ?><tr><td colspan="8">No at-risk students match these filters<?= $atRisk ? '' : ' — nobody is at risk right now' ?>.</td></tr><?php endif; ?>
                    <?php $shown = ''; foreach ($list as $r):
                        if ($r['risk_level'] !== $shown):
                            $shown = $r['risk_level'];
                            $cnt = count(array_filter($list, function ($x) use ($shown) { return $x['risk_level'] === $shown; })); ?>
                            <tr class="risk-group"><td colspan="8"><span class="badge <?= $badgeClass[$shown] ?>"><?= $shown ?> risk</span> &nbsp;<?= $cnt ?> student<?= $cnt === 1 ? '' : 's' ?> — A–Z by last name</td></tr>
                    <?php endif; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['full_name']) ?></strong><span class="small-ay"><?= htmlspecialchars($r['student_id']) ?></span></td>
                            <td><?= htmlspecialchars($r['section'] ?: '—') ?><span class="small-ay"><?= $ord[(int) $r['yl'] - 1] ?? '' ?> Year</span></td>
                            <td><?= $r['gwa'] !== null ? fmtGrade((float) $r['gwa']) : '—' ?></td>
                            <td><?= $r['attendance_rate'] !== null ? number_format((float) $r['attendance_rate'], 1) . '%' : '—' ?></td>
                            <td><?= $r['units_failed'] !== null ? (int) $r['units_failed'] : '—' ?></td>
                            <td style="white-space:normal;max-width:250px;font-size:12.5px;line-height:1.5;"><?php foreach (array_slice($r['reasons'] ?? [], 0, 3) as $why): ?><span class="why-chip"><?= htmlspecialchars($why['label']) ?></span> <?php endforeach; ?><?= empty($r['reasons']) ? '<span style="color:#8a959b;">—</span>' : '' ?></td>
                            <td><span class="badge <?= $badgeClass[$r['risk_level']] ?>"><?= number_format((float) $r['probability'], 0) ?>%</span>
                                <?php if (($r['change'] ?? null) !== null && abs($r['change']) >= 1): ?><span class="risk-move <?= $r['change'] > 0 ? 'up' : 'down' ?>" title="Since the check before the latest"><?= $r['change'] > 0 ? '▲' : '▼' ?> <?= abs(round($r['change'])) ?></span><?php endif; ?></td>
                            <td><a class="btn-secondary" style="padding:7px 12px;" href="student.php?id=<?= urlencode($r['student_id']) ?>"><i class="fa-solid fa-eye"></i> View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<script src="../assets/live-filter.js?v=2"></script>
</body>
</html>
