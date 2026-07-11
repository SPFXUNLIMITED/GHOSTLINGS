<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

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
$path_pattern = '#^' . preg_quote(invoice_signature_storage_prefix(), '#') . '/' . invoice_signature_filename_regex() . '$#';
if ($relative_path === '' || !preg_match($path_pattern, $relative_path)) {
    http_response_code(404);
    exit('Signature file not found.');
}

$storage_root = realpath(dirname(__DIR__));
$protected_dir = realpath(invoice_signature_storage_dir());
if ($storage_root === false || $protected_dir === false) {
    http_response_code(404);
    exit('Signature file not found.');
}

$full_path = realpath($storage_root . '/' . $relative_path);
if ($full_path === false) {
    http_response_code(404);
    exit('Signature file not found.');
}

if (!str_starts_with($full_path, $protected_dir . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Signature file not found.');
}

if (!is_file($full_path)) {
    http_response_code(404);
    exit('Signature file not found.');
}

header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
$download_name = preg_replace('/[^A-Za-z0-9._-]/', '', sprintf('signature-%d.png', $signature_id)) ?: 'signature.png';
header('Content-Disposition: inline; filename="' . $download_name . '"');
readfile($full_path);
exit;
