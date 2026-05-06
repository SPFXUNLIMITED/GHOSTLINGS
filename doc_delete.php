<?php
require __DIR__ . '/db.php';

$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$id          = isset($_GET['id'])          ? (int)$_GET['id']          : 0;

if (!$category_id || !$id) { header('Location: documents.php'); exit; }

$stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND project_id = ?");
$stmt->execute([$id, $category_id]);

header("Location: doc_tasks.php?category_id={$category_id}");
exit;
