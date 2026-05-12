<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: projects.php'); exit; }

// delete tasks first (if you don’t have ON DELETE CASCADE)
$pdo->prepare("DELETE FROM tasks WHERE project_id = ?")->execute([$id]);

// delete project
$pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$id]);

header('Location: projects.php');
exit;