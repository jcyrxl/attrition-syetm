<?php
// Reads the first worksheet of an Excel (.xlsx) class list so it can be checked and added (Add students). Faculty only; nothing is stored.
// Only Excel files are accepted: Word files, pictures and scans are not read.
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/importer.php';
header('Content-Type: application/json; charset=utf-8');
function fail(string $m): void { echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit; }
if (!isFaculty()) { http_response_code(403); fail('Only a professor can add students.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) { fail('No file was received.'); }
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) { fail('The file could not be uploaded (' . $f['error'] . ').'); }
if ($f['size'] > 6 * 1024 * 1024) { fail('The file is too big (the limit is 6 MB).'); }
if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') { fail('Only an Excel file (.xlsx) can be uploaded. Word files and pictures are not accepted — you can also copy the cells from Excel and paste them.'); }
try {
    $sheets = importReadXlsxSheets($f['tmp_name'], ['*']);
} catch (RuntimeException $e) { fail($e->getMessage()); }
$lines = [];
foreach ($sheets['*'] ?? [] as $row) {
    $cells = array_map('trim', $row['cells']);
    if (array_filter($cells, 'strlen')) { $lines[] = implode("\t", $cells); }
}
if (!$lines) { fail('That Excel file has no readable rows in its first worksheet.'); }
echo json_encode(['ok' => true, 'text' => implode("\n", $lines)], JSON_UNESCAPED_UNICODE);
