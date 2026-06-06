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
if (empty($_SESSION['customers_delete_csrf'])) {
  $_SESSION['customers_delete_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$success = '';
$customer_table_columns = 7;
$customers_per_page = 50;

function hubspot_token(PDO $pdo): string {
  $token = app_env_value('HUBSPOT_PRIVATE_APP_TOKEN');
  if ($token !== '') return $token;
  $token = app_env_value('HUBSPOT_ACCESS_TOKEN');
  if ($token !== '') return $token;

  try {
    app_ensure_integration_settings_table($pdo);
    $stmt = $pdo->prepare(
      "SELECT setting_val, is_encrypted
       FROM integration_settings
       WHERE setting_key = 'hubspot_private_app_token'
       LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    if (is_array($row)) {
      $stored = trim((string)($row['setting_val'] ?? ''));
      if ($stored !== '') {
        $is_encrypted = (int)($row['is_encrypted'] ?? 0) === 1;
        $resolved = $is_encrypted ? app_decrypt_setting_value($stored) : $stored;
        $resolved = trim((string)$resolved);
        if ($resolved !== '') {
          return $resolved;
        }
      }
    }
  } catch (Throwable $e) {
    error_log('HubSpot token lookup failed: ' . $e->getMessage());
  }

  return '';
}

function app_env_value(string $key): string {
  $env_value = getenv($key);
  if ($env_value === false) {
    $env_value = null;
  }

  $candidates = [
    $env_value,
    $_ENV[$key] ?? null,
    $_SERVER[$key] ?? null,
  ];

  foreach ($candidates as $candidate) {
    $value = trim((string)$candidate);
    if ($value !== '') return $value;
  }

  return '';
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

function build_customers_summary_text(int $showing_from, int $showing_to, int $customer_total, string $search): string {
  $summary = 'Showing ' . $showing_from . '-' . $showing_to . ' of ' . $customer_total . ' customer' . ($customer_total === 1 ? '' : 's') . '.';
  if ($search !== '') {
    $summary .= ' Filtered by “' . $search . '”.';
  }
  return $summary;
}

function render_customers_table_rows(array $customers, string $search, int $customer_table_columns): void {
  if (!$customers): ?>
    <tr>
      <td colspan="<?= $customer_table_columns ?>" class="muted">
        <?= $search !== '' ? 'No customers matched your search.' : 'No customers synced yet.' ?>
      </td>
    </tr>
    <?php
    return;
  endif;

  foreach ($customers as $row): ?>
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
      <td class="actions">
        <a class="btn" href="customer_details.php?id=<?= (int)$row['id'] ?>">View</a>
        <a class="btn" href="customer_form.php?id=<?= (int)$row['id'] ?>">Edit</a>
        <?php if ((int)($row['has_associations'] ?? 0) === 1): ?>
          <span title="This customer cannot be deleted because they have associated RFQs or orders.">
            <button type="button" class="btn danger" disabled>Delete</button>
          </span>
        <?php else: ?>
          <form method="post" action="customer_delete.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this customer? This action cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['customers_delete_csrf']) ?>" />
            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>" />
            <button type="submit" class="btn danger">Delete</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach;
}

function render_customers_pagination(int $total_pages, int $page, int $customers_per_page, string $search): void {
  if ($total_pages <= 1) {
    return;
  }

  $base_params = $search !== '' ? ['q' => $search] : [];
  ?>
  <div class="card" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
    <?php if ($page > 1): ?>
      <?php $prev_params = $base_params; $prev_params['page'] = $page - 1; ?>
      <a class="btn" href="?<?= h(http_build_query($prev_params)) ?>">← Prev</a>
    <?php endif; ?>
    <span class="muted">Page <?= (int)$page ?> of <?= (int)$total_pages ?> (<?= (int)$customers_per_page ?> per page)</span>
    <?php if ($page < $total_pages): ?>
      <?php $next_params = $base_params; $next_params['page'] = $page + 1; ?>
      <a class="btn" href="?<?= h(http_build_query($next_params)) ?>">Next →</a>
    <?php endif; ?>
  </div>
  <?php
}

function sync_customers_from_hubspot(PDO $pdo): array {
  $token = hubspot_token($pdo);
  if ($token === '') {
    throw new RuntimeException('Missing HubSpot token. Set HUBSPOT_PRIVATE_APP_TOKEN, HUBSPOT_ACCESS_TOKEN, or save one in integration settings.');
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

$created = isset($_GET['created']) && $_GET['created'] === '1';
$updated = isset($_GET['updated']) && $_GET['updated'] === '1';
$deleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';
$delete_blocked = isset($_GET['delete_blocked']) && $_GET['delete_blocked'] === '1';
if ($success === '') {
  if ($created) {
    $success = 'Customer added.';
  } elseif ($updated) {
    $success = 'Customer updated.';
  } elseif ($deleted) {
    $success = 'Customer deleted.';
  }
}
if ($delete_blocked) {
  $errors[] = 'This customer cannot be deleted because they have associated RFQs or orders.';
}

$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$where_sql = '';

$count_stmt = null;
if ($search !== '') {
  $where_sql = "WHERE c.first_name LIKE :q OR c.last_name LIKE :q OR c.company LIKE :q";
  $escaped_search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
  $search_like = '%' . $escaped_search . '%';
  $where_sql .= " ESCAPE '!'";
  $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM customers c $where_sql");
  $count_stmt->bindValue(':q', $search_like, PDO::PARAM_STR);
  $count_stmt->execute();
  $customer_total = (int)$count_stmt->fetchColumn();
} else {
  $customer_total = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
}

$total_pages = max(1, (int)ceil($customer_total / $customers_per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $customers_per_page;

$data_sql = "SELECT
               c.id,
               c.first_name,
               c.last_name,
               c.company,
               c.phone,
               c.email,
               c.last_updated,
               c.updated_at,
               (
                 EXISTS (
                   SELECT 1
                   FROM rfq_requests r
                   WHERE
                     (c.email <> '' AND (r.contact_email = c.email OR r.buyer_email = c.email))
                     OR (c.company <> '' AND (r.company_name = c.company OR r.buyer_company = c.company))
                     OR (TRIM(CONCAT_WS(' ', c.first_name, c.last_name)) <> '' AND (r.contact_name = TRIM(CONCAT_WS(' ', c.first_name, c.last_name)) OR r.buyer_name = TRIM(CONCAT_WS(' ', c.first_name, c.last_name))))
                 )
                 OR EXISTS (
                   SELECT 1
                   FROM customer_phone_inquiries o
                   WHERE
                     (c.email <> '' AND o.email = c.email)
                     OR (c.company <> '' AND o.company_name = c.company)
                     OR (c.phone <> '' AND o.phone_number = c.phone)
                     OR (TRIM(CONCAT_WS(' ', c.first_name, c.last_name)) <> '' AND o.customer_name = TRIM(CONCAT_WS(' ', c.first_name, c.last_name)))
                 )
               ) AS has_associations
             FROM customers c
             $where_sql
             ORDER BY (c.last_updated IS NULL) ASC, c.last_updated DESC, c.updated_at DESC, c.id DESC
             LIMIT :limit OFFSET :offset";
$data_stmt = $pdo->prepare($data_sql);
if ($search !== '') {
  $data_stmt->bindValue(':q', $search_like, PDO::PARAM_STR);
}
$data_stmt->bindValue(':limit', $customers_per_page, PDO::PARAM_INT);
$data_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$data_stmt->execute();
$customers = $data_stmt->fetchAll();

$showing_from = $customer_total > 0 ? ($offset + 1) : 0;
$showing_to = min($offset + count($customers), $customer_total);
$summary_text = build_customers_summary_text($showing_from, $showing_to, $customer_total, $search);

$is_live_search_request = isset($_GET['live_search']) && $_GET['live_search'] === '1';
if ($is_live_search_request) {
  ob_start();
  render_customers_table_rows($customers, $search, $customer_table_columns);
  $table_rows_html = (string)ob_get_clean();

  ob_start();
  render_customers_pagination($total_pages, $page, $customers_per_page, $search);
  $pagination_html = (string)ob_get_clean();

  header('Content-Type: application/json; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');
  $live_payload = json_encode([
    'countText' => '(' . (int)$customer_total . ')',
    'summaryText' => $summary_text,
    'tableRowsHtml' => $table_rows_html,
    'paginationHtml' => $pagination_html,
  ], JSON_UNESCAPED_UNICODE);
  if ($live_payload === false) {
    http_response_code(500);
    $error_payload = json_encode(['error' => 'Failed to encode live search response: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    echo $error_payload !== false ? $error_payload : '{"error":"Failed to encode live search response."}';
    exit;
  }
  echo $live_payload;
  exit;
}

render_header('Customers');
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1>Customers <span id="customers-count" class="muted" style="font-size:0.7em; font-weight:400;">(<?= (int)$customer_total ?>)</span></h1>
    <p class="muted">Sync and view HubSpot customer contacts.</p>
  </div>
  <div class="actions">
    <a class="btn primary" href="customer_form.php">+ Add New Customer</a>
    <form method="post" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['customers_sync_csrf']) ?>" />
      <button type="submit" class="btn primary" style="font-size:18px; padding:14px 24px;">Sync from HubSpot</button>
    </form>
  </div>
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

<div class="card">
  <form id="customers-search-form" method="get" action="customers.php" class="row" style="margin-bottom:4px;" role="search">
    <input
      id="customers-search-input"
      type="text"
      name="q"
      value="<?= h($search) ?>"
      placeholder="Search by customer name or company…"
      aria-label="Search customers by name or company"
      style="max-width:360px;"
    />
    <button type="submit" class="btn">Search</button>
    <a
      id="customers-search-clear"
      class="btn"
      href="customers.php"
      <?= $search === '' ? 'style="display:none;"' : '' ?>
    >Clear</a>
  </form>
  <p id="customers-summary" class="muted" style="margin:8px 0 0;"><?= h($summary_text) ?></p>
</div>

<div id="customers-table-wrap" class="card" style="padding:0; overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>First Name</th>
        <th>Last Name</th>
        <th>Company</th>
        <th>Phone</th>
        <th>Email</th>
        <th>Last Updated</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="customers-table-body"><?php render_customers_table_rows($customers, $search, $customer_table_columns); ?></tbody>
  </table>
</div>

<div id="customers-pagination">
  <?php render_customers_pagination($total_pages, $page, $customers_per_page, $search); ?>
</div>

<script>
(() => {
  const form = document.getElementById('customers-search-form');
  const input = document.getElementById('customers-search-input');
  const clearButton = document.getElementById('customers-search-clear');
  const count = document.getElementById('customers-count');
  const summary = document.getElementById('customers-summary');
  const tableBody = document.getElementById('customers-table-body');
  const pagination = document.getElementById('customers-pagination');
  if (!form || !input || !clearButton || !count || !summary || !tableBody || !pagination) {
    console.warn('Customer live search disabled: missing required DOM elements.');
    return;
  }

  const SEARCH_DEBOUNCE_DELAY_MS = 250;
  let debounceTimer = null;
  let controller = null;
  let lastQuery = input.value.trim();

  const updateClearButton = () => {
    clearButton.style.display = input.value.trim() === '' ? 'none' : '';
  };

  const updateAddressBar = (query) => {
    const nextUrl = new URL(window.location.href);
    if (query === '') {
      nextUrl.searchParams.delete('q');
    } else {
      nextUrl.searchParams.set('q', query);
    }
    nextUrl.searchParams.delete('page');
    window.history.replaceState(null, '', nextUrl.toString());
  };

  const runLiveSearch = () => {
    const query = input.value.trim();
    updateClearButton();
    if (query === lastQuery) return;
    lastQuery = query;

    if (controller) controller.abort();
    controller = new AbortController();

    const targetUrl = new URL(form.action || window.location.href);
    if (query !== '') targetUrl.searchParams.set('q', query);
    targetUrl.searchParams.set('live_search', '1');

    fetch(targetUrl.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      signal: controller.signal,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then((response) => {
        if (!response.ok) throw new Error('Live search request failed with status ' + response.status + ' (' + response.statusText + ').');
        return response.json();
      })
      .then((payload) => {
        if (!payload || typeof payload !== 'object') {
          console.warn('Customer live search received an unexpected payload from ' + targetUrl.toString() + '.', payload);
          return;
        }
        if (typeof payload.countText === 'string') count.textContent = payload.countText;
        if (typeof payload.summaryText === 'string') summary.textContent = payload.summaryText;
        if (typeof payload.tableRowsHtml === 'string') tableBody.innerHTML = payload.tableRowsHtml;
        if (typeof payload.paginationHtml === 'string') pagination.innerHTML = payload.paginationHtml;
        updateAddressBar(query);
      })
      .catch((error) => {
        if (error && error.name === 'AbortError') return;
        console.error(error);
      });
  };

  input.addEventListener('input', () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(runLiveSearch, SEARCH_DEBOUNCE_DELAY_MS);
  });

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (debounceTimer) clearTimeout(debounceTimer);
    runLiveSearch();
  });

  clearButton.addEventListener('click', (event) => {
    event.preventDefault();
    input.value = '';
    if (debounceTimer) clearTimeout(debounceTimer);
    runLiveSearch();
    input.focus();
  });
})();
</script>

<?php render_footer(); ?>
