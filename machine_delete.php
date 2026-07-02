<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed.');
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (
  empty($_SESSION['machine_delete_csrf'])
  || !hash_equals((string)$_SESSION['machine_delete_csrf'], $csrf)
) {
  http_response_code(403);
  exit('Security token mismatch.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
  $pdo->prepare("DELETE FROM machines WHERE id = ?")->execute([$id]);
}

header('Location: machines.php');
exit;
