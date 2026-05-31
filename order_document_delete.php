<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_rfq_access();

$id = (int)($_GET['id'] ?? 0);
$order_id = (int)($_GET['order_id'] ?? 0);

if ($id <= 0 || $order_id <= 0) {
  http_response_code(400);
  exit('Missing id or order_id');
}

$stmt = $pdo->prepare("SELECT id, order_id, stored_name FROM order_documents WHERE id = ? AND order_id = ? LIMIT 1");
$stmt->execute([$id, $order_id]);
$doc = $stmt->fetch();

if (!$doc) {
  http_response_code(404);
  exit('Document not found');
}

$del = $pdo->prepare("DELETE FROM order_documents WHERE id = ? AND order_id = ?");
$del->execute([$id, $order_id]);

$path = __DIR__ . '/uploads/' . $doc['stored_name'];
if (is_file($path)) {
  @unlink($path);
}

header('Location: order_form.php?order_id=' . $order_id);
exit;
