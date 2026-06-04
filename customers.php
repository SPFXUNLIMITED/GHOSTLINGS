<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const HUBSPOT_SYNC_PAGE_LIMIT = 50;
const HUBSPOT_SYNC_PAGE_SIZE = 100;
const HUBSPOT_SYNC_TIMEOUT_SECONDS = 20;
const HUBSPOT_CONTACT_PROPERTIES = 'firstname,lastname,company,phone,email,lastmodifieddate';
const HUBSPOT_CONTACTS_API_BASE = 'https://api.hubapi.com/crm/v3/objects/contacts';

if (empty($_SESSION['customers_sync_csrf'])) {
  $_SESSION['customers_sync_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$success = '';
$customer_table_columns = 6;

function hubspot_token(PDO $pdo): string {
  $token = trim((string)getenv('HUBSPOT_PRIVATE_APP_TOKEN'));
  if ($token !== '') return $token;
  $token = trim((string)getenv('HUBSPOT_ACCESS_TOKEN'));
  if ($token !== '') return $token;

  try {
    $stmt = $pdo->prepare(
      "SELECT setting_val, is_encrypted
       FROM integration_settings
       WHERE setting_key = 'hubspot_private_app_token'
       LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch() ?: [];
    $stored = (string)($row['setting_val'] ?? '');
    if ($stored === '') {
      return '';
    }
    if (!empty($row['is_encrypted'])) {
      return trim(app_decrypt_setting_value($stored));
    }
    return trim($stored);
  } catch (Throwable $e) {
    return '';
  }
}

function hubspot_contact_names(array $props): array {
  return [
    trim((string)($props['firstname'] ?? '')),
    trim((string)($props['lastname'] ?? '')),
  ];
}

function hubspot_to_datetime(?string $value): ?string {
  $value = trim((string)$value);
  if ($value === '') return null;
  if (is_numeric($value)) {
    $seconds = (int)floor(((float)$value) / 1000);
    if ($seconds > 0) {
      return gmdate('Y-m-d H:i:s', $seconds);
    }
  }
  $ts = strtotime($value);
  if ($ts === false) return null;
  return gmdate('Y-m-d H:i:s', $ts);
}

function format_customer_last_updated(?string $value): string {
  $value = trim((string)$value);
  if ($value === '') return '—';
  $ts = strtotime($value);
  if ($ts === false) return $value;
  return date('m/d/Y g:i A', $ts);
}

function sync_customers_from_hubspot(PDO $pdo): array {
  $token = hubspot_token($pdo);
  if ($token === '') {
    throw new RuntimeException('Missing HubSpot token. Set HUBSPOT_PRIVATE_APP_TOKEN, HUBSPOT_ACCESS_TOKEN, or add it in Admin Backend > Integrations.');
  }

  $url = HUBSPOT_CONTACTS_API_BASE . '?limit=' . HUBSPOT_SYNC_PAGE_SIZE . '&properties=' . HUBSPOT_CONTACT_PROPERTIES;
  $upsert = $pdo->prepare(
    "INSERT INTO customers (hubspot_contact_id, first_name, last_name, company, phone, email, last_updated)
     VALUES (?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       first_name = ?,
       last_name = ?,
       company = ?,
       phone = ?,
       email = ?,
       last_updated = ?"
  );

  $synced = 0;
  $pages = 0;
  while ($url !== null && $pages < HUBSPOT_SYNC_PAGE_LIMIT) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => HUBSPOT_SYNC_TIMEOUT_SECONDS,
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
      ],
    ]);
    $body = curl_exec($ch);
    $curl_error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
      throw new RuntimeException('HubSpot sync failed: ' . ($curl_error !== '' ? $curl_error : 'Unknown request error.'));
    }
    if ($status < 200 || $status >= 300) {
      throw new RuntimeException('HubSpot sync failed with status ' . $status . '.');
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
      throw new RuntimeException('HubSpot sync failed: invalid response format.');
    }

    $rows = $payload['results'] ?? [];
    if (is_array($rows)) {
      foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '') continue;
        $props = is_array($row['properties'] ?? null) ? $row['properties'] : [];
        [$first_name, $last_name] = hubspot_contact_names($props);
        $company = trim((string)($props['company'] ?? ''));
        $phone = trim((string)($props['phone'] ?? ''));
        $email = trim((string)($props['email'] ?? ''));
        $last_updated = hubspot_to_datetime($props['lastmodifieddate'] ?? null);
        $upsert->execute([
          $id,
          $first_name,
          $last_name,
          $company,
          $phone,
          $email,
          $last_updated,
          $first_name,
          $last_name,
          $company,
          $phone,
          $email,
          $last_updated,
        ]);
        $synced++;
      }
    }

    $next_after = $payload['paging']['next']['after'] ?? null;
    $url = $next_after !== null
      ? HUBSPOT_CONTACTS_API_BASE . '?limit=' . HUBSPOT_SYNC_PAGE_SIZE . '&after=' . urlencode((string)$next_after) . '&properties=' . HUBSPOT_CONTACT_PROPERTIES
      : null;
    $pages++;
  }

  return [
    'synced' => $synced,
    'partial' => $url !== null,
  ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['customers_sync_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    try {
      $result = sync_customers_from_hubspot($pdo);
      $success = 'HubSpot sync complete. Synced ' . (int)$result['synced'] . ' contact(s).';
      if (!empty($result['partial'])) {
        $success .= ' Page limit reached; click sync again to continue importing remaining contacts.';
      }
      $_SESSION['customers_sync_csrf'] = bin2hex(random_bytes(24));
    } catch (Throwable $e) {
      $errors[] = $e->getMessage();
    }
  }
}

$customers = $pdo->query(
  "SELECT first_name, last_name, company, phone, email, last_updated, updated_at
   FROM customers
   ORDER BY (last_updated IS NULL) ASC, last_updated DESC, updated_at DESC, id DESC"
)->fetchAll();

render_header('Customers');
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1>Customers</h1>
    <p class="muted">Sync and view HubSpot customer contacts.</p>
  </div>
  <form method="post" style="margin:0;">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['customers_sync_csrf']) ?>" />
    <button type="submit" class="btn primary" style="font-size:18px; padding:14px 24px;">Sync from HubSpot</button>
  </form>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
    <?= h($success) ?>
  </div>
<?php endif; ?>

<div class="card" style="padding:0; overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>First Name</th>
        <th>Last Name</th>
        <th>Company</th>
        <th>Phone</th>
        <th>Email</th>
        <th>Last Updated</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$customers): ?>
        <tr>
          <td colspan="<?= $customer_table_columns ?>" class="muted">No customers synced yet.</td>
        </tr>
      <?php endif; ?>
      <?php foreach ($customers as $row): ?>
        <tr>
          <td>
            <?php if ($row['first_name'] !== ''): ?>
              <strong><?= h((string)$row['first_name']) ?></strong>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['last_name'] !== ''): ?>
              <strong><?= h((string)$row['last_name']) ?></strong>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['company'] !== ''): ?>
              <?= h((string)$row['company']) ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['phone'] !== ''): ?>
              <?= h((string)$row['phone']) ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['email'] !== ''): ?>
              <?= h((string)$row['email']) ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td class="muted"><?= h(format_customer_last_updated($row['last_updated'] ?? null)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
