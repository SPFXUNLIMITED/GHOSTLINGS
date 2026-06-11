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
const APP_ENCRYPTED_MIN_PAYLOAD_BYTES = 29; // 12-byte IV + 16-byte tag + minimum 1-byte ciphertext

function app_ensure_integration_settings_table(PDO $pdo): void {
  static $ready = false;
  if ($ready) {
    return;
  }

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS integration_settings (
      setting_key   VARCHAR(100) NOT NULL,
      setting_val   MEDIUMTEXT NULL,
      is_encrypted  TINYINT(1) NOT NULL DEFAULT 0,
      updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $has_is_encrypted = $pdo->query("SHOW COLUMNS FROM integration_settings LIKE 'is_encrypted'");
  if ($has_is_encrypted === false || $has_is_encrypted->fetch(PDO::FETCH_ASSOC) === false) {
    try {
      $pdo->exec("ALTER TABLE integration_settings ADD COLUMN is_encrypted TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
      $recheck_is_encrypted = $pdo->query("SHOW COLUMNS FROM integration_settings LIKE 'is_encrypted'");
      if ($recheck_is_encrypted === false || $recheck_is_encrypted->fetch(PDO::FETCH_ASSOC) === false) {
        throw $e;
      }
    }
  }

  $has_updated_at = $pdo->query("SHOW COLUMNS FROM integration_settings LIKE 'updated_at'");
  if ($has_updated_at === false || $has_updated_at->fetch(PDO::FETCH_ASSOC) === false) {
    try {
      $pdo->exec("ALTER TABLE integration_settings ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    } catch (Throwable $e) {
      $recheck_updated_at = $pdo->query("SHOW COLUMNS FROM integration_settings LIKE 'updated_at'");
      if ($recheck_updated_at === false || $recheck_updated_at->fetch(PDO::FETCH_ASSOC) === false) {
        throw $e;
      }
    }
  }

  $ready = true;
}

function app_settings_crypto_key(): string {
  static $key = null;
  if (is_string($key)) {
    return $key;
  }

  global $config, $pdo;
  $raw = trim((string)(getenv('APP_SETTINGS_ENCRYPTION_KEY') ?: ($config['app_settings_encryption_key'] ?? '')));
  if ($raw === '') {
    app_ensure_integration_settings_table($pdo);
    $stmt = $pdo->prepare("SELECT setting_val FROM integration_settings WHERE setting_key = 'app_settings_encryption_key' LIMIT 1");
    $stmt->execute();
    $raw = trim((string)($stmt->fetchColumn() ?? ''));
  }

  if ($raw === '') {
    $generated_key = bin2hex(random_bytes(32));
    $pdo->prepare(
      "INSERT INTO integration_settings (setting_key, setting_val, is_encrypted)
       VALUES ('app_settings_encryption_key', ?, 0)
       ON DUPLICATE KEY UPDATE
         setting_val = CASE
           WHEN setting_val IS NULL OR TRIM(setting_val) = '' THEN ?
           ELSE setting_val
         END"
    )->execute([$generated_key, $generated_key]);

    $stmt = $pdo->prepare("SELECT setting_val FROM integration_settings WHERE setting_key = 'app_settings_encryption_key' LIMIT 1");
    $stmt->execute();
    $raw = trim((string)($stmt->fetchColumn() ?? ''));
  }

  if ($raw === '') {
    throw new RuntimeException('Failed to persist or retrieve app settings encryption key from integration settings.');
  }

  if (preg_match('/^[a-f0-9]{64}$/i', $raw)) {
    $decoded = hex2bin($raw);
    if ($decoded !== false && strlen($decoded) === 32) {
      $key = $decoded;
      return $key;
    }
  }

  $key = hash('sha256', $raw, true);
  return $key;
}

function app_encrypt_setting_value(string $plaintext): string {
  if ($plaintext === '') {
    return '';
  }

  $iv = random_bytes(12);
  $tag = '';
  $ciphertext = openssl_encrypt(
    $plaintext,
    'aes-256-gcm',
    app_settings_crypto_key(),
    OPENSSL_RAW_DATA,
    $iv,
    $tag,
    '',
    16
  );

  if ($ciphertext === false) {
    throw new RuntimeException('Unable to encrypt setting value.');
  }

  return base64_encode('v1' . "\0" . $iv . $tag . $ciphertext);
}

function app_decrypt_setting_value(?string $encoded): string {
  $encoded = trim((string)$encoded);
  if ($encoded === '') {
    return '';
  }

  $raw = base64_decode($encoded, true);
  if ($raw === false || strncmp($raw, 'v1' . "\0", 3) !== 0) {
    return '';
  }

  $payload = substr($raw, 3);
  if (strlen($payload) < APP_ENCRYPTED_MIN_PAYLOAD_BYTES) {
    return '';
  }

  $iv = substr($payload, 0, 12);
  $tag = substr($payload, 12, 16);
  $ciphertext = substr($payload, 28);
  if (strlen($iv) !== 12 || strlen($tag) !== 16 || $ciphertext === '') {
    return '';
  }

  $plaintext = openssl_decrypt(
    $ciphertext,
    'aes-256-gcm',
    app_settings_crypto_key(),
    OPENSSL_RAW_DATA,
    $iv,
    $tag
  );

  return $plaintext === false ? '' : (string)$plaintext;
}

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

// Add is_sop_category column to projects if it does not exist yet
try {
  $pdo->exec("ALTER TABLE projects ADD COLUMN is_sop_category TINYINT(1) NOT NULL DEFAULT 0");
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
    lunch_start   DATETIME NULL DEFAULT NULL,
    lunch_end     DATETIME NULL DEFAULT NULL,
    clock_out     DATETIME NULL DEFAULT NULL,
    hours_override DECIMAL(8,2) NULL DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_te_user    (user_id),
    INDEX idx_te_project (project_id),
    INDEX idx_te_clock_in (clock_in)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if (!defined('MYSQL_DUPLICATE_COLUMN_ERROR')) {
  define('MYSQL_DUPLICATE_COLUMN_ERROR', '42S21');
}

foreach ([
  "ALTER TABLE time_entries ADD COLUMN lunch_start DATETIME NULL DEFAULT NULL",
  "ALTER TABLE time_entries ADD COLUMN lunch_end DATETIME NULL DEFAULT NULL",
] as $sql) {
  try {
    $pdo->exec($sql);
  } catch (PDOException $e) {
    if ($e->getCode() !== MYSQL_DUPLICATE_COLUMN_ERROR) {
      throw $e;
    }
  }
}

if (!defined('ATTENDANCE_IDLE_SESSION_KEY')) {
  define('ATTENDANCE_IDLE_SESSION_KEY', 'attendance_idle_logged');
}

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
  "ALTER TABLE users ADD COLUMN delivery_address VARCHAR(500) NULL",
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
    service_type        VARCHAR(20)  NOT NULL DEFAULT 'standard',
    submission_ip       VARCHAR(45)  NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_le_user_id (user_id),
    KEY idx_le_email (email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add service_type column to laser_entries for existing installations
// (new installs already have it from the CREATE TABLE above; 42S21 = duplicate column, safe to ignore)
try {
  $pdo->exec("ALTER TABLE laser_entries ADD COLUMN service_type VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER laser_problem");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}

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

// Create app_requests table for user bug/software change/feature requests
$pdo->exec("
  CREATE TABLE IF NOT EXISTS app_requests (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    requested_by    INT UNSIGNED NOT NULL,
    request_type    ENUM('bug','software_change','feature_request') NOT NULL,
    request_title   VARCHAR(255) NOT NULL,
    request_details TEXT NOT NULL,
    priority        ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    status          ENUM('new','in_review','planned','completed','declined') NOT NULL DEFAULT 'new',
    admin_notes     TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_app_requests_requested_by (requested_by),
    KEY idx_app_requests_status (status),
    KEY idx_app_requests_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add foreign key on app_requests.requested_by for existing databases created before this constraint
$fk_check = $pdo->prepare("
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'app_requests'
    AND CONSTRAINT_NAME = 'fk_app_requests_requested_by'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
");
$fk_check->execute();
if ((int)$fk_check->fetchColumn() === 0) {
  $pdo->exec("
    ALTER TABLE app_requests
    ADD CONSTRAINT fk_app_requests_requested_by
    FOREIGN KEY (requested_by) REFERENCES users(id)
    ON DELETE CASCADE
  ");
}

// Create rfq_requests table for CO2 laser cutter procurement requests
$pdo->exec("
  CREATE TABLE IF NOT EXISTS rfq_requests (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    requested_by       INT UNSIGNED NOT NULL,
    request_category   ENUM('machine','parts') NOT NULL DEFAULT 'machine',
    request_title      VARCHAR(255) NOT NULL,
    machine_size       VARCHAR(100) NULL,
    laser_watts        VARCHAR(50)  NULL,
    tube_type          VARCHAR(100) NULL,
    part_category      VARCHAR(100) NULL,
    part_specs         TEXT         NULL,
    quantity           INT UNSIGNED NOT NULL DEFAULT 1,
    required_features  TEXT         NULL,
    additional_notes   TEXT         NULL,
    po_supplier_info   VARCHAR(500) NULL,
    po_unit_price      DECIMAL(12,2) NULL,
    po_line_total      DECIMAL(12,2) NULL,
    po_expected_delivery_date DATE NULL,
    po_delivery_address VARCHAR(500) NULL,
    po_payment_terms   TEXT NULL,
    po_shipping_method ENUM('Sea Freight','Air Freight','Express','Pickup') NULL,
    po_shipping_cost   DECIMAL(12,2) NULL,
    po_total_amount    DECIMAL(12,2) NULL,
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
    if ($e->getCode() !== '42S21') { // 42S21 = duplicate column name
      throw $e;
    }
  }

  // Align machine-specific legacy columns to nullable so parts requests can store null cleanly
  foreach ([
    "ALTER TABLE rfq_requests MODIFY machine_size VARCHAR(100) NULL",
    "ALTER TABLE rfq_requests MODIFY laser_watts VARCHAR(50) NULL",
    "ALTER TABLE rfq_requests MODIFY tube_type VARCHAR(100) NULL",
    "ALTER TABLE rfq_requests MODIFY required_features TEXT NULL",
  ] as $sql) {
    $pdo->exec($sql);
  }
}

// Create rfq_quotes table for quote, lead time, and shipping tracking
$pdo->exec("
  CREATE TABLE IF NOT EXISTS rfq_quotes (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rfq_request_id     INT UNSIGNED NOT NULL,
    supplier_name      VARCHAR(255) NOT NULL,
    alibaba_chat_link  VARCHAR(1000) NULL,
    model_name         VARCHAR(255) NULL,
    dimensions         VARCHAR(255) NULL,
    weight             VARCHAR(255) NULL,
    sku                VARCHAR(100) NULL,
    msrp               DECIMAL(12,2) NULL,
    map_price          DECIMAL(12,2) NULL,
    moq_20_price       DECIMAL(12,2) NULL,
    moq_20_margin_msrp DECIMAL(6,2) NULL,
    moq_20_margin_map  DECIMAL(6,2) NULL,
    moq_10_price       DECIMAL(12,2) NULL,
    moq_10_margin_msrp DECIMAL(6,2) NULL,
    moq_10_margin_map  DECIMAL(6,2) NULL,
    drop_ship_price    DECIMAL(12,2) NULL,
    drop_ship_margin_msrp DECIMAL(6,2) NULL,
    drop_ship_margin_map  DECIMAL(6,2) NULL,
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
  "ALTER TABLE rfq_quotes ADD COLUMN alibaba_chat_link VARCHAR(1000) NULL AFTER supplier_name",
  "ALTER TABLE rfq_quotes ADD COLUMN dimensions VARCHAR(255) NULL AFTER model_name",
  "ALTER TABLE rfq_quotes ADD COLUMN weight VARCHAR(255) NULL AFTER dimensions",
  "ALTER TABLE rfq_quotes ADD COLUMN sku VARCHAR(100) NULL",
  "ALTER TABLE rfq_quotes ADD COLUMN msrp DECIMAL(12,2) NULL",
  "ALTER TABLE rfq_quotes ADD COLUMN map_price DECIMAL(12,2) NULL AFTER msrp",
  "ALTER TABLE rfq_quotes ADD COLUMN moq_20_price DECIMAL(12,2) NULL AFTER map_price",
  "ALTER TABLE rfq_quotes ADD COLUMN moq_20_margin_msrp DECIMAL(6,2) NULL AFTER moq_20_price",
  "ALTER TABLE rfq_quotes ADD COLUMN moq_20_margin_map DECIMAL(6,2) NULL AFTER moq_20_margin_msrp",
  "ALTER TABLE rfq_quotes ADD COLUMN moq_10_price DECIMAL(12,2) NULL AFTER moq_20_margin_map",
  "ALTER TABLE rfq_quotes ADD COLUMN moq_10_margin_msrp DECIMAL(6,2) NULL AFTER moq_10_price",
  "ALTER TABLE rfq_quotes ADD COLUMN moq_10_margin_map DECIMAL(6,2) NULL AFTER moq_10_margin_msrp",
  "ALTER TABLE rfq_quotes ADD COLUMN drop_ship_price DECIMAL(12,2) NULL AFTER moq_10_margin_map",
  "ALTER TABLE rfq_quotes ADD COLUMN drop_ship_margin_msrp DECIMAL(6,2) NULL AFTER drop_ship_price",
  "ALTER TABLE rfq_quotes ADD COLUMN drop_ship_margin_map DECIMAL(6,2) NULL AFTER drop_ship_margin_msrp",
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

// Create rfq_orders table for purchase orders converted from accepted RFQ quotes
$rfq_order_status_enum = "ENUM('create_rfq','receive_quotes','evaluate_select_quote','negotiate_terms','send_purchase_order','vendor_accepts_po','make_deposit_payment','vendor_produces_machine','make_final_payment','vendor_ships_machine','receive_tracking_documents','arrives_clears_customs','final_inspection_acceptance','cancelled')";
$rfq_order_status_enum_with_legacy = "ENUM('draft','deposit_pending','deposit_paid','in_production','ready_to_ship','shipped','delivered','completed','create_rfq','receive_quotes','evaluate_select_quote','negotiate_terms','send_purchase_order','vendor_accepts_po','make_deposit_payment','vendor_produces_machine','make_final_payment','vendor_ships_machine','receive_tracking_documents','arrives_clears_customs','final_inspection_acceptance','cancelled')";
$pdo->exec("
  CREATE TABLE IF NOT EXISTS rfq_orders (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rfq_request_id           INT UNSIGNED NOT NULL,
    rfq_quote_id             INT UNSIGNED NOT NULL,
    po_number                VARCHAR(50) NULL,
    order_status             {$rfq_order_status_enum} NOT NULL DEFAULT 'create_rfq',
    order_date               DATE NULL,
    expected_ready_date      DATE NULL,
    expected_ship_date       DATE NULL,
    supplier_name            VARCHAR(255) NOT NULL DEFAULT '',
    model_name               VARCHAR(255) NULL,
    sku                      VARCHAR(100) NULL,
    quantity                 INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price               DECIMAL(12,2) NULL,
    order_total              DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency                 CHAR(3) NOT NULL DEFAULT 'USD',
    deposit_percent          DECIMAL(5,2) NULL,
    deposit_amount           DECIMAL(12,2) NULL,
    balance_amount           DECIMAL(12,2) NULL,
    payment_terms            VARCHAR(255) NULL,
    incoterm                 VARCHAR(20) NULL,
    shipping_method          VARCHAR(100) NULL,
    shipping_origin          VARCHAR(255) NULL,
    destination_port         VARCHAR(255) NULL,
    destination_address      VARCHAR(500) NULL,
    production_lead_time_days INT UNSIGNED NULL,
    trade_assurance_order_no VARCHAR(100) NULL,
    proforma_invoice_no      VARCHAR(100) NULL,
    warranty_terms           TEXT NULL,
    included_accessories     TEXT NULL,
    notes                    TEXT NULL,
    deposit_paid_at          DATETIME NULL,
    po_accepted_at           DATETIME NULL,
    production_started_at    DATETIME NULL,
    final_payment_paid_at    DATETIME NULL,
    shipped_at               DATETIME NULL,
    tracking_docs_received_at DATETIME NULL,
    customs_cleared_at       DATETIME NULL,
    accepted_at              DATETIME NULL,
    created_by               INT UNSIGNED NOT NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rfq_orders_request_id (rfq_request_id),
    KEY idx_rfq_orders_quote_id (rfq_quote_id),
    KEY idx_rfq_orders_status (order_status),
    KEY idx_rfq_orders_order_date (order_date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add RFQ order columns if they do not exist yet
foreach ([
  "ALTER TABLE rfq_orders ADD COLUMN po_number VARCHAR(50) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN order_status {$rfq_order_status_enum} NOT NULL DEFAULT 'create_rfq'",
  "ALTER TABLE rfq_orders ADD COLUMN order_date DATE NULL",
  "ALTER TABLE rfq_orders ADD COLUMN expected_ready_date DATE NULL",
  "ALTER TABLE rfq_orders ADD COLUMN expected_ship_date DATE NULL",
  "ALTER TABLE rfq_orders ADD COLUMN supplier_name VARCHAR(255) NOT NULL DEFAULT ''",
  "ALTER TABLE rfq_orders ADD COLUMN model_name VARCHAR(255) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN sku VARCHAR(100) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN quantity INT UNSIGNED NOT NULL DEFAULT 1",
  "ALTER TABLE rfq_orders ADD COLUMN unit_price DECIMAL(12,2) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN order_total DECIMAL(12,2) NOT NULL DEFAULT 0",
  "ALTER TABLE rfq_orders ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD'",
  "ALTER TABLE rfq_orders ADD COLUMN deposit_percent DECIMAL(5,2) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN deposit_amount DECIMAL(12,2) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN balance_amount DECIMAL(12,2) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN payment_terms VARCHAR(255) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN incoterm VARCHAR(20) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN shipping_method VARCHAR(100) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN shipping_origin VARCHAR(255) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN destination_port VARCHAR(255) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN destination_address VARCHAR(500) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN production_lead_time_days INT UNSIGNED NULL",
  "ALTER TABLE rfq_orders ADD COLUMN trade_assurance_order_no VARCHAR(100) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN proforma_invoice_no VARCHAR(100) NULL",
  "ALTER TABLE rfq_orders ADD COLUMN warranty_terms TEXT NULL",
  "ALTER TABLE rfq_orders ADD COLUMN included_accessories TEXT NULL",
  "ALTER TABLE rfq_orders ADD COLUMN notes TEXT NULL",
  "ALTER TABLE rfq_orders ADD COLUMN deposit_paid_at DATETIME NULL",
  "ALTER TABLE rfq_orders ADD COLUMN po_accepted_at DATETIME NULL",
  "ALTER TABLE rfq_orders ADD COLUMN production_started_at DATETIME NULL",
  "ALTER TABLE rfq_orders ADD COLUMN final_payment_paid_at DATETIME NULL",
  "ALTER TABLE rfq_orders ADD COLUMN shipped_at DATETIME NULL",
  "ALTER TABLE rfq_orders ADD COLUMN tracking_docs_received_at DATETIME NULL",
  "ALTER TABLE rfq_orders ADD COLUMN customs_cleared_at DATETIME NULL",
  "ALTER TABLE rfq_orders ADD COLUMN accepted_at DATETIME NULL",
  "ALTER TABLE rfq_orders ADD COLUMN created_by INT UNSIGNED NOT NULL DEFAULT 0",
  "ALTER TABLE rfq_orders ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
  "ALTER TABLE rfq_orders ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
] as $sql) {
  try {
    $pdo->exec($sql);
  } catch (PDOException $e) {
    if ($e->getCode() !== '42S21') {
      throw $e;
    }
  }
}

// Allow rfq_orders to be created without a quote (direct PO submissions).
try {
  $pdo->exec("ALTER TABLE rfq_orders MODIFY rfq_quote_id INT UNSIGNED NULL");
} catch (PDOException $e) {
  // Ignore – column may already be nullable.
}

// Migrate rfq_orders.order_status from legacy values to Alibaba 13-stage workflow values.
$pdo->exec("ALTER TABLE rfq_orders MODIFY COLUMN order_status {$rfq_order_status_enum_with_legacy} NOT NULL DEFAULT 'create_rfq'");
$pdo->exec("
  UPDATE rfq_orders
  SET order_status = CASE order_status
    WHEN 'draft' THEN 'create_rfq'
    WHEN 'deposit_pending' THEN 'send_purchase_order'
    WHEN 'deposit_paid' THEN 'make_deposit_payment'
    WHEN 'in_production' THEN 'vendor_produces_machine'
    WHEN 'ready_to_ship' THEN 'make_final_payment'
    WHEN 'shipped' THEN 'vendor_ships_machine'
    WHEN 'delivered' THEN 'arrives_clears_customs'
    WHEN 'completed' THEN 'final_inspection_acceptance'
    ELSE order_status
  END
  WHERE order_status IN ('draft','deposit_pending','deposit_paid','in_production','ready_to_ship','shipped','delivered','completed')
");
$pdo->exec("ALTER TABLE rfq_orders MODIFY COLUMN order_status {$rfq_order_status_enum} NOT NULL DEFAULT 'create_rfq'");

// Stage history for RFQ order workflow timeline.
$pdo->exec("
  CREATE TABLE IF NOT EXISTS rfq_order_stage_history (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id    INT UNSIGNED NOT NULL,
    from_stage  VARCHAR(64) NULL,
    to_stage    VARCHAR(64) NOT NULL,
    changed_by  INT UNSIGNED NOT NULL,
    change_note TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stage_history_order_created (order_id, created_at),
    KEY idx_stage_history_to_stage (to_stage)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Guardrail: final acceptance requires final payment and customs clearance.
$trigger_name = 'rfq_orders_before_update_stage_guard';
$trigger_check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?");
$trigger_check->execute([$trigger_name]);
if ((int)$trigger_check->fetchColumn() === 0) {
  $pdo->exec("
    CREATE TRIGGER rfq_orders_before_update_stage_guard
    BEFORE UPDATE ON rfq_orders
    FOR EACH ROW
    BEGIN
      IF NEW.order_status = 'final_inspection_acceptance'
         AND (NEW.final_payment_paid_at IS NULL OR NEW.customs_cleared_at IS NULL) THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Cannot mark final inspection and acceptance before final payment and customs clearance.';
      END IF;
    END
  ");
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

if (!defined('RFQ_CANNED_RESPONSE_SLOT_COUNT')) {
  define('RFQ_CANNED_RESPONSE_SLOT_COUNT', 4);
}
if (!defined('RFQ_CANNED_RESPONSE_LEGACY_SLOT_COUNT')) {
  define('RFQ_CANNED_RESPONSE_LEGACY_SLOT_COUNT', 6);
}
$rfq_canned_response_slots = range(1, RFQ_CANNED_RESPONSE_SLOT_COUNT);
$rfq_canned_response_legacy_slots = range(1, RFQ_CANNED_RESPONSE_LEGACY_SLOT_COUNT);
$rfq_canned_response_legacy_slot_placeholders = implode(',', array_fill(0, count($rfq_canned_response_legacy_slots), '?'));

$rfq_canned_response_defaults = [
  1 => [
    'label' => 'Machines - No Prototypes',
    'body' => 'Important notice: We will not accept any prototypes, first builds, or newly developed machines. We only want machines that you have already produced and successfully delivered to multiple customers. Do not quote any custom machines.',
  ],
  2 => [
    'label' => 'Parts - Manufacturer Only',
    'body' => 'Important: We only purchase parts directly from original manufacturers. We will not accept parts that are sourced or resold by trading companies or third-party suppliers.',
  ],
  3 => [
    'label' => 'No Custom Voltage / Specs',
    'body' => 'We will only accept items that are already manufactured in the exact specification we request. We will not accept custom modifications, voltage changes, or "special orders".',
  ],
  4 => [
    'label' => 'Stock Items Only',
    'body' => 'We only purchase items that you already have in stock and have successfully sold to other customers. Please do not quote any made-to-order or customized items.',
  ],
];
$legacy_rfq_canned_response_defaults = [
  1 => [
    'label' => 'Standard Request',
    'body' => 'Please provide pricing, lead time, and shipping cost for the specified quantity. Include warranty terms and after-sales support availability.',
  ],
  2 => [
    'label' => 'Sample Order',
    'body' => 'We would like to order a sample unit first before committing to the full quantity. Please quote for a single unit including shipping to the US.',
  ],
  3 => [
    'label' => 'Bulk Discount',
    'body' => 'We are interested in bulk pricing for this order. Please provide tiered pricing for 1, 5, and 10 units along with lead time for each tier.',
  ],
  4 => [
    'label' => 'Certification',
    'body' => 'Please confirm all available certifications and compliance documents for this machine model, including any region-specific requirements.',
  ],
  5 => [
    'label' => 'Payment Terms',
    'body' => 'Please provide your accepted payment terms, deposit requirements, and any available trade assurance or payment protection options.',
  ],
  6 => [
    'label' => 'Packaging Details',
    'body' => 'Please share packaging dimensions and gross/net weight, and confirm whether export-grade wooden crate packing is included.',
  ],
];
$rfq_canned_response_new_slots = array_keys($rfq_canned_response_defaults);
$rfq_canned_response_legacy_only_slot_numbers = array_diff(
  $rfq_canned_response_legacy_slots,
  $rfq_canned_response_new_slots
);
$rfq_canned_response_legacy_only_slots = array_map(
  'intval',
  array_values($rfq_canned_response_legacy_only_slot_numbers)
);
$rfq_canned_response_rows_stmt = $pdo->prepare(
  "SELECT slot, label, body FROM rfq_canned_responses WHERE slot IN ($rfq_canned_response_legacy_slot_placeholders) ORDER BY slot"
);
$rfq_canned_response_rows_stmt->execute($rfq_canned_response_legacy_slots);
$rfq_canned_response_rows = $rfq_canned_response_rows_stmt->fetchAll();
$rfq_canned_response_by_slot = [];
foreach ($rfq_canned_response_rows as $rfq_canned_response_row) {
  $rfq_canned_response_by_slot[(int)$rfq_canned_response_row['slot']] = [
    'label' => (string)$rfq_canned_response_row['label'],
    'body' => (string)$rfq_canned_response_row['body'],
  ];
}
$should_migrate_legacy_rfq_canned_responses = true;
foreach ($legacy_rfq_canned_response_defaults as $slot => $legacy_response) {
  if (!isset($rfq_canned_response_by_slot[$slot])) {
    if ($slot <= RFQ_CANNED_RESPONSE_SLOT_COUNT) {
      $should_migrate_legacy_rfq_canned_responses = false;
      break;
    }
    continue;
  }
  if ($rfq_canned_response_by_slot[$slot]['label'] !== $legacy_response['label']
      || $rfq_canned_response_by_slot[$slot]['body'] !== $legacy_response['body']) {
    $should_migrate_legacy_rfq_canned_responses = false;
    break;
  }
}
if ($should_migrate_legacy_rfq_canned_responses) {
  $rfq_canned_response_stmt = $pdo->prepare(
    "INSERT INTO rfq_canned_responses (slot, label, body) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE label = ?, body = ?"
  );
  foreach ($rfq_canned_response_defaults as $slot => $response) {
    $rfq_canned_response_stmt->execute([$slot, $response['label'], $response['body'], $response['label'], $response['body']]);
  }
  if ($rfq_canned_response_legacy_only_slots) {
    $rfq_canned_response_cleanup_stmt = $pdo->prepare(
      "DELETE FROM rfq_canned_responses WHERE slot IN (" . implode(',', array_fill(0, count($rfq_canned_response_legacy_only_slots), '?')) . ")"
    );
    $rfq_canned_response_cleanup_stmt->execute($rfq_canned_response_legacy_only_slots);
  }
}
$rfq_canned_response_seed_stmt = $pdo->prepare(
  "INSERT IGNORE INTO rfq_canned_responses (slot, label, body) VALUES (?, ?, ?)"
);
foreach ($rfq_canned_response_defaults as $slot => $response) {
  $rfq_canned_response_seed_stmt->execute([$slot, $response['label'], $response['body']]);
}

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

// Create customers table for HubSpot sync if it does not exist yet
$pdo->exec("
  CREATE TABLE IF NOT EXISTS customers (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    hubspot_contact_id  VARCHAR(64)  NOT NULL,
    first_name          VARCHAR(255) NOT NULL DEFAULT '',
    last_name           VARCHAR(255) NOT NULL DEFAULT '',
    company             VARCHAR(255) NOT NULL DEFAULT '',
    phone               VARCHAR(100) NOT NULL DEFAULT '',
    email               VARCHAR(255) NOT NULL DEFAULT '',
    address             VARCHAR(255) NOT NULL DEFAULT '',
    city                VARCHAR(100) NOT NULL DEFAULT '',
    state               VARCHAR(100) NOT NULL DEFAULT '',
    zip                 VARCHAR(20)  NOT NULL DEFAULT '',
    country             VARCHAR(100) NOT NULL DEFAULT '',
    last_updated        DATETIME     NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_customers_hubspot_contact_id (hubspot_contact_id),
    KEY idx_customers_company (company(191)),
    KEY idx_customers_email (email(191)),
    KEY idx_customers_last_updated (last_updated)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$hasCustomersFirstName = (int)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'customers'
    AND COLUMN_NAME = 'first_name'
")->fetchColumn();
if ($hasCustomersFirstName === 0) {
  $pdo->exec("ALTER TABLE customers ADD COLUMN first_name VARCHAR(255) NOT NULL DEFAULT '' AFTER hubspot_contact_id");
}

$hasCustomersLastName = (int)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'customers'
    AND COLUMN_NAME = 'last_name'
")->fetchColumn();
if ($hasCustomersLastName === 0) {
  $pdo->exec("ALTER TABLE customers ADD COLUMN last_name VARCHAR(255) NOT NULL DEFAULT '' AFTER first_name");
}

$hasLegacyCustomerName = (int)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'customers'
    AND COLUMN_NAME = 'customer_name'
")->fetchColumn();
if ($hasLegacyCustomerName > 0) {
  $pdo->exec("
    UPDATE customers
    SET
      first_name = CASE
        WHEN TRIM(first_name) <> '' THEN first_name
        WHEN TRIM(customer_name) = '' THEN ''
        WHEN INSTR(TRIM(customer_name), ' ') > 0 THEN SUBSTRING_INDEX(TRIM(customer_name), ' ', 1)
        ELSE TRIM(customer_name)
      END,
      last_name = CASE
        WHEN TRIM(last_name) <> '' THEN last_name
        WHEN TRIM(customer_name) = '' OR INSTR(TRIM(customer_name), ' ') = 0 THEN ''
        ELSE TRIM(SUBSTRING(TRIM(customer_name), LENGTH(SUBSTRING_INDEX(TRIM(customer_name), ' ', 1)) + 1))
      END
  ");
  $pdo->exec("ALTER TABLE customers DROP COLUMN customer_name");
}

foreach ([
  'address' => "ALTER TABLE customers ADD COLUMN address VARCHAR(255) NOT NULL DEFAULT '' AFTER email",
  'city'    => "ALTER TABLE customers ADD COLUMN city    VARCHAR(100) NOT NULL DEFAULT '' AFTER address",
  'state'   => "ALTER TABLE customers ADD COLUMN state   VARCHAR(100) NOT NULL DEFAULT '' AFTER city",
  'zip'     => "ALTER TABLE customers ADD COLUMN zip     VARCHAR(20)  NOT NULL DEFAULT '' AFTER state",
  'country' => "ALTER TABLE customers ADD COLUMN country VARCHAR(100) NOT NULL DEFAULT '' AFTER zip",
] as $col => $sql) {
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'customers'
      AND COLUMN_NAME = ?
  ");
  $stmt->execute([$col]);
  if ((int)$stmt->fetchColumn() === 0) {
    $pdo->exec($sql);
  }
}

try {
  $pdo->exec("ALTER TABLE vendors ADD COLUMN alibaba_store VARCHAR(255) NOT NULL DEFAULT ''");
} catch (PDOException $e) {
  // Column already exists
}

$hasVendorsRating = (int)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'vendors'
    AND COLUMN_NAME = 'rating'
")->fetchColumn();
if ($hasVendorsRating === 0) {
  $pdo->exec("ALTER TABLE vendors ADD COLUMN rating TINYINT UNSIGNED NULL DEFAULT NULL");
}

$hasVendorsReview = (int)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'vendors'
    AND COLUMN_NAME = 'review'
")->fetchColumn();
if ($hasVendorsReview === 0) {
  $pdo->exec("ALTER TABLE vendors ADD COLUMN review TEXT NULL DEFAULT NULL");
}

// Create freight_forwarders table if it does not exist yet
$pdo->exec("
  CREATE TABLE IF NOT EXISTS freight_forwarders (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_name        VARCHAR(255) NOT NULL,
    headquarters        VARCHAR(255) NOT NULL DEFAULT '',
    contact_person      VARCHAR(255) NOT NULL DEFAULT '',
    phone               VARCHAR(100) NOT NULL DEFAULT '',
    email               VARCHAR(255) NOT NULL DEFAULT '',
    website             VARCHAR(255) NOT NULL DEFAULT '',
    primary_routes      VARCHAR(500) NOT NULL DEFAULT '',
    shipping_modes      VARCHAR(255) NOT NULL DEFAULT '',
    certifications      VARCHAR(255) NOT NULL DEFAULT '',
    notes               TEXT         NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ff_company_name (company_name(191))
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

// Add buyer/end-user columns to rfq_requests for internal sourcing tracking
foreach ([
  "ALTER TABLE rfq_requests ADD COLUMN buyer_name    VARCHAR(255) NULL",
  "ALTER TABLE rfq_requests ADD COLUMN buyer_company VARCHAR(255) NULL",
  "ALTER TABLE rfq_requests ADD COLUMN buyer_email   VARCHAR(255) NULL",
  "ALTER TABLE rfq_requests ADD COLUMN buyer_phone   VARCHAR(100)  NULL",
] as $sql) {
  try {
    $pdo->exec($sql);
  } catch (PDOException $e) {
    if ($e->getCode() !== '42S21') {
      throw $e;
    }
  }
}

// Add acquisition_purpose column to rfq_requests if it does not exist yet
try {
  $pdo->exec("ALTER TABLE rfq_requests ADD COLUMN acquisition_purpose ENUM('customer','internal') NOT NULL DEFAULT 'customer'");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}

// Add urgency column to rfq_requests if it does not exist yet
try {
  $pdo->exec("ALTER TABLE rfq_requests ADD COLUMN urgency ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal'");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') {
    throw $e;
  }
}

// Add PO-specific fields to rfq_requests if they do not exist yet
foreach ([
  "ALTER TABLE rfq_requests ADD COLUMN po_supplier_info VARCHAR(500) NULL",
  "ALTER TABLE rfq_requests ADD COLUMN po_unit_price DECIMAL(12,2) NULL",
  "ALTER TABLE rfq_requests ADD COLUMN po_line_total DECIMAL(12,2) NULL",
  "ALTER TABLE rfq_requests ADD COLUMN po_expected_delivery_date DATE NULL",
  "ALTER TABLE rfq_requests ADD COLUMN po_delivery_address VARCHAR(500) NULL",
  "ALTER TABLE rfq_requests ADD COLUMN po_payment_terms TEXT NULL",
  "ALTER TABLE rfq_requests ADD COLUMN po_shipping_method ENUM('Sea Freight','Air Freight','Express','Pickup') NULL",
  "ALTER TABLE rfq_requests ADD COLUMN po_shipping_cost DECIMAL(12,2) NULL",
  "ALTER TABLE rfq_requests ADD COLUMN po_total_amount DECIMAL(12,2) NULL",
] as $sql) {
  try {
    $pdo->exec($sql);
  } catch (PDOException $e) {
    if ($e->getCode() !== '42S21') {
      throw $e;
    }
  }
}

// Create machine_inquiries table for CO2 laser machine purchase inquiries
$pdo->exec("
  CREATE TABLE IF NOT EXISTS machine_inquiries (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name            VARCHAR(100) NOT NULL,
    last_name             VARCHAR(100) NOT NULL,
    cell_phone            VARCHAR(30)  NOT NULL,
    email                 VARCHAR(255) NOT NULL,
    city                  VARCHAR(100) NOT NULL,
    state                 VARCHAR(50)  NOT NULL,
    zip_code              VARCHAR(20)  NOT NULL,
    machine_condition     ENUM('new','used','either') NOT NULL DEFAULT 'either',
    laser_type            VARCHAR(100) NULL,
    desired_watts         VARCHAR(50)  NULL,
    work_area             VARCHAR(100) NULL,
    budget                VARCHAR(100) NULL,
    intended_use          TEXT         NULL,
    features_wanted       TEXT         NULL,
    timeline              VARCHAR(100) NULL,
    current_machine       TINYINT(1)   NOT NULL DEFAULT 0,
    current_machine_brand VARCHAR(100) NULL,
    additional_notes      TEXT         NULL,
    heard_about_us        VARCHAR(100) NULL,
    submission_ip         VARCHAR(45)  NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mi_email (email),
    KEY idx_mi_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create machine_inquiry_settings table for admin-editable settings (e.g. promo text)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS machine_inquiry_settings (
    setting_key  VARCHAR(100)  NOT NULL,
    setting_val  MEDIUMTEXT    NULL,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create integration_settings table for admin-managed third-party integration credentials
app_ensure_integration_settings_table($pdo);

// Seed default promo text if not already set
$pdo->exec("
  INSERT IGNORE INTO machine_inquiry_settings (setting_key, setting_val) VALUES (
    'promo_text',
    '<div style=\"text-align:center;\">🔥 <strong>Limited-Time Offer!</strong> Mention this form and receive a <strong>FREE extended warranty upgrade</strong> on any new CO2 laser machine purchase. Ask our team for details!</div>'
  )
");

// Create customer_phone_inquiries table for quick internal phone inquiry logging
$pdo->exec("
  CREATE TABLE IF NOT EXISTS customer_phone_inquiries (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_name    VARCHAR(255) NOT NULL,
    company_name     VARCHAR(255) NULL,
    phone_number     VARCHAR(50) NULL,
    email            VARCHAR(255) NULL,
    inquiry_date     DATE NOT NULL,
    status           ENUM('pending','urgent','critical','ordered') NOT NULL DEFAULT 'pending',
    notes            TEXT NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cpi_inquiry_date (inquiry_date),
    KEY idx_cpi_status (status),
    KEY idx_cpi_created_at (created_at),
    CONSTRAINT fk_cpi_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Ensure customer_phone_inquiries.status exists for older deployments
$hasCpiStatus = (int)$pdo->query("
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'customer_phone_inquiries'
    AND COLUMN_NAME = 'status'
")->fetchColumn();
if ($hasCpiStatus === 0) {
  $pdo->exec("
    ALTER TABLE customer_phone_inquiries
    ADD COLUMN status ENUM('pending','urgent','critical','ordered') NOT NULL DEFAULT 'pending' AFTER inquiry_date
  ");
  $pdo->exec("ALTER TABLE customer_phone_inquiries ADD KEY idx_cpi_status (status)");
}

// Migrate customer_phone_inquiries status ENUM from old values (new/in_progress/purchased/completed/archived) to new ones
$cpiOldEnum = $pdo->query("
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'customer_phone_inquiries'
    AND COLUMN_NAME  = 'status'
    AND COLUMN_TYPE LIKE \"%'new'%\"
")->fetchColumn();
if ($cpiOldEnum !== false) {
  // Widen to VARCHAR so all old values are valid during the transition
  $pdo->exec("ALTER TABLE customer_phone_inquiries MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending'");
  // Map old statuses to new ones
  $pdo->exec("UPDATE customer_phone_inquiries SET status = 'pending'  WHERE status IN ('new', 'in_progress')");
  $pdo->exec("UPDATE customer_phone_inquiries SET status = 'ordered'  WHERE status IN ('purchased', 'completed', 'archived')");
  // Apply the new ENUM definition
  $pdo->exec("ALTER TABLE customer_phone_inquiries MODIFY COLUMN status ENUM('pending','urgent','critical','ordered') NOT NULL DEFAULT 'pending'");
}

// Create quotes table for customer quotes and invoice conversion
$pdo->exec("
  CREATE TABLE IF NOT EXISTS quotes (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id            INT UNSIGNED NULL,
    customer_name          VARCHAR(255) NOT NULL,
    company_name           VARCHAR(255) NULL,
    phone_number           VARCHAR(100) NULL,
    email                  VARCHAR(255) NULL,
    quote_date             DATE NOT NULL,
    status                 ENUM('draft','sent','converted') NOT NULL DEFAULT 'draft',
    notes                  TEXT NULL,
    subtotal_amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
    converted_invoice_no   VARCHAR(100) NULL,
    converted_at           DATETIME NULL,
    created_by             INT UNSIGNED NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_quotes_status (status),
    KEY idx_quotes_quote_date (quote_date),
    KEY idx_quotes_created_at (created_at),
    CONSTRAINT fk_quotes_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
    CONSTRAINT fk_quotes_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$_quotes_online_payment_col = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'enable_online_payment'");
if ($_quotes_online_payment_col === false || $_quotes_online_payment_col->fetch(PDO::FETCH_ASSOC) === false) {
  try {
    $pdo->exec("ALTER TABLE quotes ADD COLUMN enable_online_payment TINYINT(1) NOT NULL DEFAULT 0 AFTER subtotal_amount");
  } catch (Throwable $e) {
    $recheck = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'enable_online_payment'");
    if ($recheck === false || $recheck->fetch(PDO::FETCH_ASSOC) === false) { throw $e; }
  }
}
unset($_quotes_online_payment_col);

$_quotes_checkout_url_col = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'stripe_checkout_url'");
if ($_quotes_checkout_url_col === false || $_quotes_checkout_url_col->fetch(PDO::FETCH_ASSOC) === false) {
  try {
    $pdo->exec("ALTER TABLE quotes ADD COLUMN stripe_checkout_url TEXT NULL AFTER enable_online_payment");
  } catch (Throwable $e) {
    $recheck = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'stripe_checkout_url'");
    if ($recheck === false || $recheck->fetch(PDO::FETCH_ASSOC) === false) { throw $e; }
  }
}
unset($_quotes_checkout_url_col);

$_quotes_checkout_session_col = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'stripe_checkout_session_id'");
if ($_quotes_checkout_session_col === false || $_quotes_checkout_session_col->fetch(PDO::FETCH_ASSOC) === false) {
  try {
    $pdo->exec("ALTER TABLE quotes ADD COLUMN stripe_checkout_session_id VARCHAR(255) NULL AFTER stripe_checkout_url");
  } catch (Throwable $e) {
    $recheck = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'stripe_checkout_session_id'");
    if ($recheck === false || $recheck->fetch(PDO::FETCH_ASSOC) === false) { throw $e; }
  }
}
unset($_quotes_checkout_session_col);

$_quotes_checkout_created_col = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'stripe_checkout_created_at'");
if ($_quotes_checkout_created_col === false || $_quotes_checkout_created_col->fetch(PDO::FETCH_ASSOC) === false) {
  try {
    $pdo->exec("ALTER TABLE quotes ADD COLUMN stripe_checkout_created_at DATETIME NULL AFTER stripe_checkout_session_id");
  } catch (Throwable $e) {
    $recheck = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'stripe_checkout_created_at'");
    if ($recheck === false || $recheck->fetch(PDO::FETCH_ASSOC) === false) { throw $e; }
  }
}
unset($_quotes_checkout_created_col);

$_quotes_checkout_amount_col = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'stripe_checkout_amount'");
if ($_quotes_checkout_amount_col === false || $_quotes_checkout_amount_col->fetch(PDO::FETCH_ASSOC) === false) {
  try {
    $pdo->exec("ALTER TABLE quotes ADD COLUMN stripe_checkout_amount DECIMAL(12,2) NULL AFTER stripe_checkout_created_at");
  } catch (Throwable $e) {
    $recheck = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'stripe_checkout_amount'");
    if ($recheck === false || $recheck->fetch(PDO::FETCH_ASSOC) === false) { throw $e; }
  }
}
unset($_quotes_checkout_amount_col);

$_quotes_emailed_col = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'invoice_emailed'");
if ($_quotes_emailed_col === false || $_quotes_emailed_col->fetch(PDO::FETCH_ASSOC) === false) {
  try {
    $pdo->exec("ALTER TABLE quotes ADD COLUMN invoice_emailed TINYINT(1) NOT NULL DEFAULT 0");
  } catch (Throwable $e) {
    $recheck = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'invoice_emailed'");
    if ($recheck === false || $recheck->fetch(PDO::FETCH_ASSOC) === false) { throw $e; }
  }
}
unset($_quotes_emailed_col);

foreach ([
  'billing_street' => "ALTER TABLE quotes ADD COLUMN billing_street VARCHAR(255) NULL AFTER email",
  'billing_city'   => "ALTER TABLE quotes ADD COLUMN billing_city   VARCHAR(100) NULL AFTER billing_street",
  'billing_state'  => "ALTER TABLE quotes ADD COLUMN billing_state  VARCHAR(100) NULL AFTER billing_city",
  'billing_zip'    => "ALTER TABLE quotes ADD COLUMN billing_zip    VARCHAR(20)  NULL AFTER billing_state",
] as $_col => $_sql) {
  $_stmt = $pdo->prepare("SHOW COLUMNS FROM quotes LIKE ?");
  $_stmt->execute([$_col]);
  if ($_stmt->fetch(PDO::FETCH_ASSOC) === false) {
    try {
      $pdo->exec($_sql);
    } catch (Throwable $e) {
      $_rechk = $pdo->prepare("SHOW COLUMNS FROM quotes LIKE ?");
      $_rechk->execute([$_col]);
      if ($_rechk->fetch(PDO::FETCH_ASSOC) === false) { throw $e; }
    }
  }
}
unset($_col, $_sql, $_stmt, $_rechk);

// Create quote_items table for line items on quotes
$pdo->exec("
  CREATE TABLE IF NOT EXISTS quote_items (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id          INT UNSIGNED NOT NULL,
    line_position     INT UNSIGNED NOT NULL DEFAULT 1,
    description       VARCHAR(500) NOT NULL,
    quantity          DECIMAL(12,2) NOT NULL DEFAULT 1.00,
    cost              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    markup_percent    DECIMAL(8,2) NOT NULL DEFAULT 20.00,
    unit_price        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    line_total        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_quote_items_quote_id (quote_id),
    KEY idx_quote_items_line_position (line_position),
    CONSTRAINT fk_quote_items_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$_qi_cost_col = $pdo->query("SHOW COLUMNS FROM quote_items LIKE 'cost'");
if ($_qi_cost_col === false || $_qi_cost_col->fetch(PDO::FETCH_ASSOC) === false) {
  try {
    $pdo->exec("ALTER TABLE quote_items ADD COLUMN cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity");
  } catch (Throwable $e) {
    $recheck = $pdo->query("SHOW COLUMNS FROM quote_items LIKE 'cost'");
    if ($recheck === false || $recheck->fetch(PDO::FETCH_ASSOC) === false) { throw $e; }
  }
}
unset($_qi_cost_col);

$_qi_markup_col = $pdo->query("SHOW COLUMNS FROM quote_items LIKE 'markup_percent'");
if ($_qi_markup_col === false || $_qi_markup_col->fetch(PDO::FETCH_ASSOC) === false) {
  try {
    $pdo->exec("ALTER TABLE quote_items ADD COLUMN markup_percent DECIMAL(8,2) NOT NULL DEFAULT 20.00 AFTER cost");
  } catch (Throwable $e) {
    $recheck = $pdo->query("SHOW COLUMNS FROM quote_items LIKE 'markup_percent'");
    if ($recheck === false || $recheck->fetch(PDO::FETCH_ASSOC) === false) { throw $e; }
  }
}
unset($_qi_markup_col);

// Create shipping_rfq_requests table for freight/shipping quote requests
$pdo->exec("
  CREATE TABLE IF NOT EXISTS shipping_rfq_requests (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    requested_by        INT UNSIGNED NOT NULL,
    request_title       VARCHAR(255) NOT NULL,
    machine_model       VARCHAR(255) NOT NULL DEFAULT '',
    machine_weight_kg   DECIMAL(10,2) NULL,
    port_of_loading     VARCHAR(255) NOT NULL DEFAULT '',
    destination_type    ENUM('port_la','door_delivery') NOT NULL DEFAULT 'port_la',
    destination_address VARCHAR(500) NOT NULL DEFAULT '',
    shipment_type       ENUM('FCL','LCL') NOT NULL DEFAULT 'LCL',
    additional_notes    TEXT NULL,
    request_status      ENUM('draft','sourcing','quotes_received','shortlisted','booked','closed') NOT NULL DEFAULT 'sourcing',
    contact_name        VARCHAR(255) NULL,
    company_name        VARCHAR(255) NULL,
    contact_email       VARCHAR(255) NULL,
    contact_phone       VARCHAR(100) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_srfq_req_requested_by (requested_by),
    KEY idx_srfq_req_status (request_status),
    KEY idx_srfq_req_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create shipping_rfq_crates table for cargo crate line items on a shipping RFQ
$pdo->exec("
  CREATE TABLE IF NOT EXISTS shipping_rfq_crates (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shipping_rfq_id     INT UNSIGNED NOT NULL,
    crate_label         VARCHAR(100) NOT NULL DEFAULT '',
    length_cm           DECIMAL(10,2) NULL,
    width_cm            DECIMAL(10,2) NULL,
    height_cm           DECIMAL(10,2) NULL,
    gross_weight_kg     DECIMAL(10,2) NULL,
    quantity            INT UNSIGNED NOT NULL DEFAULT 1,
    sort_order          INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_srfq_crates_rfq_id (shipping_rfq_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create shipping_rfq_quotes table for freight quote responses
$pdo->exec("
  CREATE TABLE IF NOT EXISTS shipping_rfq_quotes (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shipping_rfq_id     INT UNSIGNED NOT NULL,
    forwarder_name      VARCHAR(255) NOT NULL,
    quote_amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency            CHAR(3) NOT NULL DEFAULT 'USD',
    transit_time_days   INT UNSIGNED NULL,
    shipment_type       ENUM('FCL','LCL') NOT NULL DEFAULT 'LCL',
    container_size      VARCHAR(50) NULL,
    port_of_loading     VARCHAR(255) NULL,
    destination         VARCHAR(255) NULL,
    quote_status        ENUM('received','under_review','negotiating','accepted','rejected') NOT NULL DEFAULT 'received',
    received_on         DATE NULL,
    notes               TEXT NULL,
    created_by          INT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_srfq_quotes_rfq_id (shipping_rfq_id),
    KEY idx_srfq_quotes_status (quote_status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add rfq_id column to time_entries for associating clock entries with sourcing RFQs
try {
  $pdo->exec("ALTER TABLE time_entries ADD COLUMN rfq_id INT UNSIGNED NULL");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S21') throw $e;
}

// Create order_documents table for shipping/trade documents attached to purchase orders
$pdo->exec("
  CREATE TABLE IF NOT EXISTS order_documents (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id      INT UNSIGNED NOT NULL,
    doc_type      VARCHAR(50)  NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name   VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(191) NULL,
    size_bytes    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_documents_order_id (order_id),
    KEY idx_order_documents_doc_type (doc_type)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create messages table for internal two-user messaging
$pdo->exec("
  CREATE TABLE IF NOT EXISTS messages (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sender_id    INT UNSIGNED NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    body         MEDIUMTEXT   NOT NULL,
    is_read      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_messages_recipient_read (recipient_id, is_read),
    KEY idx_messages_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create admin activity log table for key backend audit events
$pdo->exec("
  CREATE TABLE IF NOT EXISTS admin_activity_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,
    user_label  VARCHAR(255) NULL,
    action_name VARCHAR(150) NOT NULL,
    details     TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_activity_created_at (created_at),
    KEY idx_admin_activity_user_id (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add image columns to rfq_requests if they do not exist yet
foreach ([
  "ALTER TABLE rfq_requests ADD COLUMN image_path  VARCHAR(255) NULL",
  "ALTER TABLE rfq_requests ADD COLUMN image_thumb VARCHAR(255) NULL",
] as $sql) {
  try {
    $pdo->exec($sql);
  } catch (PDOException $e) {
    if ($e->getCode() !== '42S21') throw $e;
  }
}

// Create page_views table for employee page view tracking
$pdo->exec("
  CREATE TABLE IF NOT EXISTS page_views (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    page       VARCHAR(100)  NOT NULL,
    url        VARCHAR(512)  NOT NULL,
    viewed_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_page_views_user_id (user_id),
    KEY idx_page_views_viewed_at (viewed_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// One-time cleanup: reset page_views rows with impossible timestamps
$_pv_cleanup_done = $pdo->query(
  "SELECT setting_val FROM integration_settings WHERE setting_key = 'page_views_ts_cleanup_v1'"
)->fetchColumn();
if (!$_pv_cleanup_done) {
  $pdo->exec("UPDATE page_views SET viewed_at = NOW() WHERE viewed_at > '2026-06-11' OR viewed_at < '2025-01-01'");
  $pdo->exec("INSERT INTO integration_settings (setting_key, setting_val) VALUES ('page_views_ts_cleanup_v1', '1') ON DUPLICATE KEY UPDATE setting_val = '1'");
}
unset($_pv_cleanup_done);

if (!function_exists('log_admin_activity')) {
  function log_admin_activity(PDO $pdo, ?int $user_id, string $action_name, string $details = '', ?string $fallback_user = null): void {
    $safe_user_id = $user_id !== null && $user_id > 0 ? $user_id : null;
    $safe_action = trim($action_name);
    if ($safe_action === '') {
      return;
    }

    $safe_details = trim($details);
    $safe_user_label = trim((string)$fallback_user);
    $now_pt = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
      INSERT INTO admin_activity_log (user_id, user_label, action_name, details, created_at)
      VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
      $safe_user_id,
      $safe_user_label !== '' ? mb_substr($safe_user_label, 0, 255) : null,
      mb_substr($safe_action, 0, 150),
      $safe_details !== '' ? mb_substr($safe_details, 0, 5000) : null,
      $now_pt,
    ]);
  }
}
