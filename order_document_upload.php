<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_rfq_access();

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

$storedName = 'od' . $order_id . '_' . bin2hex(random_bytes(16)) . $ext;
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
