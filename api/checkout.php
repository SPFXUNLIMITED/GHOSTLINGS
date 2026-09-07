<?php
/**
 * api/checkout.php – Public endpoint that creates a Stripe Checkout Session
 * with dynamic pricing taken from the `machines` table.
 *
 * No Stripe Price IDs and no prices stored in Stripe: the amount is always
 * derived from machines.price at request time.
 *
 * Request (HTTP 200):  POST application/json  { "machine_id": 12 }
 * Success response:    { "url": "https://checkout.stripe.com/…" }
 * Error response (400 / 404 / 405 / 500 / 502): { "error": "…" }
 */

const CHECKOUT_API_TIMEOUT_SECONDS = 20;

// ── CORS (frontend site only) ────────────────────────────────────────────────
$allowed_origin = trim((string)(getenv('FRONTEND_ORIGIN') ?: 'https://ghostlaser.com'));
$request_origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($request_origin !== '' && strcasecmp($request_origin, $allowed_origin) === 0) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

require __DIR__ . '/../db.php';

/** Resolve the Stripe secret key: env first, then admin-managed settings. */
function checkout_api_stripe_secret_key(PDO $pdo): string {
    $secret_key = trim((string)(getenv('STRIPE_SECRET_KEY') ?: ''));
    if ($secret_key !== '') {
        return $secret_key;
    }
    try {
        app_ensure_integration_settings_table($pdo);
        $stmt = $pdo->prepare(
            "SELECT setting_val, is_encrypted FROM integration_settings WHERE setting_key = 'stripe_secret_key' LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if (is_array($row)) {
            $stored = trim((string)($row['setting_val'] ?? ''));
            if ($stored !== '') {
                $resolved = ((int)($row['is_encrypted'] ?? 0) === 1)
                    ? app_decrypt_setting_value($stored)
                    : $stored;
                return trim((string)$resolved);
            }
        }
    } catch (\Throwable $e) {
        error_log('api/checkout.php: unable to read Stripe secret key: ' . $e->getMessage());
    }
    return '';
}

// ── Parse input ──────────────────────────────────────────────────────────────
$content_type = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($content_type === 'application/json' || $content_type === '') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body. Expected { "machine_id": <id> }.']);
        exit;
    }
} else {
    $body = $_POST;
}

$machine_id_raw = $body['machine_id'] ?? null;
if (!is_int($machine_id_raw) && !(is_string($machine_id_raw) && ctype_digit(trim($machine_id_raw)))) {
    http_response_code(400);
    echo json_encode(['error' => 'machine_id is required and must be a positive integer.']);
    exit;
}
$machine_id = (int)$machine_id_raw;
if ($machine_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'machine_id is required and must be a positive integer.']);
    exit;
}

// ── Look up the machine ──────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT id, name, price, is_active, is_visible, is_catalog FROM machines WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$machine_id]);
    $machine = $stmt->fetch();
} catch (\Throwable $e) {
    error_log('api/checkout.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load machine.']);
    exit;
}

if (!is_array($machine)) {
    http_response_code(404);
    echo json_encode(['error' => 'Machine not found.']);
    exit;
}
if ((int)$machine['is_active'] !== 1 || (int)$machine['is_visible'] !== 1 || (int)$machine['is_catalog'] !== 1) {
    http_response_code(404);
    echo json_encode(['error' => 'Machine is not available for purchase.']);
    exit;
}

$price = ($machine['price'] === null || $machine['price'] === '') ? 0.0 : round((float)$machine['price'], 2);
if ($price <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Machine does not have a purchase price. Please request a quote.']);
    exit;
}

$machine_name = trim((string)($machine['name'] ?? ''));
if ($machine_name === '') {
    $machine_name = 'Machine #' . $machine_id;
}

// ── Stripe configuration ─────────────────────────────────────────────────────
// The Stripe PHP library is not vendored in this project, so we talk to the
// Stripe REST API with cURL, exactly like invoice_form.php does.
if (!function_exists('curl_init')) {
    error_log('api/checkout.php: cURL extension is not available.');
    http_response_code(500);
    echo json_encode(['error' => 'Checkout is misconfigured: Stripe client is unavailable.']);
    exit;
}

$secret_key = checkout_api_stripe_secret_key($pdo);
if ($secret_key === '') {
    error_log('api/checkout.php: Stripe secret key is not configured.');
    http_response_code(500);
    echo json_encode(['error' => 'Checkout is misconfigured: Stripe is not set up.']);
    exit;
}

// ── Create the Checkout Session ──────────────────────────────────────────────
$frontend_base = rtrim($allowed_origin, '/');
$machines_page = $frontend_base . '/machines';
$payload = [
    'mode'        => 'payment',
    'success_url' => $machines_page . '?checkout=success',
    'cancel_url'  => $machines_page . '?checkout=cancel',
    'line_items'  => [[
        'price_data' => [
            'currency'     => 'usd',
            'unit_amount'  => (int)round($price * 100),
            'product_data' => ['name' => $machine_name],
        ],
        'quantity' => 1,
    ]],
    'metadata' => [
        'machine_id'   => (string)$machine_id,
        'machine_name' => $machine_name,
    ],
];

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
if ($ch === false) {
    error_log('api/checkout.php: failed to initialize cURL session.');
    http_response_code(500);
    echo json_encode(['error' => 'Unable to start checkout.']);
    exit;
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
    CURLOPT_TIMEOUT        => CHECKOUT_API_TIMEOUT_SECONDS,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $secret_key,
        'Content-Type: application/x-www-form-urlencoded',
    ],
]);
$response_body = curl_exec($ch);
$curl_error    = curl_error($ch);
$http_code     = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($response_body === false) {
    error_log('api/checkout.php: Stripe request failed: ' . ($curl_error !== '' ? $curl_error : 'unknown error'));
    http_response_code(502);
    echo json_encode(['error' => 'Unable to reach Stripe. Please try again.']);
    exit;
}

$response = json_decode((string)$response_body, true);
if (!is_array($response)) {
    error_log('api/checkout.php: Stripe returned an invalid response (HTTP ' . $http_code . ').');
    http_response_code(502);
    echo json_encode(['error' => 'Stripe returned an invalid response.']);
    exit;
}
if ($http_code >= 400) {
    error_log('api/checkout.php: Stripe error (HTTP ' . $http_code . '): ' . trim((string)($response['error']['message'] ?? '')));
    http_response_code(502);
    echo json_encode(['error' => 'Unable to create the checkout session.']);
    exit;
}

$checkout_url = trim((string)($response['url'] ?? ''));
if ($checkout_url === '') {
    error_log('api/checkout.php: Stripe did not return a hosted checkout URL.');
    http_response_code(502);
    echo json_encode(['error' => 'Stripe did not return a checkout link.']);
    exit;
}

echo json_encode(['url' => $checkout_url], JSON_UNESCAPED_SLASHES);
