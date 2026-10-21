<?php
/** One-time codes sent by e-mail: to reset a forgotten password, and to confirm changing a password or PIN. */
require_once __DIR__ . '/mail.php';

const OTP_MINUTES = 10;
const OTP_MAX_TRIES = 5;
const OTP_RESEND_SECONDS = 45;

function otpEnsureTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(50) NOT NULL,
        purpose VARCHAR(20) NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        tries TINYINT NOT NULL DEFAULT 0,
        used TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_codes (user_id, purpose)
    )");
}
function otpMaskEmail(string $e): string {
    [$name, $dom] = array_pad(explode('@', $e, 2), 2, '');
    return substr($name, 0, 1) . str_repeat('•', max(2, strlen($name) - 2)) . substr($name, -1) . '@' . $dom;
}
/** Makes and e-mails a 6-digit code. Returns ['ok', 'error', 'demo_code' (only in demo mode), 'email' (masked)]. */
function otpSend(PDO $pdo, array $user, string $purpose): array {
    otpEnsureTable($pdo);
    if (empty($user['email'])) { return ['ok' => false, 'error' => 'There is no e-mail address on this account. Add one in User Profile first.']; }
    $q = $pdo->prepare("SELECT created_at FROM email_codes WHERE user_id = ? AND purpose = ? ORDER BY id DESC LIMIT 1");
    $q->execute([$user['user_id'], $purpose]);
    $last = $q->fetchColumn();
    if ($last && time() - strtotime($last) < OTP_RESEND_SECONDS) { return ['ok' => false, 'error' => 'Please wait a moment before asking for another code.']; }
    $code = (string) random_int(100000, 999999);
    $pdo->prepare("UPDATE email_codes SET used = 1 WHERE user_id = ? AND purpose = ? AND used = 0")->execute([$user['user_id'], $purpose]);
    $pdo->prepare("INSERT INTO email_codes (user_id, purpose, code_hash, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL " . OTP_MINUTES . " MINUTE))")
        ->execute([$user['user_id'], $purpose, password_hash($code, PASSWORD_DEFAULT)]);
    $what = ['reset' => 'reset your password', 'password' => 'change your login password', 'pin' => 'change your Student Data PIN', 'action_password' => 'change your Account-Management Password'][$purpose] ?? 'confirm this change';
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;border:1px solid #dde9ef;border-radius:12px;padding:24px;">'
          . '<h2 style="color:#064a5e;margin:0 0 8px;">Student Attrition Risk Prediction System</h2>'
          . '<p style="color:#33475a;">Hello ' . htmlspecialchars($user['full_name'] ?? '') . ', use this code to ' . $what . ':</p>'
          . '<p style="font-size:32px;letter-spacing:8px;font-weight:700;color:#0b8fa3;margin:18px 0;">' . $code . '</p>'
          . '<p style="color:#64727a;font-size:13px;">It works for ' . OTP_MINUTES . ' minutes. If you did not ask for it, ignore this e-mail — nothing changes.</p></div>';
    $m = sendMail($user['email'], 'Your confirmation code: ' . $code, $html);
    if (!$m['ok']) { return ['ok' => false, 'error' => $m['error']]; }
    return ['ok' => true, 'error' => null, 'email' => otpMaskEmail($user['email']), 'demo_code' => MAIL_SHOW_CODE ? $code : null];
}
/** Checks a code. null = correct (and now used up); otherwise the message to show. */
function otpVerify(PDO $pdo, string $userId, string $purpose, string $code): ?string {
    otpEnsureTable($pdo);
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) { return 'Enter the 6-digit code that was sent to your e-mail (press “Send code” first).'; }
    $q = $pdo->prepare("SELECT id, code_hash, tries FROM email_codes WHERE user_id = ? AND purpose = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $q->execute([$userId, $purpose]);
    $row = $q->fetch();
    if (!$row) { return 'That code has expired or was not asked for. Press “Send code” to get a new one.'; }
    if ((int) $row['tries'] >= OTP_MAX_TRIES) { return 'Too many wrong tries. Press “Send code” to get a new code.'; }
    if (!password_verify($code, $row['code_hash'])) {
        $pdo->prepare("UPDATE email_codes SET tries = tries + 1 WHERE id = ?")->execute([$row['id']]);
        return 'That code is not correct.';
    }
    $pdo->prepare("UPDATE email_codes SET used = 1 WHERE id = ?")->execute([$row['id']]);
    return null;
}
/** For Privacy Settings: the message to show if the e-mail code is missing or wrong (null = fine). */
function emailCodeError(PDO $pdo, array $user, string $purpose, string $code): ?string {
    if (empty($user['email'])) { return 'Add your e-mail address in User Profile first — a code is sent there to confirm this change.'; }
    return otpVerify($pdo, $user['user_id'], $purpose, $code);
}
