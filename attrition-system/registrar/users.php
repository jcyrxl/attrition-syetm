<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/validation.php';

// Admin-only page. Anyone else is bounced straight back to their own dashboard.
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$activePage = 'users';
$flash = null;
if (!empty($_SESSION['um_flash'])) { $flash = $_SESSION['um_flash']; unset($_SESSION['um_flash']); }   // left by the Add Account modal

// ------------------------------------------------------------
// ADD ACCOUNT (JSON, from the modal): no Account-Management Password is asked — the Admin already proved who they are at the log-in page.
// Any problem is sent back to the modal and shown there; the modal stays open until the account is saved or the Admin presses Cancel.
// (Editing, resetting a password and deleting still ask for the Account-Management Password.)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_account') {
    header('Content-Type: application/json; charset=utf-8');
    requireUserManagePermission();
    $reply = function (bool $ok, string $error = '', string $field = '') { echo json_encode(['ok' => $ok, 'error' => $error, 'field' => $field], JSON_UNESCAPED_UNICODE); exit; };
    $userId   = trim($_POST['user_id'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $role     = $_POST['role'] ?? '';
    if ($err = validateUserId($userId)) { $reply(false, $err, 'f_user_id'); }
    if ($fullName === '') { $reply(false, 'Full name is required.', 'f_full_name'); }
    if (mb_strlen($fullName) > 150) { $reply(false, 'Full name is too long (150 characters at most).', 'f_full_name'); }
    if (!in_array($role, ['faculty', 'program_head', 'admin'], true)) { $reply(false, 'Please select a valid role.', 'f_role'); }
    try {
        $default = getDefaultPassword($pdo);
        $pdo->prepare("INSERT INTO users (user_id, password_hash, full_name, role, must_change_password, created_by) VALUES (?,?,?,?,1,?)")
            ->execute([$userId, password_hash($default, PASSWORD_DEFAULT), $fullName, $role, $_SESSION['user_id']]);
    } catch (PDOException $e) {
        $reply(false, $e->errorInfo[1] == 1062 ? "User ID '$userId' is already taken." : 'Could not save the account: ' . $e->getMessage(), $e->errorInfo[1] == 1062 ? 'f_user_id' : '');
    }
    $_SESSION['um_flash'] = ['type' => 'success', 'msg' => "Account '" . htmlspecialchars($userId) . "' created. Starting password: <strong style=\"font-family:monospace;\">" . htmlspecialchars($default) . "</strong> (the system default) — they must choose their own on first login."];
    $reply(true);
}

// ------------------------------------------------------------
// Every crucial action (create / edit / delete / reset) needs the Admin's own password again.
// A wrong password never leaves the page — it just shows a message and lets them retry, with the
// escalating lockout in config/security.php after repeated failures.
//
// Passwords: Admin never types or sees anyone's password. New and reset accounts start with the
// system default password (set in Privacy Settings) and must choose their own on first login.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireUserManagePermission();
    $action = $_POST['action'] ?? '';

    $check = checkCredential($pdo, 'action_password', $_POST['credential'] ?? '');
    if (!$check['ok']) {
        if (!empty($check['logout'])) { endSessionAndRedirectLocked(); }
        $flash = ['type' => 'error', 'msg' => htmlspecialchars($check['error'])];
        $action = null;
    }

    if ($action === 'save') {
        $id       = $_POST['id'] ?? '';
        $userId   = trim($_POST['user_id'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $role     = $_POST['role'] ?? '';

        $userIdError = validateUserId($userId);
        $roleValid   = in_array($role, ['faculty', 'program_head', 'admin'], true);

        // An Admin can never change their OWN role, even if the form is tampered with.
        $isEditingSelf = false;
        if ($id) {
            $target = $pdo->prepare("SELECT user_id, role FROM users WHERE id = ?");
            $target->execute([$id]);
            $target = $target->fetch();
            if ($target && $target['user_id'] === $_SESSION['user_id']) {
                $isEditingSelf = true;
                $role = $target['role'];
                $roleValid = true;
            }
        }

        // The User ID is fixed once an account exists — editing never changes it.
        if ($id && !empty($target)) {
            $userId = $target['user_id'];
            $userIdError = null;
        } elseif ($id) {
            $userIdError = 'Account not found.';
        }

        if ($userIdError) {
            $flash = ['type' => 'error', 'msg' => htmlspecialchars($userIdError)];
        } elseif ($fullName === '') {
            $flash = ['type' => 'error', 'msg' => 'Full name is required.'];
        } elseif (!$roleValid) {
            $flash = ['type' => 'error', 'msg' => 'Please select a valid role.'];
        } else {
            try {
                if ($id) {
                    $pdo->prepare("UPDATE users SET full_name=?, role=?, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$fullName, $role, $_SESSION['user_id'], $id]);
                    $flash = ['type' => 'success', 'msg' => "Account '$userId' updated successfully." . ($isEditingSelf ? ' (Your own role cannot be changed.)' : '')];
                } else {
                    $default = getDefaultPassword($pdo);
                    $pdo->prepare("INSERT INTO users (user_id, password_hash, full_name, role, must_change_password, created_by) VALUES (?,?,?,?,1,?)")
                        ->execute([$userId, password_hash($default, PASSWORD_DEFAULT), $fullName, $role, $_SESSION['user_id']]);
                    $flash = [
                        'type' => 'success',
                        'msg'  => "Account '$userId' created. Starting password: <strong style=\"font-family:monospace;\">" . htmlspecialchars($default)
                                . "</strong> (the system default) — they must choose their own on first login.",
                    ];
                }
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'msg' => $e->errorInfo[1] == 1062 ? "User ID '$userId' is already taken." : htmlspecialchars($e->getMessage())];
            }
        }
    }

    if ($action === 'reset_password') {
        $id = $_POST['id'] ?? '';
        $target = $pdo->prepare("SELECT user_id, role FROM users WHERE id = ?");
        $target->execute([$id]);
        $target = $target->fetch();

        if (!$target) {
            $flash = ['type' => 'error', 'msg' => 'Account not found.'];
        } elseif ($target['user_id'] === $_SESSION['user_id']) {
            $flash = ['type' => 'error', 'msg' => 'To change your own password, use Privacy Settings.'];
        } else {
            $default = getDefaultPassword($pdo);
            // Back to the default + forced change; also clears any lockout so a locked-out person can recover.
            // (An Admin account that gets reset also loses its Account-Management Password and must set a new one.)
            $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 1,
                           action_password_hash = CASE WHEN role = 'admin' THEN NULL ELSE action_password_hash END,
                           verify_fail_count = 0, verify_stage = 0, verify_locked_until = NULL,
                           updated_by = ?, updated_at = NOW() WHERE id = ?")
                ->execute([password_hash($default, PASSWORD_DEFAULT), $_SESSION['user_id'], $id]);
            $flash = [
                'type' => 'success',
                'msg'  => "Password reset for '{$target['user_id']}'. Their password is now the system default: <strong style=\"font-family:monospace;\">"
                        . htmlspecialchars($default) . "</strong> — they must choose a new one on next login. You never see or set their own password."
                        . ($target['role'] === 'admin' ? ' Their Account-Management Password was cleared too, so they must set a new one.' : ''),
            ];
        }
    }

    if ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        $target = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $target->execute([$id]);
        $target = $target->fetch();

        if ($target && $target['user_id'] === $_SESSION['user_id']) {
            $flash = ['type' => 'error', 'msg' => 'You cannot delete your own account while logged in.'];
        } elseif ($target && $target['role'] === 'admin') {
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                $flash = ['type' => 'error', 'msg' => 'Cannot delete the last remaining Admin account.'];
            }
        }
        if (!$flash) {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $flash = ['type' => 'success', 'msg' => 'Account deleted.'];
        }
    }
}

// ------------------------------------------------------------
// Search / filter
// ------------------------------------------------------------
$search = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? '';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(full_name LIKE ? OR user_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($roleFilter !== '') {
    $where[] = "role = ?";
    $params[] = $roleFilter;
}
$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

$stmt = $pdo->prepare("SELECT users.*, cb.full_name AS created_by_name, ub.full_name AS updated_by_name
                       FROM users LEFT JOIN users cb ON cb.user_id = users.created_by LEFT JOIN users ub ON ub.user_id = users.updated_by
                       " . str_replace(['full_name', 'user_id', 'role'], ['users.full_name', 'users.user_id', 'users.role'], $whereSql) . " ORDER BY users.created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll();

$roleBadge = ['admin' => 'high-badge', 'program_head' => 'low-badge', 'faculty' => 'moderate-badge'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
</head>
<body>

<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <h1>User Management</h1>
            <p>Create accounts and assign roles. Adding an account needs no password (you are already logged in); editing, resetting and deleting ask for your Account-Management Password. New and reset accounts start with the system default password and must choose their own on first login — you never see or set anyone's password.</p>
        </div>
    </section>

    <?php
        $nAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        $meMail = $pdo->prepare("SELECT email FROM users WHERE user_id = ?"); $meMail->execute([$_SESSION['user_id']]); $meHasMail = (bool) $meMail->fetchColumn();
    ?>
    <?php if ($nAdmins < 2): ?>
        <div class="alert-banner" style="background:#fff4e0;color:#6b4600;"><i class="fa-solid fa-life-ring"></i> <span><strong>You are the only Admin.</strong> Create a <strong>backup Admin</strong> account (role: Admin) — if the only Admin forgets the password, nobody could reset it. A backup Admin can reset your password, and you can reset theirs.</span></div>
    <?php endif; ?>
    <?php if (!$meHasMail): ?>
        <div class="alert-banner" style="background:#eaf6fa;color:#064a5e;"><i class="fa-solid fa-envelope"></i> <span>Add <strong>your e-mail address</strong> in <a href="profile.php" style="color:#064a5e;font-weight:700;">User Profile</a>: with it you can recover your own password from “Forgot password?” on the login page.</span></div>
    <?php endif; ?>
    <?php if ($flash): ?>
        <div class="alert-banner alert-<?= $flash['type'] ?>">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <span><?= $flash['msg'] /* only static text, validated values, or escaped text is ever put in here */ ?></span>
        </div>
    <?php endif; ?>

    <?php if (!hasActionPassword($pdo)): ?>
        <div class="alert-banner" style="background:#fff1d6;color:#8a5a00;margin:0 30px 18px;">
            <i class="fa-solid fa-shield-halved"></i>
            You haven't set your Account-Management Password yet — you need it to edit, delete or reset accounts.
            <a href="privacy_settings.php" style="color:#8a5a00;font-weight:700;text-decoration:underline;margin-left:4px;">Set it up now</a>
        </div>
    <?php endif; ?>

    <form method="GET" class="toolbar">
        <div class="search-box">
            <button type="submit" class="search-submit-btn" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
            <input type="text" name="q" placeholder="Search by name or User ID" value="<?= htmlspecialchars($search) ?>">
        </div>

        <select name="role" class="filter-select" onchange="this.form.submit()">
            <option value="">All Roles</option>
                <option value="faculty" <?= $roleFilter === 'faculty' ? 'selected' : '' ?>>Faculty</option>
            <option value="program_head" <?= $roleFilter === 'program_head' ? 'selected' : '' ?>>Program Head</option>
            <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>

        <button type="button" class="btn-primary" onclick="openAddModal()">
            <i class="fa-solid fa-user-plus"></i> Add Account
        </button>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created by</th>
                    <th>Created</th>
                    <th>Updated by</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="9">No accounts found.</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $u): $isSelf = $u['user_id'] === $_SESSION['user_id']; ?>
                    <tr>
                        <td><?= htmlspecialchars($u['user_id']) ?></td>
                        <td><?= htmlspecialchars($u['full_name']) ?></td>
                        <td><span class="badge <?= $roleBadge[$u['role']] ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $u['role']))) ?></span></td>
                        <td>
                            <?php if ($u['must_change_password']): ?>
                                <span class="badge moderate-badge" title="They must set a new password on next login">Password Change Pending</span>
                            <?php else: ?>
                                <span style="font-size:12px;color:#9aa4aa;">Active</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $u['created_by'] ? htmlspecialchars($u['created_by_name'] ?: $u['created_by']) : '<span style="color:#9aa4aa;">Setup</span>' ?></td>
                        <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                        <td><?= $u['updated_by'] ? htmlspecialchars($u['updated_by_name'] ?: $u['updated_by']) : '<span style="color:#9aa4aa;">—</span>' ?></td>
                        <td style="white-space:nowrap;"><?= $u['updated_at'] ? date('M d, Y g:i A', strtotime($u['updated_at'])) : '<span style="color:#9aa4aa;">—</span>' ?></td>
                        <td>
                            <button class="btn-icon" title="Edit"
                                onclick='openEditModal(<?= json_encode($u) ?>, <?= json_encode($isSelf) ?>)'>
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <?php if (!$isSelf): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirmWithCredential(this, {title: 'Reset password', message: 'This puts the account back on the system default password and makes them choose a new one on next login. Enter your Account-Management Password to continue.'});">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="credential" class="cred-field">
                                <button type="submit" class="btn-icon" title="Reset Password"><i class="fa-solid fa-key"></i></button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirmWithCredential(this, {title: 'Delete account', message: 'This permanently deletes the account. Enter your Account-Management Password to continue.'});">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="credential" class="cred-field">
                                <button type="submit" class="btn-icon btn-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            <?php else: ?>
                                <span style="font-size:12px;color:#9aa4aa;">(you)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Add / Edit Modal -->
    <div class="modal-overlay" id="userModal">
        <div class="modal-box">
            <h2 id="modalTitle">Add Account</h2>
            <form method="POST" id="userForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="f_id">

                <div class="form-grid">
                    <div class="form-group">
                        <label>User ID</label>
                        <input type="text" name="user_id" id="f_user_id" placeholder="e.g. 2022-1001" maxlength="20" required>
                        <div id="useridHint" style="display:none;color:#c62828;font-size:12px;"></div>
                        <div id="useridLockedNote" style="display:none;color:#8a959b;font-size:12px;">The User ID can't be changed after the account is created.</div>
                    </div>
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" id="f_full_name" required>
                    </div>
                    <div class="form-group full">
                        <label id="roleLabel">Role</label>
                        <select name="role" id="f_role">
                            <option value="faculty">Faculty — a professor: records only the classes assigned to him or her</option>
                            <option value="program_head">Program Head — sees everything; records student requests and closes the semester (PIN)</option>
                            <option value="admin">Admin — manages accounts and adds subjects only</option>
                        </select>
                        <div class="small-note" id="roleLockedNote" style="display:none;font-size:12px;color:#8a959b;margin-top:4px;">
                            You cannot change your own role.
                        </div>
                    </div>
                    <div class="form-group full" id="passwordField">
                        <div class="small-note" style="font-size:12.5px;color:#455a64;background:#eef5fb;padding:10px 12px;border-radius:8px;">
                            <i class="fa-solid fa-circle-info"></i>
                            New accounts start with the system default password and must choose their own on first login.
                        </div>
                    </div>
                    <div class="form-group full" id="credGroup">
                        <label><i class="fa-solid fa-shield-halved"></i> Your Account-Management Password (to confirm)</label>
                        <input type="password" name="credential" id="f_credential" autocomplete="off" required>
                        <div class="inline-error" id="f_cred_error"></div>
                    </div>
                </div>
                <div id="addError" role="alert" style="display:none;margin:4px 0 0;padding:10px 14px;border-radius:10px;background:#fdecea;color:#c62828;font-size:13.5px;"></div>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Account</button>
                </div>
            </form>
        </div>
    </div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<?php include __DIR__ . '/includes/credential_modal.php'; ?>

<script src="../assets/validation.js?v=3"></script>
<script>
    const modal = document.getElementById('userModal');
    const roleField = document.getElementById('f_role');
    lockUserIdField(document.getElementById('f_user_id'), document.getElementById('useridHint'), { noClipboard: true });
    let mode = 'add';
    const addErr = document.getElementById('addError');
    function showAddError(msg, field) { addErr.textContent = msg; addErr.style.display = 'block'; const el = field && document.getElementById(field); if (el) el.focus(); }
    function clearAddError() { addErr.style.display = 'none'; addErr.textContent = ''; }
    ['f_user_id', 'f_full_name', 'f_role'].forEach(function (id) { document.getElementById(id).addEventListener('input', clearAddError); });
    // ADD: checked here and on the server; every problem is shown in this window, which stays open until the account is saved or Cancel is pressed.
    // (Registered BEFORE guardForm, and it stops the event, so the password check below applies to editing only.)
    document.getElementById('userForm').addEventListener('submit', function (e) {
        if (mode !== 'add') return;
        e.preventDefault(); e.stopImmediatePropagation(); clearAddError();
        const uid = document.getElementById('f_user_id').value.trim(), name = document.getElementById('f_full_name').value.trim();
        if (!uid) return showAddError('User ID is required.', 'f_user_id');
        if (!/^[0-9-]+$/.test(uid)) return showAddError('Invalid User ID — numbers and dashes (-) only.', 'f_user_id');
        if (uid.length < 4 || uid.length > 20) return showAddError('User ID must be 4 to 20 characters long.', 'f_user_id');
        if (!name) return showAddError('Full name is required.', 'f_full_name');
        const btn = document.querySelector('#userForm .btn-primary'); btn.disabled = true;
        const fd = new FormData(); fd.append('action', 'add_account'); fd.append('user_id', uid); fd.append('full_name', name); fd.append('role', roleField.value);
        fetch('users.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (res) { btn.disabled = false; if (res.ok) { location.href = 'users.php'; } else { showAddError(res.error || 'Could not save the account.', res.field); } })
            .catch(function () { btn.disabled = false; showAddError('Could not reach the server. Please try again.'); });
    });
    guardForm(document.getElementById('userForm'), document.getElementById('f_credential'), document.getElementById('f_cred_error'));

    function resetCredential() {
        document.getElementById('f_credential').value = '';
        document.getElementById('f_cred_error').style.display = 'none';
    }

    function openAddModal() {
        document.getElementById('modalTitle').textContent = 'Add Account';
        document.getElementById('userForm').reset();
        document.getElementById('f_id').value = '';
        document.getElementById('passwordField').style.display = 'block';
        mode = 'add'; clearAddError();
        document.getElementById('credGroup').style.display = 'none'; document.getElementById('f_credential').required = false; document.getElementById('f_credential').disabled = true;
        lockUserId(false);
        roleField.disabled = false;
        roleField.setAttribute('name', 'role');
        const hiddenRole = document.getElementById('f_role_hidden');
        if (hiddenRole) hiddenRole.remove();
        document.getElementById('roleLockedNote').style.display = 'none';
        resetCredential();
        modal.classList.add('show');
    }

    function openEditModal(user, isSelf) {
        document.getElementById('modalTitle').textContent = 'Edit Account';
        document.getElementById('f_id').value = user.id;
        document.getElementById('f_user_id').value = user.user_id;
        document.getElementById('f_full_name').value = user.full_name;
        roleField.value = user.role;

        // Editing yourself: role is locked (the server also ignores any change to it).
        // A disabled <select> doesn't submit, so a hidden input carries the value.
        roleField.disabled = !!isSelf;
        document.getElementById('roleLockedNote').style.display = isSelf ? 'block' : 'none';
        let hiddenRole = document.getElementById('f_role_hidden');
        if (isSelf) {
            if (!hiddenRole) {
                hiddenRole = document.createElement('input');
                hiddenRole.type = 'hidden';
                hiddenRole.name = 'role';
                hiddenRole.id = 'f_role_hidden';
                roleField.after(hiddenRole);
            }
            hiddenRole.value = user.role;
            roleField.removeAttribute('name');
        } else {
            if (hiddenRole) hiddenRole.remove();
            roleField.setAttribute('name', 'role');
        }

        document.getElementById('passwordField').style.display = 'none';
        mode = 'edit'; clearAddError();
        document.getElementById('credGroup').style.display = ''; document.getElementById('f_credential').required = true; document.getElementById('f_credential').disabled = false;
        lockUserId(true);
        resetCredential();
        modal.classList.add('show');
    }

    // Once an account exists its User ID is permanent (the server ignores any change too).
    function lockUserId(locked) {
        const f = document.getElementById('f_user_id');
        f.readOnly = locked;
        f.style.background = locked ? '#f5f8fa' : '';
        f.style.color = locked ? '#8a959b' : '';
        document.getElementById('useridLockedNote').style.display = locked ? 'block' : 'none';
    }

    function closeModal() { modal.classList.remove('show'); }
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
</script>

</body>
</html>
