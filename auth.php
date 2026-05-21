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

function is_moderator(): bool {
  return !empty($_SESSION['is_moderator']);
}

function is_admin_or_moderator(): bool {
  return is_admin() || is_moderator();
}

function can_access_rfq(): bool {
  return is_admin_or_moderator();
}

function require_admin(): void {
  require_login();
  if (!is_admin()) {
    http_response_code(403);
    exit('Access denied.');
  }
}

function require_admin_or_moderator(): void {
  require_login();
  if (!is_admin_or_moderator()) {
    http_response_code(403);
    exit('Access denied.');
  }
}

function require_rfq_access(): void {
  require_login();
  if (!can_access_rfq()) {
    http_response_code(403);
    exit('Access denied.');
  }
}
