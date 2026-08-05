<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require_login();


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: standalone_tasks.php');
  exit;
}

$id = (int)($_POST['id'] ?? 0);
$filter = (string)($_POST['filter'] ?? 'all');
$csrf_token = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals((string)($_SESSION['standalone_tasks_csrf'] ?? ''), $csrf_token)) {
  http_response_code(400);
  exit('Invalid request token.');
}
if ($id > 0) {
  $stmt = $pdo->prepare('DELETE FROM standalone_tasks WHERE id = ?');
  $stmt->execute([$id]);
}
header('Location: standalone_tasks.php' . ($filter === 'today' ? '?filter=today' : ''));
exit;
