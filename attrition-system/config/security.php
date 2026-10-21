<?php
/**
 * Protection for sensitive actions.
 *
 *  - Faculty : must re-enter their Student Data PIN (6 digits) to add/edit/delete student data.
 *  - Admin     : must re-enter their password to create/edit/delete/reset accounts.
 *
 * Wrong answers never kick the person out of the page they're on — they just get a message and
 * can try again. Repeated failures lock the check with an escalating penalty:
 *
 *   stage 0 : 6 tries  -> locked 15 minutes
 *   stage 1 : 3 tries  -> locked 30 minutes
 *   stage 2 : 3 tries  -> locked 24 hours AND the account is logged out automatically
 *
 * A correct answer clears everything. After the 24-hour lock expires the ladder starts over.
 * All times are computed in PHP (not MySQL NOW()) so server/database time zones can't disagree.
 */

const LOCK_ATTEMPTS = [6, 3, 3];
const LOCK_SECONDS  = [900, 1800, 86400];

function actionCredentialType(): string {
    return ($_SESSION['role'] ?? '') === 'admin' ? 'action_password' : 'pin';
}

function actionCredentialLabel(): string {
    return actionCredentialType() === 'action_password' ? 'Your Account-Management Password' : 'Your Student Data PIN';
}

function hasActionPassword(PDO $pdo): bool {
    $stmt = $pdo->prepare("SELECT action_password_hash FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return (bool) $stmt->fetchColumn();
}

function formatWait(int $seconds): string {
    if ($seconds >= 3600) {
        $h = (int) ceil($seconds / 3600);
        return $h . ' hour' . ($h === 1 ? '' : 's');
    }
    $m = max(1, (int) ceil($seconds / 60));
    return $m . ' minute' . ($m === 1 ? '' : 's');
}

function getLockStatus(PDO $pdo, string $userId): array {
    $stmt = $pdo->prepare("SELECT verify_fail_count, verify_stage, verify_locked_until FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['locked' => false, 'seconds_left' => 0, 'attempts_left' => 0];
    }
    if ($row['verify_locked_until'] && strtotime($row['verify_locked_until']) > time()) {
        return ['locked' => true, 'seconds_left' => strtotime($row['verify_locked_until']) - time(), 'attempts_left' => 0];
    }
    $stage = min((int) $row['verify_stage'], 2);
    return ['locked' => false, 'seconds_left' => 0, 'attempts_left' => max(0, LOCK_ATTEMPTS[$stage] - (int) $row['verify_fail_count'])];
}

function registerFailedAttempt(PDO $pdo, string $userId): array {
    $stmt = $pdo->prepare("SELECT verify_fail_count, verify_stage FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $stage = min((int) ($row['verify_stage'] ?? 0), 2);
    $fail  = (int) ($row['verify_fail_count'] ?? 0) + 1;

    if ($fail >= LOCK_ATTEMPTS[$stage]) {
        $secs = LOCK_SECONDS[$stage];
        $nextStage = $stage >= 2 ? 0 : $stage + 1;
        $pdo->prepare("UPDATE users SET verify_fail_count = 0, verify_stage = ?, verify_locked_until = ? WHERE user_id = ?")
            ->execute([$nextStage, date('Y-m-d H:i:s', time() + $secs), $userId]);
        return ['locked' => true, 'seconds_left' => $secs, 'attempts_left' => 0, 'logout' => $stage >= 2];
    }

    $pdo->prepare("UPDATE users SET verify_fail_count = ? WHERE user_id = ?")->execute([$fail, $userId]);
    return ['locked' => false, 'seconds_left' => 0, 'attempts_left' => LOCK_ATTEMPTS[$stage] - $fail, 'logout' => false];
}

function clearFailedAttempts(PDO $pdo, string $userId): void {
    $pdo->prepare("UPDATE users SET verify_fail_count = 0, verify_stage = 0, verify_locked_until = NULL WHERE user_id = ?")
        ->execute([$userId]);
}

/**
 * Checks one of the logged-in user's secrets, applying the lockout ladder above.
 *   'pin'             Faculty's Student Data PIN               (confirms student-data changes)
 *   'action_password' Admin's Account-Management Password         (confirms create/edit/delete/reset of accounts)
 *   'password'        the normal LOGIN password                   (used inside Privacy Settings)
 * The Admin's login password and Account-Management Password are two different secrets on purpose.
 * Returns ['ok' => bool, 'error' => string, 'locked' => bool, 'logout' => bool].
 */
function checkCredential(PDO $pdo, string $type, string $input): array {
    $userId  = $_SESSION['user_id'];
    $labels  = ['pin' => 'Student Data PIN', 'action_password' => 'Account-Management Password', 'password' => 'login password'];
    $columns = ['pin' => 'student_data_pin_hash', 'action_password' => 'action_password_hash', 'password' => 'password_hash'];
    $label   = $labels[$type] ?? 'password';
    $column  = $columns[$type] ?? 'password_hash';   // whitelisted above, so safe to put in the query

    $lock = getLockStatus($pdo, $userId);
    if ($lock['locked']) {
        return ['ok' => false, 'locked' => true, 'logout' => false,
                'error' => 'Too many incorrect attempts. Try again in ' . formatWait($lock['seconds_left']) . '.'];
    }

    $stmt = $pdo->prepare("SELECT $column FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();

    if ($type !== 'password' && !$hash) {
        return ['ok' => false, 'locked' => false, 'logout' => false,
                'error' => "Set up your $label first — profile menu → Privacy Settings."];
    }

    if ($hash && $input !== '' && password_verify($input, $hash)) {
        clearFailedAttempts($pdo, $userId);
        return ['ok' => true, 'locked' => false, 'logout' => false, 'error' => ''];
    }

    $res = registerFailedAttempt($pdo, $userId);
    if ($res['locked']) {
        $msg = $res['logout']
            ? 'Too many incorrect attempts. This account is locked for 24 hours and you have been logged out.'
            : 'Too many incorrect attempts. Locked for ' . formatWait($res['seconds_left']) . '.';
        return ['ok' => false, 'locked' => true, 'logout' => $res['logout'], 'error' => $msg];
    }
    $n = $res['attempts_left'];
    return ['ok' => false, 'locked' => false, 'logout' => false,
            'error' => "Incorrect $label. $n attempt" . ($n === 1 ? '' : 's') . ' left before a temporary lock.'];
}

function endSessionAndRedirectLocked(): void {
    $_SESSION = [];
    session_destroy();
    header('Location: ../index.php?error=locked');
    exit;
}

/** The starting password given to new accounts and to accounts an Admin resets. Admin can change it in Privacy Settings. */
function getDefaultPassword(PDO $pdo): string {
    try {
        $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_password'")->fetchColumn();
    } catch (PDOException $e) {
        $v = false;
    }
    return $v ?: 'Default!2026';
}
