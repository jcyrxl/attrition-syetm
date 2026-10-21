<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/predict.php';
require_once __DIR__ . '/../config/importer.php';

// Only Faculty save student data. Admin has no student pages.
if (!canManageData()) { header('Location: ' . (isAdmin() ? 'users.php' : 'dashboard.php')); exit; }   // Faculty only (optional import)

$activePage = 'import';
$flash = null;
$stage = 'upload';          // upload -> preview -> done
$imp = null;
$result = null;
$fileProblems = [];

$hasEval  = function (array $r): bool { return count($r['subjects']) > 0; };
$hasEnrol = function (array $r): bool { return $r['e_ay'] !== '' || $r['e_section'] !== '' || count($r['e_subjects']) > 0; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ------------------------------------------------------------
    // Step 1: read the PEF files and show what would be saved (nothing is saved yet)
    // ------------------------------------------------------------
    if ($action === 'preview') {
        $files = [];
        if (!empty($_FILES['pef']['name']) && is_array($_FILES['pef']['name'])) {
            foreach ($_FILES['pef']['name'] as $i => $nm) {
                if ($_FILES['pef']['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; }
                $files[] = ['name' => $nm, 'tmp' => $_FILES['pef']['tmp_name'][$i], 'error' => $_FILES['pef']['error'][$i], 'size' => $_FILES['pef']['size'][$i]];
            }
        }
        if (!$files) {
            $flash = ['type' => 'error', 'msg' => 'Please choose at least one PEF file (.xlsx).'];
        } elseif (count($files) > IMPORT_MAX_FILES) {
            $flash = ['type' => 'error', 'msg' => 'Too many files at once (' . IMPORT_MAX_FILES . ' at most). Upload them in smaller batches.'];
        } else {
            $recs = [];
            foreach ($files as $f) {
                if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > IMPORT_MAX_BYTES) { $fileProblems[] = $f['name'] . ' — the file is too big (3 MB at most).'; continue; }
                if ($f['error'] !== UPLOAD_ERR_OK) { $fileProblems[] = $f['name'] . ' — the upload did not finish.'; continue; }
                try {
                    $rec = importParsePef($f['tmp'], $f['name']);
                    $rec['n'] = count($recs) + 1;
                    $recs[] = $rec;
                } catch (RuntimeException $e) {
                    $fileProblems[] = $e->getMessage();
                }
            }
            if (!$recs) {
                $flash = ['type' => 'error', 'msg' => 'None of the files could be read.'];
            } else {
                $seen = [];
                foreach ($recs as $k => $r) { importPefCheck($recs[$k], $seen); }
                $_SESSION['import'] = ['token' => bin2hex(random_bytes(8)), 'recs' => $recs, 'forced' => []];
                $imp = $_SESSION['import'];
                $stage = 'preview';
            }
        }
    }

    // ------------------------------------------------------------
    // Step 2: save — only when EVERYTHING is complete, and only after the Faculty member's own Student Data PIN.
    // All or nothing: if any file is incomplete (or the system finds a clash) nothing is saved and the cards stay open for correction.
    // ------------------------------------------------------------
    elseif ($action === 'commit') {
        $imp = $_SESSION['import'] ?? null;
        if (!$imp || !hash_equals($imp['token'], (string) ($_POST['token'] ?? ''))) {
            $flash = ['type' => 'error', 'msg' => 'That preview has expired — please upload the files again.'];
            $imp = null;
        } else {
            $check = checkCredential($pdo, 'pin', $_POST['credential'] ?? '');
            if (!$check['ok']) {
                if (!empty($check['logout'])) { endSessionAndRedirectLocked(); }
                $flash = ['type' => 'error', 'msg' => $check['error']];
                $stage = 'preview';
            } else {
                $addNew = !empty($_POST['add_new']);
                $edits = (isset($_POST['fix']) && is_array($_POST['fix'])) ? $_POST['fix'] : [];
                $recs = $imp['recs'];
                foreach ($recs as $k => $r) {
                    if (isset($edits[$r['n']]) && is_array($edits[$r['n']])) { importPefApplyEdit($recs[$k], $edits[$r['n']]); }
                }
                $seen = [];
                foreach ($recs as $k => $r) { importPefCheck($recs[$k], $seen); }
                $imp['recs'] = $recs;
                $imp['forced'] = [];
                $_SESSION['import'] = $imp;                       // keep what was typed, even if nothing is saved
                $stage = 'preview';

                $incomplete = count(array_filter($recs, function ($r) { return !empty($r['errors']); }));
                if ($incomplete > 0) {
                    $flash = ['type' => 'error', 'msg' => 'Nothing was imported: ' . $incomplete . ' file' . ($incomplete === 1 ? ' is' : 's are') . ' still incomplete. Complete the red cards first.'];
                } else {
                    $by = $_SESSION['full_name'] ?? $_SESSION['user_id'];
                    try {
                        $pdo->beginTransaction();
                        $saver = importMakeSaver($pdo, $addNew);
                        $saved = []; $affected = []; $forced = [];
                        foreach ($recs as $r) {
                            $res = $saver($r);
                            if ($res['ok']) { $saved[] = ['res' => $res, 'rec' => $r]; $affected[$res['student_id']] = $res['status']; }
                            else { $forced[$r['n']] = $res['reason']; }
                        }
                        if ($forced) {
                            $pdo->rollBack();
                            $imp['forced'] = $forced;
                            $_SESSION['import'] = $imp;
                            $flash = ['type' => 'error', 'msg' => 'Nothing was imported: the system found ' . count($forced) . ' problem' . (count($forced) === 1 ? '' : 's') . ' (shown in red). Correct them and try again.'];
                        } else {
                            $pdo->commit();
                            $coeffs = getModelCoefficients($pdo);
                            $predicted = 0;
                            foreach ($affected as $affId => $affStatus) {
                                if ($affStatus === 'enrolled' && runPredictionForStudent($pdo, (string) $affId, $coeffs)) { $predicted++; }
                            }
                            // every student is put in the class of each subject on the PEF
                            try {
                                $aysDone = array_unique(array_filter(array_map(function ($s) { return $s['rec']['e_ay']; }, $saved)));
                                foreach ($aysDone as $ayDone) { refreshAllRosters($pdo, $ayDone); }
                            } catch (PDOException $e) { /* Classes tables not migrated yet — the Classes page explains */ }
                            usort($saved, function ($a, $b) { return strcasecmp($a['res']['name'], $b['res']['name']); });
                            $result = ['saved' => $saved, 'predicted' => $predicted, 'created' => count(array_filter($saved, function ($s) { return $s['res']['new']; }))];
                            unset($_SESSION['import']);
                            $imp = null;
                            $stage = 'done';
                            $flash = null;
                        }
                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) { $pdo->rollBack(); }
                        $flash = ['type' => 'error', 'msg' => 'Nothing was saved because of a database error: ' . $e->getMessage()];
                    }
                }
            }
        }
    }
    elseif ($action === 'cancel') { unset($_SESSION['import']); }
}

// ---- Preview: what the system knows about each file (the same rules the save step applies) ----
$notes = []; $kind = []; $serverOnly = []; $known = []; $cntExisting = $cntNew = $cntProblem = 0;
if ($stage === 'preview' && $imp) {
    $ids = array_values(array_unique(array_filter(array_map(function ($r) { return $r['student_id']; }, $imp['recs']))));
    $recMap = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT student_id, full_name, status FROM students WHERE student_id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll() as $k) { $known[$k['student_id']] = $k; }
        $st = $pdo->prepare("SELECT student_id, year_level, academic_year FROM student_records WHERE student_id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll() as $x) { $recMap[$x['student_id']][] = $x; }
    }
    $nameIndex = [];
    foreach ($pdo->query("SELECT student_id, full_name FROM students") as $ns) { $nameIndex[importNameKey($ns['full_name'])] = $ns; }

    foreach ($imp['recs'] as $r) {
        $n = $r['n']; $sid = $r['student_id'];
        $list = [];
        foreach ($r['errors'] as $m)   { $list[] = ['err', $m]; }
        foreach ($r['warnings'] as $m) { $list[] = ['warn', $m]; }
        $srv = false;
        $k = $known[$sid] ?? null;
        if (!empty($imp['forced'][$n])) { $list[] = ['err', $imp['forced'][$n]]; $srv = true; }
        if (!$r['errors']) {
            if ($k) {
                if ($r['name'] !== '' && !importNamesMatch($r['name'], $k['full_name'])) { $list[] = ['warn', 'The name differs from the system (' . $k['full_name'] . ') — check the Student No.']; }
                if ($k['status'] !== 'enrolled') { $list[] = ['warn', 'System status is ' . (STUDENT_STATUSES[$k['status']] ?? $k['status']) . ' — their current year is not changed.']; }
                $pairs = [];
                if ($hasEval($r))  { $pairs[] = [(int) $r['year'], $r['ay']]; }
                if ($hasEnrol($r)) { $pairs[] = [(int) $r['e_year'], $r['e_ay']]; }
                foreach ($pairs as [$py, $pay]) {
                    foreach ($recMap[$sid] ?? [] as $x) {
                        if ($x['academic_year'] === $pay && (int) $x['year_level'] !== $py) { $list[] = ['err', 'Already has Year ' . (int) $x['year_level'] . ' recorded in ' . $pay . '.']; $srv = true; break 2; }
                        if ((int) $x['year_level'] === $py && $x['academic_year'] !== $pay) { $list[] = ['err', 'Already has Year ' . $py . ' recorded for ' . $x['academic_year'] . ', but this form says ' . $pay . '.']; $srv = true; break 2; }
                    }
                }
            } else {
                $nk = importNameKey($r['name']);
                if ($nk !== '' && isset($nameIndex[$nk])) { $list[] = ['err', 'A student with this name already exists (Student No. ' . $nameIndex[$nk]['student_id'] . ') — is this Student No. a typo?']; $srv = true; }
            }
        }
        $isProblem = false;
        foreach ($list as $l) { if ($l[0] === 'err') { $isProblem = true; } }
        $notes[$n] = $list; $serverOnly[$n] = $srv && !$r['errors'];
        $kind[$n] = $isProblem ? 'problem' : ($k ? 'existing' : 'new');
        if ($isProblem) { $cntProblem++; } elseif ($k) { $cntExisting++; } else { $cntNew++; }
    }
}
$yearOpts = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
$semOpts  = [1 => '1st Semester', 2 => '2nd Semester'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Records | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
    <style>
        .imp-wrap { max-width: 1100px; margin: 0 30px 30px; }
        .imp-card { background: #fff; border: 1px solid #e8edf0; border-radius: 14px; padding: 26px; box-shadow: 0 3px 10px rgba(0,0,0,.03); margin-bottom: 20px; }
        .imp-card h2 { font-size: 18px; color: #064a5e; margin-bottom: 8px; }
        .imp-card label { display: block; font-size: 13px; font-weight: 600; color: #455a64; margin-bottom: 5px; }
        .drop-zone { border: 2px dashed #7fc3d6; background: #f3fbfd; border-radius: 14px; padding: 26px; text-align: center; color: #064a5e; }
        .drop-zone i { font-size: 34px; color: #0b8fa3; margin-bottom: 8px; }
        .count-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 16px 0; }
        .count-box { border-radius: 12px; padding: 14px; text-align: center; }
        .count-box strong { display: block; font-size: 26px; }
        .count-box span { font-size: 12.5px; }
        .count-box.ok { background: #e6f6fa; color: #075f73; } .count-box.new { background: #e8f0fe; color: #2a55b8; } .count-box.bad { background: #fff0f0; color: #c62828; }
        .meta-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .meta-chip { background: #e8f6fa; color: #075f73; border-radius: 20px; padding: 6px 14px; font-size: 13px; font-weight: 600; }
        .prev-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .prev-table th { text-align: left; padding: 8px 6px; background: #f6f9fc; color: #64727a; border-bottom: 2px solid #edf0f2; white-space: nowrap; }
        .prev-table td { padding: 8px 6px; border-bottom: 1px solid #f1f3f4; color: #3d4a52; vertical-align: top; }
        .note-err { color: #c62828; font-size: 12.5px; margin-top: 3px; } .note-warn { color: #a96b00; font-size: 12.5px; margin-top: 3px; }
        .attn-title { font-size: 15px; color: #c62828; margin: 18px 0 6px; display: flex; gap: 8px; align-items: center; }
        .attn-help { font-size: 13px; color: #64727a; margin-bottom: 12px; line-height: 1.6; }
        .all-good { background: #e6f6fa; color: #075f73; border-radius: 10px; padding: 14px 16px; margin: 14px 0; font-weight: 600; }
        .ok-list { margin-top: 18px; background: #f8fafc; border: 1px solid #e6edf2; border-radius: 10px; padding: 12px 16px; }
        .ok-list summary { cursor: pointer; font-weight: 600; color: #064a5e; font-size: 14.5px; }
        .confirm-box { background: #f3fbfd; border: 1px solid #bfe3ee; border-radius: 12px; padding: 20px; margin-top: 18px; }
        .confirm-box .row { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; margin-top: 14px; }
        .confirm-box button[disabled] { opacity: .45; cursor: not-allowed; }
        .blocked-hint { display: none; margin-top: 10px; color: #c62828; font-size: 13.5px; font-weight: 600; }
        /* a PEF that needs completing */
        .attn-row { border: 1px solid #f3c4c4; border-left: 6px solid #e53935; border-radius: 12px; background: #fff8f8; padding: 16px 18px; margin-bottom: 14px; }
        .attn-row.fixed { border-color: #bfe3d0; border-left-color: #16a05d; background: #f4fbf7; }
        .attn-row.recheck { border-color: #f0dcae; border-left-color: #e6a100; background: #fffaf0; }
        .attn-head { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
        .bang { display: inline-flex; width: 26px; height: 26px; border-radius: 50%; align-items: center; justify-content: center; font-weight: 800; background: #e53935; color: #fff; }
        .attn-row.fixed .bang { background: #16a05d; } .attn-row.recheck .bang { background: #e6a100; }
        .fix-state { font-size: 12.5px; font-weight: 700; color: #c62828; } .attn-row.fixed .fix-state { color: #188447; } .attn-row.recheck .fix-state { color: #a96b00; }
        .attn-row.fixed .note-err { color: #9aa4aa; text-decoration: line-through; } .attn-row.recheck .note-err { color: #a96b00; }
        .fix-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px 12px; margin-top: 12px; }
        @media (max-width: 900px) { .fix-grid { grid-template-columns: 1fr 1fr; } }
        .fix-grid .grp { grid-column: 1 / -1; font-size: 12.5px; font-weight: 700; color: #064a5e; margin-top: 4px; }
        .fix-input, .fix-sel { width: 100%; padding: 9px 10px; border: 1px solid #d8d8d8; border-radius: 8px; font-size: 13.5px; box-sizing: border-box; font-family: inherit; background: #fff; min-height: 40px; }
        .attn-row.err .fix-input, .attn-row.err .fix-sel { border-color: #e57373; }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <h1>Import Records</h1>
            <p><strong>Optional.</strong> If you prefer, upload the students' <strong>Preliminary Enrolment Forms (PEF)</strong> — the grades of the finished semester and the new semester's section are read for you. You can also record attendance and grades directly in <a href="classes.php" style="color:#087fa7;font-weight:600;">Classes</a>.</p>
        </div>
    </section>

    <?php if ($flash): ?>
        <div class="alert-banner alert-<?= $flash['type'] ?>">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="imp-wrap">

    <?php if ($stage === 'upload'): ?>

        <div class="imp-card">
            <h2>Choose the PEF files</h2>
            <p style="font-size:13.5px;color:#64727a;margin-bottom:14px;">One Excel file per student (<code>.xlsx</code>, up to <?= IMPORT_MAX_FILES ?> files at once). You can select many files together. Nothing is saved until you have checked the preview and confirmed with your PIN.</p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="preview">
                <div class="drop-zone">
                    <i class="fa-solid fa-file-excel"></i>
                    <div style="margin-bottom:12px;font-weight:600;">Select the PEF file(s)</div>
                    <input type="file" name="pef[]" accept=".xlsx" multiple required>
                </div>
                <button type="submit" class="btn-primary" style="margin-top:16px;"><i class="fa-solid fa-magnifying-glass"></i> Read Files &amp; Preview</button>
            </form>
        </div>

    <?php elseif ($stage === 'preview' && $imp):
        $recs = $imp['recs'];
        $attention = []; $okList = [];
        foreach ($recs as $r) {
            if ($kind[$r['n']] === 'problem') { $attention[] = $r; }
            else { $okList[] = $r; }
        }
        usort($okList, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        $sel = function (string $name, array $opts, $current, string $blank, int $n, $orig) {
            $h = '<select class="fix-sel" form="commitForm" name="fix[' . $n . '][' . $name . ']" data-orig="' . htmlspecialchars((string) $orig) . '">';
            $h .= '<option value="">' . htmlspecialchars($blank) . '</option>';
            foreach ($opts as $v => $label) { $h .= '<option value="' . htmlspecialchars((string) $v) . '"' . ((string) $v === (string) $current ? ' selected' : '') . '>' . htmlspecialchars((string) $label) . '</option>'; }
            return $h . '</select>';
        };
        $ayOpts = array_combine(array_reverse(AY_ENTRY_OPTIONS), array_reverse(AY_ENTRY_OPTIONS));
        $blockOpts = [1 => 'Block 1', 2 => 'Block 2', 3 => 'Block 3', 4 => 'Block 4', 5 => 'Block 5'];
    ?>

        <div class="imp-card">
            <h2>Check the preview</h2>
            <div class="meta-chips"><span class="meta-chip"><i class="fa-solid fa-file"></i> <?= count($recs) ?> PEF file<?= count($recs) === 1 ? '' : 's' ?> read</span></div>
            <?php foreach ($fileProblems as $fp): ?><div class="note-warn"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($fp) ?></div><?php endforeach; ?>

            <div class="count-row">
                <div class="count-box ok"><strong><?= $cntExisting ?></strong><span>students already in the system</span></div>
                <div class="count-box new"><strong><?= $cntNew ?></strong><span>new students</span></div>
                <div class="count-box bad"><strong><?= $cntProblem ?></strong><span>need completing</span></div>
            </div>
            <p style="font-size:13px;color:#64727a;margin-bottom:6px;"><i class="fa-solid fa-circle-info"></i> The PEF has no attendance, so <strong>100% attendance</strong> is used until attendance is entered on the Students page. Grades on the 4.00 scale are converted to percentages.</p>

            <?php if ($attention): ?>
                <h3 class="attn-title"><i class="fa-solid fa-circle-exclamation"></i> Complete these first — <?= count($attention) ?> file<?= count($attention) === 1 ? '' : 's' ?></h3>
                <p class="attn-help">Everything must be complete before the import can run. Type or choose what is missing — a red card turns normal once it is complete, and the Import button turns on when none are left.</p>
                <?php foreach ($attention as $r): $n = $r['n']; $ev = $hasEval($r); $en = $hasEnrol($r); ?>
                    <div class="attn-row err" data-kind="err" data-server="<?= $serverOnly[$n] ? '1' : '0' ?>">
                        <div class="attn-head">
                            <span class="bang">!</span>
                            <strong><?= htmlspecialchars($r['file']) ?></strong>
                            <span class="fix-state">Needs completing</span>
                        </div>
                        <?php foreach ($notes[$n] as $nt): ?>
                            <div class="<?= $nt[0] === 'err' ? 'note-err' : 'note-warn' ?>"><?= $nt[0] === 'err' ? '<b>!</b> ' : '' ?><?= htmlspecialchars($nt[1]) ?></div>
                        <?php endforeach; ?>
                        <div class="fix-grid">
                            <div><label>Student No.</label><input type="text" class="fix-input fix-id" form="commitForm" name="fix[<?= $n ?>][student_id]" value="<?= htmlspecialchars($r['student_id']) ?>" data-orig="<?= htmlspecialchars($r['student_id']) ?>" placeholder="e.g. 23-22-028" autocomplete="off"></div>
                            <div style="grid-column: span 3;"><label>Name (Last Name, First Name, M.I.)</label><input type="text" class="fix-input fix-name" form="commitForm" name="fix[<?= $n ?>][name]" value="<?= htmlspecialchars($r['name']) ?>" data-orig="<?= htmlspecialchars($r['name']) ?>" autocomplete="off"></div>
                            <?php if ($ev): ?>
                                <div class="grp">Semester that just ended (the grades)</div>
                                <div><label>School year</label><?= $sel('ay', $ayOpts, in_array($r['ay'], AY_ENTRY_OPTIONS, true) ? $r['ay'] : '', 'Choose', $n, $r['ay']) ?></div>
                                <div><label>Year level</label><?= $sel('year', $yearOpts, $r['year'], 'Choose', $n, $r['year']) ?></div>
                                <div><label>Semester</label><?= $sel('semester', $semOpts, $r['semester'], 'Choose', $n, $r['semester']) ?></div>
                                <div><label>Block</label><?= $sel('block', $blockOpts, $r['block'], 'Choose', $n, $r['block']) ?></div>
                                <?php foreach ($r['subjects'] as $si => $sj): if ($sj['problem'] === null) { continue; } ?>
                                    <div style="grid-column: span 3;"><label><?= htmlspecialchars($sj['code'] . ' — ' . $sj['title']) ?> <span style="font-weight:400;color:#c62828;">(<?= htmlspecialchars($sj['problem']) ?>)</span></label></div>
                                    <div><input type="number" step="0.01" min="0" max="5" class="fix-input fix-grade" form="commitForm" name="fix[<?= $n ?>][g][<?= $si ?>]" value="<?= htmlspecialchars($sj['raw']) ?>" data-orig="<?= htmlspecialchars($sj['raw']) ?>" placeholder="grade, e.g. 3.25"></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($en): ?>
                                <div class="grp">New semester (section <?= htmlspecialchars($r['e_section'] ?: 'not on the form') ?>)</div>
                                <div><label>School year</label><?= $sel('e_ay', $ayOpts, in_array($r['e_ay'], AY_ENTRY_OPTIONS, true) ? $r['e_ay'] : '', 'Choose', $n, $r['e_ay']) ?></div>
                                <div><label>Year level</label><?= $sel('e_year', $yearOpts, $r['e_year'], 'Choose', $n, $r['e_year']) ?></div>
                                <div><label>Semester</label><?= $sel('e_semester', $semOpts, $r['e_semester'], 'Choose', $n, $r['e_semester']) ?></div>
                                <div><label>Block</label><?= $sel('e_block', $blockOpts, $r['e_block'], 'Choose', $n, $r['e_block']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="all-good"><i class="fa-solid fa-circle-check"></i> Every file is complete. You can import now.</div>
            <?php endif; ?>

            <?php if (array_filter($okList, function ($r) use ($notes) { foreach ($notes[$r['n']] as $nt) { if ($nt[0] === 'warn') { return true; } } return false; })): ?>
                <p class="attn-help" style="margin-top:14px;"><i class="fa-solid fa-triangle-exclamation" style="color:#e6a100;"></i> Some complete files have a note (in amber, in the list below) — they will be imported as they are.</p>
            <?php endif; ?>

            <details class="ok-list" <?= $attention ? '' : 'open' ?>>
                <summary><i class="fa-solid fa-users"></i> The <?= count($okList) ?> complete file<?= count($okList) === 1 ? '' : 's' ?> (A–Z by last name)</summary>
                <table class="prev-table" style="margin-top:10px;">
                    <thead><tr><th>Student</th><th>Grades of</th><th>New semester</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($okList as $r): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['name']) ?></strong><div style="font-size:11.5px;color:#8a959b;"><?= htmlspecialchars($r['student_id']) ?></div>
                                    <?php foreach ($notes[$r['n']] as $nt): ?><div class="note-warn"><?= htmlspecialchars($nt[1]) ?></div><?php endforeach; ?></td>
                                <td><?php if ($hasEval($r)): ?><?= htmlspecialchars($r['ay']) ?> · Year <?= (int) $r['year'] ?> · <?= (int) $r['semester'] === 1 ? '1st' : '2nd' ?> Sem<div style="font-size:11.5px;color:#8a959b;"><?= count($r['grades']) ?> subjects · form GWA <?= htmlspecialchars($r['pef_gwa'] ?: '—') ?></div><?php else: ?>—<?php endif; ?></td>
                                <td><?php if ($hasEnrol($r)): ?><?= htmlspecialchars($r['e_ay']) ?> · <?= (int) $r['e_semester'] === 1 ? '1st' : '2nd' ?> Sem<div style="font-size:11.5px;color:#8a959b;">BSIT-<?= (int) $r['e_year'] ?><?= (int) $r['e_block'] ?></div><?php else: ?>—<?php endif; ?></td>
                                <td><?= $kind[$r['n']] === 'new' ? '<span style="color:#2a55b8;font-weight:600;">New</span>' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>

            <form method="POST" id="commitForm" class="confirm-box">
                <input type="hidden" name="action" value="commit">
                <input type="hidden" name="token" value="<?= htmlspecialchars($imp['token']) ?>">
                <strong style="color:#064a5e;"><i class="fa-solid fa-circle-check"></i> Ready? Confirm to save.</strong>
                <p style="font-size:13px;color:#55636b;margin-top:6px;">The grades of the finished semester are replaced; the new semester puts the student in the new school year. Risk is recalculated. All or nothing: if any file is incomplete or clashes with saved records, nothing is imported.</p>
                <label style="display:flex;gap:10px;align-items:flex-start;margin-top:12px;font-size:14px;color:#33475a;font-weight:500;">
                    <input type="checkbox" name="add_new" value="1" checked style="margin-top:3px;">
                    <span>Add students who are not in the system yet (name taken from the form; gender and age can be filled in later).</span>
                </label>
                <div class="row">
                    <div style="max-width:240px;">
                        <label><i class="fa-solid fa-shield-halved"></i> Your Student Data PIN</label>
                        <input type="password" name="credential" id="f_credential" class="pin-input" inputmode="numeric" maxlength="6" placeholder="6-digit PIN" autocomplete="off" required style="width:100%;padding:11px;border:1px solid #d8d8d8;border-radius:8px;font-size:16px;box-sizing:border-box;">
                        <div class="inline-error" id="f_cred_error"></div>
                    </div>
                    <button type="submit" id="commitBtn" class="btn-primary" <?= $cntProblem ? 'disabled' : '' ?>><i class="fa-solid fa-file-import"></i> Import <?= count($recs) ?> file<?= count($recs) === 1 ? '' : 's' ?></button>
                    <button type="submit" form="cancelForm" class="btn-secondary">Cancel</button>
                </div>
                <div class="blocked-hint" id="blockedHint" <?= $cntProblem ? 'style="display:block"' : '' ?>><i class="fa-solid fa-lock"></i> Import is off until the red cards are complete (<span id="badLeft"><?= $cntProblem ?></span> left).</div>
            </form>
            <form method="POST" id="cancelForm"><input type="hidden" name="action" value="cancel"></form>
        </div>

    <?php elseif ($stage === 'done' && $result !== null): ?>

        <div class="imp-card">
            <h2><i class="fa-solid fa-circle-check" style="color:#16a05d;"></i> Import finished</h2>
            <div class="count-row">
                <div class="count-box ok"><strong><?= count($result['saved']) - $result['created'] ?></strong><span>existing students updated</span></div>
                <div class="count-box new"><strong><?= $result['created'] ?></strong><span>new students added</span></div>
                <div class="count-box ok"><strong><?= (int) $result['predicted'] ?></strong><span>risk predictions made</span></div>
            </div>
            <table class="prev-table">
                <thead><tr><th>Student (A–Z by last name)</th><th>Grades saved</th><th>Now in</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($result['saved'] as $s): $r = $s['rec']; ?>
                        <tr><td><strong><?= htmlspecialchars($s['res']['name']) ?></strong><div style="font-size:11.5px;color:#8a959b;"><?= htmlspecialchars($s['res']['student_id']) ?></div></td>
                            <td><?= $s['res']['subjects'] ?> subjects<?= $hasEval($r) ? ' · ' . htmlspecialchars($r['ay']) . ' ' . ((int) $r['semester'] === 1 ? '1st' : '2nd') . ' Sem' : '' ?></td>
                            <td><?= $hasEnrol($r) ? htmlspecialchars($r['e_ay']) . ' · BSIT-' . (int) $r['e_year'] . (int) $r['e_block'] : '—' ?></td>
                            <td><?= $s['res']['new'] ? '<span style="color:#2a55b8;font-weight:600;">New</span>' : '' ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;">
                <a href="students.php" class="btn-primary" style="text-decoration:none;"><i class="fa-solid fa-users"></i> See the students</a>
                <a href="classes.php" class="btn-secondary" style="text-decoration:none;"><i class="fa-solid fa-chalkboard-user"></i> See their classes</a>
                <a href="import.php" class="btn-secondary" style="text-decoration:none;"><i class="fa-solid fa-file-import"></i> Import more files</a>
            </div>
        </div>

    <?php endif; ?>

    </div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<?php include __DIR__ . '/includes/credential_modal.php'; ?>
<script src="../assets/fix-rows.js?v=2"></script>
<script>
    // A wrong PIN shows right here and keeps everything typed.
    const commitForm = document.getElementById('commitForm');
    if (commitForm) {
        const pin = document.getElementById('f_credential');
        pin.addEventListener('input', function () { pin.value = pin.value.replace(/\D/g, '').slice(0, 6); });
        guardForm(commitForm, pin, document.getElementById('f_cred_error'));
    }
</script>

</body>
</html>
