<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
  $pdo->prepare("DELETE FROM vendors WHERE id = ?")->execute([$id]);
}

header('Location: vendors.php');
exit;
