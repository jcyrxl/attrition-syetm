<?php
// A quick look at one student, shown in a side panel on the Dashboard (so nobody has to leave the page they are on).
// Program Head: every class of the school year + the numbers behind the risk. Faculty: only his or her own classes.
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';
require_once __DIR__ . '/../config/grades.php';
require_once __DIR__ . '/../config/charts.php';

if (isAdmin()) { http_response_code(403); exit('Not available.'); }
$sid = (string) ($_GET['id'] ?? '');
$st = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
$st->execute([$sid]);
$student = $st->fetch();
if (!$student) { http_response_code(404); exit('<p style="padding:20px;">Student not found.</p>'); }
if (!empty($_GET['mark_read'])) { $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND student_id = ?")->execute([(int) $_GET['mark_read'], $sid]); }

$cur = currentSchoolYear($pdo);
$cq = $pdo->prepare("SELECT c.id FROM class_students cs INNER JOIN classes c ON c.id = cs.class_id WHERE cs.student_id = ? AND cs.excluded = 0 AND c.academic_year = ?"
                    . (isFaculty() ? " AND c.owner_user_id = ?" : "") . " ORDER BY c.semester DESC, c.subject_name ASC");
$cq->execute(isFaculty() ? [$sid, $cur['ay'], $_SESSION['user_id']] : [$sid, $cur['ay']]);
$classIds = array_map('intval', $cq->fetchAll(PDO::FETCH_COLUMN));
if (isFaculty() && !$classIds) { http_response_code(403); exit('<p style="padding:20px;">This student is not in one of your classes.</p>'); }

$list = [];
foreach ($classIds as $cid) { $d = classLoadData($pdo, $cid); if ($d) { $list[] = ['d' => $d, 'r' => classStudentResult($d, $sid)]; } }
$risk = latestRisks($pdo, [$sid])[$sid] ?? null;
$feat = canViewAllData() ? studentFeatures($pdo, $sid) : null;
$rec = $pdo->prepare("SELECT year_level, section, academic_year FROM student_records WHERE student_id = ? ORDER BY year_level DESC LIMIT 1");
$rec->execute([$sid]);
$rec = $rec->fetch();
$ord = ['1st', '2nd', '3rd', '4th'];
$cls = ['High' => 'high-badge', 'Moderate' => 'moderate-badge', 'Low' => 'low-badge'];
$num = function ($v) { return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); };
$g = function (?float $v, bool $prov = false) { return $v === null ? '—' : fmtGrade($v) . '%' . ($prov ? '*' : ''); };
$flags = [];
foreach ($list as $c) { foreach ($c['r']['attention'] as $why) { $flags[] = $c['d']['class']['subject_name'] . ': ' . $why; } }
?>
<div class="pv">
    <h2><?= htmlspecialchars($student['full_name']) ?></h2>
    <p class="pv-sub"><?= htmlspecialchars($student['student_id']) ?><?php if ($rec): ?> · <?= htmlspecialchars($rec['section']) ?> · <?= $ord[(int) $rec['year_level'] - 1] ?> Year<?php endif; ?>
        <?php if ($risk): ?> &nbsp;<span class="badge <?= $cls[$risk['level']] ?>"><?= $risk['level'] ?> risk <?= number_format($risk['prob'], 0) ?>%</span><?php endif; ?></p>

    <?php if ($feat): ?>
        <div class="stu-kpis" style="grid-template-columns:repeat(3,1fr);">
            <div><small>Grades (blended)</small><strong><?= fmtGrade((float) $feat['gwa']) ?>%</strong></div>
            <div><small>Attendance</small><strong><?= round((float) $feat['att']) ?>%</strong></div>
            <div><small>Failed subjects</small><strong><?= (int) $feat['failed'] ?></strong></div>
        </div>
    <?php endif; ?>

    <?php if ($flags): ?><div class="stu-flags"><strong><i class="fa-solid fa-triangle-exclamation"></i> Needs attention</strong><ul><?php foreach (array_slice($flags, 0, 6) as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?></ul></div>
    <?php else: ?><p class="cls-hint" style="margin:10px 0;">No warning signs in the recorded classes right now.</p><?php endif; ?>

    <?php if (!$list): ?><p class="cls-hint">No class record yet for <?= htmlspecialchars($cur['ay']) ?>.</p><?php else: ?>
        <h3 class="pv-h">Grades — <?= htmlspecialchars($cur['ay']) ?></h3>
        <div style="overflow-x:auto;"><table class="mini"><thead><tr><th>Subject</th><th>Prelim</th><th>Midterm</th><th>Pre-F</th><th>So far</th><th>Final</th><th>Abs.</th></tr></thead><tbody>
            <?php foreach ($list as $c): $r = $c['r']; ?>
                <tr><td><strong><?= htmlspecialchars($c['d']['class']['subject_name']) ?></strong><div class="pv-s"><?= htmlspecialchars($c['d']['class']['section']) ?> · <?= (int) $c['d']['class']['semester'] === 1 ? '1st' : '2nd' ?> sem</div></td>
                    <?php foreach ([1, 2, 3] as $t): $x = $r['terms'][$t]; ?><td style="<?= ($x['grade'] !== null && $x['grade'] < FAILING_GRADE_THRESHOLD) ? 'color:#c62828;font-weight:700;' : '' ?>"><?= $g($x['grade'], $x['prov']) ?></td><?php endforeach; ?>
                    <td style="font-weight:700;<?= ($r['running'] !== null && $r['running'] < FAILING_GRADE_THRESHOLD) ? 'color:#c62828;' : '' ?>"><?= $g($r['running']) ?></td>
                    <td><?= $r['final'] === null ? '<span style="color:#8a959b;">Not yet</span>' : '<strong>' . $g($r['final']) . '</strong>' ?></td>
                    <td style="<?= $r['alert'] === 'drop' ? 'color:#b3261e;font-weight:700;' : ($r['alert'] === 'warn' ? 'color:#8a5a00;font-weight:700;' : '') ?>"><?= $r['att']['total'] ? fmtPoints($r['att']['points']) : '—' ?></td></tr>
            <?php endforeach; ?></tbody></table></div>

        <h3 class="pv-h">Attendance</h3>
        <?php foreach ($list as $c): $d = $c['d']; if (!$d['meetings']) { continue; } ?>
            <div class="pv-s" style="margin:6px 0 3px;"><strong><?= htmlspecialchars($d['class']['subject_name']) ?></strong></div>
            <div class="attchips"><?php foreach (array_slice($d['meetings'], -14) as $m): $s = $d['att'][(int) $m['id']][$sid] ?? 'P'; ?>
                <span class="attchip st-<?= $s ?>" title="<?= date('M j', strtotime($m['meeting_date'])) ?> · <?= ['P' => 'Present', 'A' => 'Absent', 'L' => 'Late', 'E' => 'Excused'][$s] ?>"><b><?= ATTENDANCE_LABELS[$s] ?></b><small><?= date('M j', strtotime($m['meeting_date'])) ?></small></span>
            <?php endforeach; ?></div>
        <?php endforeach; ?>

        <?php $lows = [];
        foreach ($list as $c) { foreach ($c['d']['byTerm'] as $t => $x) { $acts = $x['cs']; if ($x['exam']) { $acts[] = $x['exam']; }
            foreach ($acts as $a) { $pc = $c['r']['pct'][(int) $a['id']] ?? null; if ($pc !== null && $pc < LOW_SCORE_PERCENT) { $lows[] = [$c['d']['class']['subject_name'], $a['component'] . ' · ' . $a['title'], $c['d']['scores'][(int) $a['id']][$sid], (float) $a['items'], $pc]; } } } }
        if ($lows): ?>
            <h3 class="pv-h">Scores below 50%</h3>
            <table class="mini"><tbody><?php foreach (array_slice($lows, 0, 10) as $l): ?><tr class="lowrow"><td><?= htmlspecialchars($l[0]) ?> — <?= htmlspecialchars($l[1]) ?></td><td><?= $num($l[2]) ?> / <?= $num($l[3]) ?></td><td><?= round($l[4]) ?>%</td></tr><?php endforeach; ?></tbody></table>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (canViewAllData()):
        $yq = $pdo->prepare("SELECT year_level, academic_year, section, gwa, attendance_rate, units_failed FROM student_records WHERE student_id = ? ORDER BY year_level ASC"); $yq->execute([$sid]); $years = $yq->fetchAll();
        $tq = $pdo->prepare("SELECT predicted_at, probability, risk_level FROM predictions WHERE student_id = ? ORDER BY predicted_at ASC"); $tq->execute([$sid]); $trend = $tq->fetchAll();
        if ($years): ?>
            <h3 class="pv-h">History since 1st year</h3>
            <table class="mini"><thead><tr><th>Year</th><th>School year</th><th>Grades</th><th>Attend.</th><th>Failed</th></tr></thead><tbody>
                <?php foreach ($years as $y): ?><tr><td><?= $ord[(int) $y['year_level'] - 1] ?> · <?= htmlspecialchars($y['section']) ?></td><td><?= htmlspecialchars($y['academic_year']) ?></td>
                    <td style="<?= ($y['gwa'] !== null && (float) $y['gwa'] < FAILING_GRADE_THRESHOLD) ? 'color:#c62828;font-weight:700;' : '' ?>"><?= $y['gwa'] === null ? '—' : fmtGrade((float) $y['gwa']) . '%' ?></td>
                    <td><?= $y['attendance_rate'] === null ? '—' : round((float) $y['attendance_rate']) . '%' ?></td><td><?= (int) $y['units_failed'] ?></td></tr><?php endforeach; ?></tbody></table>
        <?php endif;
        if (count($trend) >= 2):
            $tL = []; $tV = []; $tC = [];
            foreach ($trend as $t) { $tL[] = date('M j', strtotime($t['predicted_at'])); $tV[] = round((float) $t['probability'], 1); $tC[] = ['High' => '#e5534b', 'Moderate' => '#f0b429', 'Low' => '#2f9bb5'][$t['risk_level']]; } ?>
            <h3 class="pv-h">Risk over time</h3>
            <?= svgLineChart(['labels' => $tL, 'series' => [['name' => 'Risk', 'color' => '#6d5bb8', 'values' => $tV, 'colors' => $tC]], 'min' => 0, 'max' => 100, 'ticks' => 5, 'unit' => '%', 'width' => 520, 'height' => 200,
                              'ref' => [['value' => 70, 'label' => 'high', 'color' => '#e5534b'], ['value' => 40, 'label' => 'moderate', 'color' => '#d99100']]]) ?>
        <?php endif; ?>
    <?php endif; ?>

    <p style="margin-top:16px;"><a class="btn-secondary" style="text-decoration:none;" href="student.php?id=<?= urlencode($sid) ?>" data-full><i class="fa-solid fa-up-right-from-square"></i> Open the whole record</a></p>
</div>
