<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$return_to = isset($_GET['return_to']) ? (string)$_GET['return_to'] : 'tasks';

if (!$project_id || !$id) { header('Location: projects.php'); exit; }

$stmt = $pdo->prepare("SELECT id, owner_id, playbook FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); exit('Project not found'); }

if (!is_admin()) {
  $uid = current_user_id();
  $chk = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND project_id = ? AND assigned_to = ?");
  $chk->execute([$id, $project_id, $uid]);
  $task_assigned = $chk->fetch();
  if (!$task_assigned && (int)($project['owner_id'] ?? 0) !== (int)$uid) {
    http_response_code(403);
    exit('Forbidden');
  }
}

$stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND project_id = ?");
$stmt->execute([$id, $project_id]);

$return_locations = [
  'tasks' => "tasks.php?project_id={$project_id}",
  'playbook_tasks' => "playbook_tasks.php?project_id={$project_id}",
  'project_details' => "project_details.php?id={$project_id}",
];
$default_return = !empty($project['playbook']) ? 'playbook_tasks' : 'tasks';
if (!isset($return_locations[$return_to])) {
  $return_to = $default_return;
}
$location = $return_locations[$return_to];

header("Location: {$location}");
exit;