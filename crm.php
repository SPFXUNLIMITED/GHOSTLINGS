<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (empty($_SESSION['followup_log_csrf'])) {
  $_SESSION['followup_log_csrf'] = bin2hex(random_bytes(24));
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$errors  = [];
$success = '';

// ── AJAX: log a contact entry ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_contact') {
  header('Content-Type: application/json; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');

  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['followup_log_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']);
    exit;
  }

  $customer_id   = (int)($_POST['customer_id'] ?? 0);
  $contact_type  = (string)($_POST['contact_type'] ?? 'note');
  $notes         = trim((string)($_POST['notes'] ?? ''));

  if ($customer_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid customer.']);
    exit;
  }
  if (!in_array($contact_type, ['call', 'email', 'note'], true)) {
    $contact_type = 'note';
  }

  $tz  = new DateTimeZone(APP_TZ);
  $now = (new DateTime('now', $tz))->format('Y-m-d H:i:s');

  $stmt = $pdo->prepare("
    INSERT INTO contacts_log (customer_id, contact_type, notes, logged_by, logged_at, created_at)
    VALUES (?, ?, ?, ?, ?, ?)
  ");
  $stmt->execute([
    $customer_id,
    $contact_type,
    $notes !== '' ? $notes : null,
    $user_id > 0 ? $user_id : null,
    $now,
    $now,
  ]);

  $_SESSION['followup_log_csrf'] = bin2hex(random_bytes(24));

  echo json_encode(['ok' => true, 'new_csrf' => $_SESSION['followup_log_csrf']]);
  exit;
}

// ── AJAX: toggle followup_flagged ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_flag') {
  header('Content-Type: application/json; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');

  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['followup_log_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']);
    exit;
  }

  $customer_id = (int)($_POST['customer_id'] ?? 0);
  $flag_value  = (int)($_POST['flag_value']  ?? 1); // 1 = add, 0 = remove
  $flag_value  = $flag_value ? 1 : 0;

  if ($customer_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid customer.']);
    exit;
  }

  $stmt = $pdo->prepare("UPDATE customers SET followup_flagged = ? WHERE id = ?");
  $stmt->execute([$flag_value, $customer_id]);

  $_SESSION['followup_log_csrf'] = bin2hex(random_bytes(24));

  echo json_encode(['ok' => true, 'new_csrf' => $_SESSION['followup_log_csrf'], 'flagged' => (bool)$flag_value]);
  exit;
}

// ── AJAX: search all customers (for "Add to CRM") ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['customer_search']) && $_GET['customer_search'] === '1') {
  header('Content-Type: application/json; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');

  $cs_query = trim((string)($_GET['q'] ?? ''));
  if ($cs_query === '') {
    echo json_encode(['html' => '']);
    exit;
  }

  $cs_escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $cs_query);
  $cs_like    = '%' . $cs_escaped . '%';

  $cs_stmt = $pdo->prepare("
    SELECT
      c.id,
      c.first_name,
      c.last_name,
      c.company,
      c.phone,
      c.email,
      c.followup_flagged
    FROM customers c
    WHERE (
      c.first_name LIKE :q ESCAPE '!'
      OR c.last_name LIKE :q ESCAPE '!'
      OR CONCAT(c.first_name,' ',c.last_name) LIKE :q ESCAPE '!'
      OR c.company LIKE :q ESCAPE '!'
    )
    ORDER BY c.last_name, c.first_name, c.company
    LIMIT 50
  ");
  $cs_stmt->bindValue(':q', $cs_like, PDO::PARAM_STR);
  $cs_stmt->execute();
  $cs_rows = $cs_stmt->fetchAll(PDO::FETCH_ASSOC);

  ob_start();
  foreach ($cs_rows as $cs_row):
    $cs_name = trim((string)$cs_row['first_name'] . ' ' . (string)$cs_row['last_name']);
    $cs_is_flagged = !empty($cs_row['followup_flagged']);
    // Check if already in CRM via completed service requests
    $chk = $pdo->prepare("SELECT 1 FROM service_requests WHERE customer_id = ? AND request_status = 'completed' LIMIT 1");
    $chk->execute([$cs_row['id']]);
    $has_completed = (bool)$chk->fetchColumn();
    $already_in_crm = $cs_is_flagged || $has_completed;
    ?>
    <tr>
      <td>
        <a href="customer_details.php?id=<?= (int)$cs_row['id'] ?>">
          <?= h($cs_name !== '' ? $cs_name : '(no name)') ?>
        </a>
      </td>
      <td><?= h((string)$cs_row['company']) ?></td>
      <td><?= h((string)$cs_row['phone']) ?></td>
      <td><?= h((string)$cs_row['email']) ?></td>
      <td>
        <?php if ($already_in_crm): ?>
          <span style="color:#166534; font-weight:600;">✓ In CRM</span>
        <?php else: ?>
          <button
            type="button"
            class="btn add-to-crm-btn"
            data-customer-id="<?= (int)$cs_row['id'] ?>"
            data-customer-name="<?= h($cs_name !== '' ? $cs_name : (string)$cs_row['company']) ?>"
          >+ Add to CRM</button>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach;
  $html = ob_get_clean();

  echo json_encode(['html' => $html]);
  exit;
}

// ── AJAX: live search – return JSON for table rows ───────────────────────────
$is_live_search = isset($_GET['live_search']) && $_GET['live_search'] === '1';

// ── Build the follow-up customer query ───────────────────────────────────────
// Includes: customers with ≥1 completed service_request OR manually flagged.
$search = trim((string)($_GET['q'] ?? ''));

$search_sql   = '';
$search_binds = [];
if ($search !== '') {
  $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
  $like     = '%' . $escaped . '%';
  $search_sql   = " AND (c.first_name LIKE :q ESCAPE '!' OR c.last_name LIKE :q ESCAPE '!'
                         OR CONCAT(c.first_name,' ',c.last_name) LIKE :q ESCAPE '!'
                         OR c.company LIKE :q ESCAPE '!')";
  $search_binds = [':q' => $like];
}

$query_sql = "
  SELECT
    c.id,
    c.first_name,
    c.last_name,
    c.company,
    c.phone,
    c.email,
    c.followup_flagged,
    MAX(COALESCE(sr.completed_at, sr.updated_at)) AS last_service_date,
    (
      SELECT MAX(cl.logged_at)
      FROM contacts_log cl
      WHERE cl.customer_id = c.id
    ) AS last_contact_date,
    (
      SELECT cl2.contact_type
      FROM contacts_log cl2
      WHERE cl2.customer_id = c.id
      ORDER BY cl2.logged_at DESC
      LIMIT 1
    ) AS last_contact_type
  FROM customers c
  LEFT JOIN service_requests sr
    ON sr.customer_id = c.id
    AND sr.request_status = 'completed'
  WHERE (
    c.followup_flagged = 1
    OR EXISTS (
      SELECT 1 FROM service_requests sr2
      WHERE sr2.customer_id = c.id AND sr2.request_status = 'completed'
    )
  )
  $search_sql
  GROUP BY
    c.id, c.first_name, c.last_name, c.company, c.phone, c.email, c.followup_flagged
  ORDER BY
    last_contact_date IS NOT NULL ASC,
    last_contact_date ASC,
    last_service_date DESC
";

$stmt = $pdo->prepare($query_sql);
foreach ($search_binds as $key => $val) {
  $stmt->bindValue($key, $val, PDO::PARAM_STR);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compute days since last contact for each row
$today = new DateTime('today', new DateTimeZone(APP_TZ));
foreach ($rows as &$row) {
  if ($row['last_contact_date'] !== null) {
    $lc = new DateTime($row['last_contact_date'], new DateTimeZone(APP_TZ));
    $lc->setTime(0, 0, 0);
    $row['days_since_contact'] = (int)$today->diff($lc)->days;
  } else {
    $row['days_since_contact'] = null;
  }
}
unset($row);

// ── Live search response ──────────────────────────────────────────────────────
if ($is_live_search) {
  header('Content-Type: application/json; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');
  ob_start();
  render_followup_table_rows($rows);
  $rows_html = ob_get_clean();
  echo json_encode(['tableRowsHtml' => $rows_html]);
  exit;
}

// ── Helper: render table rows (used for both full page and live search) ───────
function render_followup_table_rows(array $rows): void {
  $today = new DateTime('today', new DateTimeZone(APP_TZ));
  foreach ($rows as $row):
    $days = $row['days_since_contact'];

    if ($days === null) {
      $row_style    = 'background:#fef2f2;';
      $status_label = 'Never Contacted';
      $status_color = '#dc2626';
    } elseif ($days > 365) {
      $row_style    = 'background:#fef2f2;';
      $status_label = 'Overdue';
      $status_color = '#dc2626';
    } elseif ($days >= 180) {
      $row_style    = 'background:#fefce8;';
      $status_label = 'Due Soon';
      $status_color = '#b45309';
    } else {
      $row_style    = 'background:#f0fdf4;';
      $status_label = 'OK';
      $status_color = '#166534';
    }

    $full_name   = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
    $lsd_display = $row['last_service_date'] !== null ? fmt_date_mdY(substr($row['last_service_date'], 0, 10)) : '—';
    $lcd_display = $row['last_contact_date']  !== null ? fmt_date_mdY(substr($row['last_contact_date'],  0, 10)) : '—';
    $days_display = $days !== null ? $days : '—';
    $is_flagged   = !empty($row['followup_flagged']);
    ?>
      <tr style="<?= h($row_style) ?>">
        <td>
          <a href="customer_details.php?id=<?= (int)$row['id'] ?>">
            <?= h($full_name !== '' ? $full_name : '(no name)') ?>
          </a>
          <?php if ($is_flagged): ?>
            <span title="Manually added to follow-up" style="color:#b45309; font-size:.8em;">★ flagged</span>
          <?php endif; ?>
        </td>
        <td><?= h($row['company']) ?></td>
        <td><?= h($row['phone']) ?></td>
        <td>
          <?php if (trim((string)$row['email']) !== ''): ?>
            <a href="mailto:<?= h($row['email']) ?>"><?= h($row['email']) ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td><?= h($lsd_display) ?></td>
        <td><?= h($lcd_display) ?></td>
        <td><?= h((string)$days_display) ?></td>
        <td>
          <strong style="color:<?= h($status_color) ?>;"><?= h($status_label) ?></strong>
        </td>
        <td>
          <button
            type="button"
            class="btn log-contact-btn"
            data-customer-id="<?= (int)$row['id'] ?>"
            data-customer-name="<?= h($full_name !== '' ? $full_name : (string)$row['company']) ?>"
          >Log Contact</button>
          <?php if ($is_flagged): ?>
            <button
              type="button"
              class="btn remove-flag-btn"
              data-customer-id="<?= (int)$row['id'] ?>"
              style="margin-top:4px;"
            >Remove Flag</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php
  endforeach;
}

// Compute days since last contact for each row
$today = new DateTime('today', new DateTimeZone(APP_TZ));
foreach ($rows as &$row) {
  if ($row['last_contact_date'] !== null) {
    $lc = new DateTime($row['last_contact_date'], new DateTimeZone(APP_TZ));
    $lc->setTime(0, 0, 0);
    $row['days_since_contact'] = (int)$today->diff($lc)->days;
  } else {
    $row['days_since_contact'] = null; // never contacted
  }
}
unset($row);

render_header('CRM');
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1>Customer Relationship Management</h1>
    <p class="muted">Customers with at least one completed service order or manually flagged for follow-up. Sorted by days since last contact (oldest first).</p>
  </div>
</div>

<div class="card">
  <form id="crm-search-form" method="get" action="crm.php" class="row" style="margin-bottom:4px;" role="search">
    <input
      id="crm-search-input"
      type="text"
      name="q"
      value="<?= h($search) ?>"
      placeholder="Filter CRM list by name or company…"
      aria-label="Filter CRM list by name or company"
      style="max-width:360px;"
    />
    <button type="submit" class="btn">Search</button>
    <a
      id="crm-search-clear"
      class="btn"
      href="crm.php"
      <?= $search === '' ? 'style="display:none;"' : '' ?>
    >Clear</a>
  </form>
</div>

<div class="card">
  <h2 style="margin:0 0 12px; font-size:1.1em;">Add Customer to CRM</h2>
  <div class="row" style="margin-bottom:8px;">
    <input
      id="add-crm-search-input"
      type="text"
      placeholder="Search customers by name or company…"
      aria-label="Search customers to add to CRM"
      style="max-width:360px;"
    />
    <span id="add-crm-searching" style="display:none; color:#6b7280; font-size:.9em;">Searching…</span>
  </div>
  <div id="add-crm-results" style="display:none; overflow-x:auto;">
    <table>
      <thead>
        <tr>
          <th>Customer</th>
          <th>Company</th>
          <th>Phone</th>
          <th>Email</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="add-crm-results-body"></tbody>
    </table>
  </div>
  <p id="add-crm-empty" style="display:none;" class="muted">No customers found.</p>
</div>

<div id="crm-table-wrap" class="card" style="padding:0; overflow-x:auto;">
  <?php if (!$rows): ?>
    <p id="crm-no-results" class="muted" style="padding:16px;">No customers with completed service orders found.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Customer</th>
        <th>Company</th>
        <th>Phone</th>
        <th>Email</th>
        <th>Last Service Date</th>
        <th>Last Contact Date</th>
        <th>Days Since Contact</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="crm-table-body"><?php render_followup_table_rows($rows); ?></tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ── Log Contact Modal ───────────────────────────────────────────────────── -->
<div id="log-contact-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:8px; padding:28px 32px; width:100%; max-width:480px; box-shadow:0 8px 32px rgba(0,0,0,.2); position:relative;">
    <h2 id="log-contact-title" style="margin:0 0 18px;">Log Contact</h2>
    <form id="log-contact-form">
      <input type="hidden" id="log-customer-id" name="customer_id" value="" />
      <input type="hidden" name="action" value="log_contact" />
      <input type="hidden" id="log-csrf" name="csrf_token" value="<?= h($_SESSION['followup_log_csrf']) ?>" />

      <div style="margin-bottom:14px;">
        <label for="log-contact-type" style="display:block; font-weight:600; margin-bottom:6px;">Contact Type</label>
        <select id="log-contact-type" name="contact_type" style="width:100%;">
          <option value="call">📞 Phone Call</option>
          <option value="email">✉️ Email</option>
          <option value="note">📝 Note</option>
        </select>
      </div>

      <div style="margin-bottom:18px;">
        <label for="log-notes" style="display:block; font-weight:600; margin-bottom:6px;">Notes <span class="muted" style="font-weight:400;">(optional)</span></label>
        <textarea id="log-notes" name="notes" rows="4" style="width:100%; resize:vertical;" placeholder="Summary of the conversation…"></textarea>
      </div>

      <div id="log-contact-error" class="alert error" style="display:none; margin-bottom:14px;"></div>

      <div class="row" style="gap:10px;">
        <button type="submit" class="btn primary" id="log-submit-btn">Save Log Entry</button>
        <button type="button" class="btn" id="log-cancel-btn">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var overlay   = document.getElementById('log-contact-overlay');
  var title     = document.getElementById('log-contact-title');
  var custInput = document.getElementById('log-customer-id');
  var csrfInput = document.getElementById('log-csrf');
  var form      = document.getElementById('log-contact-form');
  var errBox    = document.getElementById('log-contact-error');
  var submitBtn = document.getElementById('log-submit-btn');
  var cancelBtn = document.getElementById('log-cancel-btn');
  var notesArea = document.getElementById('log-notes');
  var typeSelect= document.getElementById('log-contact-type');

  function openModal(customerId, customerName) {
    custInput.value = customerId;
    title.textContent = 'Log Contact — ' + customerName;
    notesArea.value = '';
    typeSelect.value = 'call';
    errBox.style.display = 'none';
    errBox.textContent = '';
    submitBtn.disabled = false;
    submitBtn.textContent = 'Save Log Entry';
    overlay.style.display = 'flex';
    typeSelect.focus();
  }

  function closeModal() {
    overlay.style.display = 'none';
  }

  document.querySelectorAll('.log-contact-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openModal(btn.dataset.customerId, btn.dataset.customerName);
    });
  });

  cancelBtn.addEventListener('click', closeModal);

  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeModal();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay.style.display === 'flex') closeModal();
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    errBox.style.display = 'none';
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving…';

    var data = new FormData(form);

    fetch('crm.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: data
    })
    .then(function (resp) {
      return resp.json().then(function (json) {
        return { ok: resp.ok, json: json };
      });
    })
    .then(function (result) {
      if (result.json && result.json.ok) {
        // Update CSRF token for next submission
        if (result.json.new_csrf) {
          csrfInput.value = result.json.new_csrf;
        }
        closeModal();
        // Reload page to refresh contact dates
        window.location.reload();
      } else {
        var msg = (result.json && result.json.error) ? result.json.error : 'An error occurred. Please try again.';
        errBox.textContent = msg;
        errBox.style.display = '';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Save Log Entry';
      }
    })
    .catch(function () {
      errBox.textContent = 'Network error. Please try again.';
      errBox.style.display = '';
      submitBtn.disabled = false;
      submitBtn.textContent = 'Save Log Entry';
    });
  });
})();

// ── CRM table live search ─────────────────────────────────────────────────────
(function () {
  var crmForm      = document.getElementById('crm-search-form');
  var crmInput     = document.getElementById('crm-search-input');
  var crmClear     = document.getElementById('crm-search-clear');
  var crmTableBody = document.getElementById('crm-table-body');
  var csrfInput    = document.getElementById('log-csrf');

  if (!crmForm || !crmInput) return;

  var DEBOUNCE = 250;
  var timer = null;
  var controller = null;
  var lastQuery = crmInput.value.trim();

  function updateClear() {
    if (crmClear) crmClear.style.display = crmInput.value.trim() === '' ? 'none' : '';
  }

  function runSearch() {
    var q = crmInput.value.trim();
    updateClear();
    if (q === lastQuery) return;
    lastQuery = q;

    if (controller) controller.abort();
    controller = new AbortController();

    var url = new URL('crm.php', window.location.href);
    if (q !== '') url.searchParams.set('q', q);
    url.searchParams.set('live_search', '1');

    fetch(url.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      signal: controller.signal,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (resp) { return resp.json(); })
    .then(function (payload) {
      if (payload && typeof payload.tableRowsHtml === 'string' && crmTableBody) {
        crmTableBody.innerHTML = payload.tableRowsHtml;
        bindLogContactBtns();
        bindRemoveFlagBtns();
      }
      var nextUrl = new URL(window.location.href);
      if (q === '') { nextUrl.searchParams.delete('q'); } else { nextUrl.searchParams.set('q', q); }
      window.history.replaceState(null, '', nextUrl.toString());
    })
    .catch(function (err) { if (err && err.name === 'AbortError') return; });
  }

  crmInput.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(runSearch, DEBOUNCE);
  });

  crmForm.addEventListener('submit', function (e) {
    e.preventDefault();
    clearTimeout(timer);
    runSearch();
  });

  if (crmClear) {
    crmClear.addEventListener('click', function (e) {
      e.preventDefault();
      crmInput.value = '';
      clearTimeout(timer);
      runSearch();
      crmInput.focus();
    });
  }
})();

// ── Toggle flag (add/remove from CRM) + button binding ───────────────────────
function toggleFlag(customerId, flagValue, btn) {
  var csrfInput = document.getElementById('log-csrf');
  if (!csrfInput) return;

  if (btn) {
    btn.disabled = true;
    btn.textContent = flagValue ? 'Adding…' : 'Removing…';
  }

  var data = new FormData();
  data.append('action', 'toggle_flag');
  data.append('customer_id', customerId);
  data.append('flag_value', flagValue);
  data.append('csrf_token', csrfInput.value);

  fetch('crm.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: data
  })
  .then(function (resp) { return resp.json(); })
  .then(function (json) {
    if (json && json.ok) {
      if (json.new_csrf) csrfInput.value = json.new_csrf;
      if (flagValue) {
        // Reload the CRM table to show the newly added customer
        window.location.reload();
      } else {
        // Reload to remove unflagged customer (if it no longer qualifies)
        window.location.reload();
      }
    } else {
      if (btn) { btn.disabled = false; btn.textContent = flagValue ? '+ Add to CRM' : 'Remove Flag'; }
      alert((json && json.error) ? json.error : 'An error occurred.');
    }
  })
  .catch(function () {
    if (btn) { btn.disabled = false; btn.textContent = flagValue ? '+ Add to CRM' : 'Remove Flag'; }
    alert('Network error. Please try again.');
  });
}

function bindLogContactBtns() {
  document.querySelectorAll('.log-contact-btn').forEach(function (btn) {
    if (btn.dataset.bound) return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', function () {
      var overlay = document.getElementById('log-contact-overlay');
      var custInput = document.getElementById('log-customer-id');
      var title = document.getElementById('log-contact-title');
      var notesArea = document.getElementById('log-notes');
      var typeSelect = document.getElementById('log-contact-type');
      var errBox = document.getElementById('log-contact-error');
      var submitBtn = document.getElementById('log-submit-btn');
      if (!overlay || !custInput) return;
      custInput.value = btn.dataset.customerId;
      title.textContent = 'Log Contact — ' + btn.dataset.customerName;
      notesArea.value = '';
      typeSelect.value = 'call';
      errBox.style.display = 'none';
      submitBtn.disabled = false;
      submitBtn.textContent = 'Save Log Entry';
      overlay.style.display = 'flex';
      typeSelect.focus();
    });
  });
}

function bindRemoveFlagBtns() {
  document.querySelectorAll('.remove-flag-btn').forEach(function (btn) {
    if (btn.dataset.bound) return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', function () {
      toggleFlag(btn.dataset.customerId, 0, btn);
    });
  });
}

// ── Add to CRM search ─────────────────────────────────────────────────────────
(function () {
  var input      = document.getElementById('add-crm-search-input');
  var resultsDiv = document.getElementById('add-crm-results');
  var resultsBody= document.getElementById('add-crm-results-body');
  var emptyMsg   = document.getElementById('add-crm-empty');
  var searching  = document.getElementById('add-crm-searching');

  if (!input || !resultsDiv || !resultsBody) return;

  var DEBOUNCE = 250;
  var timer = null;
  var controller = null;
  var lastQuery = '';

  function runSearch() {
    var q = input.value.trim();
    if (q === lastQuery) return;
    lastQuery = q;

    if (q === '') {
      resultsDiv.style.display = 'none';
      if (emptyMsg) emptyMsg.style.display = 'none';
      if (searching) searching.style.display = 'none';
      resultsBody.innerHTML = '';
      return;
    }

    if (controller) controller.abort();
    controller = new AbortController();
    if (searching) searching.style.display = '';

    var url = new URL('crm.php', window.location.href);
    url.searchParams.set('customer_search', '1');
    url.searchParams.set('q', q);

    fetch(url.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      signal: controller.signal,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (resp) { return resp.json(); })
    .then(function (payload) {
      if (searching) searching.style.display = 'none';
      if (!payload || typeof payload.html !== 'string') return;
      resultsBody.innerHTML = payload.html;
      var hasRows = resultsBody.children.length > 0;
      resultsDiv.style.display = hasRows ? '' : 'none';
      if (emptyMsg) emptyMsg.style.display = hasRows ? 'none' : '';

      // Bind "Add to CRM" buttons
      resultsBody.querySelectorAll('.add-to-crm-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          toggleFlag(btn.dataset.customerId, 1, btn);
        });
      });
    })
    .catch(function (err) {
      if (err && err.name === 'AbortError') return;
      if (searching) searching.style.display = 'none';
    });
  }

  input.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(runSearch, DEBOUNCE);
  });
})();

// Bind remove-flag buttons on initial page load
bindRemoveFlagBtns();
</script>

<?php render_footer(); ?>
