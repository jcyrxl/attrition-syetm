<?php
// Sends the logged-in user a 6-digit confirmation code by e-mail (to change a password or PIN).
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/otp.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'error' => 'Bad request.']); exit; }
$purpose = $_POST['purpose'] ?? '';
if (!in_array($purpose, ['password', 'pin', 'action_password'], true)) { echo json_encode(['ok' => false, 'error' => 'Bad request.']); exit; }
$q = $pdo->prepare("SELECT user_id, full_name, email FROM users WHERE user_id = ?");
$q->execute([$_SESSION['user_id']]);
$user = $q->fetch();
echo json_encode($user ? otpSend($pdo, $user, $purpose) : ['ok' => false, 'error' => 'Account not found.'], JSON_UNESCAPED_UNICODE);
