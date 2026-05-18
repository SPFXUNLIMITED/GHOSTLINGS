<?php
// ping.php — Session keep-alive endpoint.
// Called periodically by the client-side heartbeat to reset the server-side
// session idle clock, preventing the session from expiring while the tab is open.
// Returns HTTP 401 JSON (instead of a redirect) so the JS heartbeat can detect
// an expired session and redirect the user to the login page.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

echo json_encode(['ok' => true]);
