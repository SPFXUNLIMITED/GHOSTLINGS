<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const INVOICE_TRACKER_TABLE_COLUMN_COUNT = 5;
const INVOICE_TRACKER_BASE_FILTER = "((converted_invoice_no IS NOT NULL AND converted_invoice_no <> '') OR status = 'converted')";

// ---------- AJAX: print preview ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'print_preview') {
  header('Content-Type: application/json; charset=utf-8');
  $inv_id = (int)($_GET['invoice_id'] ?? 0);
  if ($inv_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid invoice ID.']);
    exit;
  }

  $stmt = $pdo->prepare(
    "SELECT id, customer_name, company_name, quote_date, subtotal_amount, status,
            converted_invoice_no, email, notes, created_by, created_at
     FROM quotes WHERE id = ? LIMIT 1"
  );
  $stmt->execute([$inv_id]);
  $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$invoice) {
    echo json_encode(['ok' => false, 'error' => 'Invoice not found.']);
    exit;
  }

  $items_stmt = $pdo->prepare(
    "SELECT description, quantity, unit_price, line_total FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC"
  );
  $items_stmt->execute([$inv_id]);
  $invoice_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

  // Sender profile
  $sender = ['sender_name' => '', 'company_name' => '', 'address' => '', 'phone' => '', 'email' => ''];
  $created_by = isset($invoice['created_by']) && $invoice['created_by'] !== null ? (int)$invoice['created_by'] : null;
  $candidate_ids = [];
  if ($created_by !== null && $created_by > 0) $candidate_ids[] = $created_by;
  $session_uid = (int)($_SESSION['user_id'] ?? 0);
  if ($session_uid > 0 && !in_array($session_uid, $candidate_ids, true)) $candidate_ids[] = $session_uid;
  if ($candidate_ids) {
    $sp_stmt = $pdo->prepare("SELECT username, contact_name, company_name, delivery_address, contact_phone, email FROM users WHERE id = ? LIMIT 1");
    foreach ($candidate_ids as $uid) {
      $sp_stmt->execute([$uid]);
      $sp_row = $sp_stmt->fetch();
      if (!$sp_row) continue;
      $contact_name = trim((string)($sp_row['contact_name'] ?? ''));
      $username     = trim((string)($sp_row['username']     ?? ''));
      $sender['sender_name']  = $contact_name !== '' ? $contact_name : $username;
      $sender['company_name'] = trim((string)($sp_row['company_name']     ?? ''));
      $sender['address']      = trim((string)($sp_row['delivery_address'] ?? ''));
      $sender['phone']        = trim((string)($sp_row['contact_phone']    ?? ''));
      $sender['email']        = trim((string)($sp_row['email']            ?? ''));
      break;
    }
  }

  $now_stamp     = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Ymd');
  $inv_label_raw = invoice_tracker_number($invoice, $now_stamp);
  $inv_date      = invoice_tracker_effective_date($invoice);
  $customer_name = trim((string)($invoice['customer_name'] ?? ''));
  $subtotal      = number_format((float)($invoice['subtotal_amount'] ?? 0), 2);
  $notes         = trim((string)($invoice['notes'] ?? ''));
  $sender_company = $sender['company_name'] !== '' ? $sender['company_name'] : 'Our Company';
  $sender_name    = $sender['sender_name'];
  $sender_address = $sender['address'];
  $sender_phone   = $sender['phone'];
  $sender_email   = $sender['email'];

  $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

  // Helper: format address inline with given separator
  $fmt_addr = static fn(string $addr, string $sep): string =>
    (string)preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], $sep, $addr));

  // Build item rows
  $rows_html = [];
  $row_index = 0;
  foreach ($invoice_items as $item) {
    $desc       = trim((string)($item['description'] ?? ''));
    $qty        = number_format((float)($item['quantity']   ?? 0), 2);
    $unit_price = number_format((float)($item['unit_price'] ?? 0), 2);
    $line_total = number_format((float)($item['line_total'] ?? 0), 2);
    $row_bg     = ($row_index % 2 === 0) ? '#ffffff' : '#f9fafb';
    $rows_html[] = '<tr style="background:' . $row_bg . ';">'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#374151;">' . $h($desc) . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">' . $h($qty) . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">$' . $h($unit_price) . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">$' . $h($line_total) . '</td>'
      . '</tr>';
    $row_index++;
  }
  if (!$rows_html) {
    $rows_html[] = '<tr><td colspan="4" style="padding:10px 12px;text-align:center;color:#6b7280;">No line items.</td></tr>';
  }

  // Header contact line
  $header_parts = [];
  if ($sender_address !== '') $header_parts[] = $h($fmt_addr($sender_address, ' · '));
  if ($sender_phone !== '') $header_parts[] = $h($sender_phone);
  if ($sender_email !== '') $header_parts[] = '<a href="mailto:' . $h($sender_email) . '" style="color:#93c5fd;text-decoration:none;">' . $h($sender_email) . '</a>';
  $header_contact_html = implode(' &nbsp;·&nbsp; ', $header_parts);

  // Prepared-by
  $prepared_by_html = '';
  if ($sender_name !== '') {
    $prepared_by_html = 'This invoice was prepared by <strong style="color:#1e293b;">' . $h($sender_name) . '</strong>';
    if ($sender_company !== 'Our Company') {
      $prepared_by_html .= ' at <strong style="color:#1e293b;">' . $h($sender_company) . '</strong>';
    }
    $prepared_by_html .= '.';
  }

  // Footer contact line
  $footer_parts = [];
  if ($sender_address !== '') $footer_parts[] = $h($fmt_addr($sender_address, ', '));
  if ($sender_phone !== '') $footer_parts[] = $h($sender_phone);
  if ($sender_email !== '') $footer_parts[] = '<a href="mailto:' . $h($sender_email) . '" style="color:#93c5fd;text-decoration:none;">' . $h($sender_email) . '</a>';
  $footer_contact_html = implode(' &nbsp;·&nbsp; ', $footer_parts);

  $inv_label = $h($inv_label_raw);

  $preview_html =
    '<div style="max-width:680px;margin:0 auto;">'

    // ── Header banner ──
    . '<div style="background:#1e3a5f;border-radius:8px 8px 0 0;padding:28px 32px 24px;">'
      . '<p style="margin:0 0 6px;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.3px;">' . $h($sender_company) . '</p>'
      . ($header_contact_html !== '' ? '<p style="margin:0;font-size:13px;color:#93c5fd;line-height:1.6;">' . $header_contact_html . '</p>' : '')
    . '</div>'

    // ── Document title strip ──
    . '<div style="background:#ffffff;padding:20px 32px 0;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
      . '<table style="width:100%;border-collapse:collapse;">'
        . '<tr>'
          . '<td style="padding:0 0 16px;">'
            . '<p style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Invoice ' . $inv_label . '</p>'
          . '</td>'
          . '<td style="padding:0 0 16px;text-align:right;">'
            . '<p style="margin:0;font-size:13px;color:#64748b;">Date: ' . $h($inv_date) . '</p>'
          . '</td>'
        . '</tr>'
      . '</table>'
      . '<hr style="margin:0;border:none;border-top:2px solid #e2e8f0;">'
    . '</div>'

    // ── Body ──
    . '<div style="background:#ffffff;padding:24px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
      . '<p style="margin:0 0 8px;font-size:15px;color:#1e293b;">Hello' . ($customer_name !== '' ? ', ' . $h($customer_name) : '') . ',</p>'
      . '<p style="margin:0 0 24px;font-size:14px;color:#475569;">Please find your invoice details below. Thank you for your business.</p>'

      // Line items table
      . '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">'
        . '<thead>'
          . '<tr style="background:#f8fafc;">'
            . '<th style="padding:10px 12px;text-align:left;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;">Description</th>'
            . '<th style="padding:10px 12px;text-align:right;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;">Qty</th>'
            . '<th style="padding:10px 12px;text-align:right;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;">Unit Price</th>'
            . '<th style="padding:10px 12px;text-align:right;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;">Total</th>'
          . '</tr>'
        . '</thead>'
        . '<tbody>' . implode('', $rows_html) . '</tbody>'
        . '<tfoot>'
          . '<tr>'
            . '<td colspan="3" style="padding:14px 12px;text-align:right;font-weight:700;font-size:14px;color:#1e293b;border-top:2px solid #e2e8f0;">Subtotal:</td>'
            . '<td style="padding:14px 12px;text-align:right;font-weight:700;font-size:16px;color:#1e3a5f;border-top:2px solid #e2e8f0;">$' . $h($subtotal) . '</td>'
          . '</tr>'
        . '</tfoot>'
      . '</table>'

      . ($notes !== '' ? '<div style="margin-bottom:20px;padding:14px 16px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0;"><p style="margin:0 0 4px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Notes</p><p style="margin:0;font-size:14px;color:#475569;">' . nl2br($h($notes)) . '</p></div>' : '')

      . '<p style="margin:0;font-size:14px;color:#475569;">If you have any questions regarding this invoice, please do not hesitate to contact us.</p>'
    . '</div>'

    // ── Prepared-by strip ──
    . ($prepared_by_html !== ''
        ? '<div style="background:#f8fafc;padding:14px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">'
            . '<p style="margin:0;font-size:13px;color:#64748b;">' . $prepared_by_html . '</p>'
          . '</div>'
        : '')

    // ── Footer ──
    . '<div style="background:#1e3a5f;border-radius:0 0 8px 8px;padding:18px 32px;">'
      . '<p style="margin:0;font-size:12px;color:#93c5fd;line-height:1.6;">'
        . $h($sender_company)
        . ($footer_contact_html !== '' ? ' &nbsp;·&nbsp; ' . $footer_contact_html : '')
      . '</p>'
    . '</div>'

    . '</div>';

  echo json_encode(['ok' => true, 'html' => $preview_html, 'inv_label' => $inv_label_raw]);
  exit;
}

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
                <button type="button" class="btn it-print-btn" data-inv-id="<?= $inv_id ?>">🖨 Print</button>

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
      <div id="it-print-modal-content" style="display:none;"></div>
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
  padding:28px 28px 16px;
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

/* ---- @media print: suppress the main page entirely when printing from popup ---- */
@media print {
  body { display:none !important; }
}
</style>

<script>
(function () {
  'use strict';

  var modal         = document.getElementById('it-print-modal');
  var loadingEl     = document.getElementById('it-print-modal-loading');
  var contentEl     = document.getElementById('it-print-modal-content');
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
    contentEl.innerHTML = '';
    contentEl.style.display = 'none';
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

  // Print button — open a clean popup window containing only the invoice HTML
  printBtn.addEventListener('click', function () {
    var html = contentEl.innerHTML;
    if (!html) return;

    var popup = window.open('', '_blank', 'width=800,height=700,scrollbars=yes,resizable=yes');
    if (!popup) {
      alert('A pop-up was blocked. Please allow pop-ups for this site and try again.');
      return;
    }

    popup.document.open();
    popup.document.write(
      '<!DOCTYPE html>' +
      '<html lang="en">' +
      '<head>' +
        '<meta charset="UTF-8">' +
        '<meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<title>Invoice</title>' +
        '<style>' +
          '*, *::before, *::after { box-sizing: border-box; }' +
          'html, body { margin: 0; padding: 0; background: #fff; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #1e293b; }' +
          '@media screen { body { padding: 24px; } }' +
          '@page { margin: 15mm 12mm; }' +
          '@media print {' +
            'html, body { margin: 0; padding: 0; background: #fff; }' +
            'a { color: inherit !important; text-decoration: none !important; }' +
          '}' +
        '</style>' +
      '</head>' +
      '<body>' + html + '</body>' +
      '</html>'
    );
    popup.document.close();

    popup.onload = function () {
      popup.focus();
      popup.print();
    };
  });

  // Print buttons in table rows
  document.querySelectorAll('.it-print-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var invId = btn.getAttribute('data-inv-id');
      openModal();

      fetch('invoice_tracker.php?action=print_preview&invoice_id=' + encodeURIComponent(invId), {
        credentials: 'same-origin'
      })
        .then(function (res) {
          if (!res.ok) throw new Error('Server returned ' + res.status);
          return res.json();
        })
        .then(function (data) {
          if (!data.ok) {
            loadingEl.style.display = 'none';
            errorEl.textContent = data.error || 'Failed to load invoice.';
            errorEl.style.display = 'block';
            return;
          }
          contentEl.innerHTML = data.html;
          loadingEl.style.display = 'none';
          contentEl.style.display = 'block';
          printBtn.disabled = false;
        })
        .catch(function (err) {
          loadingEl.style.display = 'none';
          errorEl.textContent = 'Could not load invoice preview: ' + err.message;
          errorEl.style.display = 'block';
        });
    });
  });
}());
</script>

<?php render_footer();

