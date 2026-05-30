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

// Add caption column to task_uploads if it does not exist yet
try {
  $pdo->exec("ALTER TABLE task_uploads ADD COLUMN caption VARCHAR(255) NULL");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}

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

// Add caption column to project_uploads if it does not exist yet
try {
  $pdo->exec("ALTER TABLE project_uploads ADD COLUMN caption VARCHAR(255) NULL");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}

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

// Add RFQ profile columns to users if they do not exist yet
foreach ([
  "ALTER TABLE users ADD COLUMN contact_name VARCHAR(255) NULL",
  "ALTER TABLE users ADD COLUMN company_name VARCHAR(255) NULL",
  "ALTER TABLE users ADD COLUMN contact_phone VARCHAR(100) NULL",
] as $sql) {
  try {
    $pdo->exec($sql);
  } catch (PDOException $e) {
    if ($e->getCode() !== '42S21') throw $e;
  }
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

// Add bumped_at column to projects if it does not exist yet
try {
  $pdo->exec("ALTER TABLE projects ADD COLUMN bumped_at DATETIME NULL DEFAULT NULL");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}

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

// Create rfq_requests table for CO2 laser cutter procurement requests
$pdo->exec("
  CREATE TABLE IF NOT EXISTS rfq_requests (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    requested_by       INT UNSIGNED NOT NULL,
    request_category   ENUM('machine','parts') NOT NULL DEFAULT 'machine',
    request_title      VARCHAR(255) NOT NULL,
    machine_size       VARCHAR(100) NOT NULL,
    laser_watts        VARCHAR(50)  NOT NULL,
    tube_type          VARCHAR(100) NOT NULL,
    part_category      VARCHAR(100) NULL,
    part_specs         TEXT         NULL,
    quantity           INT UNSIGNED NOT NULL DEFAULT 1,
    required_features  TEXT         NOT NULL,
    additional_notes   TEXT         NULL,
    request_status     ENUM('draft','sourcing','quotes_received','shortlisted','ordered','closed') NOT NULL DEFAULT 'sourcing',
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rfq_requests_requested_by (requested_by),
    KEY idx_rfq_requests_status (request_status),
    KEY idx_rfq_requests_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add RFQ request category and parts fields if they do not exist yet
foreach ([
  "ALTER TABLE rfq_requests ADD COLUMN request_category ENUM('machine','parts') NOT NULL DEFAULT 'machine' AFTER requested_by",
  "ALTER TABLE rfq_requests ADD COLUMN part_category VARCHAR(100) NULL AFTER tube_type",
  "ALTER TABLE rfq_requests ADD COLUMN part_specs TEXT NULL AFTER part_category",
] as $sql) {
  try {
    $pdo->exec($sql);
  } catch (PDOException $e) {
    if ($e->getCode() !== '42S21') {
      throw $e;
    }
  }
}

// Create rfq_quotes table for quote, lead time, and shipping tracking
$pdo->exec("
  CREATE TABLE IF NOT EXISTS rfq_quotes (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rfq_request_id     INT UNSIGNED NOT NULL,
    supplier_name      VARCHAR(255) NOT NULL,
    model_name         VARCHAR(255) NULL,
    sku                VARCHAR(100) NULL,
    msrp               DECIMAL(12,2) NULL,
    quote_amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency           CHAR(3) NOT NULL DEFAULT 'USD',
    lead_time_days     INT UNSIGNED NULL,
    shipping_cost      DECIMAL(12,2) NULL,
    shipping_origin    VARCHAR(255) NULL,
    shipping_method    VARCHAR(100) NULL,
    quote_status       ENUM('received','under_review','negotiating','accepted','rejected') NOT NULL DEFAULT 'received',
    received_on        DATE NULL,
    notes              TEXT NULL,
    created_by         INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rfq_quotes_request_id (rfq_request_id),
    KEY idx_rfq_quotes_status (quote_status),
    KEY idx_rfq_quotes_received_on (received_on)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add RFQ quote file metadata columns if they do not exist yet
foreach ([
  "ALTER TABLE rfq_quotes ADD COLUMN model_name VARCHAR(255) NULL",
  "ALTER TABLE rfq_quotes ADD COLUMN sku VARCHAR(100) NULL",
  "ALTER TABLE rfq_quotes ADD COLUMN msrp DECIMAL(12,2) NULL",
  "ALTER TABLE rfq_quotes ADD COLUMN quote_file_original_name VARCHAR(255) NULL",
  "ALTER TABLE rfq_quotes ADD COLUMN quote_file_stored_name VARCHAR(255) NULL",
  "ALTER TABLE rfq_quotes ADD COLUMN quote_file_mime_type VARCHAR(191) NULL",
  "ALTER TABLE rfq_quotes ADD COLUMN quote_file_size_bytes BIGINT UNSIGNED NULL",
] as $sql) {
  try {
    $pdo->exec($sql);
  } catch (PDOException $e) {
    // 42S21 = SQLSTATE duplicate column name (column already exists)
    if ($e->getCode() !== '42S21') {
      throw $e;
    }
  }
}

// Create rfq_canned_responses table for RFQ form quick-fill buttons
$pdo->exec("
  CREATE TABLE IF NOT EXISTS rfq_canned_responses (
    slot       TINYINT UNSIGNED NOT NULL,
    label      VARCHAR(100) NOT NULL DEFAULT '',
    body       TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (slot)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Seed 6 default slots if they do not exist yet
$pdo->exec("
  INSERT IGNORE INTO rfq_canned_responses (slot, label, body) VALUES
    (1, 'Standard Request',  'Please provide pricing, lead time, and shipping cost for the specified quantity. Include warranty terms and after-sales support availability.'),
    (2, 'Sample Order',      'We would like to order a sample unit first before committing to the full quantity. Please quote for a single unit including shipping to the US.'),
    (3, 'Bulk Discount',     'We are interested in bulk pricing for this order. Please provide tiered pricing for 1, 5, and 10 units along with lead time for each tier.'),
    (4, 'Certification',     'Please confirm all available certifications and compliance documents for this machine model, including any region-specific requirements.'),
    (5, 'Payment Terms',     'Please provide your accepted payment terms, deposit requirements, and any available trade assurance or payment protection options.'),
    (6, 'Packaging Details', 'Please share packaging dimensions and gross/net weight, and confirm whether export-grade wooden crate packing is included.')
");

// Create vendors table if it does not exist yet
$pdo->exec("
  CREATE TABLE IF NOT EXISTS vendors (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_name  VARCHAR(255) NOT NULL,
    contact_name  VARCHAR(255) NOT NULL DEFAULT '',
    email         VARCHAR(255) NOT NULL DEFAULT '',
    phone         VARCHAR(100) NOT NULL DEFAULT '',
    website       VARCHAR(255) NOT NULL DEFAULT '',
    address       VARCHAR(500) NOT NULL DEFAULT '',
    notes         TEXT         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vendors_company_name (company_name(191))
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add profile_notes column to users if it does not exist yet
try {
  $pdo->exec("ALTER TABLE users ADD COLUMN profile_notes TEXT NULL");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') throw $e;
}

// Add company/contact header columns to rfq_requests if they do not exist yet
foreach ([
  "ALTER TABLE rfq_requests ADD COLUMN contact_name  VARCHAR(255) NULL",
  "ALTER TABLE rfq_requests ADD COLUMN company_name  VARCHAR(255) NULL",
  "ALTER TABLE rfq_requests ADD COLUMN contact_email VARCHAR(255) NULL",
  "ALTER TABLE rfq_requests ADD COLUMN contact_phone VARCHAR(100)  NULL",
] as $sql) {
  try {
    $pdo->exec($sql);
  } catch (PDOException $e) {
    // 42S21 = SQLSTATE 'Duplicate column name' — column already exists, safe to skip
    if ($e->getCode() !== '42S21') {
      throw $e;
    }
  }
}
