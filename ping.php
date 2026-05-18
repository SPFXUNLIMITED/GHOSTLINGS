<?php
// ping.php — Session keep-alive endpoint.
// Called periodically by the client-side heartbeat to reset the server-side
// session idle clock, preventing the session from expiring while the tab is open.
require __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
