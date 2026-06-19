<?php
/**
 * api/book-repair.php – Public API endpoint for the customer booking system.
 * Accepts JSON or form-encoded POST data from the frontend website,
 * validates all fields, and inserts a new record into service_requests.
 *
 * Success response (HTTP 201):
 *   { "success": true, "id": <new_request_id> }
 *
 * Error response (HTTP 400 / 405 / 500):
 *   { "success": false, "errors": [ "…", … ] }
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'errors' => ['Method not allowed. Use POST.']]);
    exit;
}

// ── Load DB ───────────────────────────────────────────────────────────────────
require __DIR__ . '/../db.php';

// ── Parse input (JSON body or form-encoded) ───────────────────────────────────
$content_type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($content_type === 'application/json') {
    $raw  = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => ['Invalid JSON body.']]);
        exit;
    }
} else {
    $body = $_POST;
}

// ── Helper ────────────────────────────────────────────────────────────────────
function str_field(array $body, string $key): string {
    return trim((string)($body[$key] ?? ''));
}

// ── Collect fields ────────────────────────────────────────────────────────────
$name          = str_field($body, 'name');
$phone         = str_field($body, 'phone');
$email         = str_field($body, 'email');
$machine_brand = str_field($body, 'machine_brand');
$machine_model = str_field($body, 'machine_model');
$machine_watts = str_field($body, 'machine_watts');
$machine_age   = str_field($body, 'machine_age');
$problem       = str_field($body, 'problem');
$street        = str_field($body, 'street');
$city          = str_field($body, 'city');
$state         = str_field($body, 'state');
$zip           = str_field($body, 'zip');
$country       = str_field($body, 'country') ?: 'USA';
$priority      = str_field($body, 'priority') ?: 'standard';

// ── Validate ──────────────────────────────────────────────────────────────────
$errors = [];

if ($name === '') {
    $errors[] = 'Name is required.';
} elseif (strlen($name) > 255) {
    $errors[] = 'Name must be 255 characters or fewer.';
}

if ($phone === '') {
    $errors[] = 'Phone number is required.';
} elseif (strlen($phone) > 100) {
    $errors[] = 'Phone number must be 100 characters or fewer.';
}

if ($email === '') {
    $errors[] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
} elseif (strlen($email) > 255) {
    $errors[] = 'Email address must be 255 characters or fewer.';
}

if ($machine_brand === '') {
    $errors[] = 'Machine brand is required.';
} elseif (strlen($machine_brand) > 100) {
    $errors[] = 'Machine brand must be 100 characters or fewer.';
}

if ($machine_model === '') {
    $errors[] = 'Machine model is required.';
} elseif (strlen($machine_model) > 100) {
    $errors[] = 'Machine model must be 100 characters or fewer.';
}

if ($machine_watts !== '' && strlen($machine_watts) > 50) {
    $errors[] = 'Machine wattage must be 50 characters or fewer.';
}

if ($machine_age !== '' && strlen($machine_age) > 50) {
    $errors[] = 'Machine age must be 50 characters or fewer.';
}

if ($problem === '') {
    $errors[] = 'Problem description is required.';
} elseif (strlen($problem) > 5000) {
    $errors[] = 'Problem description must be 5000 characters or fewer.';
}

if ($street === '') {
    $errors[] = 'Street address is required.';
} elseif (strlen($street) > 255) {
    $errors[] = 'Street address must be 255 characters or fewer.';
}

if ($city === '') {
    $errors[] = 'City is required.';
} elseif (strlen($city) > 100) {
    $errors[] = 'City must be 100 characters or fewer.';
}

if ($state === '') {
    $errors[] = 'State is required.';
} elseif (strlen($state) > 100) {
    $errors[] = 'State must be 100 characters or fewer.';
}

if ($zip === '') {
    $errors[] = 'ZIP / postal code is required.';
} elseif (strlen($zip) > 20) {
    $errors[] = 'ZIP / postal code must be 20 characters or fewer.';
}

if (!in_array($priority, ['standard', 'vip', 'emergency'], true)) {
    $errors[] = 'Priority must be one of: standard, vip, emergency.';
}

if ($errors) {
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// ── Derive problem_summary from the first 255 chars of problem ────────────────
$problem_summary = mb_substr($problem, 0, 255);

// ── Insert into service_requests ──────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "INSERT INTO service_requests
           (contact_name, contact_phone, contact_email,
            laser_brand, laser_model, laser_watts, laser_age,
            problem_summary, problem_details,
            service_street, service_city, service_state, service_zip, service_country,
            priority_level, source, request_status)
         VALUES
           (?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?, ?,
            ?, 'api', 'new')"
    );

    $stmt->execute([
        $name, $phone, $email,
        $machine_brand, $machine_model, $machine_watts ?: null, $machine_age ?: null,
        $problem_summary, $problem,
        $street, $city, $state, $zip, $country,
        $priority,
    ]);

    $new_id = (int) $pdo->lastInsertId();

    http_response_code(201);
    echo json_encode(['success' => true, 'id' => $new_id]);
} catch (\Throwable $ex) {
    http_response_code(500);
    echo json_encode(['success' => false, 'errors' => ['A database error occurred. Please try again.']]);
}
