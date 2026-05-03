<?php
require __DIR__ . '/db.php';

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$project_id || !$id) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND project_id = ?");
$stmt->execute([$id, $project_id]);

header("Location: tasks.php?project_id={$project_id}");
exit;