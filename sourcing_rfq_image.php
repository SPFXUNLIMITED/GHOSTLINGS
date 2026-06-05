<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_rfq_access();

$rfq_id = (int)($_GET['rfq_id'] ?? 0);
$type   = (string)($_GET['type'] ?? 'full');

if ($rfq_id <= 0) {
  http_response_code(400);
  exit('Missing rfq_id');
}

$col = $type === 'thumb' ? 'image_thumb' : 'image_path';
$stmt = $pdo->prepare("SELECT $col AS stored_name FROM rfq_requests WHERE id = ? LIMIT 1");
$stmt->execute([$rfq_id]);
$row = $stmt->fetch();

if (!$row || (string)($row['stored_name'] ?? '') === '') {
  http_response_code(404);
  exit('Image not found');
}

$stored = (string)$row['stored_name'];
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $stored)) {
  http_response_code(400);
  exit('Invalid file name');
}

$path = __DIR__ . '/uploads/' . $stored;
if (!is_file($path)) {
  http_response_code(404);
  exit('File not found on disk');
}

$fi = finfo_open(FILEINFO_MIME_TYPE);
$mime = $fi ? (finfo_file($fi, $path) ?: 'image/jpeg') : 'image/jpeg';
finfo_close($fi);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=86400');
if (isset($_GET['download'])) {
  $ext = pathinfo($stored, PATHINFO_EXTENSION);
  $download_name = 'rfq_' . $rfq_id . '_image.' . $ext;
  header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($download_name));
} else {
  header('Content-Disposition: inline');
}
readfile($path);
exit;
