<?php
/**
 * api/machines.php – Public read-only JSON API for the machines catalog.
 *
 * Returns machines where is_active = 1 AND is_visible = 1 AND is_catalog = 1,
 * ordered by name. Always returns a JSON array (empty array + HTTP 200 when
 * nothing matches).
 *
 * Error response (HTTP 405 / 500): { "error": "…" }
 */

// ── CORS (frontend site only) ────────────────────────────────────────────────
$allowed_origin = trim((string)(getenv('FRONTEND_ORIGIN') ?: 'https://ghostlaser.com'));
$request_origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($request_origin !== '' && strcasecmp($request_origin, $allowed_origin) === 0) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, OPTIONS');
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit;
}

require __DIR__ . '/../db.php';

/** Format a decimal inch value, e.g. 48.0 → "4ft", 50.0 → "4'2\"". */
function machines_api_fmt_inches(?string $inches_val): ?string {
    if ($inches_val === null || $inches_val === '') return null;
    $in = (float)$inches_val;
    if ($in <= 0) return null;
    $ft  = (int)floor($in / 12);
    $rem = round($in - ($ft * 12), 2);
    if ($ft > 0 && $rem == 0) return "{$ft}ft";
    if ($ft === 0) return "{$rem}\"";
    return "{$ft}'{$rem}\"";
}

/** Format a decimal mm value, e.g. 1219.2 → "1219mm". */
function machines_api_fmt_mm(?string $mm_val): ?string {
    if ($mm_val === null || $mm_val === '') return null;
    $mm = (float)$mm_val;
    if ($mm <= 0) return null;
    return (string)(int)round($mm) . 'mm';
}

/** "L × W" from two formatted parts, or null when both are missing. */
function machines_api_pair(?string $length, ?string $width): ?string {
    if ($length === null && $width === null) return null;
    return ($length ?? '—') . ' × ' . ($width ?? '—');
}

/** Absolute URL for an uploaded photo filename. */
function machines_api_photo_url(string $base, ?string $file): ?string {
    if ($file === null || $file === '') return null;
    return $base . '/uploads/' . rawurlencode($file);
}

try {
    $stmt = $pdo->query("
        SELECT * FROM machines
        WHERE is_active = 1 AND is_visible = 1 AND is_catalog = 1
        ORDER BY name ASC
    ");
    $rows = $stmt->fetchAll();
} catch (\Throwable $e) {
    error_log('api/machines.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load machines.']);
    exit;
}

$base_url = rtrim(trim((string)(getenv('APP_URL') ?: 'https://ghostlaser.com/project')), '/');

$out = [];
foreach ($rows as $m) {
    $machine_weight = (isset($m['machine_weight_kg']) && (float)$m['machine_weight_kg'] > 0)
        ? round((float)$m['machine_weight_kg'], 1) : null;
    $crate_weight = (isset($m['crate_weight_kg']) && (float)$m['crate_weight_kg'] > 0)
        ? round((float)$m['crate_weight_kg'], 1) : null;

    $crate_metric = machines_api_pair(
        machines_api_fmt_mm($m['crate_length_mm'] ?? null),
        machines_api_fmt_mm($m['crate_width_mm'] ?? null)
    );
    $crate_imperial = machines_api_pair(
        machines_api_fmt_inches($m['crate_length'] ?? null),
        machines_api_fmt_inches($m['crate_width'] ?? null)
    );
    $crate_h_mm  = machines_api_fmt_mm($m['crate_height_mm'] ?? null);
    $crate_h_imp = machines_api_fmt_inches($m['crate_height'] ?? null);
    if ($crate_metric !== null && $crate_h_mm !== null)    $crate_metric   .= ' × ' . $crate_h_mm;
    if ($crate_imperial !== null && $crate_h_imp !== null) $crate_imperial .= ' × ' . $crate_h_imp;

    $photos = array_values(array_filter([
        machines_api_photo_url($base_url, $m['primary_photo']   ?? null),
        machines_api_photo_url($base_url, $m['secondary_photo'] ?? null),
        machines_api_photo_url($base_url, $m['tertiary_photo']  ?? null),
    ], static fn($u) => $u !== null));

    $out[] = [
        'id'                      => (int)$m['id'],
        'name'                    => (string)$m['name'],
        // No brand/price/currency columns exist on `machines` yet.
        'brand'                   => null,
        'model'                   => ($m['model'] ?? '') !== '' ? (string)$m['model'] : null,
        'description'             => ($m['description'] ?? '') !== '' ? (string)$m['description'] : null,
        'price'                   => null,
        'currency'                => null,
        'cutting_area_metric'     => machines_api_pair(
                                        machines_api_fmt_mm($m['cut_length_mm'] ?? null),
                                        machines_api_fmt_mm($m['cut_width_mm'] ?? null)
                                     ),
        'cutting_area_imperial'   => machines_api_pair(
                                        machines_api_fmt_inches($m['cut_length'] ?? null),
                                        machines_api_fmt_inches($m['cut_width'] ?? null)
                                     ),
        'dimensions_metric'       => machines_api_pair(
                                        machines_api_fmt_mm($m['machine_length_mm'] ?? null),
                                        machines_api_fmt_mm($m['machine_width_mm'] ?? null)
                                     ),
        'dimensions_imperial'     => machines_api_pair(
                                        machines_api_fmt_inches($m['machine_length'] ?? null),
                                        machines_api_fmt_inches($m['machine_width'] ?? null)
                                     ),
        'weight_kg'               => $machine_weight,
        'weight_lbs'              => $machine_weight !== null ? (int)round($machine_weight * 2.20462) : null,
        'crate_dimensions_metric'   => $crate_metric,
        'crate_dimensions_imperial' => $crate_imperial,
        'crate_weight_kg'         => $crate_weight,
        'crate_weight_lbs'        => $crate_weight !== null ? (int)round($crate_weight * 2.20462) : null,
        'photo_url'               => $photos[0] ?? null,
        'photo_urls'              => $photos,
    ];
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
