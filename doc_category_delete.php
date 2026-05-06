<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: documents.php'); exit; }

// Delete documents (tasks) belonging to this category
$pdo->prepare("DELETE FROM tasks WHERE project_id = ?")->execute([$id]);

// Delete the category (project) itself
$pdo->prepare("DELETE FROM projects WHERE id = ? AND is_doc_category = 1")->execute([$id]);

header('Location: documents.php');
exit;
