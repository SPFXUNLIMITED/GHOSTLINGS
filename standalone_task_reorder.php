<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

require_login();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$csrf_token = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals((string)($_SESSION['standalone_tasks_csrf'] ?? ''), $csrf_token)) {
  http_response_code(400);
  exit('Invalid request token');
}

$ids = $_POST['task_ids'] ?? [];
if (!is_array($ids)) {
  http_response_code(400);
  exit('Invalid payload');
}

$ids = array_values(array_unique(array_map('intval', $ids)));
if (!$ids) {
  http_response_code(400);
  exit('No task ids provided');
}

$pdo->beginTransaction();
try {
  $stmt = $pdo->prepare('UPDATE standalone_tasks SET sort_order = ? WHERE id = ?');
  foreach ($ids as $index => $id) {
    if ($id <= 0) {
      continue;
    }
    $stmt->execute([$index + 1, $id]);
  }
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  exit('Failed to reorder tasks');
}

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
