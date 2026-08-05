<?php
// config.php

require_once __DIR__ . '/bootstrap_env.php';

if (!class_exists(\Dotenv\Dotenv::class)) {
  $autoload = __DIR__ . '/vendor/autoload.php';
  if (is_file($autoload)) {
    require_once $autoload;
  }
}
if (class_exists(\Dotenv\Dotenv::class)) {
  \Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

return [
  'version' => '1.0.0',
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'spfx_ghostlaser',
    'user' => 'spfx_ghost',
    'pass' => 'Beverly90210##',
    'charset' => 'utf8mb4',
  ],
  'recaptcha' => [
    'site_key'   => '6LdUs-csAAAAAO0OwhwPWMTV941Vs7jN3XWB7MhT',
    'secret_key' => '6LdUs-csAAAAAC1ezjVMiAAUtS0GWoQrvYSsITCo',
  ],
];