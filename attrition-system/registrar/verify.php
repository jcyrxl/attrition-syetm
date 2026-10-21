<?php
/**
 * JSON endpoint used by the PIN / password prompts. It lets a page check the credential
 * WITHOUT reloading, so a wrong answer is shown right where the person is working
 * (their open modal and everything they typed stay exactly as they were).
 * The real save/delete still re-checks the credential server-side — this is only for the UX.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (!canUsePin() && !canManageUsers())) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed.']);
    exit;
}

$type = $_POST['type'] ?? '';
if (!in_array($type, ['pin', 'action_password'], true)) { $type = actionCredentialType(); }
$res = checkCredential($pdo, $type, $_POST['credential'] ?? '');
if (!empty($res['logout'])) {
    $_SESSION = [];
    session_destroy();
}
echo json_encode($res);
