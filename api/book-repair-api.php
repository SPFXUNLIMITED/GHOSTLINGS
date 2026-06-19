<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * api/book-repair-api.php – Public API endpoint for the customer booking system.
 * Accepts JSON or form-encoded POST data from the frontend website,
 * validates all fields, and inserts a new record into service_requests.
 *
 * Success response (HTTP 201):
 *   { "success": true, "id": <new_request_id>, "suggested_dates": [...], "priority": "..." }
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

// ── Helper ────────────────────────────────────────────────────────────────────
function str_field(array $body, string $key): string {
    return trim((string)($body[$key] ?? ''));
}

function load_env_value(string $key): string {
    static $dotenv_values = null;

    if ($dotenv_values === null) {
        $dotenv_values = [];
        $dotenv_path = __DIR__ . '/../.env';
        if (is_file($dotenv_path) && is_readable($dotenv_path)) {
            $lines = file($dotenv_path, FILE_IGNORE_NEW_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim((string)$line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    $sep = strpos($line, '=');
                    if ($sep === false) {
                        continue;
                    }
                    $name = trim(substr($line, 0, $sep));
                    if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                        continue;
                    }
                    $value = trim(substr($line, $sep + 1));
                    if (strlen($value) >= 2) {
                        $first = $value[0];
                        $last  = $value[strlen($value) - 1];
                        if ($first === '"' && $last === '"') {
                            $value = substr($value, 1, -1);
                            $value = strtr($value, ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n", '\\r' => "\r", '\\t' => "\t"]);
                        } elseif ($first === "'" && $last === "'") {
                            $value = substr($value, 1, -1);
                            $value = strtr($value, ['\\\\' => '\\', "\\'" => "'"]);
                        }
                    } else {
                        $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
                        $value = rtrim($value);
                    }
                    $dotenv_values[$name] = $value;
                }
            }
        }
    }

    foreach ([getenv($key), getenv('REDIRECT_' . $key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null, $_SERVER['REDIRECT_' . $key] ?? null, $dotenv_values[$key] ?? null] as $candidate) {
        if ($candidate !== null && trim((string)$candidate) !== '') {
            return trim((string)$candidate);
        }
    }
    return '';
}

/**
 * Calls the Google Maps Geocoding API and returns an array with:
 *   ['lat' => float|null, 'lng' => float|null, 'status' => 'ok'|'failed']
 */
function geocode_address(string $full_address): array {
    $api_key = load_env_value('GOOGLE_MAPS_API_KEY');
    if ($api_key === '') {
        return ['lat' => null, 'lng' => null, 'status' => 'failed'];
    }

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?'
         . http_build_query(['address' => $full_address, 'key' => $api_key]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $curl_err = curl_errno($ch);
    curl_close($ch);

    if ($curl_err || $response === false) {
        return ['lat' => null, 'lng' => null, 'status' => 'failed'];
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'OK' || empty($data['results'][0]['geometry']['location'])) {
        error_log('api/book-repair-api.php geocode_address failed: status=' . ($data['status'] ?? 'invalid_json') . ' address=' . $full_address);
        return ['lat' => null, 'lng' => null, 'status' => 'failed'];
    }

    $location = $data['results'][0]['geometry']['location'];
    return [
        'lat'    => (float)$location['lat'],
        'lng'    => (float)$location['lng'],
        'status' => 'ok',
    ];
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

/**
 * Returns the number of active (non-cancelled, non-completed) service_requests
 * whose scheduled date falls on $date_str (Y-m-d).
 */
function count_jobs_on_date(PDO $pdo, string $date_str): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM service_requests
         WHERE DATE(created_at) = ?
           AND request_status NOT IN ('cancelled', 'completed')"
    );
    $stmt->execute([$date_str]);
    return (int)$stmt->fetchColumn();
}

/**
 * Returns an array of suggested service date strings (Y-m-d) based on priority.
 * Maximum of 3 active jobs are allowed per day.
 *
 * - emergency : today's date (weekends allowed); no capacity check
 * - vip       : next business day (skip weekends) with fewer than 3 active jobs
 * - standard  : next 3 business days (skip weekends) each with fewer than 3 active jobs
 */
function get_suggested_dates(string $priority, PDO $pdo): array {
    $dates = [];
    $today = new DateTimeImmutable('today');
    $max_jobs = 3;

    if ($priority === 'emergency') {
        // Any day including weekends; return today regardless of capacity
        $dates[] = $today->format('Y-m-d');
    } elseif ($priority === 'vip') {
        // Next business day with capacity
        $day = $today->modify('+1 day');
        while (true) {
            if ((int)$day->format('N') < 6 && count_jobs_on_date($pdo, $day->format('Y-m-d')) < $max_jobs) {
                $dates[] = $day->format('Y-m-d');
                break;
            }
            $day = $day->modify('+1 day');
        }
    } else {
        // standard: next 3 business days with capacity
        $day = $today->modify('+1 day');
        while (count($dates) < 3) {
            if ((int)$day->format('N') < 6 && count_jobs_on_date($pdo, $day->format('Y-m-d')) < $max_jobs) {
                $dates[] = $day->format('Y-m-d');
            }
            $day = $day->modify('+1 day');
        }
    }

    return $dates;
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

    // ── Geocode the service address ────────────────────────────────────────────
    $full_address = implode(', ', array_filter([$street, $city, $state, $zip], fn($p) => $p !== ''));
    $geo = $full_address !== '' ? geocode_address($full_address) : ['lat' => null, 'lng' => null, 'status' => 'failed'];

    $stmt = $pdo->prepare(
        "INSERT INTO service_requests
          (customer_id,
            laser_brand, laser_model, laser_watts, laser_age,
            problem_summary, problem_details,
            priority_level, source, request_status,
            latitude, longitude, geocode_status)
         VALUES
           (?,
            ?, ?, ?, ?,
            ?, ?,
            ?, 'api', 'new',
            ?, ?, ?)"
    );

    $stmt->execute([
        $customer_id,
        $machine_brand, $machine_model, $machine_watts ?: null, $machine_age ?: null,
        $problem_summary, $problem,
        $priority,
        $geo['lat'], $geo['lng'], $geo['status'],
    ]);

    $new_id = (int) $pdo->lastInsertId();

    // Log submission for rate limiting
    try {
        $pdo->prepare("INSERT INTO form_rate_limit (ip) VALUES (?)")->execute([$_api_ip]);
    } catch (\Throwable $ex) {
        // Non-fatal
    }

    $suggested_dates = get_suggested_dates($priority, $pdo);

    http_response_code(201);
    echo json_encode([
        'success'         => true,
        'id'              => $new_id,
        'suggested_dates' => $suggested_dates,
        'priority'        => $priority,
    ]);
} catch (\Throwable $ex) {
    error_log('api/book-repair-api.php DB error: ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'errors' => ['A database error occurred. Please try again.']]);
}
