<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const INVOICE_TRACKER_TABLE_COLUMN_COUNT = 5;
const INVOICE_TRACKER_BASE_FILTER = "((converted_invoice_no IS NOT NULL AND converted_invoice_no <> '') OR status = 'converted')";

// ---------- CSRF ----------
if (empty($_SESSION['invoice_tracker_csrf'])) {
  $_SESSION['invoice_tracker_csrf'] = bin2hex(random_bytes(24));
}
// Make invoice_form_csrf available for email forms that POST to invoice_form.php
if (empty($_SESSION['invoice_form_csrf'])) {
  $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));
}

function invoice_tracker_format_money($value): string {
  return number_format((float)$value, 2);
}

function invoice_tracker_number(array $row, string $stamp): string {
  $existing = trim((string)($row['converted_invoice_no'] ?? ''));
  if ($existing !== '') {
    return $existing;
  }

  $quote_id = (int)($row['id'] ?? 0);
  if ($quote_id <= 0) {
    return '—';
  }

  return 'INV-' . $stamp . '-' . str_pad((string)$quote_id, 5, '0', STR_PAD_LEFT);
}

function invoice_tracker_effective_date(array $invoice): string {
  $invoice_date = trim((string)($invoice['quote_date'] ?? ''));
  if ($invoice_date !== '') {
    return $invoice_date;
  }

  return substr(trim((string)($invoice['created_at'] ?? '')), 0, 10);
}

// ---------- POST action handlers ----------
$tracker_errors = [];
$tracker_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['invoice_tracker_csrf']) || !hash_equals((string)$_SESSION['invoice_tracker_csrf'], $submitted_csrf)) {
    $tracker_errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $_SESSION['invoice_tracker_csrf'] = bin2hex(random_bytes(24));
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_email_status') {
      $inv_id       = (int)($_POST['invoice_id'] ?? 0);
      $email_status = trim((string)($_POST['email_status'] ?? ''));
      if ($inv_id <= 0) {
        $tracker_errors[] = 'Invalid invoice.';
      } elseif (!in_array($email_status, ['emailed', 'not_emailed'], true)) {
        $tracker_errors[] = 'Invalid email status.';
      } else {
        $emailed_val = $email_status === 'emailed' ? 1 : 0;
        $pdo->prepare("UPDATE quotes SET invoice_emailed = ? WHERE id = ?")->execute([$emailed_val, $inv_id]);
        $tracker_success = 'Email status updated.';
      }

    } elseif ($action === 'toggle_online_payment') {
      $inv_id      = (int)($_POST['invoice_id'] ?? 0);
      $new_val_str = trim((string)($_POST['online_payment'] ?? ''));
      $new_val     = $new_val_str === '1' ? 1 : 0;
      if ($inv_id <= 0) {
        $tracker_errors[] = 'Invalid invoice.';
      } else {
        $pdo->prepare("UPDATE quotes SET enable_online_payment = ? WHERE id = ?")->execute([$new_val, $inv_id]);
        $tracker_success = 'Online payment setting updated.';
      }

    } elseif ($action === 'delete_invoice') {
      $inv_id = (int)($_POST['invoice_id'] ?? 0);
      if ($inv_id <= 0) {
        $tracker_errors[] = 'Invalid invoice.';
      } else {
        // Cascade-delete quote_items first, then the quote itself
        $pdo->prepare("DELETE FROM quote_items WHERE quote_id = ?")->execute([$inv_id]);
        $pdo->prepare("DELETE FROM quotes WHERE id = ?")->execute([$inv_id]);
        header('Location: invoice_tracker.php?deleted=1');
        exit;
      }
    }

    // After POST, redirect to avoid form re-submission
    if (empty($tracker_errors)) {
      header('Location: invoice_tracker.php?saved=1');
      exit;
    }
  }
}

$now = new DateTime('now', new DateTimeZone(APP_TZ));
$invoice_number_stamp = $now->format('Ymd');
$current_month = $now->format('Y-m');

$search = trim((string)($_GET['q'] ?? ''));
$status_filter = trim((string)($_GET['status'] ?? ''));
$invoice_statuses = [
  'converted' => 'Converted',
  'sent' => 'Sent',
  'draft' => 'Draft',
];

$where_parts = [INVOICE_TRACKER_BASE_FILTER];
$params = [];

if ($search !== '') {
  $where_parts[] = "(customer_name LIKE :q OR company_name LIKE :q OR converted_invoice_no LIKE :q)";
  $params[':q'] = '%' . $search . '%';
}

if ($status_filter !== '' && isset($invoice_statuses[$status_filter])) {
  $where_parts[] = "status = :status";
  $params[':status'] = $status_filter;
}

$stmt = $pdo->prepare(
  "SELECT id, customer_name, company_name, quote_date, subtotal_amount, status, converted_invoice_no,
          enable_online_payment, invoice_emailed, created_at
   FROM quotes
   WHERE " . implode(' AND ', $where_parts) . "
   ORDER BY created_at DESC, id DESC
   LIMIT 300"
);
foreach ($params as $key => $value) {
  $stmt->bindValue($key, $value);
}
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hero_total_invoices = count($invoices);
$hero_month_invoices = 0;
$hero_total_billed = 0.0;
$hero_customer_keys = [];
foreach ($invoices as $invoice) {
  $hero_total_billed += (float)($invoice['subtotal_amount'] ?? 0);

  $hero_date = invoice_tracker_effective_date($invoice);
  if ($hero_date !== '' && substr($hero_date, 0, 7) === $current_month) {
    $hero_month_invoices++;
  }

  $customer_name = trim((string)($invoice['customer_name'] ?? ''));
  $company_name = trim((string)($invoice['company_name'] ?? ''));
  $customer_key = strtolower($customer_name . '|' . $company_name);
  if ($customer_key !== '|') {
    $hero_customer_keys[$customer_key] = true;
  }
}
$hero_unique_customers = count($hero_customer_keys);

render_header('Invoice Tracker');
?>

<?php if (($_GET['success'] ?? '') === 'created'): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Invoice saved successfully.</div>
<?php endif; ?>

<?php if (($_GET['saved'] ?? '') === '1'): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Changes saved.</div>
<?php endif; ?>

<?php if (($_GET['deleted'] ?? '') === '1'): ?>
  <div class="alert" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">Invoice deleted.</div>
<?php endif; ?>

<?php if (($_GET['email_sent'] ?? '') !== ''): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Invoice emailed successfully.</div>
<?php endif; ?>

<?php if (($_GET['email_error'] ?? '') !== ''): ?>
  <div class="alert" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">Failed to send invoice email: <?= h(urldecode((string)$_GET['email_error'])) ?></div>
<?php endif; ?>

<?php foreach ($tracker_errors as $err): ?>
  <div class="alert" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;"><?= h($err) ?></div>
<?php endforeach; ?>

<?php if ($tracker_success !== ''): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;"><?= h($tracker_success) ?></div>
<?php endif; ?>

<div class="card laser-rfq-hero page-header">
  <div class="laser-rfq-hero-beams" aria-hidden="true">
    <span class="laser-rfq-beam laser-rfq-beam-1"></span>
    <span class="laser-rfq-beam laser-rfq-beam-2"></span>
    <span class="laser-rfq-beam laser-rfq-beam-3"></span>
  </div>
  <div class="laser-rfq-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body laser-rfq-hero-body">
    <span class="laser-rfq-hero-tag">💳 Billing Operations Hub</span>
    <h1>Invoice Tracking Dashboard <span class="laser-rfq-hero-count">(<?= (int)$hero_total_invoices ?>)</span></h1>
    <p class="muted">Track converted quotes, review customer billing details, and jump straight into invoice view or edit workflows.</p>
    <ul class="laser-rfq-hero-pills" aria-label="Invoice tracker highlights">
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🧾</span> Clean invoice registry</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">👀</span> Fast invoice review</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">✏️</span> One-click edits</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">💼</span> Customer billing context</li>
    </ul>
    <div class="laser-rfq-hero-stats" aria-label="Invoice summary">
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_total_invoices ?></strong>
        <span>Total Invoices</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_month_invoices ?></strong>
        <span>This Month</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong>$<?= h(invoice_tracker_format_money($hero_total_billed)) ?></strong>
        <span>Total Billed</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_unique_customers ?></strong>
        <span>Customers</span>
      </div>
    </div>
  </div>
  <div class="laser-rfq-hero-actions">
    <a class="btn primary" href="invoice_form.php">+ New Invoice</a>
  </div>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <div style="flex:1 1 320px;">
      <label for="invoice_tracker_search">Search Invoices</label>
      <input
        id="invoice_tracker_search"
        type="text"
        name="q"
        value="<?= h($search) ?>"
        placeholder="Search invoice #, customer, or company..."
      />
    </div>
    <div style="width:220px;">
      <label for="invoice_tracker_status">Status</label>
      <select id="invoice_tracker_status" name="status">
        <option value="">All statuses</option>
        <?php foreach ($invoice_statuses as $status_value => $status_label): ?>
          <option value="<?= h($status_value) ?>" <?= $status_filter === $status_value ? 'selected' : '' ?>><?= h($status_label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <button type="submit" class="btn primary">Filter</button>
      <a class="btn" href="invoice_tracker.php">Clear</a>
    </div>
  </form>
</div>

<style>
/* Toggle switch */
.it-toggle-wrap { display:flex; align-items:center; gap:8px; }
.it-toggle { position:relative; display:inline-block; width:40px; height:22px; flex-shrink:0; }
.it-toggle input { opacity:0; width:0; height:0; }
.it-toggle-slider {
  position:absolute; cursor:pointer; inset:0;
  background:#cbd5e1; border-radius:22px; transition:background .2s;
}
.it-toggle-slider:before {
  content:''; position:absolute;
  width:16px; height:16px; left:3px; bottom:3px;
  background:#fff; border-radius:50%; transition:transform .2s;
}
.it-toggle input:checked + .it-toggle-slider { background:#2563eb; }
.it-toggle input:checked + .it-toggle-slider:before { transform:translateX(18px); }
.it-toggle-label { font-size:0.82em; font-weight:600; color:#64748b; white-space:nowrap; }
.it-toggle input:checked ~ .it-toggle-label { color:#1d4ed8; }

/* Action button cluster */
.it-actions { display:flex; flex-wrap:wrap; gap:4px; }
.it-actions .btn { font-size:0.78em; padding:3px 8px; white-space:nowrap; }
.it-actions .btn-danger { background:#fef2f2; color:#991b1b; border-color:#fecaca; }
.it-actions .btn-danger:hover { background:#fee2e2; }

/* Email status cell */
.it-email-cell { display:flex; align-items:center; gap:6px; flex-wrap:nowrap; }
.it-email-cell select { font-size:0.82em; padding:3px 6px; width:auto; min-width:110px; }
.it-email-cell .btn { font-size:0.78em; padding:3px 8px; white-space:nowrap; }
</style>

<div class="card">
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:900px;">
      <thead>
        <tr>
          <th>#</th>
          <th>Invoice</th>
          <th class="col-status">Email Status</th>
          <th>Online Payment</th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$invoices): ?>
          <tr><td colspan="<?= INVOICE_TRACKER_TABLE_COLUMN_COUNT ?>" class="muted">No invoices found.</td></tr>
        <?php endif; ?>

        <?php foreach ($invoices as $invoice): ?>
          <?php
            $inv_id         = (int)$invoice['id'];
            $customer_name  = trim((string)($invoice['customer_name'] ?? ''));
            $company_name   = trim((string)($invoice['company_name'] ?? ''));
            $customer_display = $customer_name !== '' ? $customer_name : '—';
            $invoice_date   = invoice_tracker_effective_date($invoice);
            $is_emailed     = (int)($invoice['invoice_emailed'] ?? 0) === 1;
            $online_payment = (int)($invoice['enable_online_payment'] ?? 0) === 1;
          ?>
          <tr>
            <td class="muted"><?= $inv_id ?></td>
            <td>
              <strong><?= h(invoice_tracker_number($invoice, $invoice_number_stamp)) ?></strong><br>
              <span class="muted">
                <?= h($customer_display) ?>
                <?php if ($company_name !== ''): ?>
                  · <?= h($company_name) ?>
                <?php endif; ?>
                <br>
                Date: <?= h($invoice_date !== '' ? fmt_date_mdY($invoice_date) : '—') ?> · Total: $<?= h(invoice_tracker_format_money($invoice['subtotal_amount'] ?? 0)) ?>
              </span>
            </td>

            <!-- Email Status: dropdown + Save -->
            <td class="col-status">
              <form method="post" action="invoice_tracker.php" class="it-email-cell">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_tracker_csrf']) ?>" />
                <input type="hidden" name="action" value="save_email_status" />
                <input type="hidden" name="invoice_id" value="<?= $inv_id ?>" />
                <select name="email_status" aria-label="Email status for invoice <?= $inv_id ?>">
                  <option value="emailed"     <?= $is_emailed ? 'selected' : '' ?>>Emailed</option>
                  <option value="not_emailed" <?= !$is_emailed ? 'selected' : '' ?>>Not Emailed</option>
                </select>
                <button type="submit" class="btn">Save</button>
              </form>
            </td>

            <!-- Online Payment toggle -->
            <td>
              <form method="post" action="invoice_tracker.php">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_tracker_csrf']) ?>" />
                <input type="hidden" name="action" value="toggle_online_payment" />
                <input type="hidden" name="invoice_id" value="<?= $inv_id ?>" />
                <input type="hidden" name="online_payment" value="<?= $online_payment ? '0' : '1' ?>" />
                <div class="it-toggle-wrap">
                  <label class="it-toggle" title="<?= $online_payment ? 'Disable' : 'Enable' ?> online payment">
                    <input
                      type="checkbox"
                      <?= $online_payment ? 'checked' : '' ?>
                      onchange="this.closest('form').submit()"
                      aria-label="<?= $online_payment ? 'Online payment enabled' : 'Online payment disabled' ?>"
                    />
                    <span class="it-toggle-slider"></span>
                  </label>
                  <span class="it-toggle-label"><?= $online_payment ? 'Enabled' : 'Disabled' ?></span>
                </div>
              </form>
            </td>

            <!-- Actions -->
            <td class="col-actions">
              <div class="it-actions">
                <a class="btn" href="invoice_form.php?id=<?= $inv_id ?>&mode=view">View</a>
                <a class="btn" href="invoice_form.php?id=<?= $inv_id ?>">Edit</a>

                <!-- Email Invoice: POST to invoice_form.php using shared CSRF -->
                <form method="post" action="invoice_form.php" style="display:contents;">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
                  <input type="hidden" name="action" value="send_email" />
                  <input type="hidden" name="row_id" value="<?= $inv_id ?>" />
                  <input type="hidden" name="return_to" value="tracker" />
                  <button type="submit" class="btn">Email Invoice</button>
                </form>

                <a class="btn" href="quotes.php?view=id&id=<?= $inv_id ?>">Go back to Quote</a>

                <!-- Delete -->
                <form method="post" action="invoice_tracker.php" style="display:contents;"
                      onsubmit="return confirm('Delete this invoice permanently? This cannot be undone.');">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_tracker_csrf']) ?>" />
                  <input type="hidden" name="action" value="delete_invoice" />
                  <input type="hidden" name="invoice_id" value="<?= $inv_id ?>" />
                  <button type="submit" class="btn btn-danger">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer();

