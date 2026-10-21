<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';
require_once __DIR__ . '/../config/grades.php';

// Add students: a professor pastes (or uploads) the list of students enrolled in a subject — the same list the faculty portal shows —
// and the students are put in that class. Nothing is typed one by one.
facultyOnly();
$activePage = 'students';
$cur = currentSchoolYear($pdo);
$classes = [];
try {
    $q = $pdo->prepare("SELECT c.id, c.subject_name, c.section, (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id = c.id AND cs.excluded = 0) AS n
                        FROM classes c WHERE c.owner_user_id = ? AND c.academic_year = ? AND c.semester = ? ORDER BY c.subject_name, c.section");
    $q->execute([$_SESSION['user_id'], $cur['ay'], (int) $cur['sem']]);
    $classes = $q->fetchAll();
} catch (PDOException $e) {}
$pick = (int) ($_GET['class'] ?? 0);
$unlocked = recordUnlocked();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add students | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
    <style>
        .addbox { margin: 0 24px 16px; background: #fff; border: 1px solid #dde9ef; border-radius: 14px; padding: 18px 22px; }
        .addbox h3 { font-size: 15px; color: #064a5e; margin-bottom: 8px; } .addbox h3 span { display: inline-grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; background: #0b8fa3; color: #fff; font-size: 13px; margin-right: 8px; }
        .addbox select, .addbox textarea { width: 100%; box-sizing: border-box; border: 1px solid #cfe0e8; border-radius: 10px; padding: 11px; font-size: 14px; font-family: inherit; }
        .addbox textarea { font: 13px/1.55 Consolas, monospace; min-height: 170px; }
        .row-actions { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; align-items: center; }
        .ok-note { background: #e8f6ec; color: #1b6b3a; border-radius: 10px; padding: 12px 16px; font-size: 14px; margin-top: 12px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <h1>Add students <small class="term-small"><?= htmlspecialchars($cur['ay']) ?> · <?= (int) $cur['sem'] === 1 ? '1st' : '2nd' ?> Semester</small></h1>
            <p>Copy the names of the students enrolled in your subject — from your faculty portal or your Excel file — and paste them here. They are put in that class and you can record their attendance and scores right away.</p>
        </div>
        <a class="btn-secondary" style="text-decoration:none;" href="import.php"><i class="fa-solid fa-file-import"></i> Import PEF files instead</a>
    </section>

    <?php if (!$classes): ?>
        <div class="alert-banner" style="background:#fff4e0;color:#8a5a00;"><i class="fa-solid fa-circle-info"></i> No class is assigned to you for this semester yet. The Program Head assigns your classes under Class Assignments; then you can add the students here.</div>
    <?php else: ?>

    <div class="cls-banner lock" id="lockBanner" <?= $unlocked ? 'hidden' : '' ?> style="margin:0 24px 12px;">
        <i class="fa-solid fa-lock"></i>
        <span><strong>View-only.</strong> Unlock with your PIN to add students; it stays unlocked for one hour.</span>
        <button type="button" class="btn-primary" id="unlockBtn"><i class="fa-solid fa-lock-open"></i> Unlock to edit</button>
    </div>

    <div class="addbox">
        <h3><span>1</span> Which class?</h3>
        <select id="classPick">
            <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $pick === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['subject_name']) ?> — <?= htmlspecialchars($c['section']) ?> (<?= (int) $c['n'] ?> student<?= (int) $c['n'] === 1 ? '' : 's' ?> now)</option><?php endforeach; ?>
        </select>
    </div>

    <div class="addbox">
        <h3><span>2</span> Upload the Excel file, or paste the cells</h3>
        <p class="cls-hint" style="margin-bottom:8px;">Upload the class list as an <strong>Excel file (.xlsx)</strong> — columns <em>Student No. · Last name · First name · M.I.</em>, with a heading row (use the blank template). You can also copy the cells from Excel and paste them below, one student per line, like <code>23-22-028 &nbsp; Dela Cruz, Juan A.</code> Only Excel files are read: Word files and pictures are not accepted. The list stays in this system.</p>
        <textarea id="mlText" placeholder="23-22-028&#9;Dela Cruz, Juan A.&#10;23-22-031&#9;Santos, Maria B.&#10;23-22-040&#9;Reyes, Pedro"></textarea>
        <div class="row-actions">
            <label class="btn-secondary" style="cursor:pointer;"><i class="fa-solid fa-upload"></i> Upload an Excel file (.xlsx) <input type="file" id="mlFile" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden></label>
            <a class="btn-secondary" style="text-decoration:none;" href="../samples/class-list-template.xlsx" download><i class="fa-solid fa-file-arrow-down"></i> Blank template (.xlsx)</a>
            <button type="button" class="btn-primary" id="checkBtn"><i class="fa-solid fa-magnifying-glass"></i> Check the list</button>
            <span class="cls-saved" id="saveState"></span>
        </div>
    </div>

    <div class="addbox" id="resultBox" hidden>
        <h3><span>3</span> Check, then add</h3>
        <div id="mlResult"></div>
    </div>
    <?php endif; ?>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<script>
(function () {
    var pick = document.getElementById('classPick'); if (!pick) return;
    var state = document.getElementById('saveState');
    function say(t, bad) { state.textContent = t; state.className = 'cls-saved' + (bad ? ' bad' : ''); }
    function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
    function api(action, data) {
        return fetch('class_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
            body: JSON.stringify(Object.assign({ action: action, class_id: pick.value }, data || {})) }).then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, error: 'No connection to the server.' }; });
    }
    var unlockBtn = document.getElementById('unlockBtn');
    if (unlockBtn) {
        unlockBtn.addEventListener('click', function () {
            askSecret({ title: 'Unlock to edit', message: 'Enter your PIN to add students. It stays unlocked for one hour.', type: 'pin', confirmText: 'Unlock' }).then(function (pin) {
                if (pin === null) return;
                api('unlock', { credential: pin }).then(function (res) {
                    if (res.redirect) { location.href = res.redirect; return; }
                    if (!res.ok) { say(res.error || 'Wrong PIN.', true); return; }
                    document.getElementById('lockBanner').hidden = true; say('Unlocked ✓');
                });
            });
        });
    }
    var ta = document.getElementById('mlText');
    function append(text) { ta.value = (ta.value ? ta.value.replace(/\s+$/, '') + '\n' : '') + text.replace(/\s+$/, ''); }
    document.getElementById('mlFile').addEventListener('change', function () {
        var f = this.files[0], inp = this; if (!f) return;
        var ext = (f.name.split('.').pop() || '').toLowerCase();
        if (ext !== 'xlsx') { say('Only an Excel file (.xlsx) can be uploaded. Word files and pictures are not accepted.', true); inp.value = ''; return; }
        say('Reading the Excel file…'); var fd = new FormData(); fd.append('file', f);
        fetch('extract_text.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (x) { return x.json(); }).then(function (res) {
            if (!res.ok) { say(res.error, true); return; } append(res.text); say('Excel file read — check the list.'); inp.value = '';
        }).catch(function () { say('Could not read the Excel file.', true); });
    });
    var label = { 'new': ['New — will be created', '#e8f6ec', '#1b6b3a'], existing: ['Already in the system — will be added', '#e8f4fb', '#1f4f9f'], in_class: ['Already in this class', '#eef2f5', '#64727a'], conflict: ['Problem', '#fff1f0', '#b3261e'], error: ['Problem', '#fff1f0', '#b3261e'] };
    function handleLock(res) { if (res.locked) { document.getElementById('lockBanner').hidden = false; say('Enter your PIN first.', true); return true; } return false; }
    document.getElementById('checkBtn').addEventListener('click', function () {
        say('Checking…');
        api('preview_students', { text: document.getElementById('mlText').value }).then(function (res) {
            if (handleLock(res)) return;
            if (!res.ok) { say(res.error || 'Could not check the list.', true); return; }
            say('');
            var ok = 0, html = '<div class="table-wrap" style="margin:0 0 10px;max-height:340px;overflow:auto;"><table><thead><tr><th>#</th><th>Student No.</th><th>Name</th><th>Result</th></tr></thead><tbody>';
            res.rows.forEach(function (r) { var l = label[r.status]; if (r.status === 'new' || r.status === 'existing') ok++;
                html += '<tr><td>' + r.line + '</td><td>' + esc(r.id || '—') + '</td><td>' + esc(r.name || '—') + '</td><td><span class="badge" style="background:' + l[1] + ';color:' + l[2] + ';">' + esc(r.issue || l[0]) + '</span></td></tr>'; });
            html += '</tbody></table></div>';
            html += ok ? '<button type="button" class="btn-primary" id="addBtn"><i class="fa-solid fa-user-plus"></i> Add ' + ok + ' student' + (ok === 1 ? '' : 's') + ' to this class</button>' : '<p class="cls-hint">Nobody can be added yet — fix the lines marked Problem.</p>';
            document.getElementById('mlResult').innerHTML = html; document.getElementById('resultBox').hidden = false;
            var add = document.getElementById('addBtn');
            if (add) add.addEventListener('click', function () {
                add.disabled = true; say('Adding…');
                api('import_students', { text: document.getElementById('mlText').value }).then(function (r2) {
                    if (handleLock(r2)) { add.disabled = false; return; }
                    if (!r2.ok) { say(r2.error || 'Could not add them.', true); add.disabled = false; return; }
                    say('');
                    document.getElementById('mlResult').innerHTML = '<div class="ok-note"><i class="fa-solid fa-circle-check"></i> <strong>' + r2.added + '</strong> student' + (r2.added === 1 ? '' : 's') + ' added to the class' + (r2.created ? ' (' + r2.created + ' new in the system)' : '') + (r2.skipped ? ' · ' + r2.skipped + ' line' + (r2.skipped === 1 ? '' : 's') + ' skipped' : '') + '.</div>' +
                        '<p style="margin-top:12px;"><a class="btn-primary" style="text-decoration:none;" href="class.php?id=' + encodeURIComponent(pick.value) + '#list"><i class="fa-solid fa-table"></i> Open the class</a></p>';
                    document.getElementById('mlText').value = '';
                });
            });
        });
    });
})();
</script>
</body>
</html>
