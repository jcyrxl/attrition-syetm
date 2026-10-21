<?php
// Forgot password: the user ID + the e-mail address on the account -> a 6-digit code by e-mail -> a new password.
// It works for every role, the Admin included (so an Admin who forgets the password is not locked out for good).
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/validation.php';
require_once __DIR__ . '/config/otp.php';

$step = 'request'; $msg = null; $msgType = 'error'; $demo = null; $uid = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = trim($_POST['userid'] ?? '');
    $find = $pdo->prepare("SELECT user_id, full_name, email, password_hash FROM users WHERE user_id = ? LIMIT 1");
    $find->execute([$uid]);
    $user = $find->fetch();
    if (($_POST['step'] ?? '') === 'request') {
        $email = trim($_POST['email'] ?? '');
        $step = 'reset';
        if ($user && !empty($user['email']) && strcasecmp($user['email'], $email) === 0) {
            $r = otpSend($pdo, $user, 'reset');
            if (!$r['ok']) { $msg = $r['error']; $step = 'request'; }
            else { $msg = 'We sent a 6-digit code to ' . $r['email'] . '. It works for ' . OTP_MINUTES . ' minutes.'; $msgType = 'ok'; $demo = $r['demo_code']; }
        } else {
            // the same answer whether or not the details match, so nobody can find out which accounts exist
            $msg = 'If the User ID and e-mail match an account, a 6-digit code was sent to that e-mail.'; $msgType = 'ok';
        }
    } else {
        $step = 'reset';
        $new = $_POST['new_password'] ?? ''; $confirm = $_POST['confirm_password'] ?? '';
        if (($err = validateStrongPassword($new)) !== null) { $msg = $err; }
        elseif ($new !== $confirm) { $msg = 'The two passwords do not match.'; }
        elseif (!$user) { $msg = 'That code is not correct.'; }
        elseif (($err = otpVerify($pdo, $user['user_id'], 'reset', $_POST['code'] ?? '')) !== null) { $msg = $err; }
        elseif (password_verify($new, $user['password_hash'])) { $msg = 'Choose a password different from the old one.'; }
        else {
            $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, verify_fail_count = 0, verify_stage = 0, verify_locked_until = NULL WHERE user_id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['user_id']]);
            header('Location: index.php?reset=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot password | Student Attrition Risk Prediction System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; } body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: linear-gradient(135deg, #064a5e, #0b8fa3 60%, #5cc4e0); font-family: 'Poppins', sans-serif; padding: 20px; }
        .card { background: #fff; border-radius: 18px; padding: 30px 32px; width: 100%; max-width: 430px; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
        h1 { font-size: 20px; color: #064a5e; margin: 0 0 4px; } p.sub { font-size: 13px; color: #64727a; margin: 0 0 16px; line-height: 1.55; }
        label { display: block; font-size: 13px; font-weight: 600; color: #455a64; margin: 12px 0 5px; }
        input { width: 100%; padding: 11px 12px; border: 1px solid #d3dde3; border-radius: 9px; font-size: 14.5px; font-family: inherit; }
        button { width: 100%; margin-top: 18px; padding: 12px; border: none; border-radius: 10px; background: linear-gradient(90deg, #0b8fa3, #087fae); color: #fff; font-weight: 600; font-size: 15px; cursor: pointer; font-family: inherit; }
        .msg { padding: 10px 14px; border-radius: 9px; font-size: 13px; margin-bottom: 8px; line-height: 1.5; } .msg.error { background: #fff1f0; color: #b3261e; } .msg.ok { background: #e8f6ec; color: #1b6b3a; }
        .demo { background: #fff8e6; border: 1px dashed #f0c969; color: #6b4600; border-radius: 9px; padding: 10px 14px; font-size: 13px; margin-bottom: 8px; } .demo b { font-size: 20px; letter-spacing: 4px; }
        a.back { display: block; text-align: center; margin-top: 14px; font-size: 13px; color: #087fa7; text-decoration: none; }
    </style>
</head>
<body>
<div class="card">
    <h1>Forgot your password?</h1>
    <?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($demo): ?><div class="demo"><strong>Demo mode</strong> — e-mail is not set up on this computer (see <code>config/mail.php</code>), so the code is shown here instead: <b><?= htmlspecialchars($demo) ?></b></div><?php endif; ?>
    <?php if ($step === 'request'): ?>
        <p class="sub">Type your User ID and the e-mail address on your account. We will send you a code to choose a new password. (Your e-mail is set in <em>User Profile</em>.)</p>
        <form method="POST"><input type="hidden" name="step" value="request">
            <label>User ID</label><input type="text" name="userid" value="<?= htmlspecialchars($uid) ?>" required autocomplete="username">
            <label>E-mail address on the account</label><input type="email" name="email" required autocomplete="email">
            <button type="submit">Send me a code</button></form>
        <p class="sub" style="margin-top:14px;">No e-mail on your account? Ask the Administrator to reset your password. If you are the Administrator, ask your backup Admin.</p>
    <?php else: ?>
        <p class="sub">Enter the code, then choose your new password (at least 8 characters, with upper and lower case letters, a number and a symbol).</p>
        <form method="POST"><input type="hidden" name="step" value="reset"><input type="hidden" name="userid" value="<?= htmlspecialchars($uid) ?>">
            <label>6-digit code</label><input type="text" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
            <label>New password</label><input type="password" name="new_password" required autocomplete="new-password">
            <label>Confirm new password</label><input type="password" name="confirm_password" required autocomplete="new-password">
            <button type="submit">Change my password</button></form>
    <?php endif; ?>
    <a class="back" href="index.php">← Back to log in</a>
</div>
</body>
</html>
