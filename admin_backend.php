<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin();

const CR_LABEL_MAX = 100;
const CR_BODY_MAX  = 2000;
const CR_SLOT_COUNT = 4;
const HUBSPOT_TOKEN_MAX = 512;
const RECENT_ACTIVITY_PER_PAGE = 20;

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
    $submitted_hubspot_token = trim((string)($_POST['hubspot_private_app_token'] ?? ''));
    $clear_hubspot_token = !empty($_POST['clear_hubspot_token']);

    if (strlen($submitted_hubspot_token) > HUBSPOT_TOKEN_MAX) {
      $integrations_errors[] = 'HubSpot token must be ' . HUBSPOT_TOKEN_MAX . ' characters or fewer.';
    }

    if (!$integrations_errors) {
      try {
        app_ensure_integration_settings_table($pdo);
        app_settings_crypto_key();

        $existing_stmt = $pdo->prepare("SELECT setting_val FROM integration_settings WHERE setting_key = 'hubspot_private_app_token' LIMIT 1");
        $existing_stmt->execute();
        $existing_value = (string)($existing_stmt->fetchColumn() ?? '');

        $value_to_store = $existing_value;
        $is_encrypted = 1;

        if ($submitted_hubspot_token !== '') {
          $value_to_store = app_encrypt_setting_value($submitted_hubspot_token);
        } elseif ($clear_hubspot_token) {
          $value_to_store = '';
          $is_encrypted = 0;
        }

        $db_value_to_store = $value_to_store === '' ? null : $value_to_store;
        $pdo->prepare(
          "INSERT INTO integration_settings (setting_key, setting_val, is_encrypted)
           VALUES ('hubspot_private_app_token', ?, ?)
           ON DUPLICATE KEY UPDATE setting_val = ?, is_encrypted = ?"
        )->execute([
          $db_value_to_store,
          $is_encrypted,
          $db_value_to_store,
          $is_encrypted,
        ]);

        $_SESSION['admin_backend_csrf'] = bin2hex(random_bytes(24));
        $integrations_success = $clear_hubspot_token && $submitted_hubspot_token === ''
          ? 'HubSpot token removed.'
          : 'HubSpot token saved securely.';
      } catch (Throwable $e) {
        error_log('Integrations token save failed: ' . $e->getMessage());
        $integrations_errors[] = 'Unable to save token right now. Please try again. If this continues, check server error logs.';
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
  try {
    app_ensure_integration_settings_table($pdo);
    app_settings_crypto_key();
  } catch (Throwable $e) {
    error_log('Integrations encryption initialization failed: ' . $e->getMessage());
    $integrations_errors[] = 'Unable to initialize secure encryption for integration settings.';
  }

  $row = $pdo->prepare(
    "SELECT setting_val, updated_at
     FROM integration_settings
     WHERE setting_key = 'hubspot_private_app_token'
     LIMIT 1"
  );
  $row->execute();
  $integration = $row->fetch() ?: [];
  $hubspot_token_is_set = trim((string)($integration['setting_val'] ?? '')) !== '';
  $hubspot_token_updated_at = trim((string)($integration['updated_at'] ?? ''));
}

$total_users = 0;
$count_phone_inquiries = 0;
$count_machine_inquiries = 0;
$count_rfq_requests = 0;
$count_shipping_rfq = 0;
$count_app_requests = 0;
$recent_activity = [];
$recent_activity_total = 0;
$recent_activity_page = 1;
$activity_type_options = ['Time Entry', 'Quick Order', 'App Request', 'Page View'];
$activity_type_filter = (string)($_GET['activity_type'] ?? 'all');
if ($activity_type_filter !== 'all' && !in_array($activity_type_filter, $activity_type_options, true)) {
  $activity_type_filter = 'all';
}
$activity_sort = (string)($_GET['activity_sort'] ?? 'when');
$activity_sort_options = ['type', 'actor', 'when'];
if (!in_array($activity_sort, $activity_sort_options, true)) {
  $activity_sort = 'when';
}
$activity_dir = strtolower((string)($_GET['activity_dir'] ?? 'desc'));
if (!in_array($activity_dir, ['asc', 'desc'], true)) {
  $activity_dir = 'desc';
}
$activity_query_url = static function (array $overrides = []): string {
  $params = [];
  foreach ($_GET as $key => $value) {
    if (is_scalar($value)) {
      $params[$key] = (string)$value;
    }
  }
  $params['section'] = 'dashboard';
  foreach ($overrides as $key => $value) {
    if ($value === null || $value === '') {
      unset($params[$key]);
    } else {
      $params[$key] = (string)$value;
    }
  }
  return 'admin_backend.php?' . http_build_query($params);
};
$activity_sort_url = static function (string $column) use ($activity_query_url, $activity_sort, $activity_dir): string {
  $next_dir = ($activity_sort === $column && $activity_dir === 'asc') ? 'desc' : 'asc';
  return $activity_query_url([
    'activity_sort' => $column,
    'activity_dir' => $next_dir,
    'activity_page' => null,
  ]);
};
$activity_sort_indicator = static function (string $column) use ($activity_sort, $activity_dir): string {
  if ($activity_sort !== $column) {
    return '';
  }
  return $activity_dir === 'asc' ? ' ↑' : ' ↓';
};
$render_activity_filter = static function () use ($activity_sort, $activity_dir, $activity_type_options, $activity_type_filter): void {
  ?>
  <form method="get" action="admin_backend.php" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
    <input type="hidden" name="section" value="dashboard" />
    <input type="hidden" name="activity_sort" value="<?= h($activity_sort) ?>" />
    <input type="hidden" name="activity_dir" value="<?= h($activity_dir) ?>" />
    <div>
      <label for="activity_type" style="display:block; margin-bottom:4px;">Type</label>
      <select id="activity_type" name="activity_type">
        <option value="all">All</option>
        <?php foreach ($activity_type_options as $option): ?>
          <option value="<?= h($option) ?>" <?= $activity_type_filter === $option ? 'selected' : '' ?>><?= h($option) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn">Filter</button>
  </form>
  <?php
};

if ($section === 'dashboard') {
  try {
    $total_users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
  } catch (Throwable $e) {
    $total_users = 0;
  }

  try {
    $count_phone_inquiries = (int)$pdo->query('SELECT COUNT(*) FROM customer_phone_inquiries')->fetchColumn();
  } catch (Throwable $e) {
    $count_phone_inquiries = 0;
  }
  try {
    $count_machine_inquiries = (int)$pdo->query('SELECT COUNT(*) FROM machine_inquiries')->fetchColumn();
  } catch (Throwable $e) {
    $count_machine_inquiries = 0;
  }
  try {
    $count_rfq_requests = (int)$pdo->query('SELECT COUNT(*) FROM rfq_requests')->fetchColumn();
  } catch (Throwable $e) {
    $count_rfq_requests = 0;
  }
  try {
    $count_shipping_rfq = (int)$pdo->query('SELECT COUNT(*) FROM shipping_rfq_requests')->fetchColumn();
  } catch (Throwable $e) {
    $count_shipping_rfq = 0;
  }
  try {
    $count_app_requests = (int)$pdo->query('SELECT COUNT(*) FROM app_requests')->fetchColumn();
  } catch (Throwable $e) {
    $count_app_requests = 0;
  }

  try {
    $recent_activity_page = max(1, (int)($_GET['activity_page'] ?? 1));
    $activity_sql = "
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
        COALESCE(
          NULLIF(TRIM(u.contact_name), ''),
          NULLIF(TRIM(u.username), ''),
          CASE
            WHEN cpi.created_by IS NOT NULL THEN CONCAT('User #', cpi.created_by)
            ELSE NULL
          END,
          'Unknown user'
        ) AS actor,
        CONCAT(
          'Created Quick Order for ',
          COALESCE(
            NULLIF(TRIM(cpi.company_name), ''),
            NULLIF(TRIM(cpi.customer_name), ''),
            'Unknown customer'
          ),
          CASE
            WHEN NULLIF(TRIM(cpi.company_name), '') IS NOT NULL
              AND NULLIF(TRIM(cpi.customer_name), '') IS NOT NULL
            THEN CONCAT(' (Contact: ', TRIM(cpi.customer_name), ')')
            ELSE ''
          END
        ) AS details,
        cpi.created_at AS occurred_at
      FROM customer_phone_inquiries cpi
      LEFT JOIN users u ON u.id = cpi.created_by

      UNION ALL

      SELECT
        'App Request' AS kind,
        COALESCE(u.username, CONCAT('User #', ar.requested_by)) AS actor,
        COALESCE(NULLIF(TRIM(ar.request_title), ''), 'Request submitted') AS details,
        ar.created_at AS occurred_at
      FROM app_requests ar
      LEFT JOIN users u ON u.id = ar.requested_by

      UNION ALL

      SELECT
        'Page View' AS kind,
        COALESCE(u.username, CONCAT('User #', pv.user_id)) AS actor,
        CONCAT('Viewed ', pv.page) AS details,
        pv.viewed_at AS occurred_at
      FROM page_views pv
      LEFT JOIN users u ON u.id = pv.user_id
      WHERE pv.viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ";
    $activity_params = [];
    $activity_where_sql = '';
    if ($activity_type_filter !== 'all') {
      $activity_where_sql = ' WHERE kind = :activity_type';
      $activity_params[':activity_type'] = $activity_type_filter;
    }
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ({$activity_sql}) activity{$activity_where_sql}");
    $count_stmt->execute($activity_params);
    $recent_activity_total = (int)$count_stmt->fetchColumn();
    $activity_total_pages = max(1, (int)ceil($recent_activity_total / RECENT_ACTIVITY_PER_PAGE));
    if ($recent_activity_page > $activity_total_pages) {
      $recent_activity_page = $activity_total_pages;
    }
    $activity_offset = ($recent_activity_page - 1) * RECENT_ACTIVITY_PER_PAGE;
    if ($activity_sort === 'type' && $activity_dir === 'asc') {
      $activity_query_sql = "SELECT kind, actor, details, occurred_at
       FROM ({$activity_sql}) activity{$activity_where_sql}
       ORDER BY kind ASC
       LIMIT :activity_limit
       OFFSET :activity_offset";
    } elseif ($activity_sort === 'type') {
      $activity_query_sql = "SELECT kind, actor, details, occurred_at
       FROM ({$activity_sql}) activity{$activity_where_sql}
       ORDER BY kind DESC
       LIMIT :activity_limit
       OFFSET :activity_offset";
    } elseif ($activity_sort === 'actor' && $activity_dir === 'asc') {
      $activity_query_sql = "SELECT kind, actor, details, occurred_at
       FROM ({$activity_sql}) activity{$activity_where_sql}
       ORDER BY actor ASC
       LIMIT :activity_limit
       OFFSET :activity_offset";
    } elseif ($activity_sort === 'actor') {
      $activity_query_sql = "SELECT kind, actor, details, occurred_at
       FROM ({$activity_sql}) activity{$activity_where_sql}
       ORDER BY actor DESC
       LIMIT :activity_limit
       OFFSET :activity_offset";
    } elseif ($activity_dir === 'asc') {
      $activity_query_sql = "SELECT kind, actor, details, occurred_at
       FROM ({$activity_sql}) activity{$activity_where_sql}
       ORDER BY occurred_at ASC
       LIMIT :activity_limit
       OFFSET :activity_offset";
    } else {
      $activity_query_sql = "SELECT kind, actor, details, occurred_at
       FROM ({$activity_sql}) activity{$activity_where_sql}
       ORDER BY occurred_at DESC
       LIMIT :activity_limit
       OFFSET :activity_offset";
    }
    $activity_stmt = $pdo->prepare($activity_query_sql);
    foreach ($activity_params as $name => $value) {
      $activity_stmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $activity_stmt->bindValue(':activity_limit', RECENT_ACTIVITY_PER_PAGE, PDO::PARAM_INT);
    $activity_stmt->bindValue(':activity_offset', $activity_offset, PDO::PARAM_INT);
    $activity_stmt->execute();
    $recent_activity = $activity_stmt->fetchAll();
  } catch (Throwable $e) {
    $recent_activity = [];
    $recent_activity_total = 0;
    $recent_activity_page = 1;
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

  .admin-recent-toolbar {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: end;
    flex-wrap: wrap;
    margin-bottom: 14px;
  }

  .admin-recent-sort {
    color: inherit;
    text-decoration: none;
  }

  .admin-recent-sort:hover {
    text-decoration: underline;
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
            <div class="stat-value"><?= number_format($count_phone_inquiries) ?></div>
            <div class="stat-label">Customer Phone Inquiries</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format($count_machine_inquiries) ?></div>
            <div class="stat-label">Machine Inquiries</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format($count_rfq_requests) ?></div>
            <div class="stat-label">RFQ Requests</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format($count_shipping_rfq) ?></div>
            <div class="stat-label">Shipping RFQ Requests</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format($count_app_requests) ?></div>
            <div class="stat-label">App Requests</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format($recent_activity_total) ?></div>
            <div class="stat-label">Recent Activity Items</div>
          </div>
        </div>
      </div>

      <div class="card">
        <h3 style="margin-top:0;">Recent Activity</h3>
        <?php if (!$recent_activity): ?>
          <div class="admin-recent-toolbar">
            <?php $render_activity_filter(); ?>
          </div>
          <p class="muted" style="margin:0;"><?= $activity_type_filter === 'all' ? 'No activity has been recorded yet.' : 'No activity matches the selected type.' ?></p>
        <?php else: ?>
          <div class="admin-recent-toolbar">
            <?php $render_activity_filter(); ?>
            <div class="muted" style="font-size:12px;">
              Showing <?= number_format(count($recent_activity)) ?> of <?= number_format($recent_activity_total) ?> items
            </div>
          </div>
          <table class="admin-recent-table">
            <thead>
              <tr>
                <th><a class="admin-recent-sort" href="<?= h($activity_sort_url('type')) ?>">Type<?= h($activity_sort_indicator('type')) ?></a></th>
                <th><a class="admin-recent-sort" href="<?= h($activity_sort_url('actor')) ?>">Actor<?= h($activity_sort_indicator('actor')) ?></a></th>
                <th>Details</th>
                <th><a class="admin-recent-sort" href="<?= h($activity_sort_url('when')) ?>">When<?= h($activity_sort_indicator('when')) ?></a></th>
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
          <?php render_pagination($recent_activity_page, $recent_activity_total, RECENT_ACTIVITY_PER_PAGE, 'activity_page'); ?>
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
