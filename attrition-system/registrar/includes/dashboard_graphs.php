<?php
// The Dashboard. Program Head: the whole College. Professor: only his or her own classes and students.
// A compact grid: the three things to see first, the attendance trend, and "See more graphs" for the rest.
// Every graph is a LINE or a donut you can tap: a window opens with the data behind it (each student opens a preview — no page change).
// Expects: $pdo, $viewAy, $isHistory, $cur, $isProf, $scopeClassIds (null = all), $scopeIds (null = all).
require_once __DIR__ . '/../../config/charts.php';
$popupDataRendered = true;
$tl = TERM_LABELS;

if ($isProf && !$scopeClassIds) {
    echo '<div class="chart-empty" style="margin:0 24px 20px;padding:24px;text-align:center;"><i class="fa-solid fa-chalkboard-user"></i> No class has been assigned to you for ' . htmlspecialchars($viewAy) . ' yet. The Administrator assigns the subjects you handle — they will appear here and under <strong>My Classes</strong>.</div>';
    return;
}
$popArr = function (string $title, string $sub, array $rows, ?string $more = null, ?string $moreLabel = null): array {
    return ['t' => $title, 's' => $sub, 'r' => array_slice($rows, 0, 80), 'm' => $more, 'ml' => $moreLabel];
};
$pop = function (string $title, string $sub, array $rows, ?string $more = null, ?string $moreLabel = null) use ($popArr): string {
    return htmlspecialchars(json_encode($popArr($title, $sub, $rows, $more, $moreLabel), JSON_UNESCAPED_UNICODE), ENT_QUOTES);
};
$ord = ['1st', '2nd', '3rd', '4th'];
$ptsFmt = function (float $p): string { return fmtPoints($p); };

// ---------------- the numbers ----------------
$lists  = riskLists($pdo, $viewAy, $scopeIds);
$risk   = ['High' => count($lists['High']), 'Moderate' => count($lists['Moderate']), 'Low' => count($lists['Low'])];
$risk['total'] = $risk['High'] + $risk['Moderate'] + $risk['Low'];
$riskSum = $risk['total'];
$loa     = $isProf ? null : loaStatsForYear($pdo, $viewAy);
$popupCases = $loa ? $loa['cases'] : [];
$series  = dashboardSeries($pdo, $viewAy, $scopeClassIds);
$ts      = termStatsBySection($pdo, $viewAy, (int) ($cur['sem'] ?? 1), $scopeClassIds);
$mySums = [];
if ($isProf) { foreach ($scopeClassIds as $cid) { $s = classSummary($pdo, $cid); if ($s) { $mySums[] = $s; } } }
$riskRows = function (array $items) { return array_map(function ($r) { return ['n' => $r['name'], 'i' => $r['id'], 'd' => round($r['prob']) . '% · ' . $r['section']]; }, $items); };
$riskPop = function (string $level) use ($lists, $pop, $riskRows) {
    $rows = $riskRows($lists[$level]);
    return $pop($level . ' risk', count($rows) . ' student' . (count($rows) === 1 ? '' : 's') . ' — predicted from grades and attendance. Tap a name to look at the record.', $rows);
};
$palette = ['#e5534b', '#f0b429', '#2f9bb5', '#6d5bb8', '#1a9b6f', '#d9734e'];
?>
<div id="popupData" hidden data-cases="<?= htmlspecialchars(json_encode($popupCases, JSON_UNESCAPED_UNICODE)) ?>"></div>

<!-- ============ ROW 1: the three things to see first ============ -->
<section class="dgrid">

    <div class="gcard g-teal span-4">
        <div class="gcard-top"><span class="gcard-title">Risk of Attrition<?= $isProf ? ' — My Students' : '' ?></span><i class="fa-solid fa-triangle-exclamation gcard-ico"></i></div>
        <span class="basis"><i class="fa-solid fa-circle-info"></i> Predicted from grades and attendance · tap a slice</span>
        <?php if ($riskSum > 0): ?>
            <div class="gcard-donut">
                <?= svgDonut([
                    ['label' => 'High',     'value' => $risk['High'],     'color' => '#ff6b6b', 'pop' => $riskPop('High')],
                    ['label' => 'Moderate', 'value' => $risk['Moderate'], 'color' => '#ffd166', 'pop' => $riskPop('Moderate')],
                    ['label' => 'Low',      'value' => $risk['Low'],      'color' => '#ffffff', 'pop' => $riskPop('Low')],
                ], 140, 18, (string) $risk['High'], 'high risk') ?>
                <ul class="gcard-legend">
                    <?php foreach (['High' => '#ff6b6b', 'Moderate' => '#ffd166', 'Low' => '#fff'] as $lvl => $col): ?>
                        <li><a href="#" data-pop="<?= $riskPop($lvl) ?>"><i style="background:<?= $col ?>"></i><?= $lvl ?> <strong><?= $risk[$lvl] ?></strong></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <div class="gcard-empty">No risk scores yet — they appear as soon as grades or attendance are recorded.</div>
        <?php endif; ?>
        <div class="gcard-foot"><?= $isProf ? 'Enrolled students in my ' . count($scopeClassIds) . ' class' . (count($scopeClassIds) === 1 ? '' : 'es') . ' · ' : 'Enrolled students of ' ?><?= htmlspecialchars($viewAy) ?> · <?= $risk['total'] ?> counted</div>
    </div>

    <?php
    // ---- the sections that need attention, term by term (a line for each) ----
    $curT = max(1, (int) $ts['current']);
    $secAll = $ts['sections'];
    $secStudents = function (string $sec) use ($lists, $riskRows) {
        $rows = [];
        foreach (['High', 'Moderate', 'Low'] as $lv) { foreach ($lists[$lv] as $r) { if ($r['section'] === $sec) { $rows[] = ['n' => $r['name'], 'i' => $r['id'], 'd' => $lv . ' ' . round($r['prob']) . '%']; } } }
        return $rows;
    };
    $serA = []; $allV = []; $i = 0;
    foreach (array_slice($secAll, 0, 6, true) as $sec => $x) {
        $vals = []; $pops = [];
        foreach ([1, 2, 3] as $t) {
            $v = ($t <= $curT) ? $x['terms'][$t] : null;
            $vals[] = $v === null ? null : round($v, 1);
            if ($v !== null) { $allV[] = $v; }
            $pops[] = $v === null ? '' : $pop($sec . ' · ' . $tl[$t], 'Average ' . fmtGrade($v) . '% — ' . $x['students'] . ' students, ' . $x['attention'] . ' need attention. Sorted by risk; tap a name.', $secStudents($sec));
        }
        $serA[] = ['name' => $sec, 'color' => $palette[$i % 6], 'values' => $vals, 'pops' => $pops]; $i++;
    }
    $minV = $allV ? min($allV) : 70;
    $ymin = (int) (floor(($minV - 5) / 10) * 10); if ($ymin < 50) { $ymin = (int) max(0, floor($ymin / 20) * 20); } $ymin = min($ymin, 70);
    $ticksA = $ymin >= 50 ? (100 - $ymin) / 10 : (100 - $ymin) / 20;
    $allSecRows = [];
    foreach ($secAll as $sec => $x) {
        $d = [];
        foreach ([1, 2, 3] as $t) { if ($t <= $curT && $x['terms'][$t] !== null) { $d[] = substr($tl[$t], 0, 3) . ' ' . fmtGrade($x['terms'][$t]); } }
        $allSecRows[] = ['n' => $sec, 'i' => '', 'd' => implode(' · ', $d) . ($x['attention'] ? ' · ' . $x['attention'] . ' need attention' : ''),
                         'p' => $popArr($sec, $x['students'] . ' students — sorted by risk. Tap a name to look at the record.', $secStudents($sec))];
    }
    ?>
    <div class="dcard span-5">
        <div class="dcard-head"><div><h2>Where to look first — by section</h2><p>Average grade of the sections that need attention most, term by term. Tap a point.</p></div>
            <?php if (count($secAll) > 6): ?><button type="button" class="seeall" data-pop="<?= $pop('All ' . count($secAll) . ' sections', 'Worst first. Tap a section to see its students.', $allSecRows) ?>">See all <?= count($secAll) ?></button><?php endif; ?></div>
        <?php if ($ts['current'] > 0 && $serA): ?>
            <?= svgLineChart(['labels' => array_values($tl), 'series' => $serA, 'min' => $ymin, 'max' => 100, 'ticks' => $ticksA, 'unit' => '%', 'width' => 560, 'height' => 250,
                              'ref' => [['value' => FAILING_GRADE_THRESHOLD, 'label' => 'passing ' . (int) FAILING_GRADE_THRESHOLD . '%', 'color' => '#b3261e']]]) ?>
        <?php else: ?><div class="gcard-empty" style="margin:auto 0;">Nothing has been recorded yet this semester. The sections appear here as grades come in.</div><?php endif; ?>
    </div>

    <?php if ($isProf):
        usort($mySums, function ($a, $b) { return ($b['attention'] <=> $a['attention']); });
        $allClassRows = array_map(function ($c) { $cl = $c['d']['class']; return ['n' => $cl['subject_name'], 'i' => '', 'd' => $cl['section'] . ' · ' . $c['attention'] . ' need attention', 'h' => 'class.php?id=' . (int) $cl['id']]; }, $mySums); ?>
    <div class="gcard g-ocean span-3" style="grid-column: span 3;">
        <div class="gcard-top"><span class="gcard-title">My Classes</span><i class="fa-solid fa-chalkboard-user gcard-ico"></i></div>
        <span class="basis"><i class="fa-solid fa-user-check"></i> Students who need attention</span>
        <div class="rrows">
            <?php foreach (array_slice($mySums, 0, 5) as $c): $cl = $c['d']['class']; ?>
                <a class="rrow" href="class.php?id=<?= (int) $cl['id'] ?>" style="grid-template-columns: 1fr auto;">
                    <span class="rl"><?= htmlspecialchars($cl['subject_name']) ?> <small style="opacity:.8;">· <?= htmlspecialchars($cl['section']) ?></small></span>
                    <b><?= $c['attention'] ?><?= ($c['drop'] + $c['warn']) ? ' <i class="fa-solid fa-user-clock"></i>' : '' ?></b>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="gcard-foot"><button type="button" class="seeall" style="background:rgba(255,255,255,.18);color:#fff;border-color:rgba(255,255,255,.45);" data-pop="<?= $pop('My ' . count($mySums) . ' classes', 'The number is how many students need attention. Tap a class to record.', $allClassRows) ?>">See all <?= count($mySums) ?> classes</button></div>
    </div>
    <?php else: ?>
    <div class="gcard g-ocean span-3" style="grid-column: span 3;">
        <div class="gcard-top"><span class="gcard-title">Reasons for Leave</span><i class="fa-solid fa-comment-dots gcard-ico"></i></div>
        <span class="basis"><i class="fa-solid fa-envelope-open-text"></i> From the students' letters</span>
        <?php if ($loa['reasons']): $maxR = max($loa['reasons']); ?>
            <div class="rrows">
                <?php foreach (array_slice($loa['reasons'], 0, 5, true) as $reasonName => $n): ?>
                    <button type="button" class="rrow" onclick='openReasonModal(<?= jsonAttr($reasonName) ?>)' title="See the students">
                        <span class="rl"><?= htmlspecialchars($reasonName) ?></span><span class="rt"><i style="width:<?= max(6, (int) round($n / $maxR * 100)) ?>%"></i></span><b><?= $n ?></b>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php else: ?><div class="gcard-empty">No reasons yet — they come from the students' letters.</div><?php endif; ?>
        <div class="gcard-foot">Tap a reason to see who wrote it.</div>
    </div>
    <?php endif; ?>
</section>

<!-- ============ ROW 2: attendance ============ -->
<?php
$weeks = [];
foreach ($series['dates'] as $date => $x) {
    $wk = date('Y-m-d', strtotime('monday this week', strtotime($date)));
    if (!isset($weeks[$wk])) { $weeks[$wk] = ['pts' => 0.0, 'n' => 0, 'who' => []]; }
    $weeks[$wk]['pts'] += $x['pts']; $weeks[$wk]['n'] += $x['n'];
    foreach ($x['who'] as $w) { $weeks[$wk]['who'][] = [$w[0], $w[1], $w[2], $w[3], $date]; }
}
ksort($weeks);
$wLabels = []; $wVals = []; $wPops = []; $wCols = []; $wMin = 100;
foreach ($weeks as $wk => $x) {
    $rate = $x['n'] ? ($x['n'] - $x['pts']) / $x['n'] * 100 : 100; $wMin = min($wMin, $rate);
    $rows = array_map(function ($w) { return ['n' => $w[0], 'i' => $w[1], 'd' => ['A' => 'Absent', 'L' => 'Late', 'E' => 'Excused'][$w[2]] . ' · ' . $w[3] . ' · ' . date('M j', strtotime($w[4]))]; }, $x['who']);
    $wLabels[] = date('M j', strtotime($wk)); $wVals[] = round($rate, 1); $wCols[] = $rate < 80 ? '#e5534b' : null;
    $wPops[] = $pop('Week of ' . date('F j, Y', strtotime($wk)), 'Attendance ' . round($rate) . '% — ' . count($rows) . ' absent, late or excused mark' . (count($rows) === 1 ? '' : 's') . ' this week. Tap a name.', $rows);
}
$wymin = (int) max(0, floor(($wMin - 5) / 10) * 10); if ($wymin > 70) { $wymin = 70; }
$absAll = array_map(function ($a) { return ['n' => $a[0], 'i' => $a[1], 'd' => fmtPoints($a[5]) . ' · ' . $a[2] . ' · ' . $a[3]]; }, $series['absent']);
?>
<section class="dgrid">
    <div class="dcard span-7">
        <div class="dcard-head"><div><h2>Attendance rate, week by week</h2><p>From the start of the school year to today. A ring below the dashed line is a week under 80%. Tap a point to see who was absent.</p></div></div>
        <?php if (!$weeks): ?><div class="gcard-empty" style="margin:auto 0;">No attendance has been recorded yet.</div>
        <?php else: ?>
            <?= svgLineChart(['labels' => $wLabels, 'series' => [['name' => 'Attendance', 'color' => '#2f9bb5', 'values' => $wVals, 'pops' => $wPops, 'colors' => $wCols]], 'min' => $wymin, 'max' => 100, 'ticks' => (100 - $wymin) / 10, 'unit' => '%', 'width' => 800, 'height' => 250,
                              'ref' => [['value' => 80, 'label' => '80%', 'color' => '#e5534b']]]) ?>
        <?php endif; ?>
    </div>
    <div class="dcard span-5">
        <div class="dcard-head"><div><h2>Who is absent</h2><p>Absent = 1, late or excused = ½ · <strong>3 = Dropped (Unofficially)</strong></p></div>
            <?php if (count($series['absent']) > 6): ?><button type="button" class="seeall" data-pop="<?= $pop('Everyone with absences', 'Absence points from the start of the school year. Tap a name.', $absAll) ?>">See all <?= count($series['absent']) ?></button><?php endif; ?></div>
        <?php if (!$series['absent']): ?><div class="gcard-empty" style="margin:auto 0;"><?= $series['dates'] ? 'Nobody has been absent.' : 'No attendance has been recorded yet.' ?></div><?php endif; ?>
        <div class="mini-list">
            <?php foreach (array_slice($series['absent'], 0, 6) as $a): $cl = $a[5] >= ABSENCE_DROP_AT ? 'drop' : ($a[5] >= ABSENCE_ALERT_AT ? 'warn' : ''); ?>
                <a class="mini-row" href="student.php?id=<?= urlencode($a[1]) ?>"><span class="who"><strong><?= htmlspecialchars($a[0]) ?></strong><small><?= htmlspecialchars($a[2]) ?> · <?= htmlspecialchars($a[3]) ?></small></span><span class="pts <?= $cl ?>"><?= $ptsFmt($a[5]) ?></span></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<button type="button" class="more-toggle" id="moreToggle"><span>See more graphs</span> <i class="fa-solid fa-chevron-down"></i></button>

<!-- ============ MORE: quizzes, grades, history, who to look at ============ -->
<div id="moreBlock" hidden>
<?php
// ---- quizzes and activities, in the order they were given ----
$agg = [];
foreach ($series['activities'] as $a) {
    $k = $a['term'] . '|' . $a['title'];
    if (!isset($agg[$k])) { $agg[$k] = ['term' => $a['term'], 'title' => $a['title'], 'sum' => 0.0, 'n' => 0, 'classes' => []]; }
    $agg[$k]['sum'] += $a['avg'] * $a['n']; $agg[$k]['n'] += $a['n']; $agg[$k]['classes'][] = $a;
}
uasort($agg, function ($a, $b) { return ($a['term'] <=> $b['term']) ?: strnatcmp($a['title'], $b['title']); });
$agg = array_slice($agg, 0, 14, true);
$qLabels = []; $qVals = []; $qPops = []; $qCols = []; $qMin = 100;
foreach ($agg as $g) {
    $avg = $g['sum'] / max(1, $g['n']); $qMin = min($qMin, $avg);
    $rows = [];
    usort($g['classes'], function ($p, $q) { return $p['avg'] <=> $q['avg']; });
    foreach ($g['classes'] as $c) {
        $below = array_map(function ($b) { return ['n' => $b[0], 'i' => $b[1], 'd' => rtrim(rtrim(number_format($b[2], 2), '0'), '.') . ' / ' . rtrim(rtrim(number_format($b[3], 2), '0'), '.') . ' (' . round($b[4]) . '%)']; }, $c['below']);
        $rows[] = ['n' => $c['subject'] . ' · ' . $c['section'], 'i' => '', 'd' => 'avg ' . round($c['avg']) . '%' . ($below ? ' · ' . count($below) . ' below 50%' : ''),
                   'p' => $popArr($c['subject'] . ' — ' . $g['title'], $below ? count($below) . ' student' . (count($below) === 1 ? '' : 's') . ' scored below 50% (class average ' . round($c['avg']) . '%).' : 'Nobody scored below 50% (class average ' . round($c['avg']) . '%).', $below)];
    }
    $qLabels[] = substr($tl[$g['term']], 0, 1) . ' · ' . $g['title']; $qVals[] = round($avg, 1); $qCols[] = $avg < FAILING_GRADE_THRESHOLD ? '#e5534b' : null;
    $qPops[] = $pop($g['title'] . ' · ' . $tl[$g['term']], 'Average ' . round($avg) . '% over ' . count($g['classes']) . ' class' . (count($g['classes']) === 1 ? '' : 'es') . ' (lowest first). Tap a class for its students below 50%.', $rows);
}
$qymin = (int) max(0, floor(($qMin - 5) / 10) * 10); if ($qymin > 70) { $qymin = 70; }
// ---- grades, term by term ----
$gLabels = ['Prelim', 'Midterm', 'Pre-Finals', 'Final']; $avgV = []; $failV = []; $gPops = []; $gPops2 = [];
foreach ([1, 2, 3, 4] as $t) {
    $ag = $series['termAgg'][$t];
    $avgV[] = $ag['n'] ? round($ag['sum'] / $ag['n'], 1) : null;
    $failV[] = $ag['n'] ? round(count($ag['fail']) / $ag['n'] * 100, 1) : null;
    $frows = array_map(function ($f) { return ['n' => $f[0], 'i' => $f[1], 'd' => fmtGrade($f[2]) . '% · ' . $f[3] . ' · ' . $f[4]]; }, $ag['fail']);
    usort($frows, function ($a, $b) { return strcasecmp($a['n'], $b['n']); });
    $p = $ag['n'] ? $pop('Failing — ' . $gLabels[$t - 1], count($ag['fail']) . ' of ' . $ag['n'] . ' grades are below ' . (int) FAILING_GRADE_THRESHOLD . '%. Tap a name.', $frows) : '';
    $gPops[] = $p; $gPops2[] = $p;
}
$perClass = [];
foreach ($series['grades'] as $c) {
    $perClass[] = ['n' => $c['label'] . ' · ' . $c['section'], 'i' => '', 'd' => $c['term_label'] . ' — failing ' . count($c['bands']['fail']) . ' · 75–89 ' . count($c['bands']['ok']) . ' · 90+ ' . count($c['bands']['good']),
                   'p' => $popArr($c['label'] . ' · ' . $c['section'], 'Failing students — ' . $c['term_label'], array_map(function ($b) { return ['n' => $b[0], 'i' => $b[1], 'd' => fmtGrade($b[2]) . '%']; }, $c['bands']['fail']))];
}
?>
<section class="dgrid">
    <div class="dcard span-6">
        <div class="dcard-head"><div><h2>Quizzes and activities</h2><p>The class average of each one, in the order they were given. A red ring is below passing. Tap a point.</p></div></div>
        <?php if (!$qLabels): ?><div class="gcard-empty" style="margin:auto 0;">No scores have been recorded yet.</div>
        <?php else: ?><?= svgLineChart(['labels' => $qLabels, 'series' => [['name' => 'Class average', 'color' => '#6d5bb8', 'values' => $qVals, 'pops' => $qPops, 'colors' => $qCols]], 'min' => $qymin, 'max' => 100, 'ticks' => (100 - $qymin) / 10, 'unit' => '%', 'width' => 720, 'height' => 262,
                                'ref' => [['value' => FAILING_GRADE_THRESHOLD, 'label' => 'passing', 'color' => '#b3261e']]]) ?><?php endif; ?>
    </div>
    <div class="dcard span-6">
        <div class="dcard-head"><div><h2>Grades, term by term</h2><p>The average grade and how many are failing. Once every term is complete, the <strong>Final</strong> point appears. Tap a point.</p></div>
            <?php if ($perClass): ?><button type="button" class="seeall" data-pop="<?= $pop('Failing, class by class', 'The term in progress (or the Final once every term is complete).', $perClass) ?>">Per class</button><?php endif; ?></div>
        <?php if (!$series['termAgg'][1]['n']): ?><div class="gcard-empty" style="margin:auto 0;">No grades have been recorded yet.</div>
        <?php else: ?><?= svgLineChart(['labels' => $gLabels, 'series' => [['name' => 'Average grade', 'color' => '#2f9bb5', 'values' => $avgV, 'pops' => $gPops, 'values_shown' => true],
                                                                          ['name' => '% failing', 'color' => '#e5534b', 'values' => $failV, 'pops' => $gPops2, 'values_shown' => true]], 'min' => 0, 'max' => 100, 'ticks' => 5, 'unit' => '%', 'width' => 720, 'height' => 262,
                                'ref' => [['value' => FAILING_GRADE_THRESHOLD, 'label' => 'passing', 'color' => '#b3261e']]]) ?><?php endif; ?>
    </div>
</section>

<section class="dgrid">
    <?php if (!$isProf):
        $hn = historyToNow($pdo, $viewAy);
        $hL = []; $hV = []; $hP = []; $hC = [];
        foreach ($hn as $r) {
            $hL[] = $r['ay']; $hV[] = round($r['rate'], 1); $hC[] = $r['now'] ? '#f0b429' : null;
            if ($r['now']) { $hP[] = $pop('At risk right now', $r['n'] . ' of ' . $r['total'] . ' enrolled students are at high or moderate risk. Tap a name.', $riskRows(array_merge($lists['High'], $lists['Moderate']))); }
            else {
                $rows = array_map(function ($s) { return ['n' => $s['full_name'], 'i' => $s['student_id'], 'd' => ($s['case_type'] === 'du' ? 'Dropped' : 'Dismissed') . ' · ' . ($s['reason'] === 'Others' && $s['reason_other'] ? $s['reason_other'] : $s['reason'])]; }, attritionStudents($pdo, $r['ay']));
                $hP[] = $pop('Left in ' . $r['ay'], $r['n'] . ' of ' . $r['total'] . ' students dropped out or were dismissed — with the reason in their letter. Tap a name.', $rows);
            }
        }
        $hMax = max(10, (int) (ceil(max($hV ?: [0]) / 5) * 5) + 5); ?>
    <div class="dcard span-6">
        <div class="dcard-head"><div><h2>From history to now</h2><p>The attrition rate of each earlier school year (left per 100 students) — and, in amber, the share at risk right now. Tap a point.</p></div></div>
        <?= svgLineChart(['labels' => $hL, 'series' => [['name' => 'Attrition / at risk', 'color' => '#5b7fe0', 'values' => $hV, 'pops' => $hP, 'colors' => $hC, 'values_shown' => true]], 'min' => 0, 'max' => $hMax, 'ticks' => 5, 'unit' => '%', 'width' => 720, 'height' => 262]) ?>
    </div>
    <div class="dcard span-6">
        <?php $hi = $lists['High']; ?>
        <div class="dcard-head"><div><h2>Highest risk students</h2><p>The most likely to leave in <?= htmlspecialchars($viewAy) ?>. Tap one to look at the record.</p></div>
            <?php if (count($hi) > 6): ?><button type="button" class="seeall" data-pop="<?= $pop('High-risk students', count($hi) . ' students — highest first.', $riskRows($hi)) ?>">See all <?= count($hi) ?></button><?php endif; ?></div>
        <?php if (!$hi): ?><div class="gcard-empty" style="margin:auto 0;">No high-risk students.</div><?php endif; ?>
        <div class="mini-list"><?php foreach (array_slice($hi, 0, 6) as $r): ?>
            <a class="mini-row" href="student.php?id=<?= urlencode($r['id']) ?>"><span class="who"><strong><?= htmlspecialchars($r['name']) ?></strong><small><?= htmlspecialchars($r['section']) ?></small></span><span class="pts drop"><?= round($r['prob']) ?>%</span></a>
        <?php endforeach; ?></div>
    </div>
    <?php else: $attn = attentionList($pdo, $scopeClassIds, 40);
        $attRows = array_map(function ($a) { return ['n' => $a['name'], 'i' => $a['sid'], 'd' => $a['subject'] . ' · ' . implode(' · ', $a['reasons'])]; }, $attn); ?>
    <div class="dcard span-12">
        <div class="dcard-head"><div><h2>Students who need attention</h2><p>In my classes — a failing term (even before it is finished), scores below 50%, or 2 absences.</p></div>
            <?php if (count($attn) > 6): ?><button type="button" class="seeall" data-pop="<?= $pop('Students who need attention', 'In my classes. Tap a name.', $attRows) ?>">See all <?= count($attn) ?></button><?php endif; ?></div>
        <?php if (!$attn): ?><div class="gcard-empty" style="margin:auto 0;">Nobody needs attention right now.</div><?php endif; ?>
        <div class="mini-list"><?php foreach (array_slice($attn, 0, 6) as $a): ?>
            <a class="mini-row" href="student.php?id=<?= urlencode($a['sid']) ?>"><span class="who"><strong><?= htmlspecialchars($a['name']) ?></strong><small><?= htmlspecialchars($a['subject']) ?> · <?= htmlspecialchars(implode(' · ', $a['reasons'])) ?></small></span><span class="pts warn"><?= count($a['reasons']) ?></span></a>
        <?php endforeach; ?></div>
    </div>
    <?php endif; ?>
</section>
</div>
