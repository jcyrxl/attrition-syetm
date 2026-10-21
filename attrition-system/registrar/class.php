<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';
require_once __DIR__ . '/../config/grades.php';

facultyOnly();
$activePage = 'classes';
$backUrl = 'classes.php';
$cid = (int) ($_GET['id'] ?? 0);
if ($cid) { try { refreshClassRoster($pdo, $cid); } catch (PDOException $e) { /* tables not migrated: classes.php explains */ } }   // the class list builds itself
$data = $cid ? classLoadData($pdo, $cid) : null;
// a professor opens only the classes assigned to him or her; Admin and Program Head can look at any class
if (!$data || !canViewClass($data['class'])) { header('Location: classes.php'); exit; }

// Bring the students' records and risk up to date with what is recorded in this class, so what is shown here is what the whole system says.
if ($data['students'] && ($data['meetings'] || $data['scores'])) {
    try {
        $pdo->beginTransaction();
        $cache = [];
        syncStudentsFromClasses($pdo, array_map(function ($s) { return $s['student_id']; }, $data['students']), $cache);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
}

$class    = $data['class'];
$canEdit  = canEditClass($class);
$unlocked = recordUnlocked();
$ids      = array_map(function ($s) { return $s['student_id']; }, $data['students']);
$risks    = latestRisks($pdo, $ids);
$P = [];
foreach ($ids as $sid) { $P[$sid] = studentPayload($data, $sid, $risks); }

// the term being worked on: the latest one with any attendance or score (opens on its tab)
$activeTerm = 1;
foreach ($data['meetings'] as $m) { $activeTerm = max($activeTerm, (int) $m['term']); }
foreach ($data['byTerm'] as $t => $x) {
    $acts = $x['cs']; if ($x['exam']) { $acts[] = $x['exam']; }
    foreach ($acts as $a) { if (!empty($data['scores'][(int) $a['id']])) { $activeTerm = max($activeTerm, (int) $t); } }
}
$mt = [1 => [], 2 => [], 3 => []];
foreach ($data['meetings'] as $m) { $mt[max(1, min(3, (int) $m['term']))][] = $m; }
$kinds = [];
foreach ($data['byTerm'] as $t => $x) { foreach ($x['cs'] as $a) { $kinds[normComponent($a['component'])] = $a['component']; } }
$weightTotal = array_sum($data['weights']);

$notIn = [];
if ($canEdit) {
    $nq = $pdo->prepare("SELECT s.student_id, s.full_name FROM students s WHERE s.status = 'enrolled' AND s.student_id NOT IN (SELECT student_id FROM class_students WHERE class_id = ? AND excluded = 0) ORDER BY s.full_name ASC LIMIT 600");
    $nq->execute([$cid]);
    $notIn = $nq->fetchAll();
}
$fmt = function ($v, int $d = 1) { return $v === null ? '—' : fmtGrade((float) $v); };       // grades: a whole number or .5
$archived = function_exists('isClassArchived') && isClassArchived($class);
$numText = function ($v) { return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); };
$riskBadge = function (?array $r): string {
    if (!$r) { return '<span class="badge" style="background:#eef2f5;color:#8a959b;">Not scored yet</span>'; }
    $cls = ['High' => 'high-badge', 'Moderate' => 'moderate-badge', 'Low' => 'low-badge'][$r['level']];
    return '<span class="badge ' . $cls . '">' . $r['level'] . ' ' . number_format($r['prob'], 0) . '%</span>';
};
$ord = ['1st', '2nd', '3rd', '4th'];
$disabled = (!$canEdit || !$unlocked) ? 'disabled' : '';
$dropNow = array_filter($P, function ($p) { return $p['alert'] === 'drop'; });
$warnNow = array_filter($P, function ($p) { return $p['alert'] === 'warn'; });
$names = []; foreach ($data['students'] as $s) { $names[$s['student_id']] = $s['full_name']; }
$tabNames = [1 => 'Prelim', 2 => 'Midterm', 3 => 'Pre-Finals'];
$failBelow = FAILING_GRADE_THRESHOLD;
// open "Dropped (Unofficially)" records of these students (a professor takes back only his or her own)
$duCases = [];
if ($ids) {
    $dq = $pdo->prepare("SELECT id, student_id, recorded_by_user FROM student_cases WHERE case_type = 'du' AND returned_on IS NULL AND student_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $dq->execute($ids);
    foreach ($dq->fetchAll() as $r) { $duCases[$r['student_id']] = $r; }
}
$goneTag = function (array $s): string {
    return $s['status'] === 'enrolled' ? '' : '<span class="gone-tag" style="--c:' . (STATUS_COLORS[$s['status']] ?? '#8a959b') . '">' . htmlspecialchars(STUDENT_STATUSES[$s['status']] ?? $s['status']) . '</span>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($class['subject_name']) ?> | Classes</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
    <link rel="stylesheet" href="../assets/class-ui.css?v=2">
</head>
<body>

<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <div id="sheet" data-class="<?= $cid ?>" data-edit="<?= $canEdit ? '1' : '0' ?>" data-unlocked="<?= $unlocked ? '1' : '0' ?>" data-low="<?= LOW_SCORE_PERCENT ?>" data-active="t<?= $activeTerm ?>" data-fail="<?= $failBelow ?>" data-title="<?= htmlspecialchars($class['subject_name'] . ' ' . $class['section']) ?>">
    <section class="cls-head">
        <div class="cls-head-l">
            <a href="classes.php" class="back-link" title="<?= isFaculty() ? 'My classes' : 'All classes' ?>"><i class="fa-solid fa-arrow-left"></i></a>
            <div>
                <h1><?= htmlspecialchars($class['subject_code'] ?: $class['subject_name']) ?> <span>· <?= htmlspecialchars($class['section']) ?></span></h1>
                <p><?= $class['subject_code'] ? htmlspecialchars($class['subject_name']) . ' · ' : '' ?><?= $ord[classSectionYear($class['section']) - 1] ?> Year · <?= (int) $class['semester'] === 1 ? '1st' : '2nd' ?> Semester <?= htmlspecialchars($class['academic_year']) ?> · <?= count($ids) ?> student<?= count($ids) === 1 ? '' : 's' ?> · <?= htmlspecialchars($class['owner_name'] ?: 'Not assigned to a professor yet') ?></p>
            </div>
        </div>
        <div class="cls-head-r">
            <span class="cls-saved" id="saveState"></span>
            <?php if ($canEdit): ?>
            <div class="cls-lockline" id="lockBanner" <?= $unlocked ? 'hidden' : '' ?>><span><i class="fa-solid fa-lock"></i> View-only</span>
                <button type="button" class="btn-primary" id="unlockBtn" title="Stray clicks and keystrokes can’t change any grade until you unlock with your PIN"><i class="fa-solid fa-lock-open"></i> Unlock to edit</button></div>
            <?php endif; ?>
            <button type="button" class="btn-secondary" data-act="download-csv"><i class="fa-solid fa-file-arrow-down"></i> Export CSV</button>
        </div>
    </section>


    <?php if ($archived): ?>
        <div class="cls-banner"><i class="fa-solid fa-box-archive"></i><span>This class belongs to an <strong>earlier semester</strong> — it is archived, so it can only be viewed.</span></div>
    <?php elseif (!$canEdit): ?>
        <div class="cls-banner"><i class="fa-solid fa-eye"></i>
            <span><?= isFaculty() ? 'This class is not assigned to you.' : 'You are looking at this class — <strong>view only</strong>.' ?>
            <?= $class['owner_user_id'] ? 'Only <strong>' . htmlspecialchars($class['owner_name']) . '</strong> records here.' : 'The Program Head has not assigned it to a professor yet, so nobody can record in it.' ?></span></div>
    <?php else: ?>
    <?php endif; ?>


    <div class="cls-bar">
        <div class="cls-tabs" role="tablist">
            <?php foreach ($tabNames as $t => $label): ?>
                <button type="button" data-tab="t<?= $t ?>" class="<?= $t === $activeTerm ? 'on' : '' ?>"><?= $label ?> <small><?= (int) (TERM_WEIGHTS[$t] * 100) ?>%</small></button>
            <?php endforeach; ?>
            <button type="button" data-tab="summary"><i class="fa-solid fa-table-list"></i> Summary <em class="tab-badge" id="attnChip" hidden>0</em></button>
        </div>
        <div class="cls-bar-right">
            <div class="cls-alertbox" id="absBox" tabindex="0" <?= ($dropNow || $warnNow) ? '' : 'hidden' ?>>
                <i class="fa-solid fa-user-clock"></i> <span><b id="absCount"><?= count($dropNow) + count($warnNow) ?></b> absence alert<?= (count($dropNow) + count($warnNow)) === 1 ? '' : 's' ?></span>
                <div class="abs-pop"><strong>Attendance alert</strong> — absences add up from Prelim to Pre-Finals; <strong>3 or more = Dropped (Unofficially)</strong>.
                    <span id="absList"><?php<?php
                $bits = [];
                foreach ($dropNow as $sid => $p) { $bits[] = '<b class="d">' . htmlspecialchars($names[$sid]) . ' (' . fmtPoints($p['att']['points']) . ' — limit reached)</b>'; }
                foreach ($warnNow as $sid => $p) { $bits[] = '<b class="w">' . htmlspecialchars($names[$sid]) . ' (' . fmtPoints($p['att']['points']) . ')</b>'; }
                echo implode(' · ', $bits);
            ?></span></div>
            </div>
            <div class="cls-search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="rowFind" placeholder="Find a student" autocomplete="off"></div>
            <label class="cls-pill" title="Show only the students who need attention"><input type="checkbox" id="onlyAttn"><i class="fa-solid fa-triangle-exclamation"></i> Needs attention</label>
            <button type="button" class="cls-iconbtn" data-tab="setup" title="Grading setup — how much each kind of activity counts"><i class="fa-solid fa-scale-balanced"></i></button>
            <button type="button" class="cls-iconbtn" data-tab="list" title="Class list"><i class="fa-solid fa-user-group"></i></button>
        </div>
    </div>

    <!-- ================= ONE TAB PER TERM ================= -->
    <?php foreach ($tabNames as $t => $label): $ex = $data['byTerm'][$t]['exam']; $cs = $data['byTerm'][$t]['cs']; ?>
    <section class="cls-pane <?= $t === $activeTerm ? 'on' : '' ?>" id="pane-t<?= $t ?>" data-term="<?= $t ?>" data-view="scores">
        <div class="cls-subbar">
            <div class="cls-seg" role="tablist"><button type="button" class="on" data-view="scores"><i class="fa-solid fa-list-check"></i> Scores</button><button type="button" data-view="att"><i class="fa-solid fa-calendar-check"></i> Attendance</button></div>
            <?php if ($canEdit): ?>
            <details class="pop">
                <summary class="cls-chipbtn"><i class="fa-solid fa-calendar-plus"></i> Meeting</summary>
                <div class="pop-card">
                    <strong>Add a meeting to <?= $label ?></strong>
                    <input type="date" id="mtDate<?= $t ?>" class="rec" value="<?= date('Y-m-d') ?>" <?= $disabled ?>>
                    <button type="button" class="btn-primary rec" data-act="add-meeting" data-term="<?= $t ?>" <?= $disabled ?>>Add meeting</button>
                </div>
            </details>
            <details class="pop">
                <summary class="cls-chipbtn"><i class="fa-solid fa-table-columns"></i> Column</summary>
                <div class="pop-card">
                    <strong>Add a column to <?= $label ?></strong>
                    <label>What is it?</label>
                    <select id="colType<?= $t ?>" class="rec col-type" data-term="<?= $t ?>" <?= $disabled ?>><?php foreach (ACTIVITY_TYPES as $ty): ?><option value="<?= $ty ?>"><?= $ty ?></option><?php endforeach; ?><option value="__other">Other (type the name)…</option></select>
                    <input type="text" id="colOther<?= $t ?>" class="rec" placeholder="Name, e.g. Case study" hidden <?= $disabled ?>>
                    <label>Title <span class="opt">(optional — Quiz 1, Quiz 2 … are numbered for you)</span></label>
                    <input type="text" id="colTitle<?= $t ?>" class="rec" <?= $disabled ?>>
                    <label>Items <span class="opt">(whole number or .5)</span></label>
                    <input type="number" id="colItems<?= $t ?>" class="rec" min="0.5" step="0.5" placeholder="e.g. 10" <?= $disabled ?>>
                    <button type="button" class="btn-primary rec" data-act="add-column" data-term="<?= $t ?>" <?= $disabled ?>>Add column</button>
                </div>
            </details>
            <?php endif; ?>
            <span class="cls-legend"><b>/</b> present · <b>A</b> absent 1 · <b>L</b> late ½ · <b>E</b> excused ½ · red = needs attention</span>
        </div>
        <div class="sheet-scroll"><table class="sheet" data-term="<?= $t ?>">
            <thead>
            <tr class="grp">
                <th class="sticky" rowspan="2">Student <small><?= count($data['students']) ?></small></th>
                <th class="calc" rowspan="2" title="Absences of the whole semester">Abs.</th>
                <?php if ($mt[$t]): ?><th class="g g-att col-att" colspan="<?= count($mt[$t]) ?>"><i class="fa-solid fa-calendar-check"></i> Attendance</th><?php endif; ?>
                <th class="g g-cs col-sc" colspan="<?= count($cs) + 1 ?>"><i class="fa-solid fa-list-check"></i> Class standing · 60%</th>
                <th class="g g-ex col-sc" colspan="2"><i class="fa-solid fa-file-pen"></i> Exam · 40%</th>
                <th class="g g-tg" colspan="2"><?= $label ?></th>
            </tr>
            <tr>
                <?php foreach ($mt[$t] as $m): ?>
                    <th class="mh col-att"><div><?= date('M j', strtotime($m['meeting_date'])) ?></div>
                        <?php if ($canEdit): ?><a href="#" class="rec-link" data-act="all-present" data-mid="<?= (int) $m['id'] ?>" title="Mark everyone present">all /</a><a href="#" class="rec-link x" data-act="del-meeting" data-mid="<?= (int) $m['id'] ?>" title="Delete this meeting">✕</a><?php endif; ?></th>
                <?php endforeach; ?>
                <?php foreach ($cs as $a): ?>
                    <th class="ah col-sc"><small><?= htmlspecialchars($a['component']) ?></small><div><?= htmlspecialchars($a['title']) ?></div>
                        <span class="out">out of <input type="number" class="rec items-input" data-aid="<?= (int) $a['id'] ?>" value="<?= $numText($a['items']) ?>" min="1" <?= $disabled ?>></span>
                        <?php if ($canEdit): ?><a href="#" class="rec-link x" data-act="del-activity" data-aid="<?= (int) $a['id'] ?>" title="Remove this column">✕</a><?php endif; ?></th>
                <?php endforeach; ?>
                <th class="calc col-sc">%</th>
                <th class="ah ex col-sc"><small>Exam</small><div><?= htmlspecialchars($ex['title'] ?? 'Exam') ?></div>
                    <span class="out">out of <input type="number" class="rec items-input" data-aid="<?= (int) ($ex['id'] ?? 0) ?>" value="<?= $numText($ex['items'] ?? 50) ?>" min="1" <?= $disabled ?>></span></th>
                <th class="calc col-sc">%</th>
                <th class="calc strong">Grade</th>
                <th class="calc" title="Needs attention">!</th>
            </tr></thead>
            <tbody>
                <?php if (!$data['students']): ?><tr><td class="sticky" colspan="2" style="padding:18px;color:#8a959b;">No students yet — they appear automatically when students who take this subject are enrolled.</td></tr><?php endif; ?>
                <?php foreach ($data['students'] as $s): $sid = $s['student_id']; $p = $P[$sid]; $h = htmlspecialchars($sid); $tm = $p['terms'][$t]; ?>
                <tr data-sid="<?= $h ?>" class="<?= $p['attention'] ? 'attn' : '' ?> <?= $s['status'] !== 'enrolled' ? 'gone' : '' ?>">
                    <td class="sticky name"><strong><?= htmlspecialchars($s['full_name']) ?></strong><small><?= $h ?></small><?= $goneTag($s) ?></td>
                    <td class="calc abs <?= $p['alert'] ?: '' ?>" data-cell="abs" data-sid="<?= $h ?>" title="Absent <?= $p['att']['absent'] ?> · Late <?= $p['att']['late'] ?> · Excused <?= $p['att']['excused'] ?>"><?= $p['att']['total'] ? fmtPoints($p['att']['points']) : '—' ?></td>
                    <?php foreach ($mt[$t] as $m): $st = $data['att'][(int) $m['id']][$sid] ?? 'P'; ?>
                        <td class="c col-att"><select class="rec att-sel st-<?= $st ?>" data-mid="<?= (int) $m['id'] ?>" data-sid="<?= $h ?>" data-prev="<?= $st ?>" <?= $disabled ?>>
                            <?php foreach (ATTENDANCE_LABELS as $code => $lbl): ?><option value="<?= $code ?>" <?= $code === $st ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?></select></td>
                    <?php endforeach; ?>
                    <?php foreach ($cs as $a): $aid = (int) $a['id']; $sc = $data['scores'][$aid][$sid] ?? null; $pc = $p['pct'][$aid] ?? null; ?>
                        <td class="c col-sc"><input type="number" class="rec score <?= ($pc !== null && $pc < LOW_SCORE_PERCENT) ? 'low' : '' ?>" step="0.5" min="0" max="<?= (float) $a['items'] ?>" data-aid="<?= $aid ?>" data-sid="<?= $h ?>" data-prev="<?= $sc === null ? '' : $numText($sc) ?>" value="<?= $sc === null ? '' : $numText($sc) ?>" <?= $disabled ?>></td>
                    <?php endforeach; ?>
                    <td class="calc col-sc" data-cell="cs" data-sid="<?= $h ?>" data-term="<?= $t ?>"><?= $fmt($tm['cs']) ?><?= $tm['cs'] !== null ? '%' : '' ?></td>
                    <?php if ($ex): $aid = (int) $ex['id']; $sc = $data['scores'][$aid][$sid] ?? null; $pc = $p['pct'][$aid] ?? null; ?>
                        <td class="c col-sc"><input type="number" class="rec score <?= ($pc !== null && $pc < LOW_SCORE_PERCENT) ? 'low' : '' ?>" step="0.5" min="0" max="<?= (float) $ex['items'] ?>" data-aid="<?= $aid ?>" data-sid="<?= $h ?>" data-prev="<?= $sc === null ? '' : $numText($sc) ?>" value="<?= $sc === null ? '' : $numText($sc) ?>" <?= $disabled ?>></td>
                    <?php else: ?><td class="col-sc"></td><?php endif; ?>
                    <td class="calc col-sc" data-cell="exam" data-sid="<?= $h ?>" data-term="<?= $t ?>"><?= $fmt($tm['exam']) ?><?= $tm['exam'] !== null ? '%' : '' ?></td>
                    <td class="calc strong <?= ($tm['grade'] !== null && $tm['grade'] < $failBelow) ? 'bad' : '' ?>" data-cell="grade" data-sid="<?= $h ?>" data-term="<?= $t ?>"><?= $fmt($tm['grade']) ?><?= $tm['grade'] !== null ? '%' . ($tm['prov'] ? '*' : '') : '' ?></td>
                    <td class="calc flag" data-cell="flag" data-sid="<?= $h ?>" title="<?= htmlspecialchars(implode(' · ', $p['attention'])) ?>"><?= $p['attention'] ? '<i class="fa-solid fa-triangle-exclamation"></i>' : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="avg">
                <td class="sticky">Class average</td><td></td>
                <?php foreach ($mt[$t] as $m): ?><td class="col-att"></td><?php endforeach; ?>
                <?php foreach ($cs as $a): ?><td class="c col-sc" data-avg="a<?= (int) $a['id'] ?>"></td><?php endforeach; ?>
                <td class="c col-sc" data-avg="cs<?= $t ?>"></td><td class="c col-sc" data-avg="a<?= (int) ($ex['id'] ?? 0) ?>"></td><td class="c col-sc" data-avg="exam<?= $t ?>"></td><td class="c strong" data-avg="grade<?= $t ?>"></td><td></td>
            </tr></tfoot>
        </table></div>
        <p class="cls-hint" style="margin:8px 2px;">* so far — the exam is not in yet. A blank score = <em>not entered yet</em>; type 0 for a missed one. Scores are whole numbers or .5. <strong>Enter</strong> / <strong>↓</strong> go to the next student; you can <strong>paste a column from Excel</strong>.</p>
    </section>
    <?php endforeach; ?>

    <!-- ================= SUMMARY ================= -->
    <section class="cls-pane" id="pane-summary">
        <div class="cls-tools" style="margin-bottom:8px;">
            <span class="cls-hint"><strong>Grade so far</strong> uses the terms that exist and flags a failing student at once. The <strong>Final Grade</strong> appears only when Prelim, Midterm and Pre-Finals are all complete (class standing and exam in each).</span>
            <button type="button" class="btn-secondary" data-act="download-csv" style="margin-left:auto;"><i class="fa-solid fa-file-arrow-down"></i> Download (CSV)</button>
        </div>
        <div class="sheet-scroll"><table class="sheet" id="summaryTable">
            <thead><tr><th class="sticky">Student</th>
                <?php foreach ($tabNames as $t => $label): ?><th class="calc"><?= $label ?> <small><?= (int) (TERM_WEIGHTS[$t] * 100) ?>%</small></th><?php endforeach; ?>
                <th class="calc strong">Grade so far</th><th class="calc strong">Final Grade</th><th class="calc">Absences</th><th class="calc">Risk</th><th class="calc" style="min-width:270px;">Needs attention</th><th class="calc" style="min-width:210px;">Status</th></tr></thead>
            <tbody>
                <?php foreach ($data['students'] as $s): $sid = $s['student_id']; $p = $P[$sid]; $h = htmlspecialchars($sid); ?>
                <tr data-sid="<?= $h ?>" class="<?= $p['attention'] ? 'attn' : '' ?> <?= $s['status'] !== 'enrolled' ? 'gone' : '' ?>">
                    <td class="sticky name"><strong><?= htmlspecialchars($s['full_name']) ?></strong><small><?= $h ?></small><?= $goneTag($s) ?></td>
                    <?php foreach ($tabNames as $t => $label): $tm = $p['terms'][$t]; ?>
                        <td class="calc <?= ($tm['grade'] !== null && $tm['grade'] < $failBelow) ? 'bad' : '' ?>" data-cell="s-term" data-sid="<?= $h ?>" data-term="<?= $t ?>"><?= $fmt($tm['grade']) ?><?= $tm['grade'] !== null ? '%' . ($tm['prov'] ? '*' : '') : '' ?></td>
                    <?php endforeach; ?>
                    <td class="calc strong <?= ($p['running'] !== null && $p['running'] < $failBelow) ? 'bad' : '' ?>" data-cell="running" data-sid="<?= $h ?>"><?= $fmt($p['running']) ?><?= $p['running'] !== null ? '%' : '' ?></td>
                    <td class="calc strong" data-cell="final" data-sid="<?= $h ?>"><?= $p['final'] !== null ? $fmt($p['final']) . '%' : '<span class="notyet">Not yet</span>' ?></td>
                    <td class="calc abs <?= $p['alert'] ?: '' ?>" data-cell="abs" data-sid="<?= $h ?>" title="Absent <?= $p['att']['absent'] ?> · Late <?= $p['att']['late'] ?> · Excused <?= $p['att']['excused'] ?>"><?= $p['att']['total'] ? fmtPoints($p['att']['points']) : '—' ?></td>
                    <td class="calc" data-cell="risk" data-sid="<?= $h ?>"><?= $riskBadge($p['risk']) ?></td>
                    <td class="calc attn-text" data-cell="attn" data-sid="<?= $h ?>" data-full="<?= htmlspecialchars(implode(' · ', $p['attention'])) ?>"><?= $p['attention'] ? '<span class="attn-clamp"><i class="fa-solid fa-triangle-exclamation"></i> ' . htmlspecialchars(implode(' · ', $p['attention'])) . '</span>' : '<span style="color:#8a959b;">—</span>' ?></td>
                    <td class="calc act">
                        <?php if ($s['status'] !== 'enrolled'): ?>
                            <span class="status-pill" style="--c:<?= STATUS_COLORS[$s['status']] ?? '#8a959b' ?>;"><?= htmlspecialchars(STUDENT_STATUSES[$s['status']] ?? $s['status']) ?></span>
                            <?php if ($canEdit && isset($duCases[$sid]) && $duCases[$sid]['recorded_by_user'] === ($_SESSION['user_id'] ?? '')): ?>
                                <button type="button" class="du-btn rec" data-act="undo-du" data-case="<?= (int) $duCases[$sid]['id'] ?>" data-name="<?= htmlspecialchars($s['full_name']) ?>"><i class="fa-solid fa-rotate-left"></i> Undo</button>
                            <?php endif; ?>
                        <?php elseif ($canEdit): ?>
                            <button type="button" class="du-btn rec <?= $p['alert'] === 'drop' ? 'urgent' : '' ?>" data-act="mark-du" data-sid="<?= $h ?>" data-name="<?= htmlspecialchars($s['full_name']) ?>" title="You decide: the student stopped attending"><i class="fa-solid fa-user-slash"></i> Mark Dropped (Unofficially)</button>
                        <?php else: ?><span style="color:#8a959b;">Enrolled</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="cls-hint" style="margin:8px 2px;">The risk uses this class <em>and</em> the student's earlier years, and is predicted again every time you save.</p>
    </section>

    <!-- ================= GRADING SETUP ================= -->
    <section class="cls-pane" id="pane-setup">
        <div class="cls-panel-inner">
            <h3 class="cls-h">How much each kind of activity counts <span class="cls-hint"><?= $data['weights'] ? '(weighted)' : '(total score method)' ?></span></h3>
            <p class="cls-hint" style="margin:6px 0 10px;">By default the class standing is <strong>total score ÷ total items</strong> of every column, whatever its kind. If your grading is, for example, <em>Quiz 40%, Activity 30%, Recitation 30%</em>, set it here and it is used for every term of this class.</p>
            <?php if (!$kinds): ?><p class="cls-hint">Add some columns first — the kinds you use will be listed here.</p>
            <?php else: ?>
                <div class="w-grid">
                    <?php foreach ($kinds as $k => $display): ?>
                        <label><?= htmlspecialchars($display) ?> <input type="number" class="rec w-input" data-comp="<?= htmlspecialchars($display) ?>" min="0" max="100" step="1" value="<?= isset($data['weights'][$k]) ? $numText($data['weights'][$k]) : '' ?>" <?= $disabled ?>> %</label>
                    <?php endforeach; ?>
                </div>
                <p class="cls-hint" style="margin:8px 0;">Total: <strong><?= $weightTotal ? $numText($weightTotal) . '%' : '—' ?></strong><?= ($weightTotal && abs($weightTotal - 100) > 0.01) ? ' (it does not have to be 100 — the kinds are scaled to 100%)' : '' ?>. A kind with no weight is not counted while weights are used.</p>
                <?php if ($canEdit): ?><button type="button" class="btn-primary rec" data-act="save-weights" <?= $disabled ?>>Save weights</button>
                    <button type="button" class="btn-secondary rec" data-act="clear-weights" <?= $disabled ?>>Use total score (no weights)</button><?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- ================= CLASS LIST ================= -->
    <section class="cls-pane" id="pane-list">
        <div class="cls-panel-inner">
            <h3 class="cls-h">Class list <span class="cls-hint">(<?= count($ids) ?> — filled in automatically from the subjects each student takes)</span></h3>
            <p class="cls-hint" style="margin:6px 0 12px;">Students are put in this class automatically. To add students, use <a href="students.php">Students → Add students</a>.</p>
            <div class="table-wrap" style="margin:0;"><table>
                <thead><tr><th>Student</th><th>ID</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($data['students'] as $s): ?>
                        <tr><td><strong><?= htmlspecialchars($s['full_name']) ?></strong></td><td><?= htmlspecialchars($s['student_id']) ?></td>
                            <td><?php if ($canEdit): ?><button type="button" class="btn-icon btn-danger rec" data-act="remove-student" data-sid="<?= htmlspecialchars($s['student_id']) ?>" title="Take out of this class" <?= $disabled ?>><i class="fa-solid fa-user-minus"></i></button><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$data['students']): ?><tr><td colspan="3">No students yet.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </section>

    </div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<script>document.body.classList.add("sidebar-collapsed");</script>
<script src="../assets/class-sheet.js?v=6"></script>
</body>
</html>
