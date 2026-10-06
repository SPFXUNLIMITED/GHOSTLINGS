<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'api/book-repair-api.php';

echo "<h2>API Key Test</h2>";

$key = load_env_value('GOOGLE_MAPS_API_KEY');

if (empty($key)) {
    echo "<strong style='color:red'>API Key is EMPTY</strong>";
} else {
    $visible = substr($key, 0, 8) . "..." . substr($key, -4);
    echo "<strong style='color:green'>API Key Found:</strong> " . $visible . "<br>";
    echo "Length: " . strlen($key) . " characters";
}

echo "<hr><pre>";
print_r([
    'env_path'     => __DIR__ . '/.env',
    'file_exists'  => file_exists(__DIR__ . '/.env') ? 'YES' : 'NO',
    'key_status'   => empty($key) ? 'MISSING' : 'PRESENT'
]);
echo "</pre>";
?>