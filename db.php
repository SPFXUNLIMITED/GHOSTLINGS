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

if (!defined('ATTENDANCE_IDLE_SESSION_KEY')) {
  define('ATTENDANCE_IDLE_SESSION_KEY', 'attendance_idle_logged');
}

// Sync existing admin users: set role='admin' where is_admin=1 and role='user'
try {
  $pdo->exec("UPDATE users SET role='admin' WHERE is_admin=1 AND role='user'");
} catch (PDOException $e) { /* ignore */ }

// Ensure the Eve system account exists for internal messaging
$eve_user_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$eve_user_stmt->execute(['Eve']);
if (!$eve_user_stmt->fetch()) {
  $eve_password_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
  $pdo->prepare(
    "INSERT INTO users (username, password_hash, is_admin, role, email_verified) VALUES (?, ?, 0, 'system', 1)"
  )->execute(['Eve', $eve_password_hash]);
} else {
  $pdo->prepare("UPDATE users SET role = 'system', is_admin = 0 WHERE username = ?")->execute(['Eve']);
}

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
    po_shipping_method VARCHAR(20) NULL,
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
    quote_status       ENUM('received','shortlisted','under_review','negotiating','accepted','rejected') NOT NULL DEFAULT 'received',
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

// Create rfq_orders table for purchase orders converted from accepted RFQ quotes
$rfq_order_status_enum = "ENUM('create_rfq','receive_quotes','evaluate_select_quote','negotiate_terms','send_purchase_order','vendor_accepts_po','make_deposit_payment','vendor_produces_machine','make_final_payment','vendor_ships_machine','receive_tracking_documents','arrives_clears_customs','final_inspection_acceptance','cancelled')";
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
    port          VARCHAR(255) NOT NULL DEFAULT '',
    website       VARCHAR(255) NOT NULL DEFAULT '',
    address       VARCHAR(500) NOT NULL DEFAULT '',
    notes         TEXT         NULL,
    alibaba_profile_photo_path  VARCHAR(255) NULL DEFAULT NULL,
    alibaba_profile_photo_thumb VARCHAR(255) NULL DEFAULT NULL,
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

// Create freight_forwarders table if it does not exist yet
$pdo->exec("
  CREATE TABLE IF NOT EXISTS freight_forwarders (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_name        VARCHAR(255) NOT NULL,
    logo_path           VARCHAR(255) NULL DEFAULT NULL,
    logo_thumb          VARCHAR(255) NULL DEFAULT NULL,
    headquarters        VARCHAR(255) NOT NULL DEFAULT '',
    contact_person      VARCHAR(255) NOT NULL DEFAULT '',
    phone               VARCHAR(100) NOT NULL DEFAULT '',
    email               VARCHAR(255) NOT NULL DEFAULT '',
    website             VARCHAR(255) NOT NULL DEFAULT '',
    primary_routes      VARCHAR(500) NOT NULL DEFAULT '',
    shipping_modes      VARCHAR(255) NOT NULL DEFAULT '',
    does_consolidation  TINYINT(1)   NOT NULL DEFAULT 0,
    certifications      VARCHAR(255) NOT NULL DEFAULT '',
    notes               TEXT         NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ff_company_name (company_name(191))
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

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
    payment_status         ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    paid_at                DATETIME NULL,
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

// Create approval alerts table for quote/invoice approval requests
$pdo->exec("
  CREATE TABLE IF NOT EXISTS approval_alerts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_id INT UNSIGNED NOT NULL,
    entity_type  ENUM('quote','invoice') NOT NULL,
    entity_id    INT UNSIGNED NOT NULL,
    message      VARCHAR(500) NOT NULL,
    link_url     VARCHAR(500) NOT NULL,
    is_read      TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_approval_alerts_recipient_unread (recipient_id, is_read, created_at),
    KEY idx_approval_alerts_entity (entity_type, entity_id),
    CONSTRAINT fk_approval_alerts_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
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

// One-time cleanup: delete attendance/payroll entries before June 10, 2026
$_te_cleanup_done = $pdo->query(
  "SELECT setting_val FROM integration_settings WHERE setting_key = 'time_entries_pre_20260610_deleted_v1'"
)->fetchColumn();
if (!$_te_cleanup_done) {
  $pdo->exec("DELETE FROM time_entries WHERE DATE(clock_in) < '2026-06-10'");
  $pdo->exec("INSERT INTO integration_settings (setting_key, setting_val) VALUES ('time_entries_pre_20260610_deleted_v1', '1') ON DUPLICATE KEY UPDATE setting_val = '1'");
}
unset($_te_cleanup_done);

// Create bank_transactions table for imported bank activity
$pdo->exec("
  CREATE TABLE IF NOT EXISTS bank_transactions (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_date       DATE NOT NULL,
    description            TEXT NOT NULL,
    normalized_description VARCHAR(500) NOT NULL,
    amount                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    running_balance        DECIMAL(12,2) NULL,
    transaction_type       ENUM('credit','debit','transfer','other') NOT NULL DEFAULT 'credit',
    source                 VARCHAR(100) NOT NULL DEFAULT 'bank_of_america_csv',
    reference              VARCHAR(191) NULL,
    customer_name          VARCHAR(255) NULL,
    transaction_hash       CHAR(64) NOT NULL,
    source_filename        VARCHAR(255) NULL,
    source_line_number     INT UNSIGNED NULL,
    raw_row_json           LONGTEXT NULL,
    linked_payment_id      INT UNSIGNED NULL,
    matched_customer_id    INT UNSIGNED NULL,
    matched_invoice_id     INT UNSIGNED NULL,
    match_status           ENUM('unmatched','matched','ignored') NOT NULL DEFAULT 'unmatched',
    imported_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_bank_transactions_hash (transaction_hash),
    UNIQUE KEY uniq_bank_transactions_linked_payment_id (linked_payment_id),
    KEY idx_bank_transactions_date (transaction_date),
    KEY idx_bank_transactions_type (transaction_type),
    KEY idx_bank_transactions_source (source),
    KEY idx_bank_transactions_reference (reference),
    KEY idx_bank_transactions_match_status (match_status),
    KEY idx_bank_transactions_matched_customer_id (matched_customer_id),
    KEY idx_bank_transactions_matched_invoice_id (matched_invoice_id),
    CONSTRAINT fk_bank_transactions_customer FOREIGN KEY (matched_customer_id) REFERENCES customers (id) ON DELETE SET NULL,
    CONSTRAINT fk_bank_transactions_invoice FOREIGN KEY (matched_invoice_id) REFERENCES quotes (id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create customer_payments table for recording payments received from customers
$pdo->exec("
  CREATE TABLE IF NOT EXISTS customer_payments (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id     INT UNSIGNED NOT NULL,
    payment_date    DATE NOT NULL,
    amount          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method  VARCHAR(50) NOT NULL DEFAULT 'check',
    reference_no    VARCHAR(100) NULL,
    notes           TEXT NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cp_customer_id (customer_id),
    KEY idx_cp_payment_date (payment_date),
    KEY idx_cp_created_at (created_at),
    CONSTRAINT fk_cp_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create invoice_credit_applications table for tracking customer credit applied to specific invoices
$pdo->exec("
  CREATE TABLE IF NOT EXISTS invoice_credit_applications (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id        INT UNSIGNED NOT NULL,
    customer_id     INT UNSIGNED NOT NULL,
    applied_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    applied_date    DATE NOT NULL,
    notes           TEXT NULL,
    applied_by      INT UNSIGNED NULL,
    payment_id      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ica_quote_id (quote_id),
    KEY idx_ica_customer_id (customer_id),
    KEY idx_ica_applied_date (applied_date),
    KEY idx_ica_payment_id (payment_id),
    CONSTRAINT fk_ica_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE CASCADE,
    CONSTRAINT fk_ica_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    CONSTRAINT fk_ica_payment FOREIGN KEY (payment_id) REFERENCES customer_payments (id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

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

if (!function_exists('bank_tx_normalize_description')) {
  function bank_tx_normalize_description(string $description): string {
    $description = preg_replace('/\s+/u', ' ', trim($description));
    return mb_strtolower((string)$description);
  }
}

if (!function_exists('bank_tx_parse_money')) {
  function bank_tx_parse_money(?string $raw): ?float {
    $raw = trim((string)$raw);
    // Bank of America exports sometimes use "..." in Running Bal. as a placeholder when no balance is shown.
    if ($raw === '' || $raw === '...') {
      return null;
    }

    $negative = false;
    if (preg_match('/^\(.*\)$/', $raw)) {
      $negative = true;
      $raw = substr($raw, 1, -1);
    }

    $normalized = str_replace(['$', ',', ' '], '', $raw);
    if ($normalized === '' || !is_numeric($normalized)) {
      return null;
    }

    $amount = (float)$normalized;
    return $negative ? ($amount * -1) : $amount;
  }
}

if (!function_exists('bank_tx_amount_string')) {
  function bank_tx_amount_string(float $amount): string {
    return number_format($amount, 2, '.', '');
  }
}

if (!function_exists('bank_tx_hash')) {
  function bank_tx_hash(string $date_ymd, string $description, float $amount): string {
    return hash('sha256', $date_ymd . '|' . bank_tx_normalize_description($description) . '|' . bank_tx_amount_string($amount));
  }
}

if (!function_exists('bank_tx_detect_type')) {
  function bank_tx_detect_type(string $description, float $amount): string {
    $desc = mb_strtolower($description);
    if (str_contains($desc, 'transfer')) {
      return 'transfer';
    }
    if ($amount > 0) {
      return 'credit';
    }
    if ($amount < 0) {
      return 'debit';
    }
    return 'other';
  }
}

if (!function_exists('bank_tx_classify_source')) {
  function bank_tx_classify_source(string $description): string {
    $desc = mb_strtolower($description);
    return match (true) {
      str_contains($desc, 'zelle') => 'zelle',
      str_contains($desc, 'stripe') => 'stripe',
      str_contains($desc, 'atm') => 'atm',
      str_contains($desc, 'ach'), str_contains($desc, 'wire') => 'ach',
      str_contains($desc, 'deposit') => 'deposit',
      default => 'bank_of_america_csv',
    };
  }
}

if (!function_exists('bank_tx_extract_reference')) {
  function bank_tx_extract_reference(string $description): ?string {
    $patterns = [
      '/\bConf#\s*([A-Z0-9-]+)/i',
      '/\bID:([A-Z0-9-]+)/i',
      '/#([A-Z0-9]{4,})\b/i',
    ];
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $description, $matches)) {
        return strtoupper(trim((string)$matches[1]));
      }
    }
    return null;
  }
}

if (!function_exists('bank_tx_extract_customer_name')) {
  function bank_tx_extract_customer_name(string $description): ?string {
    if (preg_match('/zelle payment from\s+(.+?)(?:\s+conf#|\s*$)/i', $description, $matches)) {
      return trim((string)$matches[1]);
    }
    if (preg_match('/zelle payment to\s+(.+?)(?:\s+conf#|\s*$)/i', $description, $matches)) {
      return trim((string)$matches[1]);
    }
    return null;
  }
}

if (!function_exists('bank_tx_suggest_payment_method')) {
  function bank_tx_suggest_payment_method(string $source, string $description): string {
    return match ($source) {
      'zelle', 'stripe', 'ach' => 'ach_wire',
      'atm' => str_contains(mb_strtolower($description), 'ckcd') ? 'check' : 'cash',
      default => str_contains(mb_strtolower($description), 'check') ? 'check' : 'other',
    };
  }
}

if (!function_exists('bank_tx_invoice_search_term')) {
  function bank_tx_invoice_search_term(?string $customer_name, ?string $reference, string $description): string {
    $candidate = trim((string)$customer_name);
    if ($candidate !== '') {
      return $candidate;
    }
    $candidate = trim((string)$reference);
    if ($candidate !== '') {
      return $candidate;
    }
    $description = trim($description);
    if ($description === '') {
      return '';
    }
    return mb_substr($description, 0, 60);
  }
}

