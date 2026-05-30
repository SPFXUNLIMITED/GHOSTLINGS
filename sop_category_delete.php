<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: sops.php'); exit; }

// Verify the target is actually an SOP category
$stmt = $pdo->prepare("SELECT id, owner_id FROM projects WHERE id = ? AND is_sop_category = 1");
$stmt->execute([$id]);
$cat = $stmt->fetch();
if (!$cat) { http_response_code(404); exit('SOP category not found'); }

// Non-admins may only delete their own categories
if (!is_admin()) {
  $uid = current_user_id();
  if ((int)$cat['owner_id'] !== $uid) { http_response_code(403); exit('Forbidden'); }
}

// Delete SOP pages (tasks) belonging to this category
$pdo->prepare("DELETE FROM tasks WHERE project_id = ?")->execute([$id]);

// Delete the category (project) itself
$pdo->prepare("DELETE FROM projects WHERE id = ? AND is_sop_category = 1")->execute([$id]);

header('Location: sops.php');
exit;
