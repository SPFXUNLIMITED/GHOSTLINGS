<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing id');
}

$stmt = $pdo->prepare("SELECT id, original_name, stored_name, mime_type FROM order_documents WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
  http_response_code(404);
  exit('Document not found');
}

$stored = (string)($doc['stored_name'] ?? '');
if ($stored === '') {
  http_response_code(404);
  exit('No file for this document');
}
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $stored)) {
  http_response_code(400);
  exit('Invalid file name');
}

$path = __DIR__ . '/uploads/' . $stored;
if (!is_file($path)) {
  http_response_code(404);
  exit('File not found on disk');
}

$download_name = trim((string)($doc['original_name'] ?? 'document'));
$download_name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $download_name) ?? '';
$download_name = str_replace(['\\', '/'], '_', $download_name);
if ($download_name === '') {
  $download_name = 'document';
}

$mime = trim((string)($doc['mime_type'] ?? ''));
if ($mime === '') {
  $mime = 'application/octet-stream';
}
$inline = isset($_GET['inline']) && $_GET['inline'] === '1' && is_inline_preview_attachment($download_name, $mime);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($path));
if ($inline) {
  header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($download_name));
} else {
  header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($download_name));
}
readfile($path);
exit;
