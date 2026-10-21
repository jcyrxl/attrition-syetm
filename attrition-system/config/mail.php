<?php
/**
 * E-MAIL — used to send the confirmation codes (forgot password, changing a password or PIN).
 *
 * MAIL_MODE 'log'  (default, nothing to set up): the e-mail is NOT sent; it is saved in storage/outbox/ and the code is also shown on the
 *                  screen ("demo mode"). Good for testing and for the defense on a computer without internet mail.
 * MAIL_MODE 'smtp' : really sends it. For Gmail: turn on 2-step verification, make an "App password", then fill in SMTP_USER (your
 *                  Gmail address) and SMTP_PASS (the 16-letter app password) below; host smtp.gmail.com, port 587, security 'tls'.
 *                  (Or put the same define()s in config/mail.local.php — that file is never overwritten.)
 */
if (file_exists(__DIR__ . '/mail.local.php')) { require __DIR__ . '/mail.local.php'; }
if (!defined('MAIL_MODE'))      { define('MAIL_MODE', 'log'); }
if (!defined('MAIL_FROM'))      { define('MAIL_FROM', 'no-reply@attrition-system.local'); }
if (!defined('MAIL_FROM_NAME')) { define('MAIL_FROM_NAME', 'Student Attrition Risk Prediction System'); }
if (!defined('SMTP_HOST'))      { define('SMTP_HOST', 'smtp.gmail.com'); }
if (!defined('SMTP_PORT'))      { define('SMTP_PORT', 587); }
if (!defined('SMTP_SECURE'))    { define('SMTP_SECURE', 'tls'); }      // 'tls' (port 587) or 'ssl' (port 465)
if (!defined('SMTP_USER'))      { define('SMTP_USER', ''); }
if (!defined('SMTP_PASS'))      { define('SMTP_PASS', ''); }
if (!defined('MAIL_SHOW_CODE')) { define('MAIL_SHOW_CODE', MAIL_MODE === 'log'); }   // demo mode shows the code on screen

function smtpRead($fp): array {
    $data = ''; $code = 0;
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') { $code = (int) substr($line, 0, 3); break; }
    }
    return [$code, $data];
}
function smtpCmd($fp, string $cmd, array $ok): ?string {
    if ($cmd !== '') { fwrite($fp, $cmd . "\r\n"); }
    [$c, $d] = smtpRead($fp);
    return in_array($c, $ok, true) ? null : trim($d);
}

/** Sends (or, in demo mode, saves) an HTML e-mail. Returns ['ok' => bool, 'error' => ?string]. */
function sendMail(string $to, string $subject, string $html): array {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return ['ok' => false, 'error' => 'That e-mail address is not valid.']; }
    if (MAIL_MODE !== 'smtp') {
        $dir = __DIR__ . '/../storage/outbox';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.html';
        $ok = @file_put_contents($file, "<!-- To: $to | Subject: $subject -->\n" . $html);
        return $ok === false ? ['ok' => false, 'error' => 'Could not save the e-mail in storage/outbox (make the folder writable).'] : ['ok' => true, 'error' => null];
    }
    if (SMTP_USER === '' || SMTP_PASS === '') { return ['ok' => false, 'error' => 'E-mail is not set up yet (config/mail.php).']; }
    $fp = @stream_socket_client((SMTP_SECURE === 'ssl' ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 15);
    if (!$fp) { return ['ok' => false, 'error' => 'Could not reach the mail server (' . $errstr . ').']; }
    stream_set_timeout($fp, 15);
    $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $steps = [];
    $fail = null;
    [$c, $d] = smtpRead($fp);
    if ($c !== 220) { fclose($fp); return ['ok' => false, 'error' => 'Mail server said: ' . trim($d)]; }
    if (($fail = smtpCmd($fp, "EHLO $host", [250])) === null && SMTP_SECURE === 'tls') {
        if (($fail = smtpCmd($fp, 'STARTTLS', [220])) === null) {
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return ['ok' => false, 'error' => 'Could not start a secure connection to the mail server.']; }
            $fail = smtpCmd($fp, "EHLO $host", [250]);
        }
    }
    $fail = $fail ?? smtpCmd($fp, 'AUTH LOGIN', [334]);
    $fail = $fail ?? smtpCmd($fp, base64_encode(SMTP_USER), [334]);
    $fail = $fail ?? smtpCmd($fp, base64_encode(SMTP_PASS), [235]);
    $fail = $fail ?? smtpCmd($fp, 'MAIL FROM:<' . (SMTP_USER ?: MAIL_FROM) . '>', [250]);
    $fail = $fail ?? smtpCmd($fp, "RCPT TO:<$to>", [250, 251]);
    $fail = $fail ?? smtpCmd($fp, 'DATA', [354]);
    if ($fail === null) {
        $headers = 'From: =?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?= <' . (SMTP_USER ?: MAIL_FROM) . ">\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nDate: " . date('r')
                 . "\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        fwrite($fp, $headers . chunk_split(base64_encode($html)) . "\r\n.\r\n");
        [$c, $d] = smtpRead($fp);
        if ($c !== 250) { $fail = trim($d); }
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $fail === null ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'Mail server said: ' . $fail];
}
