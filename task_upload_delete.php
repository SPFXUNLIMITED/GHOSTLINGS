<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$task_id = (int)($_GET['task_id'] ?? 0);

if ($id <= 0 || $task_id <= 0) {
  http_response_code(400);
  exit('Missing id or task_id');
}

// Ensure upload belongs to this task
$stmt = $pdo->prepare("SELECT id, task_id, stored_name FROM task_uploads WHERE id = ? AND task_id = ? LIMIT 1");
$stmt->execute([$id, $task_id]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) {
  http_response_code(404);
  exit('File not found');
}

// Delete DB row first
$del = $pdo->prepare("DELETE FROM task_uploads WHERE id = ? AND task_id = ?");
$del->execute([$id, $task_id]);

// Then delete file from disk
$path = __DIR__ . '/uploads/' . $u['stored_name'];
if (is_file($path)) {
  @unlink($path);
}

header('Location: task_uploads.php?task_id=' . $task_id);
exit;