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