<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const HUBSPOT_SYNC_PAGE_LIMIT = 50;
const HUBSPOT_SYNC_TIMEOUT_SECONDS = 20;
const HUBSPOT_UNKNOWN_CUSTOMER_NAME = 'Unknown';

if (empty($_SESSION['customers_sync_csrf'])) {
  $_SESSION['customers_sync_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$success = '';

function hubspot_token(): string {
  $token = trim((string)getenv('HUBSPOT_PRIVATE_APP_TOKEN'));
  if ($token !== '') return $token;
  return trim((string)getenv('HUBSPOT_ACCESS_TOKEN'));
}

function hubspot_contact_name(array $props): string {
  $first = trim((string)($props['firstname'] ?? ''));
  $last = trim((string)($props['lastname'] ?? ''));
  $full = trim($first . ' ' . $last);
  if ($full !== '') return $full;
  $email = trim((string)($props['email'] ?? ''));
  return $email !== '' ? $email : HUBSPOT_UNKNOWN_CUSTOMER_NAME;
}

function hubspot_to_datetime(?string $value): ?string {
  $value = trim((string)$value);
  if ($value === '') return null;
  if (ctype_digit($value)) {
    $seconds = (int)floor(((float)$value) / 1000);
    if ($seconds > 0) {
      return gmdate('Y-m-d H:i:s', $seconds);
    }
  }
  $ts = strtotime($value);
  if ($ts === false) return null;
  return gmdate('Y-m-d H:i:s', $ts);
}

function sync_customers_from_hubspot(PDO $pdo): array {
  $token = hubspot_token();
  if ($token === '') {
    throw new RuntimeException('Missing HubSpot token. Set HUBSPOT_PRIVATE_APP_TOKEN or HUBSPOT_ACCESS_TOKEN.');
  }

  $url = 'https://api.hubapi.com/crm/v3/objects/contacts?limit=100&properties=firstname,lastname,company,phone,email,lastmodifieddate';
  $upsert = $pdo->prepare(
    "INSERT INTO customers (hubspot_contact_id, customer_name, company, phone, email, last_updated)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       customer_name = ?,
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
        $customer_name = hubspot_contact_name($props);
        $company = trim((string)($props['company'] ?? ''));
        $phone = trim((string)($props['phone'] ?? ''));
        $email = trim((string)($props['email'] ?? ''));
        $last_updated = hubspot_to_datetime($props['lastmodifieddate'] ?? null);
        $upsert->execute([
          $id,
          $customer_name,
          $company,
          $phone,
          $email,
          $last_updated,
          $customer_name,
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
      ? 'https://api.hubapi.com/crm/v3/objects/contacts?limit=100&after=' . urlencode((string)$next_after) . '&properties=firstname,lastname,company,phone,email,lastmodifieddate'
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
      $success = 'HubSpot sync complete. Synced ' . (int)$result['synced'] . ' customer(s).';
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
  "SELECT customer_name, company, phone, email, last_updated
   FROM customers
   ORDER BY COALESCE(last_updated, updated_at) DESC, id DESC"
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
        <th>Customer Name</th>
        <th>Company</th>
        <th>Phone</th>
        <th>Email</th>
        <th>Last Updated</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$customers): ?>
        <tr>
          <td colspan="5" class="muted">No customers synced yet.</td>
        </tr>
      <?php endif; ?>
      <?php foreach ($customers as $row): ?>
        <tr>
          <td><strong><?= h((string)$row['customer_name']) ?></strong></td>
          <td><?= $row['company'] !== '' ? h((string)$row['company']) : '<span class="muted">—</span>' ?></td>
          <td><?= $row['phone'] !== '' ? h((string)$row['phone']) : '<span class="muted">—</span>' ?></td>
          <td><?= $row['email'] !== '' ? h((string)$row['email']) : '<span class="muted">—</span>' ?></td>
          <td class="muted"><?= $row['last_updated'] !== null ? h((string)$row['last_updated']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
