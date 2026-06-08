<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json');

// CSRF check (token passed as URL query param by TinyMCE images_upload_url)
$csrf = (string)($_GET['csrf'] ?? '');
if (empty($_SESSION['messages_csrf']) || !hash_equals((string)$_SESSION['messages_csrf'], $csrf)) {
  http_response_code(403);
  echo json_encode(['error' => 'Security token mismatch.']);
  exit;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
  http_response_code(400);
  echo json_encode(['error' => 'No file received.']);
  exit;
}

$f = $_FILES['file'];
$err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['error' => 'Upload error code ' . $err . '.']);
  exit;
}

$tmpPath  = (string)($f['tmp_name'] ?? '');
$sizeBytes = (int)($f['size'] ?? 0);

// 5 MB limit
if ($sizeBytes > 5 * 1024 * 1024) {
  http_response_code(400);
  echo json_encode(['error' => 'File exceeds 5 MB limit.']);
  exit;
}

// Validate MIME type via finfo (not just extension)
if (!is_uploaded_file($tmpPath)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid upload.']);
  exit;
}

$fi = finfo_open(FILEINFO_MIME_TYPE);
$mime = $fi ? (finfo_file($fi, $tmpPath) ?: '') : '';
if ($fi) finfo_close($fi);

$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowedMimes, true)) {
  http_response_code(400);
  echo json_encode(['error' => 'Only JPEG, PNG, GIF, and WebP images are allowed.']);
  exit;
}

$mimeToExt = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/gif'  => 'gif',
  'image/webp' => 'webp',
];
$ext = $mimeToExt[$mime];

$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
  @mkdir($uploadsDir, 0775, true);
}
if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
  http_response_code(500);
  echo json_encode(['error' => 'uploads/ directory is not writable.']);
  exit;
}

$userId     = (int)$_SESSION['user_id'];
$storedName = 'msg' . $userId . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
$destPath   = $uploadsDir . '/' . $storedName;

if (!move_uploaded_file($tmpPath, $destPath)) {
  http_response_code(500);
  echo json_encode(['error' => 'Failed to save uploaded file.']);
  exit;
}

// Return the URL TinyMCE will embed as the image src
echo json_encode(['location' => '/project/message_image_serve.php?file=' . rawurlencode($storedName)]);
exit;
