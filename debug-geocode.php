<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$env_path = __DIR__ . '/.env';
$lines = file($env_path);
$api_key = '';

foreach ($lines as $line) {
    if (strpos($line, 'GOOGLE_MAPS_API_KEY') !== false) {
        $api_key = trim(explode('=', $line, 2)[1]);
        $api_key = trim($api_key, " \"'\n\r");
        break;
    }
}

$address = urlencode("123 Main Street, Anaheim, CA 92801");
$url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . $address . "&key=" . $api_key;

echo "<h2>Google Geocoding Test</h2>";
$response = file_get_contents($url);
$data = json_decode($response, true);

echo "<strong>Status:</strong> " . ($data ?? 'No status returned') . "<br><br>";

if (!empty($data['error_message'])) {
    echo "<strong style='color:red'>Error:</strong> " . $data ;
} elseif ($data === 'OK') {
    echo "<strong style='color:green'>SUCCESS!</strong>";
} else {
    echo "<pre>";
    print_r($data);
    echo "</pre>";
}
?>