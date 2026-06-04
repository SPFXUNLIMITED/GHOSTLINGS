<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed.');
}

$csrf = (string)($_POST['delete_csrf_token'] ?? '');
if (empty($_SESSION['inventory_delete_csrf']) || !hash_equals((string)$_SESSION['inventory_delete_csrf'], $csrf)) {
  http_response_code(403);
  exit('Security token mismatch.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: inventory_list.php');
  exit;
}

$stmt = $pdo->prepare("SELECT item_name, part_number, image_stored_name FROM inventory_items WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
  header('Location: inventory_list.php');
  exit;
}

$pdo->prepare("DELETE FROM inventory_items WHERE id = ?")->execute([$id]);

$image_stored_name = trim((string)($item['image_stored_name'] ?? ''));
if ($image_stored_name !== '') {
  $image_path = __DIR__ . '/uploads/inventory/' . basename($image_stored_name);
  if (is_file($image_path)) {
    @unlink($image_path);
  }
}

try {
  $actor_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
  if ($actor_id !== null && $actor_id <= 0) {
    $actor_id = null;
  }
  $actor_name = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : '';
  $detail = 'Inventory item #' . $id . ' deleted: ' . (string)($item['item_name'] ?? '');
  $part_number = trim((string)($item['part_number'] ?? ''));
  if ($part_number !== '') {
    $detail .= ' [' . $part_number . ']';
  }
  log_admin_activity($pdo, $actor_id, 'Inventory Item Deleted', $detail, $actor_name);
} catch (Throwable $e) {
  // Non-blocking audit log write.
}

$_SESSION['inventory_delete_csrf'] = bin2hex(random_bytes(24));

header('Location: inventory_list.php?success=deleted');
exit;
