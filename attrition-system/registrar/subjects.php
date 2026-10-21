<?php
/**
 * Subjects (ADMIN only). The Admin adds the subjects of the curriculum — the NAME only.
 * He or she does NOT give a subject code, choose a section or assign a professor: that is the Program Head's work, in Class Assignments.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/analytics.php';

if (!canManageSubjects()) { header('Location: dashboard.php'); exit; }

$activePage = 'subjects';
$flash = null;
if (!empty($_SESSION['subj_flash'])) { $flash = $_SESSION['subj_flash']; unset($_SESSION['subj_flash']); }

// ---------------- ADD (JSON, from the modal; the errors stay in the modal) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_subject') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = function (bool $ok, string $error = '') { echo json_encode(['ok' => $ok, 'error' => $error], JSON_UNESCAPED_UNICODE); exit; };
    $name = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['subject_name'] ?? '')));
    if ($name === '') { $reply(false, 'Please type the subject name.'); }
    if (mb_strlen($name) < 3) { $reply(false, 'The subject name is too short (3 characters at least).'); }
    if (mb_strlen($name) > 100) { $reply(false, 'The subject name is too long (100 characters at most).'); }
    if (!preg_match('/^[\p{L}\p{N} .,&()\/:\'’-]+$/u', $name)) { $reply(false, 'The subject name can only have letters, numbers, spaces and . , & ( ) / : \' -'); }
    try {
        $pdo->prepare("INSERT INTO subjects (subject_name, created_by) VALUES (?, ?)")->execute([$name, $_SESSION['user_id']]);
    } catch (PDOException $e) {
        $reply(false, $e->errorInfo[1] == 1062 ? "'$name' is already in the list." : 'Could not save the subject: ' . $e->getMessage());
    }
    $_SESSION['subj_flash'] = ['type' => 'success', 'msg' => "Subject '" . htmlspecialchars($name) . "' added. The Program Head can now make classes from it (subject code, section and professor)."];
    $reply(true);
}

// ---------------- DELETE (the Account-Management Password; only a subject no class uses) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_subject') {
    $check = checkCredential($pdo, 'action_password', $_POST['credential'] ?? '');
    if (!$check['ok']) {
        if (!empty($check['logout'])) { endSessionAndRedirectLocked(); }
        $flash = ['type' => 'error', 'msg' => htmlspecialchars($check['error'])];
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $s = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?"); $s->execute([$id]); $subj = $s->fetch();
        if (!$subj) { $flash = ['type' => 'error', 'msg' => 'That subject could not be found.']; }
        else {
            $n = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE subject_name = ?"); $n->execute([$subj['subject_name']]);
            if ((int) $n->fetchColumn() > 0) { $flash = ['type' => 'error', 'msg' => htmlspecialchars("'" . $subj['subject_name'] . "' is used by a class, so it cannot be deleted.")]; }
            else { $pdo->prepare("DELETE FROM subjects WHERE id = ?")->execute([$id]); $flash = ['type' => 'success', 'msg' => htmlspecialchars("Subject '" . $subj['subject_name'] . "' deleted.")]; }
        }
    }
}

$cur = currentSchoolYear($pdo);
$sem = function_exists('storedCurrentSemester') ? (int) storedCurrentSemester($pdo) : 1;
$search = mb_substr(trim($_GET['q'] ?? ''), 0, 60);
$sql = "SELECT s.*, u.full_name AS created_by_name,
               (SELECT COUNT(*) FROM classes c WHERE c.subject_name = s.subject_name AND c.academic_year = ? AND c.semester = ?) AS n_now,
               (SELECT COUNT(*) FROM classes c WHERE c.subject_name = s.subject_name) AS n_all
        FROM subjects s LEFT JOIN users u ON u.user_id = s.created_by" . ($search !== '' ? " WHERE s.subject_name LIKE ?" : '') . " ORDER BY s.subject_name ASC";
$params = [$cur['ay'], (int) $cur['sem']]; if ($search !== '') { $params[] = "%$search%"; }
$rows = []; $setupError = '';
try { $q = $pdo->prepare($sql); $q->execute($params); $rows = $q->fetchAll(); }
catch (PDOException $e) { $setupError = 'The Subjects table is missing. Run database/migration_roles.sql once. (' . $e->getMessage() . ')'; }
$semLabel = (int) $cur['sem'] === 1 ? '1st Semester' : '2nd Semester';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subjects | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
</head>
<body>
<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <h1>Subjects</h1>
            <p>Add the subjects of the curriculum. The subject code, the section and the professor are given by the <strong>Program Head</strong> in Class Assignments, when a class is made from a subject.</p>
        </div>
    </section>

    <?php if ($setupError): ?><div class="alert-banner alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($setupError) ?></div><?php endif; ?>
    <?php if ($flash): ?>
        <div class="alert-banner alert-<?= $flash['type'] ?>"><i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><span><?= $flash['msg'] ?></span></div>
    <?php endif; ?>

    <form method="GET" class="toolbar">
        <div class="search-box">
            <button type="submit" class="search-submit-btn" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
            <input type="text" name="q" placeholder="Search a subject" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
        </div>
        <button type="button" class="btn-primary" onclick="openSubjectModal()"><i class="fa-solid fa-plus"></i> Add Subject</button>
    </form>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Subject</th><th>Classes (<?= htmlspecialchars($cur['ay']) ?> · <?= $semLabel ?>)</th><th>Added by</th><th>Date added</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if (!$rows): ?><tr><td colspan="5">No subject yet. Press <strong>Add Subject</strong>.</td></tr><?php endif; ?>
                <?php foreach ($rows as $s): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($s['subject_name']) ?></strong></td>
                        <td><?= (int) $s['n_now'] ?><?= (int) $s['n_all'] > (int) $s['n_now'] ? ' <span class="small-ay" style="display:inline;">(' . (int) $s['n_all'] . ' in all)</span>' : '' ?></td>
                        <td><?= $s['created_by'] ? htmlspecialchars($s['created_by_name'] ?: $s['created_by']) : '<span style="color:#9aa4aa;">Imported records</span>' ?></td>
                        <td><?= date('M d, Y', strtotime($s['created_at'])) ?></td>
                        <td>
                            <?php if ((int) $s['n_all'] === 0): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirmWithCredential(this, {title: 'Delete subject', message: 'This removes the subject from the list. Enter your Account-Management Password to continue.', confirmText: 'Delete', danger: true});">
                                <input type="hidden" name="action" value="delete_subject"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="credential" class="cred-field">
                                <button type="submit" class="btn-icon btn-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            <?php else: ?><span style="font-size:12px;color:#9aa4aa;">In use</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Add Subject (errors stay in this window) -->
    <div class="modal-overlay" id="subjectModal">
        <div class="modal-box" style="width:480px;">
            <h2>Add Subject</h2>
            <form id="subjectForm" novalidate>
                <div class="form-group">
                    <label for="s_name">Subject name</label>
                    <input type="text" id="s_name" maxlength="100" placeholder="e.g. Introduction to Computing" autocomplete="off" required>
                </div>
                <p style="font-size:12.5px;color:#6b7f88;margin:8px 0 0;line-height:1.5;"><i class="fa-solid fa-circle-info"></i> Only the name. The Program Head gives the subject code, chooses the section and assigns the professor.</p>
                <div id="subjError" role="alert" style="display:none;margin:12px 0 0;padding:10px 14px;border-radius:10px;background:#fdecea;color:#c62828;font-size:13.5px;"></div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeSubjectModal()">Cancel</button>
                    <button type="submit" class="btn-primary" id="s_save">Add Subject</button>
                </div>
            </form>
        </div>
    </div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<?php include __DIR__ . '/includes/credential_modal.php'; ?>
<script>
    const sModal = document.getElementById('subjectModal'), sName = document.getElementById('s_name'), sErr = document.getElementById('subjError');
    function openSubjectModal() { sName.value = ''; sErr.style.display = 'none'; sModal.classList.add('show'); setTimeout(function () { sName.focus(); }, 50); }
    function closeSubjectModal() { sModal.classList.remove('show'); }
    sModal.addEventListener('click', function (e) { if (e.target === sModal) closeSubjectModal(); });
    sName.addEventListener('input', function () { sErr.style.display = 'none'; });
    function showSErr(m) { sErr.textContent = m; sErr.style.display = 'block'; sName.focus(); }
    // the window stays open until the subject is saved or Cancel is pressed
    document.getElementById('subjectForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const name = sName.value.trim().replace(/\s+/g, ' ');
        if (!name) return showSErr('Please type the subject name.');
        if (name.length < 3) return showSErr('The subject name is too short (3 characters at least).');
        const btn = document.getElementById('s_save'); btn.disabled = true;
        const fd = new FormData(); fd.append('action', 'add_subject'); fd.append('subject_name', name);
        fetch('subjects.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (res) { btn.disabled = false; if (res.ok) location.href = 'subjects.php'; else showSErr(res.error || 'Could not save the subject.'); })
            .catch(function () { btn.disabled = false; showSErr('Could not reach the server. Please try again.'); });
    });
</script>
</body>
</html>
