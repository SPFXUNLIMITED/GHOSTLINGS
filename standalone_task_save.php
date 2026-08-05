<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

require_login();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

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
$description = trim((string)($_POST['description'] ?? ''));
$status = (string)($_POST['status'] ?? 'pending');
$priority = (string)($_POST['priority'] ?? 'medium');
$due_date = trim((string)($_POST['due_date'] ?? ''));

if (!in_array($filter, ['all', 'today'], true)) {
  $filter = 'all';
}
if ($description === '') {
  header('Location: standalone_tasks.php' . ($filter === 'today' ? '?filter=today' : ''));
  exit;
}
if (!in_array($status, ['pending', 'in-progress', 'completed'], true)) {
  $status = 'pending';
}
if (!in_array($priority, ['high', 'medium', 'low'], true)) {
  $priority = 'medium';
}
if ($due_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
  $due_date = '';
}
$due_value = $due_date === '' ? null : $due_date;

if ($id > 0) {
  $stmt = $pdo->prepare('UPDATE standalone_tasks SET description = ?, status = ?, priority = ?, due_date = ? WHERE id = ?');
  $stmt->execute([$description, $status, $priority, $due_value, $id]);
} else {
  $pdo->beginTransaction();
  try {
    $sort_order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM standalone_tasks FOR UPDATE')->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO standalone_tasks (description, status, priority, due_date, sort_order) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$description, $status, $priority, $due_value, $sort_order]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

header('Location: standalone_tasks.php' . ($filter === 'today' ? '?filter=today' : ''));
exit;
