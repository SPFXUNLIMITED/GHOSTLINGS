<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

$file = (string)($_GET['file'] ?? '');

// Only serve files that match the message image naming pattern
if (!preg_match('/^msg\d+_[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $file)) {
  http_response_code(400);
  exit('Invalid file name.');
}

$path = __DIR__ . '/uploads/' . $file;
if (!is_file($path)) {
  http_response_code(404);
  exit('Image not found.');
}

$fi   = finfo_open(FILEINFO_MIME_TYPE);
$mime = $fi ? (finfo_file($fi, $path) ?: 'image/jpeg') : 'image/jpeg';
if ($fi) finfo_close($fi);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: inline');
readfile($path);
exit;
