<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';
require_once __DIR__ . '/../config/grades.php';

// Class Assignments (PROGRAM HEAD): make a class from a subject the Administrator added — give it its subject code, choose the section and
// assign the professor — and decide which professor handles which class. Per SUBJECT, because one subject has many sections and different
// professors may teach the same subject in different sections. Everything is saved from here with a pop-up PIN check.
// (The Admin only adds the subjects, in Subjects; he or she cannot make or assign a class.)
if (!canAssignClasses()) { header('Location: ' . (isAdmin() ? 'subjects.php' : 'dashboard.php')); exit; }

$cur = currentSchoolYear($pdo);
$faculty = $pdo->query("SELECT user_id, full_name FROM users WHERE role = 'faculty' ORDER BY full_name")->fetchAll();
$facultyIds = array_map(function ($f) { return $f['user_id']; }, $faculty);
$facultyName = [];
foreach ($faculty as $f) { $facultyName[$f['user_id']] = $f['full_name']; }
$ord = ['1st', '2nd', '3rd', '4th'];

// ---------------- the saving (JSON; the password was already checked by the pop-up, and is checked again here) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = function (bool $ok, string $msg, array $extra = []) { echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE); exit; };
    requireAssignPermission();
    $check = checkCredential($pdo, 'pin', $_POST['credential'] ?? '');
    if (!$check['ok']) { $reply(false, $check['error'], !empty($check['logout']) ? ['logout' => true] : []); }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_assignments') {
            $given = (isset($_POST['owner']) && is_array($_POST['owner'])) ? $_POST['owner'] : [];
            if (!$given) { $reply(false, 'No change reached the server — pick a professor for at least one class first.'); }
            $changed = 0;
            $upd = $pdo->prepare("UPDATE classes SET owner_user_id = ? WHERE id = ?");
            $get = $pdo->prepare("SELECT owner_user_id FROM classes WHERE id = ?");
            foreach ($given as $id => $who) {
                $id = (int) $id; $who = (string) $who;
                if ($who !== '' && !in_array($who, $facultyIds, true)) { continue; }
                $get->execute([$id]);
                $now = $get->fetchColumn();
                if ($now === false) { continue; }
                if ((string) $now !== $who) { $upd->execute([$who === '' ? null : $who, $id]); $changed++; }
            }
            $reply(true, $changed ? $changed . ' class' . ($changed === 1 ? '' : 'es') . ' assigned. Each professor now sees only his or her own classes.' : 'Those classes already had that professor — nothing to change.', ['changed' => $changed]);
        }
        if ($action === 'create_class') {
            $subject = trim($_POST['subject'] ?? ''); $code = trim($_POST['code'] ?? ''); $section = $_POST['section'] ?? ''; $who = (string) ($_POST['new_owner'] ?? '');
            $known = $pdo->prepare("SELECT 1 FROM subjects WHERE subject_name = ?"); $known->execute([$subject]);
            if ($subject === '' || !$known->fetchColumn()) { $reply(false, 'Please choose a subject from the list. The Administrator adds the subjects.'); }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{1,19}$/', $code)) { $reply(false, 'Please type the subject code (letters and numbers, for example ITE101).'); }
            if (!preg_match('/^BSIT-[1-4][1-8]$/', $section)) { $reply(false, 'Please choose the section.'); }
            if ($who !== '' && !in_array($who, $facultyIds, true)) { $reply(false, 'Please choose a professor from the list.'); }
            $dup = $pdo->prepare("SELECT 1 FROM classes WHERE subject_name = ? AND section = ? AND academic_year = ? AND semester = ?");
            $dup->execute([$subject, $section, $cur['ay'], (int) $cur['sem']]);
            if ($dup->fetchColumn()) { $reply(false, 'That class already exists.'); }
            $pdo->beginTransaction();
            createClass($pdo, $subject, $section, $cur['ay'], (int) $cur['sem'], $who === '' ? null : $who, strtoupper($code));
            $pdo->commit();
            $reply(true, 'Class created — its students were put in automatically.');
        }
        if ($action === 'delete_class') {
            $id = (int) ($_POST['delete_id'] ?? 0);
            $data = classLoadData($pdo, $id);
            if (!$data) { $reply(false, 'That class no longer exists.'); }
            $pdo->beginTransaction();
            foreach ($data['students'] as $s) { removeClassFromStudent($pdo, $id, $s['student_id'], $data['class']); }
            $pdo->prepare("DELETE FROM classes WHERE id = ?")->execute([$id]);
            $pdo->commit();
            $reply(true, 'Class deleted, and its subject taken out of the students\' records.');
        }
        $reply(false, 'Unknown action.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $reply(false, 'Could not save: ' . $e->getMessage());
    }
}

// ---------------- the classes of the current semester, grouped by subject ----------------
$search = mb_substr(trim($_GET['q'] ?? ''), 0, 60);
$show   = ($_GET['show'] ?? '') === 'unassigned' ? 'unassigned' : '';
$where = ["c.academic_year = ?", "c.semester = ?"]; $params = [$cur['ay'], (int) $cur['sem']];
if ($show === 'unassigned') { $where[] = "(c.owner_user_id IS NULL OR c.owner_user_id = '')"; }
if ($search !== '') { $where[] = "(c.subject_name LIKE ? OR c.subject_code LIKE ? OR c.section LIKE ? OR u.full_name LIKE ?)"; array_push($params, "%$search%", "%$search%", "%$search%", "%$search%"); }
$rows = []; $setupError = ''; $unassigned = 0; $totalClasses = 0;
try {
    $q = $pdo->prepare("SELECT c.*, u.full_name AS owner_name, (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id = c.id AND cs.excluded = 0) AS n_students
                        FROM classes c LEFT JOIN users u ON u.user_id = c.owner_user_id WHERE " . implode(' AND ', $where) . " ORDER BY c.subject_name ASC, c.section ASC");
    $q->execute($params);
    $rows = $q->fetchAll();
    $cq = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(owner_user_id IS NULL OR owner_user_id = ''), 0) AS u FROM classes WHERE academic_year = ? AND semester = ?");
    $cq->execute([$cur['ay'], (int) $cur['sem']]);
    $cc = $cq->fetch(); $totalClasses = (int) $cc['n']; $unassigned = (int) $cc['u'];
} catch (PDOException $e) { $setupError = 'The Classes tables are not up to date. Run database/migration_classes.sql. (' . $e->getMessage() . ')'; }
$bySubject = [];
foreach ($rows as $c) { $bySubject[$c['subject_name']][] = $c; }
$handles = [];
try {
    $hq = $pdo->prepare("SELECT c.owner_user_id, c.subject_name, c.section FROM classes c WHERE c.academic_year = ? AND c.semester = ? AND c.owner_user_id IS NOT NULL AND c.owner_user_id <> '' ORDER BY c.subject_name, c.section");
    $hq->execute([$cur['ay'], (int) $cur['sem']]);
    foreach ($hq->fetchAll() as $h) { $handles[$h['owner_user_id']][$h['subject_name']][] = $h['section']; }
} catch (PDOException $e) {}
$subjects = [];
try { $subjects = $pdo->query("SELECT subject_name FROM subjects ORDER BY subject_name LIMIT 500")->fetchAll(PDO::FETCH_COLUMN); } catch (PDOException $e) { $setupError = $setupError ?: 'The Subjects table is missing. Run database/migration_roles.sql once.'; }
$semLabel = (int) $cur['sem'] === 1 ? '1st Semester' : '2nd Semester';
$activePage = 'assignments';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Assignments | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
    <style>
        .own-sel { width: 100%; min-width: 190px; padding: 8px 10px; font-size: 13.5px; } .own-sel.none { border-color: #f0c969; background: #fff8e6; } .own-sel.chg { border-color: var(--brand); background: var(--brand-50); }
        .subj { margin: 0 24px 12px; overflow: hidden; }
        .subj > summary { cursor: pointer; list-style: none; padding: 13px 18px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; background: #f7fafc; }
        .subj > summary::-webkit-details-marker { display: none; } .subj > summary::before { content: '\f054'; font-family: 'Font Awesome 6 Free'; font-weight: 900; font-size: 11px; color: var(--ink-3); transition: transform .15s; } .subj[open] > summary::before { transform: rotate(90deg); }
        .subj[open] > summary { border-bottom: 1px solid var(--line); }
        .subj .sname { font-weight: 600; color: var(--ink); font-size: 15px; } .subj .scount { font-size: 12.5px; color: var(--ink-3); }
        .bulk { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 10px 18px; background: #fbfdfe; border-bottom: 1px solid #eef2f4; font-size: 13px; color: var(--ink-2); }
        .bulk select { padding: 7px 10px; min-width: 190px; font-size: 13px; }
        .subj table { width: 100%; border-collapse: collapse; } .subj th { text-align: left; font-size: 11.5px; padding: 8px 12px; background: #fff; } .subj td { padding: 8px 12px; border-top: 1px solid #f0f3f5; font-size: 13.5px; }
        .idtag { font-family: Consolas, monospace; font-size: 12px; color: var(--brand-700); background: var(--brand-50); border-radius: 6px; padding: 2px 7px; }
        .whobox { margin: 0 24px 14px; display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 10px; }
        .whocard { padding: 12px 15px; font-size: 13px; } .whocard strong { color: var(--brand-900); display: block; margin-bottom: 5px; }
        .whocard span.chip { display: inline-block; background: var(--brand-50); color: var(--brand-700); border-radius: 10px; padding: 2px 8px; margin: 2px 3px 2px 0; font-size: 12px; font-weight: 500; }
        .cf label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 5px; } .cf input, .cf select { width: 100%; padding: 11px; font-size: 14.5px; box-sizing: border-box; }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <h1>Class Assignments <small class="term-small"><?= htmlspecialchars($cur['ay']) ?> · <?= $semLabel ?></small></h1>
            <p>Give each professor the sections he or she handles, subject by subject. <strong>The same subject can have different professors in different sections.</strong> A professor sees and records only his or her own classes.</p>
        </div>
        <button type="button" class="btn-primary" id="newClassBtn"><i class="fa-solid fa-plus"></i> New class</button>
    </section>

    <?php if ($setupError): ?><div class="alert-banner alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($setupError) ?></div><?php endif; ?>
    <?php if (!$faculty): ?><div class="alert-banner" style="background:#fff4e0;color:#8a5a00;margin:0 24px 14px;"><i class="fa-solid fa-circle-info"></i> There is no Faculty account yet. Ask the Administrator to create the professors' accounts (role: Faculty) in User Management, then assign their classes here.</div><?php endif; ?>

    <?php if ($handles): ?>
    <div class="whobox">
        <?php foreach ($handles as $uid => $subs): $n = 0; foreach ($subs as $sc) { $n += count($sc); } ?>
            <div class="whocard"><strong><?= htmlspecialchars($facultyName[$uid] ?? $uid) ?> <span style="font-weight:500;color:var(--ink-3);">· <?= $n ?> class<?= $n === 1 ? '' : 'es' ?></span></strong>
                <?php foreach ($subs as $sn => $secs): ?><div><span style="color:var(--ink-2);"><?= htmlspecialchars($sn) ?></span> <?php foreach ($secs as $sx): ?><span class="chip"><?= htmlspecialchars($sx) ?></span><?php endforeach; ?></div><?php endforeach; ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="GET" class="toolbar">
        <div class="search-box">
            <button type="submit" class="search-submit-btn" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
            <input type="text" name="q" placeholder="Search subject, section or professor" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
        </div>
        <select name="show" class="filter-select" onchange="this.form.submit()"><option value="">All classes (<?= $totalClasses ?>)</option><option value="unassigned" <?= $show === 'unassigned' ? 'selected' : '' ?>>Not assigned yet (<?= $unassigned ?>)</option></select>
    </form>

    <?php if (!$bySubject): ?><div class="chart-empty" style="margin:0 24px;padding:24px;text-align:center;">No class for this search.</div><?php endif; ?>
    <?php foreach ($bySubject as $sname => $list): $assigned = count(array_filter($list, function ($c) { return $c['owner_user_id'] !== null && $c['owner_user_id'] !== ''; })); ?>
        <details class="subj" <?= ($assigned < count($list) || $search !== '') ? 'open' : '' ?>>
            <summary>
                <span class="sname"><?= htmlspecialchars($sname) ?></span>
                <?php if ($list[0]['subject_code']): ?><span class="idtag"><?= htmlspecialchars($list[0]['subject_code']) ?></span><?php endif; ?>
                <span class="scount"><?= count($list) ?> section<?= count($list) === 1 ? '' : 's' ?> · <?= $assigned ?> assigned</span>
                <?php if ($assigned < count($list)): ?><span class="badge moderate-badge"><?= count($list) - $assigned ?> not assigned</span><?php endif; ?>
            </summary>
            <div class="bulk">
                <span><i class="fa-solid fa-wand-magic-sparkles"></i> Tick sections, choose a professor:</span>
                <select class="bulk-who"><option value="">— Not assigned —</option><?php foreach ($faculty as $f): ?><option value="<?= htmlspecialchars($f['user_id']) ?>"><?= htmlspecialchars($f['full_name']) ?></option><?php endforeach; ?></select>
                <button type="button" class="btn-secondary bulk-apply" style="padding:7px 14px;">Apply to ticked</button>
            </div>
            <table>
                <thead><tr><th style="width:36px;"><input type="checkbox" class="grp-all" title="Tick all sections of this subject"></th><th style="width:70px;">ID</th><th>Section</th><th>Students</th><th>Professor</th><th style="width:44px;"></th></tr></thead>
                <tbody>
                    <?php foreach ($list as $c): $has = $c['owner_user_id'] !== null && $c['owner_user_id'] !== ''; ?>
                        <tr>
                            <td><input type="checkbox" class="row-check" value="<?= (int) $c['id'] ?>"></td>
                            <td><span class="idtag">#<?= (int) $c['id'] ?></span></td>
                            <td><strong><?= htmlspecialchars($c['section']) ?></strong><span class="small-ay" style="display:block;font-size:11px;"><?= $ord[classSectionYear($c['section']) - 1] ?> Year</span></td>
                            <td><?= (int) $c['n_students'] ?></td>
                            <td><select class="own-sel <?= $has ? '' : 'none' ?>" data-id="<?= (int) $c['id'] ?>" data-orig="<?= htmlspecialchars((string) $c['owner_user_id']) ?>">
                                <option value="">— Not assigned —</option>
                                <?php foreach ($faculty as $f): ?><option value="<?= htmlspecialchars($f['user_id']) ?>" <?= $c['owner_user_id'] === $f['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['full_name']) ?></option><?php endforeach; ?>
                                <?php if ($has && !in_array($c['owner_user_id'], $facultyIds, true)): ?><option value="<?= htmlspecialchars($c['owner_user_id']) ?>" selected><?= htmlspecialchars($c['owner_name'] ?: $c['owner_user_id']) ?> (not a Faculty account)</option><?php endif; ?></select></td>
                            <td><button type="button" class="btn-icon btn-danger del-class" data-id="<?= (int) $c['id'] ?>" data-name="<?= htmlspecialchars($sname . ' ' . $c['section']) ?>" title="Delete this class"><i class="fa-solid fa-trash"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endforeach; ?>
    <div style="height:90px;"></div>

    <!-- appears while there are unsaved changes — Save is always in reach -->
    <div class="savebar" id="savebar" role="status">
        <span><strong id="chgCount">0</strong> unsaved change<span id="chgS">s</span></span>
        <button type="button" class="btn-secondary" id="discardBtn">Discard</button>
        <button type="button" class="btn-primary" id="saveBtn"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>
    </div>

    <div class="modal-overlay" id="newClassModal">
        <div class="modal-box" style="width:520px;">
            <h2>New class</h2>
            <p style="font-size:13.5px;color:var(--ink-2);line-height:1.6;">One class = one subject with its code, one section and its professor, for <strong><?= htmlspecialchars($cur['ay']) ?> · <?= $semLabel ?></strong>. Its students are put in automatically.</p>
            <div class="cf" id="newClassForm">
                <label>Subject <span style="font-weight:400;color:var(--ink-3);">(added by the Administrator)</span></label>
                <select id="n_subject"><option value="">Choose a subject</option><?php foreach ($subjects as $sj): ?><option value="<?= htmlspecialchars($sj) ?>"><?= htmlspecialchars($sj) ?></option><?php endforeach; ?></select>
                <?php if (!$subjects): ?><div style="font-size:12.5px;color:#a86400;margin-top:6px;"><i class="fa-solid fa-circle-info"></i> No subject yet. Ask the Administrator to add it in <strong>Subjects</strong>.</div><?php endif; ?>
                <label>Subject code <span style="font-weight:400;color:var(--ink-3);">(e.g. ITE101)</span></label>
                <input type="text" id="n_code" maxlength="20" autocomplete="off" placeholder="ITE101">
                <label>Section</label>
                <select id="n_section"><option value="">Choose the section</option>
                    <?php for ($y = 1; $y <= 4; $y++): for ($b = 1; $b <= 8; $b++): $sec = 'BSIT-' . $y . $b; ?><option value="<?= $sec ?>"><?= $sec ?> — <?= $ord[$y - 1] ?> Year, Block <?= $b ?></option><?php endfor; endfor; ?></select>
                <label>Professor</label>
                <select id="n_owner"><option value="">— Assign later —</option><?php foreach ($faculty as $f): ?><option value="<?= htmlspecialchars($f['user_id']) ?>"><?= htmlspecialchars($f['full_name']) ?></option><?php endforeach; ?></select>
                <div class="modal-actions" style="margin-top:18px;">
                    <button type="button" class="btn-secondary" id="newCancel">Cancel</button>
                    <button type="button" class="btn-primary" id="newCreate">Create class</button>
                </div>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<script>
(function () {
    function post(fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch('assignments.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } }).then(function (r) { return r.json(); });
    }
    function done(res) {
        if (res.logout) { location.href = '../index.php?error=locked'; return; }
        toast(res.msg, res.ok ? 'ok' : 'bad');
        if (res.ok) { dirtyOff(); setTimeout(function () { location.reload(); }, 800); }
    }
    // ---- unsaved changes ----
    var sels = Array.prototype.slice.call(document.querySelectorAll('.own-sel'));
    function changes() { return sels.filter(function (s) { return s.value !== s.dataset.orig; }); }
    function refresh() {
        sels.forEach(function (s) { s.classList.toggle('none', s.value === ''); s.classList.toggle('chg', s.value !== s.dataset.orig); });
        var n = changes().length;
        document.getElementById('chgCount').textContent = n; document.getElementById('chgS').textContent = n === 1 ? '' : 's';
        document.getElementById('savebar').classList.toggle('show', n > 0);
    }
    function dirtyOff() { window.onbeforeunload = null; }
    window.onbeforeunload = function () { return changes().length ? 'You have unsaved changes.' : undefined; };
    sels.forEach(function (s) { s.addEventListener('change', refresh); });
    document.getElementById('discardBtn').addEventListener('click', function () { sels.forEach(function (s) { s.value = s.dataset.orig; }); refresh(); });
    document.getElementById('saveBtn').addEventListener('click', function () {
        var ch = changes(); if (!ch.length) { toast('Nothing to save yet — change a professor first.', 'warn'); return; }
        askSecret({ type: 'pin', title: 'Save the assignments', message: '<strong>' + ch.length + '</strong> class' + (ch.length === 1 ? '' : 'es') + ' will change professor.', confirmText: 'Save changes' }).then(function (secret) {
            if (secret === null) return;
            var f = { action: 'save_assignments', credential: secret };
            ch.forEach(function (s) { f['owner[' + s.dataset.id + ']'] = s.value; });
            post(f).then(done).catch(function () { toast('Could not reach the server.', 'bad'); });
        });
    });
    // ---- tick sections -> choose a professor -> Apply ----
    document.querySelectorAll('.bulk-apply').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var grp = btn.closest('.subj'), who = grp.querySelector('.bulk-who').value, boxes = grp.querySelectorAll('.row-check:checked');
            if (!boxes.length) { toast('Tick the sections first.', 'warn'); return; }
            boxes.forEach(function (b) { var s = grp.querySelector('.own-sel[data-id="' + b.value + '"]'); s.value = who; b.checked = false; });
            grp.querySelector('.grp-all').checked = false; refresh();
            toast(boxes.length + ' section' + (boxes.length === 1 ? '' : 's') + ' set — press Save changes.', '');
        });
    });
    document.querySelectorAll('.grp-all').forEach(function (all) { all.addEventListener('change', function () { all.closest('.subj').querySelectorAll('.row-check').forEach(function (b) { b.checked = all.checked; }); }); });
    // ---- delete a class ----
    document.querySelectorAll('.del-class').forEach(function (btn) {
        btn.addEventListener('click', function () {
            askSecret({ type: 'pin', danger: true, title: 'Delete this class?', message: '<strong>' + btn.dataset.name.replace(/</g, '&lt;') + '</strong> will be deleted with all its attendance and scores, and taken out of the students\' records.', confirmText: 'Delete class' }).then(function (secret) {
                if (secret === null) return;
                post({ action: 'delete_class', credential: secret, delete_id: btn.dataset.id }).then(done).catch(function () { toast('Could not reach the server.', 'bad'); });
            });
        });
    });
    // ---- a new class ----
    var modal = document.getElementById('newClassModal');
    document.getElementById('newClassBtn').addEventListener('click', function () { modal.classList.add('show'); document.getElementById('n_subject').focus(); });
    document.getElementById('newCancel').addEventListener('click', function () { modal.classList.remove('show'); });
    document.getElementById('newCreate').addEventListener('click', function () {
        var subject = document.getElementById('n_subject').value.trim(), section = document.getElementById('n_section').value;
        if (!subject) { toast('Please choose a subject.', 'warn'); return; }
        var codeVal = document.getElementById('n_code').value.trim();
        if (!/^[A-Za-z0-9][A-Za-z0-9 -]{1,19}$/.test(codeVal)) { toast('Please type the subject code, for example ITE101.', 'warn'); return; }
        if (!section) { toast('Please choose the section.', 'warn'); return; }
        askSecret({ type: 'pin', title: 'Create the class', message: '<strong>' + subject.replace(/</g, '&lt;') + '</strong> · ' + section, confirmText: 'Create class' }).then(function (secret) {
            if (secret === null) return;
            post({ action: 'create_class', credential: secret, subject: subject, code: document.getElementById('n_code').value, section: section, new_owner: document.getElementById('n_owner').value }).then(function (res) { if (res.ok) modal.classList.remove('show'); done(res); }).catch(function () { toast('Could not reach the server.', 'bad'); });
        });
    });
    refresh();
})();
</script>
</body>
</html>
