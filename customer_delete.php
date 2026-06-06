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

$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['customers_delete_csrf']) || !hash_equals((string)$_SESSION['customers_delete_csrf'], $csrf)) {
  http_response_code(403);
  exit('Security token mismatch.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: customers.php');
  exit;
}

$stmt = $pdo->prepare("SELECT first_name, last_name, company, phone, email FROM customers WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$customer) {
  header('Location: customers.php');
  exit;
}

$email = trim((string)($customer['email'] ?? ''));
$company = trim((string)($customer['company'] ?? ''));
$phone = trim((string)($customer['phone'] ?? ''));
$full_name = trim((string)($customer['first_name'] ?? '') . ' ' . (string)($customer['last_name'] ?? ''));

$rfq_count_stmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM rfq_requests
  WHERE
    (? <> '' AND (contact_email = ? OR buyer_email = ?))
    OR (? <> '' AND (company_name = ? OR buyer_company = ?))
    OR (? <> '' AND (contact_name = ? OR buyer_name = ?))
");
$rfq_count_stmt->execute([
  $email, $email, $email,
  $company, $company, $company,
  $full_name, $full_name, $full_name,
]);
$rfq_count = (int)$rfq_count_stmt->fetchColumn();

$order_count_stmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM customer_phone_inquiries
  WHERE
    (? <> '' AND email = ?)
    OR (? <> '' AND company_name = ?)
    OR (? <> '' AND phone_number = ?)
    OR (? <> '' AND customer_name = ?)
");
$order_count_stmt->execute([
  $email, $email,
  $company, $company,
  $phone, $phone,
  $full_name, $full_name,
]);
$order_count = (int)$order_count_stmt->fetchColumn();

if ($rfq_count > 0 || $order_count > 0) {
  header('Location: customers.php?delete_blocked=1');
  exit;
}

$pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
header('Location: customers.php?deleted=1');
exit;
