<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$state = trim((string)($_POST['state'] ?? ''));
if (!in_array($state, ['idle', 'active'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid state']);
    exit;
}

$uid = current_user_id();
$tz = new DateTimeZone('America/Los_Angeles');
$today = (new DateTime('now', $tz))->format('Y-m-d');
$session_key = defined('ATTENDANCE_IDLE_SESSION_KEY')
    ? ATTENDANCE_IDLE_SESSION_KEY
    : 'attendance_idle_logged';
$fallback_user = trim((string)($_SESSION['username'] ?? ''));

$stmt = $pdo->prepare("
    SELECT id
    FROM time_entries
    WHERE user_id = ?
      AND clock_out IS NULL
      AND hours_override IS NULL
      AND DATE(clock_in) = ?
    ORDER BY clock_in DESC
    LIMIT 1
");
$stmt->execute([$uid, $today]);
$open = $stmt->fetch();

if (!$open) {
    unset($_SESSION[$session_key]);
    echo json_encode(['ok' => true, 'tracked' => false, 'logged' => false]);
    exit;
}

$logged = false;

if ($state === 'idle') {
    if (empty($_SESSION[$session_key])) {
        log_admin_activity($pdo, $uid, 'Attendance', 'went idle', $fallback_user);
        $_SESSION[$session_key] = 1;
        $logged = true;
    }
} else {
    if (!empty($_SESSION[$session_key])) {
        log_admin_activity($pdo, $uid, 'Attendance', 'resumed activity', $fallback_user);
        unset($_SESSION[$session_key]);
        $logged = true;
    }
}

echo json_encode(['ok' => true, 'tracked' => true, 'logged' => $logged]);
