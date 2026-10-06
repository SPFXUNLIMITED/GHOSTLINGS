<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2>Google Maps API Key Test</h2>";

$dotenv_path = __DIR__ . '/.env';
echo "Looking for .env at: " . $dotenv_path . "<br><br>";

if (!file_exists($dotenv_path)) {
    die("<strong style='color:red'>.env file NOT found!</strong>");
}

$key = '';
$lines = file($dotenv_path, FILE_IGNORE_NEW_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, 'GOOGLE_MAPS_API_KEY=') === 0) {
        $key = trim(substr($line, 19));
        $key = trim($key, '"\'');
        break;
    }
}

if (empty($key)) {
    echo "<strong style='color:red'>API Key NOT FOUND in .env file</strong>";
} else {
    echo "<strong style='color:green'>API Key Found!</strong><br>";
    echo "Key starts with: " . substr($key, 0, 10) . "...<br>";
    echo "Length: " . strlen($key);
}
?>