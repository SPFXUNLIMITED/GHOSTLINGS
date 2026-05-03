<?php
// auth.php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

function require_login(): void {
  if (empty($_SESSION['user_id'])) {
    $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
    header('Location: login.php?next=' . urlencode($next));
    exit;
  }
}

function current_username(): ?string {
  return $_SESSION['username'] ?? null;
}

function current_user_id(): ?int {
  return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function is_admin(): bool {
  return !empty($_SESSION['is_admin']);
}

function require_admin(): void {
  require_login();
  if (!is_admin()) {
    http_response_code(403);
    exit('Access denied.');
  }
}