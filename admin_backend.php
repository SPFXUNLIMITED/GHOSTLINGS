<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin();

const CR_LABEL_MAX = 100;
const CR_BODY_MAX  = 2000;
const CR_SLOT_COUNT = 4;
const HUBSPOT_TOKEN_MAX = 512;
const APP_SETTINGS_ENCRYPTION_KEY_MAX = 512;

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['admin_backend_csrf'])) {
  $_SESSION['admin_backend_csrf'] = bin2hex(random_bytes(24));
}

$allowed_sections = [
  'dashboard',
  'users',
  'time_reports',
  'canned_responses',
  'integrations',
  'system_settings',
];

$section = (string)($_GET['section'] ?? 'dashboard');
if ($section === 'overview') {
  $section = 'dashboard';
}
if (!in_array($section, $allowed_sections, true)) {
  $section = 'dashboard';
}

$cr_success = '';
$cr_errors  = [];
$integrations_success = '';
$integrations_errors = [];
$hubspot_token_is_set = false;
$hubspot_token_updated_at = '';
$app_settings_encryption_key_is_set = false;
$app_settings_encryption_key_updated_at = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'canned_responses') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['admin_backend_csrf'], $csrf)) {
    $cr_errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    for ($i = 1; $i <= CR_SLOT_COUNT; $i++) {
      $lbl  = trim((string)($_POST["cr_label_{$i}"] ?? ''));
      $body = trim((string)($_POST["cr_body_{$i}"] ?? ''));
      if (strlen($lbl) > CR_LABEL_MAX) {
        $cr_errors[] = "Response {$i} label must be " . CR_LABEL_MAX . ' characters or fewer.';
      }
      if (strlen($body) > CR_BODY_MAX) {
        $cr_errors[] = "Response {$i} body must be " . CR_BODY_MAX . ' characters or fewer.';
      }
    }

    if (!$cr_errors) {
      for ($i = 1; $i <= CR_SLOT_COUNT; $i++) {
        $lbl  = trim((string)($_POST["cr_label_{$i}"] ?? ''));
        $body = trim((string)($_POST["cr_body_{$i}"] ?? ''));
        $pdo->prepare(
          "INSERT INTO rfq_canned_responses (slot, label, body) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE label = ?, body = ?"
        )->execute([$i, $lbl, $body, $lbl, $body]);
      }
      $_SESSION['admin_backend_csrf'] = bin2hex(random_bytes(24));
      $cr_success = 'Canned responses saved.';
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'integrations') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['admin_backend_csrf'], $csrf)) {
    $integrations_errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $submitted_encryption_key = trim((string)($_POST['app_settings_encryption_key'] ?? ''));
    $clear_encryption_key = !empty($_POST['clear_app_settings_encryption_key']);
    $submitted_hubspot_token = trim((string)($_POST['hubspot_private_app_token'] ?? ''));
    $clear_hubspot_token = !empty($_POST['clear_hubspot_token']);

    if (strlen($submitted_encryption_key) > APP_SETTINGS_ENCRYPTION_KEY_MAX) {
      $integrations_errors[] = 'Encryption key must be ' . APP_SETTINGS_ENCRYPTION_KEY_MAX . ' characters or fewer.';
    }
    if (strlen($submitted_hubspot_token) > HUBSPOT_TOKEN_MAX) {
      $integrations_errors[] = 'HubSpot token must be ' . HUBSPOT_TOKEN_MAX . ' characters or fewer.';
    }

    if (!$integrations_errors) {
      try {
        $existing_stmt = $pdo->query(
          "SELECT setting_key, setting_val, is_encrypted
           FROM integration_settings
           WHERE setting_key IN ('hubspot_private_app_token', 'app_settings_encryption_key')"
        );
        $existing = [];
        foreach ($existing_stmt->fetchAll() as $setting_row) {
          $existing[(string)$setting_row['setting_key']] = $setting_row;
        }

        $existing_hubspot_value = trim((string)($existing['hubspot_private_app_token']['setting_val'] ?? ''));
        $existing_hubspot_is_encrypted = (int)($existing['hubspot_private_app_token']['is_encrypted'] ?? 0) === 1;
        $existing_encryption_key = trim((string)($existing['app_settings_encryption_key']['setting_val'] ?? ''));
        $resolved_encryption_key = $submitted_encryption_key !== '' ? $submitted_encryption_key : $existing_encryption_key;
        if ($resolved_encryption_key === '') {
          $resolved_encryption_key = trim((string)(getenv('APP_SETTINGS_ENCRYPTION_KEY') ?: (($config['app_settings_encryption_key'] ?? ''))));
        }

        $key_will_be_cleared = $clear_encryption_key && $submitted_encryption_key === '';
        $hubspot_token_will_be_set = ($submitted_hubspot_token !== '') || ($existing_hubspot_value !== '' && !$clear_hubspot_token);
        if ($key_will_be_cleared && $hubspot_token_will_be_set) {
          $integrations_errors[] = 'Clear the HubSpot token before removing the encryption key.';
        }

        if ($submitted_hubspot_token !== '' && $resolved_encryption_key === '') {
          $integrations_errors[] = 'Set an encryption key before saving a HubSpot token.';
        }

        if (!$integrations_errors) {
          $hubspot_value_to_store = $existing_hubspot_value;
          $hubspot_is_encrypted_to_store = $existing_hubspot_is_encrypted ? 1 : 0;

          if ($submitted_hubspot_token !== '') {
            $hubspot_value_to_store = app_encrypt_setting_value($submitted_hubspot_token, $resolved_encryption_key);
            $hubspot_is_encrypted_to_store = 1;
          } elseif ($clear_hubspot_token) {
            $hubspot_value_to_store = '';
            $hubspot_is_encrypted_to_store = 0;
          } elseif ($submitted_encryption_key !== '' && $existing_hubspot_value !== '') {
            $hubspot_plaintext = $existing_hubspot_is_encrypted
              ? app_decrypt_setting_value($existing_hubspot_value)
              : $existing_hubspot_value;
            $hubspot_plaintext = trim((string)$hubspot_plaintext);
            if ($hubspot_plaintext === '') {
              throw new RuntimeException('Unable to decrypt saved HubSpot token.');
            }
            $hubspot_value_to_store = app_encrypt_setting_value($hubspot_plaintext, $submitted_encryption_key);
            $hubspot_is_encrypted_to_store = 1;
          }

          $hubspot_db_value = $hubspot_value_to_store === '' ? null : $hubspot_value_to_store;
          $pdo->prepare(
            "INSERT INTO integration_settings (setting_key, setting_val, is_encrypted)
             VALUES ('hubspot_private_app_token', ?, ?)
             ON DUPLICATE KEY UPDATE setting_val = ?, is_encrypted = ?"
          )->execute([
            $hubspot_db_value,
            $hubspot_is_encrypted_to_store,
            $hubspot_db_value,
            $hubspot_is_encrypted_to_store,
          ]);

          $encryption_key_to_store = $submitted_encryption_key !== ''
            ? $submitted_encryption_key
            : ($clear_encryption_key ? '' : $existing_encryption_key);
          $encryption_key_db_value = $encryption_key_to_store === '' ? null : $encryption_key_to_store;
          $pdo->prepare(
            "INSERT INTO integration_settings (setting_key, setting_val, is_encrypted)
             VALUES ('app_settings_encryption_key', ?, 0)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val), is_encrypted = 0"
          )->execute([$encryption_key_db_value]);

          $_SESSION['admin_backend_csrf'] = bin2hex(random_bytes(24));
          $integrations_success = 'Integration settings saved securely.';
        }
      } catch (Throwable $e) {
        $integrations_errors[] = 'Unable to save integration settings. Verify the encryption key and try again.';
      }
    }
  }
}

$canned = [];
if ($section === 'canned_responses') {
  $rows = $pdo->query(
    'SELECT slot, label, body FROM rfq_canned_responses WHERE slot IN (1,2,3,4) ORDER BY slot'
  )->fetchAll();
  foreach ($rows as $r) {
    $canned[(int)$r['slot']] = $r;
  }
  for ($i = 1; $i <= CR_SLOT_COUNT; $i++) {
    if (!isset($canned[$i])) {
      $canned[$i] = ['slot' => $i, 'label' => '', 'body' => ''];
    }
  }
}

if ($section === 'integrations') {
  $row = $pdo->prepare(
    "SELECT setting_key, setting_val, updated_at
     FROM integration_settings
     WHERE setting_key IN ('hubspot_private_app_token', 'app_settings_encryption_key')"
  );
  $row->execute();
  $integration_rows = $row->fetchAll() ?: [];
  $integrations_by_key = [];
  foreach ($integration_rows as $integration_row) {
    $integrations_by_key[(string)$integration_row['setting_key']] = $integration_row;
  }
  $hubspot_token_is_set = trim((string)($integrations_by_key['hubspot_private_app_token']['setting_val'] ?? '')) !== '';
  $hubspot_token_updated_at = trim((string)($integrations_by_key['hubspot_private_app_token']['updated_at'] ?? ''));
  $app_settings_encryption_key_is_set = trim((string)($integrations_by_key['app_settings_encryption_key']['setting_val'] ?? '')) !== '';
  $app_settings_encryption_key_updated_at = trim((string)($integrations_by_key['app_settings_encryption_key']['updated_at'] ?? ''));
}

$total_users = 0;
$total_inquiries = 0;
$recent_activity = [];

if ($section === 'dashboard') {
  try {
    $total_users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
  } catch (Throwable $e) {
    $total_users = 0;
  }

  try {
    $total_inquiries = (int)$pdo->query(
      'SELECT
        (SELECT COUNT(*) FROM customer_phone_inquiries)
      + (SELECT COUNT(*) FROM machine_inquiries)
      + (SELECT COUNT(*) FROM rfq_requests)
      + (SELECT COUNT(*) FROM shipping_rfq_requests)
      + (SELECT COUNT(*) FROM app_requests) AS total_inquiries'
    )->fetchColumn();
  } catch (Throwable $e) {
    $total_inquiries = 0;
  }

  try {
    $recent_activity = $pdo->query(
      "SELECT kind, actor, details, occurred_at
       FROM (
         SELECT
           'Time Entry' AS kind,
           COALESCE(u.username, CONCAT('User #', te.user_id)) AS actor,
           COALESCE(NULLIF(TRIM(te.description), ''), 'Clock activity recorded') AS details,
           te.created_at AS occurred_at
         FROM time_entries te
         LEFT JOIN users u ON u.id = te.user_id

         UNION ALL

         SELECT
           'Quick Order' AS kind,
           COALESCE(NULLIF(TRIM(customer_name), ''), 'Unknown caller') AS actor,
           COALESCE(NULLIF(TRIM(company_name), ''), 'Customer inquiry logged') AS details,
           created_at AS occurred_at
         FROM customer_phone_inquiries

         UNION ALL

         SELECT
           'App Request' AS kind,
           COALESCE(u.username, CONCAT('User #', ar.requested_by)) AS actor,
           COALESCE(NULLIF(TRIM(ar.request_title), ''), 'Request submitted') AS details,
           ar.created_at AS occurred_at
         FROM app_requests ar
         LEFT JOIN users u ON u.id = ar.requested_by
       ) activity
       ORDER BY occurred_at DESC
       LIMIT 8"
    )->fetchAll();
  } catch (Throwable $e) {
    $recent_activity = [];
  }
}

$menu = [
  'dashboard' => ['label' => 'Dashboard', 'subtitle' => 'Overview'],
  'users' => ['label' => 'Users', 'subtitle' => 'Accounts & permissions'],
  'time_reports' => ['label' => 'Time Reports', 'subtitle' => 'Payroll and hour tracking'],
  'canned_responses' => ['label' => 'Canned Responses', 'subtitle' => 'RFQ quick responses'],
  'integrations' => ['label' => 'Integrations', 'subtitle' => 'API tokens and external services'],
  'system_settings' => ['label' => 'System Settings', 'subtitle' => 'Configuration and controls'],
];

$format_activity_datetime = static function ($value): string {
  $ts = strtotime((string)$value);
  if ($ts === false) return '—';
  $dt = new DateTime('@' . $ts);
  $dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
  return $dt->format('M j, Y g:i A') . ' PT';
};

render_header('Admin Backend');
?>

<style>
  .admin-shell {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr);
    gap: 18px;
    align-items: start;
  }

  .admin-sidebar {
    position: sticky;
    top: 12px;
    padding: 12px;
  }

  .admin-brand {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--m);
    margin: 4px 0 12px;
  }

  .admin-nav-link {
    display: block;
    text-decoration: none;
    border: 1px solid transparent;
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 8px;
    color: #334155;
    background: #f8fafc;
    transition: border-color .15s ease, background .15s ease;
  }

  .admin-nav-link:last-child {
    margin-bottom: 0;
  }

  .admin-nav-link:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
  }

  .admin-nav-link.active {
    background: #eff6ff;
    border-color: #93c5fd;
    color: #1d4ed8;
  }

  .admin-nav-label {
    display: block;
    font-weight: 700;
    line-height: 1.2;
  }

  .admin-nav-subtitle {
    display: block;
    font-size: 12px;
    color: var(--m);
    margin-top: 2px;
  }

  .admin-main {
    min-width: 0;
  }

  .admin-page-title {
    margin: 0;
    font-size: 1.45rem;
  }

  .admin-placeholder {
    text-align: center;
    padding: 30px 16px;
  }

  .admin-recent-table {
    width: 100%;
    border-collapse: collapse;
  }

  .admin-recent-table th,
  .admin-recent-table td {
    border-bottom: 1px solid var(--b);
    padding: 10px 8px;
    text-align: left;
    font-size: 13px;
    vertical-align: top;
  }

  .admin-recent-table th {
    color: var(--m);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
    font-weight: 700;
  }

  .admin-grid-two {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }

  .canned-item + .canned-item {
    margin-top: 22px;
  }

  @media (max-width: 980px) {
    .admin-shell {
      grid-template-columns: 1fr;
    }

    .admin-sidebar {
      position: static;
    }
  }

  @media (max-width: 700px) {
    .admin-grid-two {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="card page-head">
  <div class="page-head-main">
    <h1 class="admin-page-title">Admin Area</h1>
    <p class="muted">Centralized management for operations, users, reporting, and system controls.</p>
  </div>
</div>

<div class="admin-shell">
  <aside class="card admin-sidebar">
    <div class="admin-brand">Administration</div>
    <?php foreach ($menu as $key => $item): ?>
      <a class="admin-nav-link <?= $section === $key ? 'active' : '' ?>" href="admin_backend.php?section=<?= h($key) ?>">
        <span class="admin-nav-label"><?= h($item['label']) ?></span>
        <span class="admin-nav-subtitle"><?= h($item['subtitle']) ?></span>
      </a>
    <?php endforeach; ?>
  </aside>

  <main class="admin-main">
    <?php if ($section === 'dashboard'): ?>
      <div class="card">
        <h2 style="margin-top:0;">Dashboard Overview</h2>
        <p class="muted">High-level visibility into team activity and incoming requests.</p>

        <div class="stat-grid" style="margin-top:14px;">
          <div class="stat-card">
            <div class="stat-value"><?= number_format($total_users) ?></div>
            <div class="stat-label">Total Users</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format($total_inquiries) ?></div>
            <div class="stat-label">Total Inquiries</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format(count($recent_activity)) ?></div>
            <div class="stat-label">Recent Activity Items</div>
          </div>
        </div>
      </div>

      <div class="card">
        <h3 style="margin-top:0;">Recent Activity</h3>
        <?php if (!$recent_activity): ?>
          <p class="muted" style="margin:0;">No activity has been recorded yet.</p>
        <?php else: ?>
          <table class="admin-recent-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>Actor</th>
                <th>Details</th>
                <th>When</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recent_activity as $row): ?>
                <tr>
                  <td><?= h((string)$row['kind']) ?></td>
                  <td><?= h((string)$row['actor']) ?></td>
                  <td><?= h((string)$row['details']) ?></td>
                  <td><?= h($format_activity_datetime($row['occurred_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <?php elseif ($section === 'canned_responses'): ?>

      <div class="card">
        <h2 style="margin-top:0;">Canned Responses</h2>
        <p class="muted" style="margin-bottom:16px;">
          Configure quick-fill response buttons for the RFQ additional notes field.
        </p>

        <?php if ($cr_errors): ?>
          <div class="alert error" style="margin-bottom:14px;">
            <ul style="margin:0; padding-left:18px;">
              <?php foreach ($cr_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($cr_success): ?>
          <div class="alert" style="margin-bottom:14px; border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            <?= h($cr_success) ?>
          </div>
        <?php endif; ?>

        <form method="post" action="admin_backend.php?section=canned_responses" novalidate>
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['admin_backend_csrf']) ?>" />

          <?php for ($i = 1; $i <= CR_SLOT_COUNT; $i++): ?>
            <div class="canned-item">
              <h3 class="form-section-heading" style="margin-top:0;">Response <?= $i ?></h3>
              <div class="admin-grid-two">
                <div>
                  <label>Button Label</label>
                  <input type="text" name="cr_label_<?= $i ?>" maxlength="100"
                         value="<?= h($canned[$i]['label']) ?>"
                         placeholder="e.g. Standard Request" />
                </div>
                <div>
                  <label>Response Text</label>
                  <textarea name="cr_body_<?= $i ?>" rows="4" maxlength="2000"
                            placeholder="Text to insert into Additional Notes..."><?= h($canned[$i]['body']) ?></textarea>
                </div>
              </div>
            </div>
          <?php endfor; ?>

          <div style="margin-top:18px;">
            <button type="submit" class="btn primary">Save Canned Responses</button>
          </div>
        </form>
      </div>

    <?php elseif ($section === 'integrations'): ?>

      <div class="card">
        <h2 style="margin-top:0;">Integrations</h2>
        <p class="muted" style="margin-bottom:16px;">Manage third-party API credentials used by the system.</p>

        <?php if ($integrations_errors): ?>
          <div class="alert error" style="margin-bottom:14px;">
            <ul style="margin:0; padding-left:18px;">
              <?php foreach ($integrations_errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($integrations_success): ?>
          <div class="alert" style="margin-bottom:14px; border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            <?= h($integrations_success) ?>
          </div>
        <?php endif; ?>

        <form method="post" action="admin_backend.php?section=integrations" novalidate>
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['admin_backend_csrf']) ?>" />

          <div style="max-width:560px;">
            <label for="app_settings_encryption_key">App Settings Encryption Key</label>
            <input id="app_settings_encryption_key" type="password" name="app_settings_encryption_key" maxlength="<?= APP_SETTINGS_ENCRYPTION_KEY_MAX ?>" autocomplete="new-password" aria-describedby="app_settings_encryption_key_help" placeholder="<?= $app_settings_encryption_key_is_set ? 'Saved key is hidden. Enter a new key to rotate it.' : 'Enter encryption key' ?>" />
            <p id="app_settings_encryption_key_help" class="muted" style="margin:6px 0 12px; font-size:12px;">
              <?= $app_settings_encryption_key_is_set ? 'An encryption key is currently saved for protected integration settings.' : 'No encryption key saved yet.' ?>
              <?php if ($app_settings_encryption_key_updated_at !== ''): ?>
                Last updated: <?= h($format_activity_datetime($app_settings_encryption_key_updated_at)) ?>.
              <?php endif; ?>
            </p>

            <label for="hubspot_private_app_token">HubSpot Private App Token</label>
            <input id="hubspot_private_app_token" type="password" name="hubspot_private_app_token" maxlength="<?= HUBSPOT_TOKEN_MAX ?>" autocomplete="new-password" aria-describedby="hubspot_private_app_token_help" placeholder="<?= $hubspot_token_is_set ? 'Saved token is hidden. Enter a new token to replace it.' : 'Enter HubSpot token' ?>" />
            <p id="hubspot_private_app_token_help" class="muted" style="margin:6px 0 0; font-size:12px;">
              <?= $hubspot_token_is_set ? 'A token is currently saved and encrypted in the database.' : 'No token saved yet.' ?>
              <?php if ($hubspot_token_updated_at !== ''): ?>
                Last updated: <?= h($format_activity_datetime($hubspot_token_updated_at)) ?>.
              <?php endif; ?>
            </p>
          </div>

          <label style="display:inline-flex; gap:8px; align-items:center; margin-top:12px;">
            <input type="checkbox" name="clear_app_settings_encryption_key" value="1" />
            <span>Clear saved encryption key</span>
          </label>

          <label style="display:inline-flex; gap:8px; align-items:center; margin-top:12px;">
            <input type="checkbox" name="clear_hubspot_token" value="1" />
            <span>Clear saved token</span>
          </label>

          <div style="margin-top:18px;">
            <button type="submit" class="btn primary">Save Integration Settings</button>
          </div>
        </form>
      </div>

    <?php elseif ($section === 'users'): ?>

      <div class="card admin-placeholder">
        <h2 style="margin-top:0;">Users</h2>
        <p class="muted">User management panel placeholder. This section will include account controls and role management.</p>
        <a class="btn" href="users.php">Open Current Users Page</a>
      </div>

    <?php elseif ($section === 'time_reports'): ?>

      <div class="card admin-placeholder">
        <h2 style="margin-top:0;">Time Reports</h2>
        <p class="muted">Time reporting placeholder. This section will host reporting filters, export options, and summaries.</p>
        <a class="btn" href="time_report.php">Open Current Time Reports</a>
      </div>

    <?php elseif ($section === 'system_settings'): ?>

      <div class="card admin-placeholder">
        <h2 style="margin-top:0;">System Settings</h2>
        <p class="muted">System settings placeholder. This section will include global configuration and maintenance controls.</p>
      </div>

    <?php endif; ?>
  </main>
</div>

<?php render_footer(); ?>
