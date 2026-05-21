<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_rfq_access();

$quote_id = (int)($_GET['quote_id'] ?? 0);
if ($quote_id <= 0) {
  http_response_code(400);
  exit('Missing quote_id');
}

$stmt = $pdo->prepare("
  SELECT id, quote_file_original_name, quote_file_stored_name, quote_file_mime_type
  FROM rfq_quotes
  WHERE id = ?
  LIMIT 1
");
$stmt->execute([$quote_id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
  http_response_code(404);
  exit('Quote not found');
}

$stored = (string)($quote['quote_file_stored_name'] ?? '');
if ($stored === '') {
  http_response_code(404);
  exit('No attachment for this quote');
}
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $stored)) {
  http_response_code(400);
  exit('Invalid attachment name');
}

$path = __DIR__ . '/uploads/' . $stored;
if (!is_file($path)) {
  http_response_code(404);
  exit('Attachment file not found');
}

$download_name = trim((string)($quote['quote_file_original_name'] ?? 'quote-attachment'));
$download_name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $download_name) ?? '';
$download_name = str_replace(['\\', '/'], '_', $download_name);
if ($download_name === '') {
  $download_name = 'quote-attachment';
}

$mime = trim((string)($quote['quote_file_mime_type'] ?? ''));
if ($mime === '') {
  $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($download_name));
readfile($path);
exit;
