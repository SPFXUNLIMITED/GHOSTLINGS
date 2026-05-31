<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_rfq_access();

const ORDER_DOC_MAX_BYTES = 20 * 1024 * 1024; // 20 MB
const ORDER_DOC_ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip'];
const ORDER_DOC_ALLOWED_MIMES = [
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.ms-excel',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'text/csv',
  'text/plain',
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp',
  'application/zip',
];

$valid_doc_types = [
  'proforma_invoice',
  'commercial_invoice',
  'packing_list',
  'bill_of_lading',
  'certificate_origin',
  'customs_documents',
];

$order_id = (int)($_POST['order_id'] ?? 0);
$doc_type = trim((string)($_POST['doc_type'] ?? ''));

if ($order_id <= 0) {
  http_response_code(400);
  exit('Missing order_id');
}

if (!in_array($doc_type, $valid_doc_types, true)) {
  http_response_code(400);
  exit('Invalid document type');
}

// Verify order exists
$check = $pdo->prepare("SELECT id FROM rfq_orders WHERE id = ? LIMIT 1");
$check->execute([$order_id]);
if (!$check->fetch()) {
  http_response_code(404);
  exit('Order not found');
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

$sizeBytes = (int)($f['size'] ?? 0);
if ($sizeBytes > ORDER_DOC_MAX_BYTES) {
  http_response_code(400);
  exit('File exceeds maximum allowed size of ' . (ORDER_DOC_MAX_BYTES / 1024 / 1024) . ' MB');
}

$originalName = (string)($f['name'] ?? 'file');
$tmpPath = (string)($f['tmp_name'] ?? '');

// Validate extension against whitelist
$ext = '';
$dot = strrpos($originalName, '.');
if ($dot !== false) {
  $ext = strtolower(substr($originalName, $dot + 1));
}
if (!in_array($ext, ORDER_DOC_ALLOWED_EXTENSIONS, true)) {
  http_response_code(400);
  exit('File type not allowed. Allowed types: ' . implode(', ', ORDER_DOC_ALLOWED_EXTENSIONS));
}

// Detect MIME and validate against whitelist
$mime = null;
if (is_file($tmpPath) && function_exists('finfo_open')) {
  $fi = finfo_open(FILEINFO_MIME_TYPE);
  if ($fi) {
    $mime = finfo_file($fi, $tmpPath) ?: null;
    finfo_close($fi);
  }
}
if ($mime !== null && !in_array($mime, ORDER_DOC_ALLOWED_MIMES, true)) {
  http_response_code(400);
  exit('File content type not allowed');
}

$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
  @mkdir($uploadsDir, 0755, true);
}
if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
  http_response_code(500);
  exit('uploads/ directory is missing or not writable');
}

$storedName = 'od' . $order_id . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
$destPath = $uploadsDir . '/' . $storedName;

if (!move_uploaded_file($tmpPath, $destPath)) {
  http_response_code(500);
  exit('Failed to move uploaded file');
}

$stmt = $pdo->prepare("INSERT INTO order_documents (order_id, doc_type, original_name, stored_name, mime_type, size_bytes)
  VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([
  $order_id,
  $doc_type,
  $originalName,
  $storedName,
  $mime,
  $sizeBytes,
]);

header('Location: order_form.php?order_id=' . $order_id . '&saved=1');
exit;
