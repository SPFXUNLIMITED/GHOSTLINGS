<?php
/**
 * standalone_task_latest.php – Polled by the task list to learn about tasks
 * created by other people (web form or JSON API).
 *
 * GET                -> { "last_new_task_id": 123 }
 * GET ?task_id=123   -> { "last_new_task_id": 123, "task": { "id": 123, "description": "…" } }
 */

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/new_task_banner.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['error' => 'Authentication required.']);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  header('Allow: GET');
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed. Use GET.']);
  exit;
}

$response = ['last_new_task_id' => get_last_new_task_id($pdo)];

$task_id = (int)($_GET['task_id'] ?? 0);
if ($task_id > 0) {
  $stmt = $pdo->prepare('SELECT id, description, status, priority, due_date FROM standalone_tasks WHERE id = ?');
  $stmt->execute([$task_id]);
  $task = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($task) {
    $response['task'] = [
      'id' => (int)$task['id'],
      'description' => new_task_banner_sanitize_title((string)$task['description']),
      'status' => (string)$task['status'],
      'priority' => (string)$task['priority'],
      'due_date' => $task['due_date'] !== null ? (string)$task['due_date'] : null,
    ];
  }
}

echo json_encode($response);
