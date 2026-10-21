<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/validation.php';
require_once __DIR__ . '/../config/otp.php';

// One Privacy Settings page for every role, with the same layout. Every secret has its own clearly named card:
//   Faculty       -> Login Password  +  Student Data PIN
//   Program Head  -> Login Password  +  PIN (to clear a class / archive a semester)
//   Admin         -> Login Password  +  Account-Management Password  +  Default Password
$activePage   = 'privacy';
$flash        = null;
$isFaculty  = canUsePin();           // only Faculty hold a Student Data PIN; Admin has the Account-Management Password; the Program Head only a login password
$isAdminUser  = isAdmin();
$forcedReset  = !empty($_SESSION['must_change_password']);

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$pinAlreadySet = $isFaculty && !empty($user['student_data_pin_hash']);
$actionPwSet   = $isAdminUser && !empty($user['action_password_hash']);

// A failed check: log out on the final lockout, otherwise show the message right here on the page.
$handleFail = function (array $check) use (&$flash) {
    if (!empty($check['logout'])) { endSessionAndRedirectLocked(); }
    $flash = ['type' => 'error', 'msg' => $check['error']];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- Login Password (everyone) ----
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $proceed = true;

        // Right after a reset/new account they've just typed the temporary password to log in,
        // so they only choose their new one.
        if (!$forcedReset) {
            $check = checkCredential($pdo, 'password', $current);
            if (!$check['ok']) { $handleFail($check); $proceed = false; }
        }
        if ($proceed) {
            if (($err = validateStrongPassword($new)) !== null) {
                $flash = ['type' => 'error', 'msg' => $err];
            } elseif ($new !== $confirm) {
                $flash = ['type' => 'error', 'msg' => 'New password and confirmation do not match.'];
            } elseif (password_verify($new, $user['password_hash'])) {
                $flash = ['type' => 'error', 'msg' => 'Your new login password must be different from the current one.'];
            } elseif ($actionPwSet && password_verify($new, $user['action_password_hash'])) {
                $flash = ['type' => 'error', 'msg' => 'Your login password must be different from your Account-Management Password — they are two separate passwords.'];
            } elseif (!$forcedReset && ($otpErr = emailCodeError($pdo, $user, 'password', $_POST['email_code'] ?? '')) !== null) {
                $flash = ['type' => 'error', 'msg' => $otpErr];
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE user_id = ?")->execute([$hash, $_SESSION['user_id']]);
                $user['password_hash'] = $hash;
                $_SESSION['must_change_password'] = false;
                $forcedReset = false;
                $flash = ['type' => 'success', 'msg' => 'Login password updated successfully.'];
            }
        }
    }

    // ---- Student Data PIN (Faculty only) ----
    if ($action === 'set_pin' && $isFaculty && !$forcedReset) {
        $currentPin = $_POST['current_pin'] ?? '';
        $newPin     = $_POST['new_pin'] ?? '';
        $confirmPin = $_POST['confirm_pin'] ?? '';
        $proceed = true;

        if ($pinAlreadySet) {
            $check = checkCredential($pdo, 'pin', $currentPin);
            if (!$check['ok']) { $handleFail($check); $proceed = false; }
        }
        if ($proceed) {
            if (($err = validatePin($newPin)) !== null) {
                $flash = ['type' => 'error', 'msg' => $err];
            } elseif ($newPin !== $confirmPin) {
                $flash = ['type' => 'error', 'msg' => 'New PIN and confirmation do not match.'];
            } elseif ($pinAlreadySet && ($otpErr = emailCodeError($pdo, $user, 'pin', $_POST['email_code'] ?? '')) !== null) {
                $flash = ['type' => 'error', 'msg' => $otpErr];
            } else {
                $wasSet = $pinAlreadySet;
                $pdo->prepare("UPDATE users SET student_data_pin_hash = ? WHERE user_id = ?")
                    ->execute([password_hash($newPin, PASSWORD_DEFAULT), $_SESSION['user_id']]);
                $pinAlreadySet = true;
                $flash = ['type' => 'success', 'msg' => $wasSet ? 'Your Student Data PIN has been updated.' : 'Your Student Data PIN has been set up.'];
            }
        }
    }

    // ---- Account-Management Password (Admin only) — a SECOND password, different from the login one ----
    if ($action === 'set_action_password' && $isAdminUser && !$forcedReset) {
        $new     = $_POST['new_action_password'] ?? '';
        $confirm = $_POST['confirm_action_password'] ?? '';

        // First time: prove it's really you with your login password. After that: your current Account-Management Password.
        $check = $actionPwSet
            ? checkCredential($pdo, 'action_password', $_POST['current_action_password'] ?? '')
            : checkCredential($pdo, 'password', $_POST['login_password_confirm'] ?? '');

        if (!$check['ok']) {
            $handleFail($check);
        } elseif (($err = validateStrongPassword($new)) !== null) {
            $flash = ['type' => 'error', 'msg' => 'Account-Management Password: ' . $err];
        } elseif ($new !== $confirm) {
            $flash = ['type' => 'error', 'msg' => 'New Account-Management Password and confirmation do not match.'];
        } elseif (password_verify($new, $user['password_hash'])) {
            $flash = ['type' => 'error', 'msg' => 'It must be different from your login password — they are two separate passwords.'];
        } elseif ($actionPwSet && ($otpErr = emailCodeError($pdo, $user, 'action_password', $_POST['email_code'] ?? '')) !== null) {
            $flash = ['type' => 'error', 'msg' => $otpErr];
        } else {
            $wasSet = $actionPwSet;
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET action_password_hash = ? WHERE user_id = ?")->execute([$hash, $_SESSION['user_id']]);
            $user['action_password_hash'] = $hash;
            $actionPwSet = true;
            $flash = ['type' => 'success', 'msg' => $wasSet ? 'Your Account-Management Password has been updated.' : 'Your Account-Management Password has been set up.'];
        }
    }

    // ---- Default password for new / reset accounts (Admin only; confirmed with the Account-Management Password) ----
    if ($action === 'set_default_password' && $isAdminUser && !$forcedReset) {
        $new = $_POST['default_password'] ?? '';
        $check = checkCredential($pdo, 'action_password', $_POST['current_action_password'] ?? '');
        if (!$check['ok']) {
            $handleFail($check);
        } elseif (($err = validateStrongPassword($new)) !== null) {
            $flash = ['type' => 'error', 'msg' => 'Default password: ' . $err];
        } else {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('default_password', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$new]);
            $flash = ['type' => 'success', 'msg' => 'Default password updated. It applies to accounts created or reset from now on.'];
        }
    }
}

$lock = ($isFaculty || $isAdminUser) ? getLockStatus($pdo, $_SESSION['user_id']) : ['locked' => false];
$currentDefault = $isAdminUser ? getDefaultPassword($pdo) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Settings | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
    <style>
        .profile-wrap { max-width: 640px; margin: 0 30px 30px; }
        .profile-card {
            background: white; border: 1px solid #e8edf0; border-radius: 12px;
            padding: 28px; box-shadow: 0 3px 10px rgba(0,0,0,.03); margin-bottom: 20px;
        }
        .profile-card h2 { font-size: 17px; color: #173f5f; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .profile-card h2 i { color: #1681ad; }
        .profile-card .card-note { font-size: 13px; color: #7a858d; margin-bottom: 6px; line-height: 1.6; }
        .profile-card .form-group { margin-bottom: 14px; margin-top: 16px; }
        .profile-card .form-group label { display: block; font-size: 14px; font-weight: 600; color: #33475a; margin-bottom: 6px; }
        .profile-card input[type="password"], .profile-card input[type="text"] {
            width: 100%; padding: 11px; border: 1px solid #d8d8d8; border-radius: 8px; font-size: 15px; box-sizing: border-box;
        }
        .profile-card input:focus { border-color: #1E88E5; outline: none; }
        .profile-card input.pin-input { font-size: 18px; }
        .chip { display: inline-block; font-size: 11.5px; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
        .chip.login   { background: #e3f6fb; color: #087fa7; }
        .chip.confirm { background: #fff1d6; color: #8a5a00; }
        .chip.newacct { background: #e3f7ec; color: #16824a; }
        .summary-box { background: #f3f8fc; border: 1px solid #dcebf5; border-radius: 12px; padding: 18px 20px; margin-bottom: 20px; }
        .summary-box h3 { font-size: 14.5px; color: #173f5f; margin-bottom: 10px; }
        .summary-box ul { list-style: none; padding: 0; margin: 0; }
        .summary-box li { font-size: 13.5px; color: #455a64; margin-bottom: 8px; display: flex; gap: 10px; align-items: flex-start; line-height: 1.5; }
        .summary-box .warn { margin-top: 10px; font-size: 13px; color: #8a5a00; background: #fff8e6; padding: 8px 12px; border-radius: 8px; }
        .forced-reset-banner {
            background: #fff1d6; color: #8a5a00; padding: 16px 18px; border-radius: 10px;
            font-size: 14px; margin: 0 30px 20px; display: flex; gap: 10px; align-items: flex-start;
        }
        .default-box { background: #eef5fb; border-radius: 8px; padding: 10px 14px; font-size: 13.5px; color: #33475a; margin-top: 12px; }
        .default-box code { font-family: monospace; font-size: 14px; font-weight: 700; color: #173f5f; }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <a href="<?= $homeUrl ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to <?= htmlspecialchars($homeLabel) ?></a>
            <h1>Privacy Settings</h1>
            <p>Manage the passwords and PIN that protect your account.</p>
        </div>
    </section>

    <?php if ($forcedReset): ?>
        <div class="forced-reset-banner">
            <i class="fa-solid fa-triangle-exclamation" style="margin-top:2px;"></i>
            <div>
                <strong>You are using a temporary password.</strong><br>
                For your security, choose your own new login password below before you can use the rest of the system.
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($lock['locked'])): ?>
        <div class="alert-banner alert-error" style="margin:0 30px 18px;">
            <i class="fa-solid fa-lock"></i>
            Too many incorrect attempts — confirmations are locked for another <?= htmlspecialchars(formatWait($lock['seconds_left'])) ?>.
        </div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="alert-banner alert-<?= $flash['type'] ?>" style="margin-left:30px;margin-right:30px;">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="profile-wrap">

        <?php if (!$forcedReset): ?>
        <div class="summary-box">
            <h3><i class="fa-solid fa-circle-info"></i> What each one is for</h3>
            <ul>
                <li><span class="chip login">Login Password</span><span>Signs you in to the system.</span></li>
                <?php if ($isFaculty): ?>
                <li><span class="chip confirm">Student Data PIN</span><span>6 digits. Confirms adding, editing or deleting a student record.</span></li>
                <?php endif; ?>
                <?php if ($isAdminUser): ?>
                <li><span class="chip confirm">Account-Management Password</span><span>Confirms creating, editing, deleting or resetting an account.</span></li>
                <li><span class="chip newacct">Default Password</span><span>The starting password given to new and reset accounts.</span></li>
                <?php endif; ?>
            </ul>
            <?php if ($isAdminUser): ?>
            <div class="warn"><i class="fa-solid fa-triangle-exclamation"></i> Your Login Password and Account-Management Password must be two <strong>different</strong> passwords.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 1. LOGIN PASSWORD — everyone -->
        <div class="profile-card">
            <h2><i class="fa-solid fa-key"></i> Change Login Password <span class="chip login">Used to log in</span></h2>
            <p class="card-note">This is the password you type on the login page. Only you choose it — no one else, including Admin, can see or change it.</p>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <?php if (!$forcedReset): ?>
                <div class="form-group">
                    <label>Current Login Password</label>
                    <input type="password" name="current_password" required>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>New Login Password</label>
                    <input type="password" name="new_password" id="new_password" required>
                    <ul id="pwChecklist"></ul>
                </div>
                <div class="form-group">
                    <label>Confirm New Login Password</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <?php if (!$forcedReset): ?>
                <div class="form-group">
                    <label>E-mail code <span style="font-weight:400;color:#8a959b;">— <?= !empty($user['email']) ? 'sent to ' . htmlspecialchars(otpMaskEmail($user['email'])) : 'no e-mail on your account' ?></span></label>
                    <?php if (!empty($user['email'])): ?>
                        <div style="display:flex;gap:8px;"><input type="text" name="email_code" inputmode="numeric" maxlength="6" placeholder="6-digit code" autocomplete="off" required style="flex:1;">
                        <button type="button" class="btn-secondary send-code" data-purpose="password" style="white-space:nowrap;">Send code</button></div>
                        <div class="code-msg" style="font-size:12px;margin-top:5px;color:#64727a;"></div>
                    <?php else: ?><div style="font-size:12.5px;color:#b3261e;">Add your e-mail address in <a href="profile.php" style="color:#b3261e;font-weight:700;">User Profile</a> first — a code is sent there to confirm this change.</div><?php endif; ?>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn-primary">Update Login Password</button>
            </form>
        </div>

        <?php if (!$forcedReset && $isFaculty): ?>
        <!-- 2. STUDENT DATA PIN — Faculty -->
        <div class="profile-card">
            <h2><i class="fa-solid fa-shield-halved"></i> <?= $pinAlreadySet ? 'Change Student Data PIN' : 'Set Up Student Data PIN' ?> <span class="chip confirm">Confirms student record changes</span></h2>
            <p class="card-note">A separate 6-digit PIN only you know, asked <?= isProgramHead() ? 'when you record a student request, clear a class or archive a semester' : 'when you record in a class or add students' ?>. Nobody else, including Admin, can see or set it.</p>
            <form method="POST">
                <input type="hidden" name="action" value="set_pin">
                <?php if ($pinAlreadySet): ?>
                <div class="form-group">
                    <label>Current PIN</label>
                    <input type="password" name="current_pin" class="pin-input" inputmode="numeric" maxlength="6" required>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label><?= $pinAlreadySet ? 'New PIN' : 'Create PIN' ?> (6 digits)</label>
                    <input type="password" name="new_pin" class="pin-input" inputmode="numeric" maxlength="6" required>
                </div>
                <div class="form-group">
                    <label>Confirm <?= $pinAlreadySet ? 'New ' : '' ?>PIN</label>
                    <input type="password" name="confirm_pin" class="pin-input" inputmode="numeric" maxlength="6" required>
                </div>
                <?php if ($pinAlreadySet): ?>
                <div class="form-group">
                    <label>E-mail code <span style="font-weight:400;color:#8a959b;">— <?= !empty($user['email']) ? 'sent to ' . htmlspecialchars(otpMaskEmail($user['email'])) : 'no e-mail on your account' ?></span></label>
                    <?php if (!empty($user['email'])): ?>
                        <div style="display:flex;gap:8px;"><input type="text" name="email_code" inputmode="numeric" maxlength="6" placeholder="6-digit code" autocomplete="off" required style="flex:1;">
                        <button type="button" class="btn-secondary send-code" data-purpose="pin" style="white-space:nowrap;">Send code</button></div>
                        <div class="code-msg" style="font-size:12px;margin-top:5px;color:#64727a;"></div>
                    <?php else: ?><div style="font-size:12.5px;color:#b3261e;">Add your e-mail address in <a href="profile.php" style="color:#b3261e;font-weight:700;">User Profile</a> first — a code is sent there to confirm this change.</div><?php endif; ?>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn-primary"><?= $pinAlreadySet ? 'Update PIN' : 'Set PIN' ?></button>
            </form>
        </div>
        <?php endif; ?>

        <?php if (!$forcedReset && $isAdminUser): ?>
        <!-- 2. ACCOUNT-MANAGEMENT PASSWORD — Admin -->
        <div class="profile-card">
            <h2><i class="fa-solid fa-shield-halved"></i> <?= $actionPwSet ? 'Change Account-Management Password' : 'Set Up Account-Management Password' ?> <span class="chip confirm">Confirms account changes</span></h2>
            <p class="card-note">
                A <strong>second password</strong>, different from your login password. You type it each time you create, edit, delete or reset an account.
                <?= $actionPwSet ? '' : 'To set it up the first time, confirm with your login password.' ?>
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="set_action_password">
                <div class="form-group">
                    <?php if ($actionPwSet): ?>
                        <label>Current Account-Management Password</label>
                        <input type="password" name="current_action_password" required>
                    <?php else: ?>
                        <label>Your Login Password (to confirm it's you)</label>
                        <input type="password" name="login_password_confirm" required>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>New Account-Management Password</label>
                    <input type="password" name="new_action_password" id="new_action_password" required>
                    <ul id="actionChecklist"></ul>
                </div>
                <div class="form-group">
                    <label>Confirm New Account-Management Password</label>
                    <input type="password" name="confirm_action_password" required>
                </div>
                <?php if ($actionPwSet): ?>
                <div class="form-group">
                    <label>E-mail code <span style="font-weight:400;color:#8a959b;">— <?= !empty($user['email']) ? 'sent to ' . htmlspecialchars(otpMaskEmail($user['email'])) : 'no e-mail on your account' ?></span></label>
                    <?php if (!empty($user['email'])): ?>
                        <div style="display:flex;gap:8px;"><input type="text" name="email_code" inputmode="numeric" maxlength="6" placeholder="6-digit code" autocomplete="off" required style="flex:1;">
                        <button type="button" class="btn-secondary send-code" data-purpose="action_password" style="white-space:nowrap;">Send code</button></div>
                        <div class="code-msg" style="font-size:12px;margin-top:5px;color:#64727a;"></div>
                    <?php else: ?><div style="font-size:12.5px;color:#b3261e;">Add your e-mail address in <a href="profile.php" style="color:#b3261e;font-weight:700;">User Profile</a> first — a code is sent there to confirm this change.</div><?php endif; ?>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn-primary"><?= $actionPwSet ? 'Update Account-Management Password' : 'Set Account-Management Password' ?></button>
            </form>
        </div>

        <!-- 3. DEFAULT PASSWORD — Admin -->
        <div class="profile-card">
            <h2><i class="fa-solid fa-user-lock"></i> Default Password for New &amp; Reset Accounts <span class="chip newacct">Given to new accounts</span></h2>
            <p class="card-note">
                New accounts you create, and accounts you reset, start with this password and must replace it on first login.
                Change it whenever you like so an old default can't be reused.
            </p>
            <div class="default-box">Current default: <code><?= htmlspecialchars($currentDefault) ?></code></div>
            <?php if ($actionPwSet): ?>
            <form method="POST">
                <input type="hidden" name="action" value="set_default_password">
                <div class="form-group">
                    <label>New Default Password</label>
                    <input type="text" name="default_password" autocomplete="off" required>
                </div>
                <div class="form-group">
                    <label>Your Account-Management Password (to confirm)</label>
                    <input type="password" name="current_action_password" required>
                </div>
                <button type="submit" class="btn-primary">Update Default Password</button>
            </form>
            <?php else: ?>
                <p class="card-note" style="margin-top:12px;">Set up your Account-Management Password above first — it's needed to change this.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>

<script src="../assets/validation.js?v=3"></script>
<script>
    attachPasswordStrength(document.getElementById('new_password'), document.getElementById('pwChecklist'));
    const actionInput = document.getElementById('new_action_password');
    if (actionInput) attachPasswordStrength(actionInput, document.getElementById('actionChecklist'));
    document.querySelectorAll('input.pin-input').forEach(function (el) {
        el.addEventListener('input', function () { el.value = el.value.replace(/\D/g, '').slice(0, 6); });
    });
</script>

<script>
    // "Send code": the 6-digit code is sent to the account's e-mail (demo mode shows it on screen)
    document.querySelectorAll('.send-code').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var msg = btn.closest('.form-group').querySelector('.code-msg'); btn.disabled = true; msg.textContent = 'Sending…'; msg.style.color = '#64727a';
            var fd = new FormData(); fd.append('purpose', btn.dataset.purpose);
            fetch('send_code.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
                if (!r.ok) { msg.textContent = r.error || 'Could not send the code.'; msg.style.color = '#b3261e'; btn.disabled = false; return; }
                msg.style.color = '#1b6b3a'; msg.textContent = 'Code sent to ' + r.email + '.' + (r.demo_code ? ' Demo mode (e-mail is not set up) — your code is ' + r.demo_code + '.' : '');
                var s = 45; btn.textContent = 'Resend (' + s + ')'; var t = setInterval(function () { s--; btn.textContent = 'Resend (' + s + ')'; if (s <= 0) { clearInterval(t); btn.disabled = false; btn.textContent = 'Send code'; } }, 1000);
            }).catch(function () { msg.textContent = 'No connection to the server.'; msg.style.color = '#b3261e'; btn.disabled = false; });
        });
    });
</script>
</body>
</html>
