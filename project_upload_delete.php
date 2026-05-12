<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$project_id = (int)($_GET['project_id'] ?? 0);

if ($id <= 0 || $project_id <= 0) {
  http_response_code(400);
  exit('Missing id or project_id');
}

$stmt = $pdo->prepare("SELECT id, project_id, stored_name FROM project_uploads WHERE id = ? AND project_id = ? LIMIT 1");
$stmt->execute([$id, $project_id]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) {
  http_response_code(404);
  exit('File not found');
}

$del = $pdo->prepare("DELETE FROM project_uploads WHERE id = ? AND project_id = ?");
$del->execute([$id, $project_id]);

$stored = $u['stored_name'];
// Prevent path traversal: stored_name must be a plain filename with no separators
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $stored)) {
  http_response_code(400);
  exit('Invalid stored name');
}

$path = __DIR__ . '/uploads/' . $stored;
if (is_file($path)) {
  unlink($path);
}

header('Location: project_details.php?id=' . $project_id);
exit;
