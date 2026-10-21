<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';
require_once __DIR__ . '/../config/grades.php';

/**
 * One student, everything in one place.
 *   Program Head : every quiz, activity, attendance mark and grade of every class (all school years), the whole history from 1st year,
 *                  the risk over time, and the requests — view only.
 *   Faculty      : ONLY the classes assigned to him or her (their attendance, scores and grades), nothing about other subjects.
 */
denyAdmin();
$activePage = 'students';
$backUrl = 'students.php';
$sid = (string) ($_GET['id'] ?? '');
$st = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
$st->execute([$sid]);
$student = $st->fetch();
if (!$student) { header('Location: students.php'); exit; }

if (!empty($_GET['mark_read'])) { $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND student_id = ?")->execute([(int) $_GET['mark_read'], $sid]); }

// ---- which classes of this student may this person see? ----
$cq = $pdo->prepare("SELECT c.id FROM class_students cs INNER JOIN classes c ON c.id = cs.class_id WHERE cs.student_id = ? AND cs.excluded = 0"
                    . (isFaculty() ? " AND c.owner_user_id = ?" : "") . " ORDER BY c.academic_year DESC, c.semester DESC, c.subject_name ASC");
$cq->execute(isFaculty() ? [$sid, $_SESSION['user_id']] : [$sid]);
$classIds = array_map('intval', $cq->fetchAll(PDO::FETCH_COLUMN));
if (isFaculty() && !$classIds) { header('Location: students.php'); exit; }      // not one of my students
$full = canViewAllData();

$groups = [];       // "AY|sem" => [classes]
foreach ($classIds as $cid) {
    $d = classLoadData($pdo, $cid);
    if (!$d) { continue; }
    $key = $d['class']['academic_year'] . '|' . $d['class']['semester'];
    $groups[$key][] = ['d' => $d, 'r' => classStudentResult($d, $sid)];
}
$risk = latestRisks($pdo, [$sid])[$sid] ?? null;
$feat = $full ? studentFeatures($pdo, $sid) : null;
$curRec = $pdo->prepare("SELECT * FROM student_records WHERE student_id = ? ORDER BY year_level DESC LIMIT 1");
$curRec->execute([$sid]);
$curRec = $curRec->fetch();
$cur = currentSchoolYear($pdo);

$records = []; $subjects = []; $trend = []; $cases = [];
if ($full) {
    $q = $pdo->prepare("SELECT * FROM student_records WHERE student_id = ? ORDER BY year_level ASC"); $q->execute([$sid]); $records = $q->fetchAll();
    $q = $pdo->prepare("SELECT * FROM subject_records WHERE student_id = ? ORDER BY year_level ASC, semester ASC, subject_name ASC"); $q->execute([$sid]);
    foreach ($q->fetchAll() as $r) { $subjects[(int) $r['year_level']][(int) $r['semester']][] = $r; }
    $q = $pdo->prepare("SELECT predicted_at, probability, risk_level FROM predictions WHERE student_id = ? ORDER BY predicted_at ASC"); $q->execute([$sid]); $trend = $q->fetchAll();
    try { $q = $pdo->prepare("SELECT * FROM student_cases WHERE student_id = ? ORDER BY filed_on DESC"); $q->execute([$sid]); $cases = $q->fetchAll(); } catch (PDOException $e) {}
}
$ord = ['1st', '2nd', '3rd', '4th'];
$riskCls = ['High' => 'high-badge', 'Moderate' => 'moderate-badge', 'Low' => 'low-badge'];
$fmt = function ($v, int $d = 1) { return $v === null ? '—' : ($d === 0 ? number_format((float) $v, 0) : fmtGrade((float) $v)); };   // grades: a whole number or .5
$num = function ($v) { return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); };
$statusLabel = STUDENT_STATUSES[$student['status']] ?? ucfirst($student['status']);
$statusColor = STATUS_COLORS[$student['status']] ?? '#64727a';
$firstGroup = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student['full_name']) ?> | Students</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
</head>
<body>
<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <h1><?= htmlspecialchars($student['full_name']) ?></h1>
            <p><?= htmlspecialchars($student['student_id']) ?>
                <?php if ($curRec): ?> · <?= htmlspecialchars($curRec['section']) ?> · <?= $ord[(int) $curRec['year_level'] - 1] ?> Year · <?= htmlspecialchars($curRec['academic_year']) ?><?php endif; ?>
                &nbsp;<span class="status-pill" style="--c: <?= $statusColor ?>;"><?= htmlspecialchars($statusLabel) ?></span>
                <?php if ($risk): ?>&nbsp;<span class="badge <?= $riskCls[$risk['level']] ?>"><?= $risk['level'] ?> risk <?= number_format($risk['prob'], 0) ?>%</span><?php endif; ?></p>
        </div>
    </section>

    <?php if (isFaculty()): ?>
        <div class="alert-banner" style="background:#eaf6fa;color:#064a5e;"><i class="fa-solid fa-eye"></i> You see this student's attendance, scores and grades in <strong>your own class<?= count($classIds) === 1 ? '' : 'es' ?></strong> only.</div>
    <?php endif; ?>

    <div class="stu-nav">
        <a href="#overview">Overview</a><a href="#attendance">Attendance</a><a href="#scores">Quizzes &amp; scores</a><a href="#grades">Grades</a>
        <?php if ($full): ?><a href="#history">History since 1st year</a><a href="#trend">Risk over time</a><?php if ($cases): ?><a href="#requests">Requests</a><?php endif; ?><?php endif; ?>
    </div>

    <!-- ================= overview ================= -->
    <section class="dash-panel" id="overview"><div class="panel">
        <div class="panel-header"><div><h2>Overview</h2><p>What the system sees now, and what the risk is based on.</p></div></div>
        <div class="stu-kpis">
            <div><small>Risk of attrition</small><strong><?= $risk ? $risk['level'] . ' · ' . number_format($risk['prob'], 0) . '%' : 'Not scored yet' ?></strong></div>
            <?php if ($feat): ?>
                <div><small>Grades (current + earlier, blended)</small><strong><?= $fmt($feat['gwa'] ?? null) ?>%</strong></div>
                <div><small>Attendance (blended)</small><strong><?= $fmt($feat['att'] ?? null) ?>%</strong></div>
                <div><small>Failed subjects (all years)</small><strong><?= (int) ($feat['failed'] ?? 0) ?></strong></div>
                <div><small>Trend (this year vs earlier)</small><strong><?= isset($feat['trend']) ? ($feat['trend'] > 0 ? '+' : '') . number_format((float) $feat['trend'], 1) : '—' ?></strong></div>
            <?php endif; ?>
        </div>
        <?php
        $flags = [];
        foreach ($groups as $gk => $list) { foreach ($list as $c) { foreach ($c['r']['attention'] as $why) { $flags[] = $c['d']['class']['subject_name'] . ': ' . $why; } } }
        if ($flags): ?>
            <div class="stu-flags"><strong><i class="fa-solid fa-triangle-exclamation"></i> Needs attention</strong>
                <ul><?php foreach ($flags as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?></ul></div>
        <?php else: ?><p class="cls-hint" style="margin-top:10px;">No warning signs in the recorded classes right now.</p><?php endif; ?>
    </div></section>

    <?php if (!$groups): ?>
        <section class="dash-panel"><div class="panel"><div class="chart-empty">No class record exists for this student yet<?= $full ? ' — the history below comes from the imported records' : '' ?>.</div></div></section>
    <?php endif; ?>

    <!-- ================= attendance (every class) ================= -->
    <section class="dash-panel" id="attendance"><div class="panel">
        <div class="panel-header"><div><h2>Attendance</h2><p>Every meeting. <strong>/</strong> present · <strong>A</strong> absent = 1 · <strong>L</strong> late = ½ · <strong>E</strong> excused = ½. Absences add up over the semester: 3 or more = Dropped (Unofficially).</p></div></div>
        <?php foreach ($groups as $gk => $list): [$ay, $sem] = explode('|', $gk); ?>
            <h3 class="fc-title" style="margin-top:14px;"><?= htmlspecialchars($ay) ?> · <?= (int) $sem === 1 ? '1st' : '2nd' ?> Semester</h3>
            <?php foreach ($list as $c): $d = $c['d']; $r = $c['r']; $cl = $d['class']; ?>
                <div class="stu-class">
                    <div class="stu-class-h"><strong><?= htmlspecialchars($cl['subject_name']) ?></strong> <small><?= htmlspecialchars($cl['section']) ?></small>
                        <span class="badge <?= $r['alert'] === 'drop' ? 'high-badge' : ($r['alert'] === 'warn' ? 'moderate-badge' : 'low-badge') ?>"><?= $r['att']['total'] ? fmtPoints($r['att']['points']) . ' absence' . ($r['att']['points'] == 1 ? '' : 's') : 'no meetings yet' ?></span></div>
                    <?php if (!$d['meetings']): ?><p class="cls-hint">No meeting has been recorded.</p><?php else: ?>
                        <div class="attchips">
                            <?php foreach ($d['meetings'] as $m): $s = $d['att'][(int) $m['id']][$sid] ?? 'P'; ?>
                                <span class="attchip st-<?= $s ?>" title="<?= date('M j, Y', strtotime($m['meeting_date'])) ?> · <?= ['P' => 'Present', 'A' => 'Absent', 'L' => 'Late', 'E' => 'Excused'][$s] ?> · <?= TERM_LABELS[(int) $m['term']] ?>"><b><?= ATTENDANCE_LABELS[$s] ?></b><small><?= date('M j', strtotime($m['meeting_date'])) ?></small></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div></section>

    <!-- ================= quizzes and scores ================= -->
    <section class="dash-panel" id="scores"><div class="panel">
        <div class="panel-header"><div><h2>Quizzes, activities and exams</h2><p>Every score, term by term. Red = below 50% of the items.</p></div></div>
        <?php foreach ($groups as $gk => $list): [$ay, $sem] = explode('|', $gk); ?>
            <h3 class="fc-title" style="margin-top:14px;"><?= htmlspecialchars($ay) ?> · <?= (int) $sem === 1 ? '1st' : '2nd' ?> Semester</h3>
            <?php foreach ($list as $c): $d = $c['d']; $r = $c['r']; $cl = $d['class']; ?>
                <details class="stu-class" <?= $firstGroup ? 'open' : '' ?>>
                    <summary><strong><?= htmlspecialchars($cl['subject_name']) ?></strong> <small><?= htmlspecialchars($cl['section']) ?></small></summary>
                    <?php foreach (TERM_LABELS as $t => $tlabel):
                        $acts = $d['byTerm'][$t]['cs']; if ($d['byTerm'][$t]['exam']) { $acts[] = $d['byTerm'][$t]['exam']; }
                        $any = false; foreach ($acts as $a) { if (isset($d['scores'][(int) $a['id']][$sid])) { $any = true; } }
                        if (!$any) { continue; } ?>
                        <table class="mini"><thead><tr><th colspan="4"><?= $tlabel ?></th></tr></thead><tbody>
                            <?php foreach ($acts as $a): $aid = (int) $a['id']; $sc = $d['scores'][$aid][$sid] ?? null; $pc = $r['pct'][$aid] ?? null; ?>
                                <tr class="<?= ($pc !== null && $pc < LOW_SCORE_PERCENT) ? 'lowrow' : '' ?>"><td><?= htmlspecialchars($a['component']) ?> · <?= htmlspecialchars($a['title']) ?></td>
                                    <td><?= $sc === null ? '—' : $num($sc) ?></td><td>/ <?= $num($a['items']) ?></td><td><?= $pc === null ? '' : round($pc) . '%' ?></td></tr>
                            <?php endforeach; ?></tbody></table>
                    <?php endforeach; ?>
                    <?php if ($r['low'] === 0 && !array_filter($r['pct'])): ?><p class="cls-hint">No scores recorded yet.</p><?php endif; ?>
                </details>
                <?php $firstGroup = false; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div></section>

    <!-- ================= grades ================= -->
    <section class="dash-panel" id="grades"><div class="panel">
        <div class="panel-header"><div><h2>Grades</h2><p>Prelim 30% · Midterm 30% · Pre-Finals 40%. <strong>Grade so far</strong> uses the terms that exist; the <strong>Final Grade</strong> appears only when all three terms are complete.</p></div></div>
        <div class="table-container"><table>
            <thead><tr><th>Subject</th><th>School year</th><?php foreach (TERM_LABELS as $tl): ?><th><?= $tl ?></th><?php endforeach; ?><th>Grade so far</th><th>Final Grade</th></tr></thead>
            <tbody>
                <?php if (!$groups): ?><tr><td colspan="7">No class grades yet.</td></tr><?php endif; ?>
                <?php foreach ($groups as $gk => $list): [$ay, $sem] = explode('|', $gk); foreach ($list as $c): $r = $c['r']; ?>
                    <tr><td><strong><?= htmlspecialchars($c['d']['class']['subject_name']) ?></strong><span class="small-ay"><?= htmlspecialchars($c['d']['class']['section']) ?></span></td>
                        <td><?= htmlspecialchars($ay) ?> · <?= (int) $sem === 1 ? '1st' : '2nd' ?></td>
                        <?php foreach ([1, 2, 3] as $t): $g = $r['terms'][$t]['grade']; ?><td style="<?= ($g !== null && $g < FAILING_GRADE_THRESHOLD) ? 'color:#c62828;font-weight:700;' : '' ?>"><?= $g === null ? '—' : $fmt($g) . '%' . ($r['terms'][$t]['prov'] ? '*' : '') ?></td><?php endforeach; ?>
                        <td style="font-weight:700;<?= ($r['running'] !== null && $r['running'] < FAILING_GRADE_THRESHOLD) ? 'color:#c62828;' : '' ?>"><?= $r['running'] === null ? '—' : $fmt($r['running']) . '%' ?></td>
                        <td style="font-weight:700;"><?= $r['final'] === null ? '<span style="color:#8a959b;font-weight:500;">Not yet</span>' : $fmt($r['final']) . '%' ?></td></tr>
                <?php endforeach; endforeach; ?>
            </tbody>
        </table></div>
        <p class="cls-hint" style="margin-top:6px;">* so far — the exam of that term is not in yet.</p>
    </div></section>

    <?php if ($full): ?>
    <!-- ================= history since 1st year ================= -->
    <section class="dash-panel" id="history"><div class="panel">
        <div class="panel-header"><div><h2>History since 1st year</h2><p>Year by year, with every subject and its grade<?= $student['status'] === 'graduated' ? ' — this student has graduated' : '' ?>.</p></div></div>
        <?php if (!$records): ?><div class="chart-empty">No earlier records.</div><?php endif; ?>
        <?php foreach ($records as $rec): $y = (int) $rec['year_level']; ?>
            <details class="stu-class" <?= $rec === end($records) ? 'open' : '' ?>>
                <summary><strong><?= $ord[$y - 1] ?> Year</strong> <small><?= htmlspecialchars($rec['academic_year']) ?> · <?= htmlspecialchars($rec['section']) ?></small>
                    <span class="badge <?= ($rec['gwa'] !== null && (float) $rec['gwa'] < FAILING_GRADE_THRESHOLD) ? 'high-badge' : 'low-badge' ?>">GWA <?= $fmt($rec['gwa']) ?>%</span>
                    <small>attendance <?= $fmt($rec['attendance_rate'], 0) ?>% · failed <?= (int) $rec['units_failed'] ?></small></summary>
                <?php foreach ([1, 2] as $sm): if (empty($subjects[$y][$sm])) { continue; } ?>
                    <table class="mini"><thead><tr><th colspan="3"><?= $sm === 1 ? '1st' : '2nd' ?> Semester</th></tr></thead><tbody>
                        <?php foreach ($subjects[$y][$sm] as $sj): ?>
                            <tr class="<?= ($sj['grade'] !== null && (float) $sj['grade'] < FAILING_GRADE_THRESHOLD) ? 'lowrow' : '' ?>"><td><?= htmlspecialchars($sj['subject_name']) ?></td>
                                <td><?= $sj['grade'] === null ? '—' : $fmt($sj['grade']) . '%' ?></td><td><?= $sj['attendance_rate'] === null ? '' : 'attendance ' . round((float) $sj['attendance_rate']) . '%' ?></td></tr>
                        <?php endforeach; ?></tbody></table>
                <?php endforeach; ?>
            </details>
        <?php endforeach; ?>
    </div></section>

    <!-- ================= risk over time ================= -->
    <section class="dash-panel" id="trend"><div class="panel">
        <div class="panel-header"><div><h2>Risk over time</h2><p>The predicted chance of leaving, each time it was calculated — from the first record to today.</p></div></div>
        <?php if (count($trend) < 2): ?><div class="chart-empty">The line appears once the risk has been calculated at least twice.</div>
        <?php else: $n = count($trend); $W = 760; $H = 190; $px = 36; $pts = [];
            foreach ($trend as $i => $t) { $pts[] = [$px + $i * (($W - 2 * $px) / max(1, $n - 1)), $H - 24 - ((float) $t['probability'] / 100) * ($H - 44), $t]; } ?>
            <div style="overflow-x:auto;"><svg viewBox="0 0 <?= $W ?> <?= $H ?>" style="width:100%;max-width:<?= $W ?>px;height:auto;">
                <?php foreach ([40, 70] as $line): $yy = $H - 24 - ($line / 100) * ($H - 44); ?><line x1="<?= $px ?>" x2="<?= $W - $px ?>" y1="<?= $yy ?>" y2="<?= $yy ?>" stroke="#e1e8ec" stroke-dasharray="4 4"/><text x="4" y="<?= $yy + 4 ?>" font-size="11" fill="#8a959b"><?= $line ?>%</text><?php endforeach; ?>
                <polyline fill="none" stroke="#3fb5c9" stroke-width="2.5" points="<?= implode(' ', array_map(function ($p) { return round($p[0], 1) . ',' . round($p[1], 1); }, $pts)) ?>"/>
                <?php foreach ($pts as $p): $c = ['High' => '#e5534b', 'Moderate' => '#f0b429', 'Low' => '#3fb5c9'][$p[2]['risk_level']]; ?>
                    <circle cx="<?= round($p[0], 1) ?>" cy="<?= round($p[1], 1) ?>" r="4.5" fill="<?= $c ?>"><title><?= date('M j, Y', strtotime($p[2]['predicted_at'])) ?> — <?= $p[2]['risk_level'] ?> <?= round((float) $p[2]['probability']) ?>%</title></circle>
                <?php endforeach; ?>
                <text x="<?= $px ?>" y="<?= $H - 4 ?>" font-size="11" fill="#8a959b"><?= date('M Y', strtotime($trend[0]['predicted_at'])) ?></text>
                <text x="<?= $W - $px ?>" y="<?= $H - 4 ?>" font-size="11" fill="#8a959b" text-anchor="end"><?= date('M Y', strtotime($trend[$n - 1]['predicted_at'])) ?></text>
            </svg></div>
        <?php endif; ?>
    </div></section>

    <?php if ($cases): ?>
    <section class="dash-panel" id="requests"><div class="panel">
        <div class="panel-header"><div><h2>Requests</h2><p>Leave of absence, dismissal, drop and shift requests filed for this student.</p></div></div>
        <div class="table-container"><table><thead><tr><th>Type</th><th>School year</th><th>Reason</th><th>Filed</th><th>Returned</th></tr></thead><tbody>
            <?php foreach ($cases as $cs): ?><tr><td><?= htmlspecialchars(['loa' => 'Leave of Absence', 'hd' => 'Honorable Dismissal', 'du' => 'Dropped (Unofficial)', 'shifted' => 'Shifted'][$cs['case_type']] ?? $cs['case_type']) ?></td>
                <td><?= htmlspecialchars($cs['academic_year']) ?> · <?= htmlspecialchars($cs['semester']) ?></td><td><?= htmlspecialchars($cs['reason'] === 'Others' && $cs['reason_other'] ? $cs['reason_other'] : $cs['reason']) ?></td>
                <td><?= date('M j, Y', strtotime($cs['filed_on'])) ?></td><td><?= $cs['returned_on'] ? date('M j, Y', strtotime($cs['returned_on'])) : '—' ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div></section>
    <?php endif; ?>
    <?php endif; ?>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
</body>
</html>
