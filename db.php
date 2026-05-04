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

// Add owner_id column to projects if it does not exist yet
try {
  $pdo->exec("ALTER TABLE projects ADD COLUMN owner_id INT NULL DEFAULT NULL");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}

// Add is_admin column to users if it does not exist yet
try {
  $pdo->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}

// Create task_uploads table if it does not exist yet
$pdo->exec("
  CREATE TABLE IF NOT EXISTS task_uploads (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id       INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name   VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(191) NULL,
    size_bytes    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    caption       VARCHAR(255) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_task_uploads_task_id (task_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create time_entries table if it does not exist yet
$pdo->exec("
  CREATE TABLE IF NOT EXISTS time_entries (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    project_id    INT NULL DEFAULT NULL,
    description   TEXT NULL DEFAULT NULL,
    clock_in      DATETIME NOT NULL,
    clock_out     DATETIME NULL DEFAULT NULL,
    hours_override DECIMAL(8,2) NULL DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_te_user    (user_id),
    INDEX idx_te_project (project_id),
    INDEX idx_te_clock_in (clock_in)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");