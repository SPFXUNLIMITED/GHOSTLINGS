<?php
// db.php
date_default_timezone_set('America/Los_Angeles');

$config = require __DIR__ . '/config.php';
$db = $config['db'];

$dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

$pdo = new PDO($dsn, $db['user'], $db['pass'], $options);

// Add priority column to projects if it does not exist yet
try {
  $pdo->exec("ALTER TABLE projects ADD COLUMN priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium'");
} catch (PDOException $e) {
  // Column already exists — SQLSTATE 42S21
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}

// Add priority column to tasks if it does not exist yet
try {
  $pdo->exec("ALTER TABLE tasks ADD COLUMN priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium'");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}

// Add assigned_to column to tasks if it does not exist yet
try {
  $pdo->exec("ALTER TABLE tasks ADD COLUMN assigned_to INT NULL DEFAULT NULL");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}