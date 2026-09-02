<?php
/**
 * api/customer-interactions.php
 *
 * Shared AJAX backend for the reusable customer interaction module
 * (customer_interaction_module.php). Any admin page that includes
 * the module can call this endpoint to load a customer + interaction
 * history, log a new interaction, or save edited customer fields.
 *
 * POST body (JSON): { "action": "get_customer"|"log_interaction"|"save_customer", ... }
 * Auth: requires an active admin session (matches customers.php / customer_details.php).
 * CSRF: X-CSRF-Token header must match $_SESSION['customer_interaction_csrf'].
 */

session_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || (!is_admin() && !is_moderator())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/customer_interaction_module.php';

customerInteractionEnsureSchema($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$adminId = (int) ($_SESSION['user_id'] ?? 0);
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

$action = trim((string) ($body['action'] ?? ''));
$csrfHeader = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

if (!customerInteractionCsrfIsValid($csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

try {
    if ($action === 'get_customer') {
        $customerId = (int) ($body['customer_id'] ?? 0);
        $record = customerInteractionFetchRecord($pdo, $customerId);
        if ($record === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Customer not found.']);
            exit;
        }
        echo json_encode(['success' => true, 'record' => $record], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'log_interaction') {
        customerInteractionLog($pdo, $adminId, $body);
        echo json_encode(['success' => true]);
    } elseif ($action === 'save_customer') {
        customerInteractionSaveCustomer($pdo, $body);
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[customer-interactions] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unexpected server error.']);
}
