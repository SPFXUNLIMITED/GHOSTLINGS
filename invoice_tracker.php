<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const INVOICE_TRACKER_TABLE_COLUMN_COUNT = 7;
const INVOICE_TRACKER_BASE_FILTER = "((converted_invoice_no IS NOT NULL AND converted_invoice_no <> '') OR status = 'converted')";
// One cent tolerance for float rounding when comparing money values.
const INVOICE_TRACKER_PAYMENT_EPSILON = 0.009;

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

function invoice_approval_label(string $status): string {
  return match ($status) {
    'pending_approval' => 'Pending Approval',
    'approved' => 'Approved',
    default => 'Not Submitted',
  };
}

function invoice_approval_badge_colors(string $status): array {
  return match ($status) {
    'pending_approval' => ['#fef3c7', '#92400e'],
    'approved' => ['#dcfce7', '#166534'],
    default => ['#f1f5f9', '#475569'],
  };
}

function invoice_create_admin_approval_alerts(PDO $pdo, int $invoice_id, string $message): void {
  $admin_ids = $pdo->query("SELECT id FROM users WHERE is_admin = 1 OR role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
  if (!$admin_ids) {
    return;
  }

  $ins = $pdo->prepare("
    INSERT INTO approval_alerts (recipient_id, entity_type, entity_id, message, link_url)
    VALUES (?, 'invoice', ?, ?, ?)
  ");
  $link_url = 'invoice_form.php?id=' . $invoice_id . '&mode=view';
  foreach ($admin_ids as $admin_id_raw) {
    $admin_id = (int)$admin_id_raw;
    if ($admin_id <= 0) {
      continue;
    }
    $ins->execute([$admin_id, $invoice_id, $message, $link_url]);
  }
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
    } elseif ($action === 'send_for_approval') {
      $inv_id = (int)($_POST['invoice_id'] ?? 0);
      if ($inv_id <= 0) {
        $tracker_errors[] = 'Invalid invoice.';
      } else {
        $check = $pdo->prepare("SELECT id, customer_name FROM quotes WHERE id = ? LIMIT 1");
        $check->execute([$inv_id]);
        $check_row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$check_row) {
          $tracker_errors[] = 'Invoice not found.';
        } else {
          $pdo->prepare("UPDATE quotes SET approval_status = 'pending_approval' WHERE id = ?")->execute([$inv_id]);
          $actor = trim((string)($_SESSION['username'] ?? 'A team member'));
          $customer_name_val = trim((string)($check_row['customer_name'] ?? ''));
          $customer_bold = $customer_name_val !== '' ? ' for <strong>' . h($customer_name_val) . '</strong>' : '';
          invoice_create_admin_approval_alerts($pdo, $inv_id, h($actor) . ' sent Invoice #' . $inv_id . $customer_bold . ' for approval.');
          header('Location: invoice_tracker.php?approval_sent=1');
          exit;
        }
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
  "SELECT id, customer_name, company_name, quote_date, subtotal_amount, tax_amount, status, approval_status, converted_invoice_no,
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

// Build a set of invoice IDs that already have payments applied in invoice_credit_applications
$applied_invoice_ids = [];
if ($invoices) {
  $inv_id_list = array_map('intval', array_column($invoices, 'id'));
  $placeholders = implode(',', array_fill(0, count($inv_id_list), '?'));
  $applied_stmt = $pdo->prepare("SELECT DISTINCT quote_id FROM invoice_credit_applications WHERE quote_id IN ($placeholders)");
  $applied_stmt->execute($inv_id_list);
  foreach ($applied_stmt->fetchAll(PDO::FETCH_COLUMN) as $qid) {
    $applied_invoice_ids[(int)$qid] = true;
  }
}

$applied_payment_amounts = [];
if ($invoices) {
  $inv_id_list = array_map('intval', array_column($invoices, 'id'));
  $placeholders = implode(',', array_fill(0, count($inv_id_list), '?'));
  $payment_totals_stmt = $pdo->prepare(
    "SELECT ica.quote_id, COALESCE(SUM(ica.applied_amount), 0) AS total_applied
     FROM invoice_credit_applications ica
     WHERE ica.quote_id IN ($placeholders)
       AND EXISTS (
         SELECT 1
         FROM customer_payments cp
         WHERE cp.customer_id = ica.customer_id
       )
     GROUP BY ica.quote_id"
  );
  $payment_totals_stmt->execute($inv_id_list);
  foreach ($payment_totals_stmt->fetchAll(PDO::FETCH_ASSOC) as $payment_row) {
    $quote_id = (int)($payment_row['quote_id'] ?? 0);
    if ($quote_id <= 0) {
      continue;
    }
    $applied_payment_amounts[$quote_id] = round((float)($payment_row['total_applied'] ?? 0), 2);
  }
}

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
<?php if (($_GET['approval_sent'] ?? '') === '1'): ?>
  <div class="alert" style="border-color:#fde68a; background:#fffbeb; color:#92400e;">Invoice sent for approval.</div>
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
.it-actions .it-btn-disabled { opacity:0.45; cursor:not-allowed; }
.it-actions .btn-danger:disabled { opacity:0.45; cursor:not-allowed; }

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
          <th>Approval Status</th>
          <th>Online Payment</th>
          <th>Payment Status</th>
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
            $approval_status = (string)($invoice['approval_status'] ?? 'none');
            [$approval_bg, $approval_color] = invoice_approval_badge_colors($approval_status);
            $has_applied_payment = isset($applied_invoice_ids[$inv_id]);
            $invoice_subtotal_amount = round((float)($invoice['subtotal_amount'] ?? 0), 2);
            $invoice_tax_amount = round((float)($invoice['tax_amount'] ?? 0), 2);
            if ($invoice_tax_amount < 0) {
              error_log('invoice_tracker.php detected negative tax amount for quote #' . $inv_id . ': ' . $invoice_tax_amount);
              $invoice_tax_amount = 0.0;
            }
            $invoice_total_due = round($invoice_subtotal_amount + $invoice_tax_amount, 2);
            $invoice_paid_amount = round((float)($applied_payment_amounts[$inv_id] ?? 0), 2);
            if ($invoice_paid_amount < 0) {
              error_log('invoice_tracker.php detected negative applied payment total for quote #' . $inv_id . ': ' . $invoice_paid_amount);
              $invoice_paid_amount = 0.0;
            }
            if ($invoice_total_due <= INVOICE_TRACKER_PAYMENT_EPSILON || $invoice_paid_amount >= ($invoice_total_due - INVOICE_TRACKER_PAYMENT_EPSILON)) {
              $payment_label = 'Paid';
              $payment_bg = '#dcfce7';
              $payment_color = '#166534';
            } elseif ($invoice_paid_amount > INVOICE_TRACKER_PAYMENT_EPSILON) {
              $payment_label = 'Partially Paid';
              $payment_bg = '#ffedd5';
              $payment_color = '#c2410c';
            } else {
              $payment_label = 'Unpaid';
              $payment_bg = '#f1f5f9';
              $payment_color = '#475569';
            }
            $applied_tooltip = 'This payment has been applied to an invoice and cannot be modified';
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

            <td>
              <span style="display:inline-flex;align-items:center;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;background:<?= h($approval_bg) ?>;color:<?= h($approval_color) ?>;">
                <?= h(invoice_approval_label($approval_status)) ?>
              </span>
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

            <td>
              <span style="display:inline-flex;align-items:center;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;background:<?= h($payment_bg) ?>;color:<?= h($payment_color) ?>;">
                <?= h($payment_label) ?>
              </span>
            </td>

            <!-- Actions -->
            <td class="col-actions">
              <div class="it-actions">
                <a class="btn" href="invoice_form.php?id=<?= $inv_id ?>&mode=view">View</a>
                <?php if ($has_applied_payment): ?>
                  <a class="btn it-btn-disabled" aria-disabled="true" title="<?= h($applied_tooltip) ?>">Edit</a>
                <?php else: ?>
                  <a class="btn" href="invoice_form.php?id=<?= $inv_id ?>">Edit</a>
                <?php endif; ?>
                <form method="post" action="invoice_tracker.php" style="display:contents;">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_tracker_csrf']) ?>" />
                  <input type="hidden" name="action" value="send_for_approval" />
                  <input type="hidden" name="invoice_id" value="<?= $inv_id ?>" />
                  <button type="submit" class="btn">Send for Approval</button>
                </form>

                <a class="btn" href="quotes.php?view=id&id=<?= $inv_id ?>">Go back to Quote</a>

                <!-- Delete -->
                <form method="post" action="invoice_tracker.php" style="display:contents;"
                      onsubmit="return confirm('Delete this invoice permanently? This cannot be undone.');">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_tracker_csrf']) ?>" />
                  <input type="hidden" name="action" value="delete_invoice" />
                  <input type="hidden" name="invoice_id" value="<?= $inv_id ?>" />
                  <button type="submit" class="btn btn-danger"<?= $has_applied_payment ? ' disabled title="' . h($applied_tooltip) . '"' : '' ?>>Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== Invoice Print Modal ===== -->
<div id="it-print-modal" role="dialog" aria-modal="true" aria-labelledby="it-print-modal-title" style="display:none;">
  <div class="it-modal-backdrop"></div>
  <div class="it-modal-shell">
    <!-- Close button -->
    <button type="button" class="it-modal-close" aria-label="Close preview">&times;</button>

    <!-- Modal header -->
    <div class="it-modal-header">
      <div class="it-modal-header-icon" aria-hidden="true">🧾</div>
      <div>
        <h2 id="it-print-modal-title" class="it-modal-title">Invoice Preview</h2>
        <p class="it-modal-subtitle">Review your invoice before printing</p>
      </div>
    </div>

    <!-- Invoice content area (populated via JS) -->
    <div class="it-modal-body">
      <div id="it-print-modal-loading" class="it-modal-loading" aria-live="polite">
        <span class="it-spinner" aria-hidden="true"></span>
        Loading invoice&hellip;
      </div>
      <iframe id="it-print-modal-iframe" title="Invoice preview" style="display:none;" sandbox="allow-same-origin allow-modals allow-popups allow-popups-to-escape-sandbox"></iframe>
      <div id="it-print-modal-error" class="it-modal-error" style="display:none;" role="alert"></div>
    </div>

    <!-- Footer actions -->
    <div class="it-modal-footer">
      <button type="button" class="it-modal-cancel-btn" id="it-modal-cancel">Cancel</button>
      <button type="button" class="it-modal-print-btn" id="it-modal-print-btn" disabled>
        <span class="it-modal-print-icon" aria-hidden="true">🖨</span>
        Print Invoice
      </button>
    </div>
  </div>
</div>

<style>
/* ---- Print Modal ---- */
#it-print-modal {
  position:fixed; inset:0; z-index:9000;
}
.it-modal-backdrop {
  position:absolute; inset:0;
  background:rgba(15,23,42,0.72);
  backdrop-filter:blur(4px);
  -webkit-backdrop-filter:blur(4px);
  animation:it-fade-in .18s ease;
}
@keyframes it-fade-in { from { opacity:0; } to { opacity:1; } }
.it-modal-shell {
  position:absolute;
  top:50%; left:50%;
  transform:translate(-50%,-50%);
  width:min(760px, calc(100vw - 32px));
  max-height:calc(100vh - 48px);
  display:flex;
  flex-direction:column;
  background:#fff;
  border-radius:16px;
  box-shadow:0 32px 80px rgba(0,0,0,.4), 0 0 0 1px rgba(0,0,0,.08);
  animation:it-slide-up .22s cubic-bezier(.34,1.26,.64,1);
  overflow:hidden;
}
@keyframes it-slide-up {
  from { opacity:0; transform:translate(-50%,calc(-50% + 24px)); }
  to   { opacity:1; transform:translate(-50%,-50%); }
}
.it-modal-close {
  position:absolute; top:14px; right:16px;
  width:32px; height:32px;
  border:none; border-radius:50%;
  background:rgba(255,255,255,.15);
  color:#fff;
  font-size:20px; line-height:1;
  cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  transition:background .15s;
  z-index:1;
}
.it-modal-close:hover { background:rgba(255,255,255,.3); }
.it-modal-header {
  display:flex; align-items:center; gap:16px;
  padding:22px 28px 20px;
  background:linear-gradient(135deg,#1e3a5f 0%,#1d4ed8 100%);
  flex-shrink:0;
}
.it-modal-header-icon {
  font-size:32px; line-height:1;
  filter:drop-shadow(0 2px 6px rgba(0,0,0,.25));
}
.it-modal-title {
  margin:0 0 2px;
  font-size:20px; font-weight:700; color:#fff;
  letter-spacing:0.2px;
}
.it-modal-subtitle {
  margin:0;
  font-size:13px; color:#93c5fd;
}
.it-modal-body {
  flex:1 1 auto;
  overflow-y:auto;
  padding:20px;
  background:#f1f5f9;
}
.it-modal-loading {
  display:flex; align-items:center; gap:12px;
  justify-content:center;
  padding:48px 0;
  font-size:15px; color:#64748b;
}
.it-spinner {
  display:inline-block;
  width:22px; height:22px;
  border:3px solid #e2e8f0;
  border-top-color:#1d4ed8;
  border-radius:50%;
  animation:it-spin .7s linear infinite;
}
@keyframes it-spin { to { transform:rotate(360deg); } }
.it-modal-error {
  padding:14px 18px;
  background:#fef2f2;
  border:1px solid #fecaca;
  border-radius:8px;
  color:#991b1b;
  font-size:14px;
}
#it-print-modal-iframe {
  width:100%;
  min-height:1100px;
  border:0;
  border-radius:8px;
  background:#fff;
}
.it-modal-footer {
  display:flex; align-items:center; justify-content:flex-end;
  gap:12px;
  padding:16px 28px;
  background:#fff;
  border-top:1px solid #e2e8f0;
  flex-shrink:0;
}
.it-modal-cancel-btn {
  padding:10px 20px;
  background:#fff;
  color:#475569;
  border:1px solid #cbd5e1;
  border-radius:8px;
  font-size:14px; font-weight:600;
  cursor:pointer;
  transition:background .15s, border-color .15s;
}
.it-modal-cancel-btn:hover { background:#f8fafc; border-color:#94a3b8; }
.it-modal-print-btn {
  display:flex; align-items:center; gap:8px;
  padding:12px 28px;
  background:linear-gradient(135deg,#1d4ed8,#1e3a5f);
  color:#fff;
  border:none;
  border-radius:10px;
  font-size:15px; font-weight:700;
  cursor:pointer;
  box-shadow:0 4px 14px rgba(29,78,216,.45);
  transition:opacity .15s, box-shadow .15s, transform .1s;
  letter-spacing:0.2px;
}
.it-modal-print-btn:hover:not(:disabled) { opacity:.92; box-shadow:0 6px 20px rgba(29,78,216,.55); transform:translateY(-1px); }
.it-modal-print-btn:active:not(:disabled) { transform:translateY(0); }
.it-modal-print-btn:disabled { opacity:.5; cursor:not-allowed; box-shadow:none; }
.it-modal-print-icon { font-size:18px; }
</style>

<script>
(function () {
  'use strict';

  var modal         = document.getElementById('it-print-modal');
  var loadingEl     = document.getElementById('it-print-modal-loading');
  var iframeEl      = document.getElementById('it-print-modal-iframe');
  var errorEl       = document.getElementById('it-print-modal-error');
  var printBtn      = document.getElementById('it-modal-print-btn');
  var cancelBtns    = [
    document.getElementById('it-modal-cancel'),
    modal.querySelector('.it-modal-close')
  ];

  function openModal() {
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    modal.querySelector('.it-modal-close').focus();
  }

  function closeModal() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
    iframeEl.src = 'about:blank';
    iframeEl.style.display = 'none';
    loadingEl.style.display = 'flex';
    errorEl.style.display = 'none';
    errorEl.textContent = '';
    printBtn.disabled = true;
  }

  cancelBtns.forEach(function (btn) {
    if (btn) btn.addEventListener('click', closeModal);
  });

  // Close on backdrop click
  modal.querySelector('.it-modal-backdrop').addEventListener('click', closeModal);

  // Close on Escape
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') closeModal();
  });

  iframeEl.addEventListener('load', function () {
    if (!iframeEl.src || iframeEl.src === 'about:blank') {
      return;
    }
    loadingEl.style.display = 'none';
    errorEl.style.display = 'none';
    iframeEl.style.display = 'block';
    printBtn.disabled = false;
  });

  iframeEl.addEventListener('error', function () {
    loadingEl.style.display = 'none';
    iframeEl.style.display = 'none';
    errorEl.textContent = 'Could not load invoice preview.';
    errorEl.style.display = 'block';
    printBtn.disabled = true;
  });

  printBtn.addEventListener('click', function () {
    if (!iframeEl.src || iframeEl.src === 'about:blank') return;
    var iframeWindow = iframeEl.contentWindow;
    if (!iframeWindow) {
      errorEl.textContent = 'Invoice preview is not ready yet.';
      errorEl.style.display = 'block';
      return;
    }
    iframeWindow.focus();
    iframeWindow.print();
  });

  // Print buttons in table rows
  document.querySelectorAll('.it-print-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var invId = btn.getAttribute('data-inv-id');
      var invIdNum = Number(invId);
      openModal();
      printBtn.disabled = true;
      loadingEl.style.display = 'flex';
      errorEl.style.display = 'none';
      errorEl.textContent = '';
      iframeEl.style.display = 'none';
      if (!Number.isInteger(invIdNum) || invIdNum <= 0) {
        loadingEl.style.display = 'none';
        errorEl.textContent = 'Invalid invoice ID for preview.';
        errorEl.style.display = 'block';
        return;
      }
      iframeEl.src = 'email_preview.php?id=' + encodeURIComponent(String(invIdNum)) + '&context=invoice&_ts=' + Date.now();
    });
  });
}());
</script>

<?php render_footer();
