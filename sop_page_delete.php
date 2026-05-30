<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$id          = isset($_GET['id'])          ? (int)$_GET['id']          : 0;

if (!$category_id || !$id) { header('Location: sops.php'); exit; }

// Verify the SOP page belongs to an SOP category project
$stmt = $pdo->prepare("
  SELECT t.id FROM tasks t
  JOIN projects p ON p.id = t.project_id
  WHERE t.id = ? AND t.project_id = ? AND p.is_sop_category = 1
");
$stmt->execute([$id, $category_id]);
if (!$stmt->fetch()) { http_response_code(404); exit('SOP page not found'); }

// Non-admins may only delete SOP pages in categories they own
if (!is_admin()) {
  $uid = current_user_id();
  $chk = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND is_sop_category = 1 AND owner_id = ?");
  $chk->execute([$category_id, $uid]);
  if (!$chk->fetch()) { http_response_code(403); exit('Forbidden'); }
}

$stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND project_id = ?");
$stmt->execute([$id, $category_id]);

header("Location: sop_pages.php?category_id={$category_id}");
exit;
