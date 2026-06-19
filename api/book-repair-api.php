<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * api/book-repair-api.php – Public API endpoint for the customer booking system.
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

// ── Rate limit: max 10 submissions per IP per hour (reuses form_rate_limit) ───
$_api_ip = (function (): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
})();

try {
    $rl_check = $pdo->prepare(
        "SELECT COUNT(*) FROM form_rate_limit WHERE ip = ? AND submitted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $rl_check->execute([$_api_ip]);
    if ((int) $rl_check->fetchColumn() >= 10) {
        http_response_code(429);
        echo json_encode(['success' => false, 'errors' => ['Too many submissions. Please try again later.']]);
        exit;
    }
} catch (\Throwable $ex) {
    // Non-fatal: proceed if rate-limit table is unavailable
}

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
 
// Backward compatibility for older clients still sending legacy keys.
if (!array_key_exists('machine_watts', $body) && array_key_exists('watts', $body)) {
    $body['machine_watts'] = $body['watts'];
}
if (!array_key_exists('machine_age', $body) && array_key_exists('age', $body)) {
    $body['machine_age'] = $body['age'];
}
 
// ── Helper ────────────────────────────────────────────────────────────────────
function str_field(array $body, string $key): string {
    return trim((string)($body[$key] ?? ''));
}

function split_name_parts(string $full_name): array {
    $full_name = trim($full_name);
    if ($full_name === '') {
        return ['', ''];
    }
    $parts = preg_split('/\s+/', $full_name) ?: [];
    if (!$parts) {
        return [$full_name, ''];
    }
    $first = (string)array_shift($parts);
    $last = trim(implode(' ', $parts));
    return [$first, $last];
}

function resolve_customer_id(
    PDO $pdo,
    string $name,
    string $phone,
    string $email,
    string $street,
    string $city,
    string $state,
    string $zip,
    string $country
): int {
    [$first_name, $last_name] = split_name_parts($name);

    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE email = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$email]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    }

    if ($phone !== '') {
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$phone]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    }

    if ($first_name !== '' && $last_name !== '') {
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE first_name = ? AND last_name = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$first_name, $last_name]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    }

    $hubspot_contact_id = 'service_api_' . bin2hex(random_bytes(10));
    $insert = $pdo->prepare("
        INSERT INTO customers (
            hubspot_contact_id, first_name, last_name, company, phone, email,
            address, city, state, zip, country, last_updated
        ) VALUES (?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, NULL)
    ");
    $insert->execute([
        $hubspot_contact_id,
        $first_name,
        $last_name,
        $phone,
        $email,
        $street,
        $city,
        $state,
        $zip,
        $country,
    ]);

    return (int)$pdo->lastInsertId();
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
$errors       = [];
$field_errors = [];

if ($name === '') {
    $msg = 'Name is required.';
    $errors[] = $msg; $field_errors['name'] = $msg;
} elseif (strlen($name) > 255) {
    $msg = 'Name must be 255 characters or fewer.';
    $errors[] = $msg; $field_errors['name'] = $msg;
}

if ($phone === '') {
    $msg = 'Phone number is required.';
    $errors[] = $msg; $field_errors['phone'] = $msg;
} elseif (strlen($phone) > 100) {
    $msg = 'Phone number must be 100 characters or fewer.';
    $errors[] = $msg; $field_errors['phone'] = $msg;
}

if ($email === '') {
    $msg = 'Email address is required.';
    $errors[] = $msg; $field_errors['email'] = $msg;
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $msg = 'A valid email address is required.';
    $errors[] = $msg; $field_errors['email'] = $msg;
} elseif (strlen($email) > 255) {
    $msg = 'Email address must be 255 characters or fewer.';
    $errors[] = $msg; $field_errors['email'] = $msg;
}

if ($machine_brand === '') {
    $msg = 'Machine brand is required.';
    $errors[] = $msg; $field_errors['machine_brand'] = $msg;
} elseif (strlen($machine_brand) > 100) {
    $msg = 'Machine brand must be 100 characters or fewer.';
    $errors[] = $msg; $field_errors['machine_brand'] = $msg;
}

if ($machine_model === '') {
    $msg = 'Machine model is required.';
    $errors[] = $msg; $field_errors['machine_model'] = $msg;
} elseif (strlen($machine_model) > 100) {
    $msg = 'Machine model must be 100 characters or fewer.';
    $errors[] = $msg; $field_errors['machine_model'] = $msg;
}

if ($machine_watts !== '' && strlen($machine_watts) > 50) {
    $msg = 'Machine wattage must be 50 characters or fewer.';
    $errors[] = $msg; $field_errors['machine_watts'] = $msg;
}

if ($machine_age !== '' && strlen($machine_age) > 50) {
    $msg = 'Machine age must be 50 characters or fewer.';
    $errors[] = $msg; $field_errors['machine_age'] = $msg;
}

if ($problem === '') {
    $msg = 'Problem description is required.';
    $errors[] = $msg; $field_errors['problem'] = $msg;
} elseif (strlen($problem) > 5000) {
    $msg = 'Problem description must be 5000 characters or fewer.';
    $errors[] = $msg; $field_errors['problem'] = $msg;
}

if ($street === '') {
    $msg = 'Street address is required.';
    $errors[] = $msg; $field_errors['street'] = $msg;
} elseif (strlen($street) > 255) {
    $msg = 'Street address must be 255 characters or fewer.';
    $errors[] = $msg; $field_errors['street'] = $msg;
}

if ($city === '') {
    $msg = 'City is required.';
    $errors[] = $msg; $field_errors['city'] = $msg;
} elseif (strlen($city) > 100) {
    $msg = 'City must be 100 characters or fewer.';
    $errors[] = $msg; $field_errors['city'] = $msg;
}

if ($state === '') {
    $msg = 'State is required.';
    $errors[] = $msg; $field_errors['state'] = $msg;
} elseif (strlen($state) > 100) {
    $msg = 'State must be 100 characters or fewer.';
    $errors[] = $msg; $field_errors['state'] = $msg;
}

if ($zip === '') {
    $msg = 'ZIP / postal code is required.';
    $errors[] = $msg; $field_errors['zip'] = $msg;
} elseif (strlen($zip) > 20) {
    $msg = 'ZIP / postal code must be 20 characters or fewer.';
    $errors[] = $msg; $field_errors['zip'] = $msg;
}

if (!in_array($priority, ['standard', 'vip', 'emergency'], true)) {
    $msg = 'Priority must be one of: standard, vip, emergency.';
    $errors[] = $msg; $field_errors['priority'] = $msg;
}

if ($errors) {
    http_response_code(400);
    echo json_encode([
        'success'      => false,
        'errors'       => $errors,
        'field_errors' => $field_errors,
        'received'     => [
            'name'          => $name,
            'phone'         => $phone,
            'email'         => $email,
            'machine_brand' => $machine_brand,
            'machine_model' => $machine_model,
            'machine_watts' => $machine_watts,
            'machine_age'   => $machine_age,
            'problem'       => mb_substr($problem, 0, 200) . (mb_strlen($problem) > 200 ? '…' : ''),
            'street'        => $street,
            'city'          => $city,
            'state'         => $state,
            'zip'           => $zip,
            'country'       => $country,
            'priority'      => $priority,
        ],
    ]);
    exit;
}

// ── Derive problem_summary from the first 255 chars of problem ────────────────
$problem_summary = mb_substr($problem, 0, 255);

// ── Insert into service_requests ──────────────────────────────────────────────
try {
    $customer_id = resolve_customer_id(
        $pdo,
        $name,
        $phone,
        $email,
        $street,
        $city,
        $state,
        $zip,
        $country
    );

    $stmt = $pdo->prepare(
        "INSERT INTO service_requests
          (customer_id,
            laser_brand, laser_model, laser_watts, laser_age,
            problem_summary, problem_details,
            priority_level, source, request_status)
         VALUES
           (?,
            ?, ?, ?, ?,
            ?, ?,
            ?, 'api', 'new')"
    );

    $stmt->execute([
        $customer_id,
        $machine_brand, $machine_model, $machine_watts ?: null, $machine_age ?: null,
        $problem_summary, $problem,
        $priority,
    ]);

    $new_id = (int) $pdo->lastInsertId();

    // Log submission for rate limiting
    try {
        $pdo->prepare("INSERT INTO form_rate_limit (ip) VALUES (?)")->execute([$_api_ip]);
    } catch (\Throwable $ex) {
        // Non-fatal
    }

    http_response_code(201);
    echo json_encode(['success' => true, 'id' => $new_id]);
} catch (\Throwable $ex) {
    error_log('api/book-repair-api.php DB error: ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'errors' => ['A database error occurred. Please try again.']]);
}
