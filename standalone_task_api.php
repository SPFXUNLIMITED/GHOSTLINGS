<?php
/**
 * standalone_task_api.php – Session-less JSON API for creating standalone tasks.
 *
 * POST a JSON body: { "description": "…", "priority": "high|medium|low",
 * "due_date": "YYYY-MM-DD", "status": "pending|in-progress|completed" }
 * Authenticate with an X-API-Key header matching the STANDALONE_TASK_API_KEY
 * environment variable.
 *
 * Success (HTTP 201): { "id": 123 }
 * Error responses: { "error": "…" }
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function standalone_task_api_fail(int $status, string $message): void {
  http_response_code($status);
  echo json_encode(['error' => $message]);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Allow: POST');
  standalone_task_api_fail(405, 'Method not allowed. Use POST.');
}

require __DIR__ . '/db.php';

$expected_key = (string)(getenv('STANDALONE_TASK_API_KEY') ?: '');
if ($expected_key === '') {
  standalone_task_api_fail(503, 'API key is not configured.');
}
$provided_key = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
if (!hash_equals($expected_key, $provided_key)) {
  standalone_task_api_fail(401, 'Invalid or missing API key.');
}

$raw_body = (string)file_get_contents('php://input');
$payload = json_decode($raw_body, true);
if (!is_array($payload)) {
  standalone_task_api_fail(400, 'Request body must be a JSON object.');
}

$description = trim((string)($payload['description'] ?? ''));
if ($description === '') {
  standalone_task_api_fail(422, 'Description is required.');
}

$status = (string)($payload['status'] ?? 'pending');
if (!in_array($status, ['pending', 'in-progress', 'completed'], true)) {
  standalone_task_api_fail(422, 'Status must be one of: pending, in-progress, completed.');
}

$priority = (string)($payload['priority'] ?? 'medium');
if (!in_array($priority, ['high', 'medium', 'low'], true)) {
  standalone_task_api_fail(422, 'Priority must be one of: high, medium, low.');
}

$due_date = trim((string)($payload['due_date'] ?? ''));
if ($due_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
  standalone_task_api_fail(422, 'Due date must use the YYYY-MM-DD format.');
}
$due_value = $due_date === '' ? null : $due_date;

try {
  $pdo->beginTransaction();
  $sort_order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM standalone_tasks FOR UPDATE')->fetchColumn();
  $stmt = $pdo->prepare('INSERT INTO standalone_tasks (description, status, priority, due_date, sort_order) VALUES (?, ?, ?, ?, ?)');
  $stmt->execute([$description, $status, $priority, $due_value, $sort_order]);
  $id = (int)$pdo->lastInsertId();
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log('standalone_task_api.php: ' . $e->getMessage());
  standalone_task_api_fail(500, 'Unable to create the task.');
}

http_response_code(201);
echo json_encode(['id' => $id]);
