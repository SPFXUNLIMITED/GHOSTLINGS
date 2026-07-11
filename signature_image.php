<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

define('SIGNATURE_IMAGE_STORAGE_ROOT', dirname(__DIR__));
define('SIGNATURE_IMAGE_STORAGE_DIR', SIGNATURE_IMAGE_STORAGE_ROOT . '/protected_signatures');

$signature_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($signature_id <= 0) {
    http_response_code(400);
    exit('Invalid signature ID.');
}

$stmt = $pdo->prepare('SELECT id, signature_path FROM invoice_signatures WHERE id = ? LIMIT 1');
$stmt->execute([$signature_id]);
$signature = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$signature) {
    http_response_code(404);
    exit('Signature not found.');
}

$relative_path = trim((string)($signature['signature_path'] ?? ''));
if ($relative_path === '' || !preg_match('#^protected_signatures/sig_[a-f0-9]{32}\.png$#', $relative_path)) {
    http_response_code(404);
    exit('Signature file not found.');
}

$storage_root = realpath(SIGNATURE_IMAGE_STORAGE_DIR);
$full_path = realpath(SIGNATURE_IMAGE_STORAGE_ROOT . '/' . $relative_path);
if ($storage_root === false || $full_path === false || !str_starts_with($full_path, $storage_root . DIRECTORY_SEPARATOR) || !is_file($full_path)) {
    http_response_code(404);
    exit('Signature file not found.');
}

header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Disposition: inline; filename="signature-' . $signature_id . '.png"');
readfile($full_path);
exit;
