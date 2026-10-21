<?php
/**
 * ONE-TIME SETUP SCRIPT.
 * Run this once in your browser (e.g. http://localhost/attrition-system/create_admin.php)
 * to create your Faculty / Admin logins. DELETE THIS FILE
 * after you're done — it should never stay on a live server.
 *
 * This is the one place in the system where a password is still typed in
 * directly, rather than auto-generated — because it exists to bootstrap the
 * very first account, before any Admin exists to use users.php's "Add
 * Account" flow. Whoever runs this already has direct file access to the
 * server, so there's no one else to keep the password secret from yet.
 * Every account created afterward, via users.php, starts on the system default
 * password instead (see config/security.php::getDefaultPassword()) and must change it on first login.
 *
 * Rules enforced (see config/validation.php — the real, server-side check):
 *   - User ID: numbers and dashes only, 4-20 characters.
 *   - Password: at least 8 characters, with an uppercase letter, a lowercase
 *     letter, a number, and a special character.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/validation.php';

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId   = trim($_POST['userid']);
    $fullName = trim($_POST['fullname']);
    $password = $_POST['password'];
    $role     = $_POST['role'];

    $userIdError   = validateUserId($userId);
    $passwordError = validateStrongPassword($password);

    if ($userIdError) {
        $message = $userIdError;
        $messageType = 'error';
    } elseif ($fullName === '') {
        $message = 'Full name is required.';
        $messageType = 'error';
    } elseif ($passwordError) {
        $message = $passwordError;
        $messageType = 'error';
    } elseif (!in_array($role, ['faculty', 'program_head', 'admin'], true)) {
        $message = 'Please select a valid role.';
        $messageType = 'error';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            "INSERT INTO users (user_id, password_hash, full_name, role)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), full_name = VALUES(full_name), role = VALUES(role)"
        );
        $stmt->execute([$userId, $hash, $fullName, $role]);

        $message = "Account '$userId' created/updated successfully. You can now log in from index.php.";
        $messageType = 'success';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account</title>
<style>
    body{font-family:Arial,sans-serif;background:#eef5fb;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:30px 15px;box-sizing:border-box;}
    .box{background:white;padding:38px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.1);width:400px;max-width:100%;}
    h2{margin-top:0;font-size:20px;color:#173f5f;}
    label{display:block;font-size:14px;font-weight:600;color:#33475a;margin-bottom:6px;}
    input,select{width:100%;padding:11px;margin-bottom:6px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box;font-size:15px;}
    .field{margin-bottom:14px;}
    .hint{font-size:12px;color:#c62828;display:none;margin-bottom:8px;}
    button{width:100%;padding:13px;background:#0078d7;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:15px;margin-top:8px;}
    button:hover{background:#0063b3;}
    p.msg{padding:12px;border-radius:6px;font-size:14px;}
    p.msg.success{background:#e3f7ec;color:#16824a;}
    p.msg.error{background:#ffe4e4;color:#c62828;}
    .small-note{font-size:12px;color:#8a959b;margin-top:4px;}
</style>
</head>
<body>
<div class="box">
    <h2>Create Account</h2>
    <?php if ($message): ?><p class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <form method="POST" id="createForm">
        <div class="field">
            <label>User ID (numbers and dashes only)</label>
            <input type="text" name="userid" id="userid" placeholder="e.g. 2022-1001" required>
            <div class="hint" id="useridHint"></div>
            <div class="small-note">4 to 20 characters — numbers and dashes (-) only. Letters are not allowed.</div>
        </div>
        <div class="field">
            <label>Full Name</label>
            <input type="text" name="fullname" placeholder="e.g. Juan Dela Cruz" required>
        </div>
        <div class="field">
            <label>Password</label>
            <input type="password" name="password" id="password" placeholder="Password" required>
            <ul id="pwChecklist"></ul>
        </div>
        <div class="field">
            <label>Role</label>
            <select name="role">
                <option value="faculty">Faculty</option>
                <option value="program_head">Program Head</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <button type="submit">Create Account</button>
    </form>
</div>
<script src="assets/validation.js?v=3"></script>
<script>
    lockUserIdField(document.getElementById('userid'), document.getElementById('useridHint'));
    attachPasswordStrength(document.getElementById('password'), document.getElementById('pwChecklist'));
</script>
</body>
</html>
