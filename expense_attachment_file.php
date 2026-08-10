<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

function expense_attachment_safe_name(string $name): bool {
  return preg_match('/^[A-Za-z0-9._-]+$/', $name) === 1;
}

function expense_attachment_inline_allowed(?string $fileName, ?string $mime): bool {
  $fileName = strtolower(trim((string)$fileName));
  $mime = strtolower(trim((string)$mime));
  $ext = pathinfo($fileName, PATHINFO_EXTENSION);
  $inlineExt = ['pdf', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
  if ($ext !== '' && in_array($ext, $inlineExt, true)) {
    return true;
  }
  $inlineMime = ['application/pdf', 'text/plain', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];
  return in_array($mime, $inlineMime, true);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Invalid attachment ID');
}

$stmt = $pdo->prepare(
  "SELECT ea.id, ea.original_name, ea.stored_name, ea.mime_type
   FROM expense_attachments ea
   INNER JOIN expenses e ON e.id = ea.expense_id
   WHERE ea.id = ?
   LIMIT 1"
);
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  http_response_code(404);
  exit('Attachment not found');
}

$stored = trim((string)($row['stored_name'] ?? ''));
if ($stored === '' || !expense_attachment_safe_name($stored)) {
  http_response_code(400);
  exit('Invalid stored attachment');
}

$path = __DIR__ . '/uploads/' . $stored;
if (!is_file($path)) {
  http_response_code(404);
  exit('Attachment file missing');
}

$downloadName = trim((string)($row['original_name'] ?? 'expense-attachment'));
if ($downloadName === '') {
  $downloadName = 'expense-attachment';
}
$mime = trim((string)($row['mime_type'] ?? 'application/octet-stream'));
if ($mime === '' || str_contains($mime, "\n") || str_contains($mime, "\r") || !preg_match('/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/i', $mime)) {
  $mime = 'application/octet-stream';
}
$inline = isset($_GET['inline']) && $_GET['inline'] === '1' && expense_attachment_inline_allowed($downloadName, $mime);
$size = filesize($path);
if ($size === false) {
  http_response_code(500);
  exit('Could not read attachment size');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)$size);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename*=UTF-8\'\'' . rawurlencode($downloadName));
readfile($path);
exit;
