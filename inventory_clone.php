<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

function inventory_clone_redirect(string $location): void {
  $_SESSION['inventory_clone_csrf'] = bin2hex(random_bytes(24));
  header('Location: ' . $location);
  exit;
}

function generate_inventory_part_number(PDO $pdo, int $inventory_id): string {
  $seed = max(1, $inventory_id);
  $stmt = $pdo->prepare("SELECT id FROM inventory_items WHERE part_number = ? AND id <> ? LIMIT 1");
  do {
    $candidate = sprintf('INV-%05d', $seed);
    $seed++;
    $stmt->execute([$candidate, $inventory_id]);
  } while ($stmt->fetchColumn() !== false);

  return $candidate;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed.');
}

$csrf = (string)($_POST['clone_csrf_token'] ?? '');
if (empty($_SESSION['inventory_clone_csrf']) || !hash_equals((string)$_SESSION['inventory_clone_csrf'], $csrf)) {
  http_response_code(403);
  exit('Security token mismatch.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  inventory_clone_redirect('inventory_list.php');
}

$stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) {
  inventory_clone_redirect('inventory_list.php');
}

$clone = $item;
unset($clone['id'], $clone['created_at'], $clone['updated_at']);
$clone['part_number'] = 'TEMP-' . bin2hex(random_bytes(12));
$clone['current_stock'] = 0;
if (array_key_exists('image_original_name', $clone)) {
  $clone['image_original_name'] = null;
}
if (array_key_exists('image_stored_name', $clone)) {
  $clone['image_stored_name'] = null;
}
if (array_key_exists('image_mime_type', $clone)) {
  $clone['image_mime_type'] = null;
}

$columns = array_keys($clone);
$quoted_columns = array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', $columns);
$placeholders = implode(', ', array_fill(0, count($columns), '?'));
$insert_sql = "INSERT INTO inventory_items (" . implode(', ', $quoted_columns) . ") VALUES (" . $placeholders . ")";

$new_id = 0;
$new_part_number = '';
$pdo->beginTransaction();
try {
  $insert_stmt = $pdo->prepare($insert_sql);
  $insert_stmt->execute(array_values($clone));
  $new_id = (int)$pdo->lastInsertId();
  $new_part_number = generate_inventory_part_number($pdo, $new_id);
  $pdo->prepare("UPDATE inventory_items SET part_number = ? WHERE id = ?")->execute([$new_part_number, $new_id]);
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  throw $e;
}

try {
  $actor_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
  if ($actor_id !== null && $actor_id <= 0) {
    $actor_id = null;
  }
  $actor_name = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : '';
  $detail = 'Inventory item #' . (int)$new_id . ' cloned from #' . (int)$id;
  $item_name = trim((string)($item['item_name'] ?? ''));
  if ($item_name !== '') {
    $detail .= ': ' . $item_name;
  }
  if ($new_part_number !== '') {
    $detail .= ' [' . $new_part_number . ']';
  }
  log_admin_activity($pdo, $actor_id, 'Inventory Item Cloned', $detail, $actor_name);
} catch (Throwable $e) {
  // Non-blocking audit log write.
}

inventory_clone_redirect('inventory_form.php?id=' . (int)$new_id);
