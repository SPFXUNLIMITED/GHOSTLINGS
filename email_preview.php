<?php
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($id === false || $id === null) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Unable to generate email preview.</h2>';
    exit;
}

$_GET['id'] = (string)$id;
$_GET['email_preview'] = 1;
require __DIR__ . '/invoice_form.php';
