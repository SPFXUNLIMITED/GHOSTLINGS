<?php
// idle_clockout.php — AJAX endpoint: auto-clock-out the current user if they have an open entry.
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$uid = current_user_id();
$tz  = new DateTimeZone('America/Los_Angeles');

$stmt = $pdo->prepare("
    SELECT id FROM time_entries
    WHERE user_id = ? AND clock_out IS NULL AND hours_override IS NULL
    ORDER BY clock_in DESC
    LIMIT 1
");
$stmt->execute([$uid]);
$open = $stmt->fetch();

if ($open) {
    $now = (new DateTime('now', $tz))->format('Y-m-d H:i:s');
    $pdo->prepare("
        UPDATE time_entries SET clock_out = ? WHERE id = ? AND user_id = ?
    ")->execute([$now, (int)$open['id'], $uid]);
    echo json_encode(['ok' => true, 'clocked_out' => true]);
} else {
    echo json_encode(['ok' => true, 'clocked_out' => false]);
}
