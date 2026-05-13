<?php
// db.php
define('APP_TZ', 'America/Los_Angeles');
date_default_timezone_set(APP_TZ);

$config = require __DIR__ . '/config.php';
$db = $config['db'];

$dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

$pdo = new PDO($dsn, $db['user'], $db['pass'], $options);

// Add playbook column to projects if it does not exist yet
try {
  $pdo->exec("ALTER TABLE projects ADD COLUMN playbook TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}

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

// Add archived column to projects if it does not exist yet
try {
  $pdo->exec("ALTER TABLE projects ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {
  // SQLSTATE 42S21 = column already exists
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

// Add is_doc_category column to projects if it does not exist yet
try {
  $pdo->exec("ALTER TABLE projects ADD COLUMN is_doc_category TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}

// Create task_comments table if it does not exist yet
$pdo->exec("
  CREATE TABLE IF NOT EXISTS task_comments (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id    INT UNSIGNED NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_task_comments_task_id (task_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create project_comments table if it does not exist yet
$pdo->exec("
  CREATE TABLE IF NOT EXISTS project_comments (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_project_comments_project_id (project_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create project_uploads table if it does not exist yet
$pdo->exec("
  CREATE TABLE IF NOT EXISTS project_uploads (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id    INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name   VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(191) NULL,
    size_bytes    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    caption       VARCHAR(255) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_project_uploads_project_id (project_id)
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

// Add email column to users if it does not exist yet
try {
  $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') throw $e;
}

// Add unique index on email if not already present (ignore error if exists)
try {
  $pdo->exec("ALTER TABLE users ADD UNIQUE INDEX idx_users_email (email)");
} catch (PDOException $e) {
  // 42000 duplicate key name or 42S21; just skip
}

// Add role column to users if it does not exist yet
try {
  $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('admin','moderator','user') NOT NULL DEFAULT 'user'");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') throw $e;
}

// Add email_verified column to users if it does not exist yet
try {
  $pdo->exec("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') throw $e;
}

// Add verification_token column to users if it does not exist yet
try {
  $pdo->exec("ALTER TABLE users ADD COLUMN verification_token VARCHAR(64) NULL");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') throw $e;
}

// Add token_expires column to users if it does not exist yet
try {
  $pdo->exec("ALTER TABLE users ADD COLUMN token_expires DATETIME NULL");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') throw $e;
}

// Add lat/lng columns to users if they do not exist yet
try {
  $pdo->exec("ALTER TABLE users ADD COLUMN lat DECIMAL(10,7) NULL");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') throw $e;
}
try {
  $pdo->exec("ALTER TABLE users ADD COLUMN lng DECIMAL(10,7) NULL");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') throw $e;
}

// Sync existing admin users: set role='admin' where is_admin=1 and role='user'
try {
  $pdo->exec("UPDATE users SET role='admin' WHERE is_admin=1 AND role='user'");
} catch (PDOException $e) { /* ignore */ }

// Create laser_entries table if it does not exist yet
$pdo->exec("
  CREATE TABLE IF NOT EXISTS laser_entries (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED NOT NULL,
    first_name          VARCHAR(100) NOT NULL,
    last_name           VARCHAR(100) NOT NULL,
    cell_phone          VARCHAR(30)  NOT NULL,
    city                VARCHAR(100) NOT NULL,
    state               VARCHAR(50)  NOT NULL,
    zip_code            VARCHAR(20)  NOT NULL,
    email               VARCHAR(255) NOT NULL,
    laser_brand         VARCHAR(100) NOT NULL,
    laser_model         VARCHAR(100) NOT NULL,
    laser_watts         VARCHAR(50)  NOT NULL,
    laser_age           VARCHAR(50)  NOT NULL,
    laser_problem       TEXT         NOT NULL,
    submission_ip       VARCHAR(45)  NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_le_user_id (user_id),
    KEY idx_le_email (email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create form_rate_limit table for CSRF / rate-limit tracking
$pdo->exec("
  CREATE TABLE IF NOT EXISTS form_rate_limit (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip         VARCHAR(45)  NOT NULL,
    submitted_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_frl_ip (ip)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");