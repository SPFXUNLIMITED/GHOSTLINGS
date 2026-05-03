<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

/**
 * Ensure table exists (safe to run repeatedly).
 * If your DB user lacks CREATE privilege, create it manually using the SQL I provided earlier.
 */
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS task_uploads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(191) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    caption VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_task_uploads_task_id (task_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
  // ignore here; page will error later if table truly missing
}

$task_id = (int)($_POST['task_id'] ?? 0);
$caption = trim((string)($_POST['caption'] ?? ''));

if ($task_id <= 0) {
  http_response_code(400);
  exit('Missing task_id');
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
  @mkdir($uploadsDir, 0775, true);
}
if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
  http_response_code(500);
  exit('uploads/ directory is missing or not writable');
}

$originalName = (string)($f['name'] ?? 'file');
$tmpPath = (string)($f['tmp_name'] ?? '');
$sizeBytes = (int)($f['size'] ?? 0);

// Detect MIME (best effort)
$mime = null;
if (is_file($tmpPath) && function_exists('finfo_open')) {
  $fi = finfo_open(FILEINFO_MIME_TYPE);
  if ($fi) {
    $mime = finfo_file($fi, $tmpPath) ?: null;
    finfo_close($fi);
  }
}

// Keep a sanitized extension (optional, helps with previews)
$ext = '';
$dot = strrpos($originalName, '.');
if ($dot !== false) {
  $ext = strtolower(substr($originalName, $dot + 1));
  $ext = preg_replace('/[^a-z0-9]+/i', '', $ext);
  if ($ext !== '') $ext = '.' . $ext;
}

// Unguessable stored name, scoped by task id
$storedName = 't' . $task_id . '_' . bin2hex(random_bytes(16)) . $ext;
$destPath = $uploadsDir . '/' . $storedName;

if (!move_uploaded_file($tmpPath, $destPath)) {
  http_response_code(500);
  exit('Failed to move uploaded file');
}

$stmt = $pdo->prepare("INSERT INTO task_uploads (task_id, original_name, stored_name, mime_type, size_bytes, caption)
  VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([
  $task_id,
  $originalName,
  $storedName,
  $mime,
  $sizeBytes,
  ($caption !== '' ? $caption : null)
]);

header('Location: task_uploads.php?task_id=' . $task_id);
exit;