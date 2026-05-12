<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS project_uploads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(191) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    caption VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_project_uploads_project_id (project_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
  // ignore; page will error later if table truly missing
}

$project_id = (int)($_POST['project_id'] ?? 0);
$caption = trim((string)($_POST['caption'] ?? ''));

if ($project_id <= 0) {
  http_response_code(400);
  exit('Missing project_id');
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
  http_response_code(400);
  exit('Missing file');
}

$f = $_FILES['file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  http_response_code(400);
  exit('Upload failed (code ' . (int)$f['error'] . ')');
}

$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
  mkdir($uploadsDir, 0775, true);
}
if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
  http_response_code(500);
  exit('uploads/ directory is missing or not writable');
}

$originalName = (string)($f['name'] ?? 'file');
$tmpPath = (string)($f['tmp_name'] ?? '');
$sizeBytes = (int)($f['size'] ?? 0);

$mime = null;
if (is_file($tmpPath) && function_exists('finfo_open')) {
  $fi = finfo_open(FILEINFO_MIME_TYPE);
  if ($fi) {
    $mime = finfo_file($fi, $tmpPath) ?: null;
    finfo_close($fi);
  }
}

$ext = '';
$dot = strrpos($originalName, '.');
if ($dot !== false) {
  $ext = strtolower(substr($originalName, $dot + 1));
  $ext = preg_replace('/[^a-z0-9]+/i', '', $ext);
  if ($ext !== '') $ext = '.' . $ext;
}

$storedName = 'p' . $project_id . '_' . bin2hex(random_bytes(16)) . $ext;
$destPath = $uploadsDir . '/' . $storedName;

if (!move_uploaded_file($tmpPath, $destPath)) {
  http_response_code(500);
  exit('Failed to move uploaded file');
}

$stmt = $pdo->prepare("INSERT INTO project_uploads (project_id, original_name, stored_name, mime_type, size_bytes, caption)
  VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([
  $project_id,
  $originalName,
  $storedName,
  $mime,
  $sizeBytes,
  ($caption !== '' ? $caption : null)
]);

header('Location: project_details.php?id=' . $project_id);
exit;
