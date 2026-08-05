<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: standalone_tasks.php');
  exit;
}

$id = (int)($_POST['id'] ?? 0);
$filter = (string)($_POST['filter'] ?? 'all');
if ($id > 0) {
  $stmt = $pdo->prepare('DELETE FROM standalone_tasks WHERE id = ?');
  $stmt->execute([$id]);
}
header('Location: standalone_tasks.php' . ($filter === 'today' ? '?filter=today' : ''));
exit;
