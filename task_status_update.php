<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!($_SESSION['username'] ?? null)) { header('Location: login.php'); exit; }

$task_id = (int)($_POST['task_id'] ?? 0);
$status  = (string)($_POST['status'] ?? '');
$project_id = (int)($_POST['project_id'] ?? 0); // for redirect

$allowed = ['todo','doing','done'];
if ($task_id <= 0 || !in_array($status, $allowed, true)) {
  // fall back redirect
  header('Location: projects.php');
  exit;
}

// Update
$stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
$stmt->execute([$status, $task_id]);

// Redirect back to tasks list
if ($project_id > 0) {
  header('Location: tasks.php?project_id=' . $project_id);
} else {
  header('Location: projects.php');
}
exit;