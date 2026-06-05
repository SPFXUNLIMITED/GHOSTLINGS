<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

// ─── Order statuses (mirrors order_tracker.php) ───────────────────────────────

$order_statuses = [
  'create_rfq' => 'Create RFQ',
  'receive_quotes' => 'Receive Quotes',
  'evaluate_select_quote' => 'Evaluate and Select Best Quote',
  'negotiate_terms' => 'Negotiate Terms',
  'send_purchase_order' => 'Send Purchase Order',
  'vendor_accepts_po' => 'Vendor Accepts PO',
  'make_deposit_payment' => 'Make Deposit Payment',
  'vendor_produces_machine' => 'Vendor Produces Machine',
  'make_final_payment' => 'Make Final Payment',
  'vendor_ships_machine' => 'Vendor Ships Machine',
  'receive_tracking_documents' => 'Receive Tracking and Documents',
  'arrives_clears_customs' => 'Arrives and Clears Customs',
  'final_inspection_acceptance' => 'Final Inspection and Acceptance',
  'cancelled' => 'Cancelled',
];

// ─── Helpers (same logic as order_tracker.php) ───────────────────────────────

function format_order_email_currency_submitted($amount, string $currency): string {
  if ($amount === null || $amount === '') {
    return 'N/A';
  }
  if (is_numeric($amount)) {
    return $currency . ' ' . number_format((float)$amount, 2);
  }
  return trim((string)$amount);
}

function format_order_email_date_submitted($value): string {
  $value = trim((string)$value);
  if ($value === '') {
    return 'N/A';
  }
  $timestamp = strtotime($value);
  if ($timestamp === false) {
    return $value;
  }
  return date('F j, Y', $timestamp);
}

function build_order_email_text_submitted(array $order, array $order_statuses): string {
  $sep = str_repeat('=', 60);
  $sep2 = str_repeat('-', 60);
  $currency = strtoupper(trim((string)($order['currency'] ?? 'USD')));
  if ($currency === '') {
    $currency = 'USD';
  }
  $requested_by = trim((string)($order['requested_by_username'] ?? ''));
  $contact_name = trim((string)($order['contact_name'] ?? ''));
  $company_name = trim((string)($order['company_name'] ?? ''));
  $contact_email = trim((string)($order['contact_email'] ?? ''));
  $contact_phone = trim((string)($order['contact_phone'] ?? ''));
  $po_number = trim((string)($order['po_number'] ?? ''));
  if ($po_number === '') {
    $po_number = 'PO #' . (int)($order['id'] ?? 0);
  }
  $status_key = (string)($order['order_status'] ?? '');
  $status_label = (string)($order_statuses[$status_key] ?? ucwords(str_replace('_', ' ', $status_key)));
  $created_date = format_order_email_date_submitted((string)($order['order_date'] ?? $order['created_at'] ?? ''));

  $lines = [
    $sep,
    'PURCHASE ORDER (PO)',
    $sep,
    '',
    'PO #:         ' . $po_number,
    'Date:         ' . $created_date,
    'Status:       ' . $status_label,
    'RFQ #:        ' . (int)($order['rfq_request_id'] ?? 0),
    'Quote #:      ' . ((int)($order['rfq_quote_id'] ?? 0) > 0 ? (int)$order['rfq_quote_id'] : 'N/A'),
    '',
    $sep2,
    'FROM:',
    $sep2,
  ];

  if ($company_name !== '') {
    $lines[] = 'Company:      ' . $company_name;
  }
  if ($contact_name !== '') {
    $lines[] = 'Contact:      ' . $contact_name;
  } elseif ($requested_by !== '') {
    $lines[] = 'Requested By: ' . $requested_by;
  }
  if ($contact_email !== '') {
    $lines[] = 'Email:        ' . $contact_email;
  }
  if ($contact_phone !== '') {
    $lines[] = 'Phone:        ' . $contact_phone;
  }

  $lines[] = '';
  $lines[] = $sep2;
  $lines[] = 'SUPPLIER:';
  $lines[] = $sep2;
  $lines[] = 'Supplier:      ' . trim((string)($order['supplier_name'] ?? 'N/A'));

  $lines[] = '';
  $lines[] = $sep2;
  $lines[] = 'ORDER DETAILS:';
  $lines[] = $sep2;
  $lines[] = 'Request Title: ' . trim((string)($order['request_title'] ?? 'N/A'));
  $lines[] = 'Model:         ' . trim((string)($order['model_name'] ?? 'N/A'));
  $lines[] = 'SKU:           ' . trim((string)($order['sku'] ?? 'N/A'));
  $lines[] = 'Quantity:      ' . (int)($order['quantity'] ?? 0);
  $lines[] = 'Unit Price:    ' . format_order_email_currency_submitted($order['unit_price'] ?? null, $currency);
  $lines[] = 'Order Total:   ' . format_order_email_currency_submitted($order['order_total'] ?? null, $currency);

  $lines[] = '';
  $lines[] = $sep2;
  $lines[] = 'PAYMENT & SHIPPING:';
  $lines[] = $sep2;
  $lines[] = 'Payment Terms: ' . trim((string)($order['payment_terms'] ?? 'N/A'));
  $lines[] = 'Deposit:       ' . format_order_email_currency_submitted($order['deposit_amount'] ?? null, $currency);
  $lines[] = 'Balance:       ' . format_order_email_currency_submitted($order['balance_amount'] ?? null, $currency);
  $lines[] = 'Incoterm:      ' . trim((string)($order['incoterm'] ?? 'N/A'));
  $lines[] = 'Shipping:      ' . trim((string)($order['shipping_method'] ?? 'N/A'));
  $lines[] = 'Origin:        ' . trim((string)($order['shipping_origin'] ?? 'N/A'));
  $lines[] = 'Destination:   ' . trim((string)($order['destination_port'] ?? 'N/A'));
  $lines[] = 'Deliver To:    ' . trim((string)($order['destination_address'] ?? 'N/A'));
  $lines[] = 'Expected Ready: ' . format_order_email_date_submitted((string)($order['expected_ready_date'] ?? ''));
  $lines[] = 'Expected Ship: ' . format_order_email_date_submitted((string)($order['expected_ship_date'] ?? ''));
  $lines[] = 'Lead Time:     ' . ($order['production_lead_time_days'] !== null && $order['production_lead_time_days'] !== '' ? ((int)$order['production_lead_time_days'] . ' days') : 'N/A');
  $lines[] = 'Trade Assurance: ' . trim((string)($order['trade_assurance_order_no'] ?? 'N/A'));
  $lines[] = 'Proforma Invoice: ' . trim((string)($order['proforma_invoice_no'] ?? 'N/A'));

  $included_accessories = trim((string)($order['included_accessories'] ?? ''));
  if ($included_accessories !== '') {
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'INCLUDED ACCESSORIES:';
    $lines[] = $sep2;
    $lines[] = $included_accessories;
  }

  $warranty_terms = trim((string)($order['warranty_terms'] ?? ''));
  if ($warranty_terms !== '') {
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'WARRANTY TERMS:';
    $lines[] = $sep2;
    $lines[] = $warranty_terms;
  }

  $notes = trim((string)($order['notes'] ?? ''));
  if ($notes !== '') {
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'NOTES:';
    $lines[] = $sep2;
    $lines[] = $notes;
  }

  $lines[] = '';
  $lines[] = $sep;
  $lines[] = 'Please confirm receipt, pricing, and production/shipping schedule.';
  $lines[] = $sep;

  return implode("\n", $lines);
}

// ─── Validate order_id ───────────────────────────────────────────────────────

$order_id = max(0, (int)($_GET['order_id'] ?? 0));
if ($order_id === 0) {
  header('Location: order_tracker.php');
  exit;
}

// ─── Fetch order ─────────────────────────────────────────────────────────────

$stmt = $pdo->prepare(
  "SELECT o.*, r.request_title, r.contact_name, r.company_name, r.contact_email, r.contact_phone,
          u.username AS requested_by_username
   FROM rfq_orders o
   INNER JOIN rfq_requests r ON r.id = o.rfq_request_id
   LEFT JOIN users u ON u.id = r.requested_by
   WHERE o.id = ?
   LIMIT 1"
);
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
  header('Location: order_tracker.php');
  exit;
}

$order_email_text = build_order_email_text_submitted($order, $order_statuses);
$page_title = 'Purchase Order Submitted';

render_header($page_title);
?>

<div class="card laser-rfq-hero page-header">
  <div>
    <h1>📋 Purchase Order Submitted</h1>
    <p class="muted">
      Purchase Order #<?= (int)$order['id'] ?> has been submitted.
      Copy this text and send the Purchase Order to the supplier.
    </p>
  </div>
  <a class="btn" href="order_tracker.php">Go to Order Tracker →</a>
</div>

<div class="card">
  <h2 style="margin-top:0;">PO Email Text</h2>
  <p class="muted" style="margin-top:0;">
    Click <strong>Copy to Clipboard</strong> below, then send the Purchase Order directly to the supplier.
  </p>

  <label id="order_submitted_text_label" for="order_submitted_text">Email / message content</label>
  <textarea
    id="order_submitted_text"
    rows="24"
    readonly
    aria-labelledby="order_submitted_text_label"
    style="width:100%; font-family:monospace; font-size:13px; resize:vertical;"
  ><?= h($order_email_text) ?></textarea>

  <div class="row" style="margin-top:16px; gap:12px; align-items:center; flex-wrap:wrap;">
    <button
      type="button"
      class="btn"
      style="font-size:16px; padding:12px 28px; font-weight:700;"
      onclick="copyOrderSubmittedText()"
    >
      📋 Copy to Clipboard
    </button>
    <span id="order_submitted_copy_status" class="muted" role="status" aria-live="polite"></span>
  </div>
</div>

<div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
  <p style="margin:0;">
    Next step: copy the PO text and send it to the supplier, then track the order in the Order Tracker.
  </p>
  <div class="row" style="gap:8px;">
    <a class="btn" href="order_tracker.php" style="font-size:15px; padding:10px 22px; font-weight:700; background:#2563eb; color:#fff; border-color:#1d4ed8;">Go to Order Tracker →</a>
    <a class="btn" href="order_form.php">New Order</a>
  </div>
</div>

<script>
  function copyOrderSubmittedText() {
    const text   = document.getElementById('order_submitted_text').value;
    const status = document.getElementById('order_submitted_copy_status');
    const successMsg = 'Copied to clipboard!';
    const failMsg    = 'Failed to copy. Please select the text and copy manually.';
    const successMs  = 3000;
    const failMs     = 5000;

    const canUseClipboard = navigator.clipboard && typeof navigator.clipboard.writeText === 'function';
    if (!canUseClipboard) {
      status.textContent = failMsg;
      setTimeout(function () { status.textContent = ''; }, failMs);
      return;
    }

    navigator.clipboard.writeText(text).then(function () {
      status.textContent = successMsg;
      setTimeout(function () { status.textContent = ''; }, successMs);
    }, function () {
      status.textContent = failMsg;
      setTimeout(function () { status.textContent = ''; }, failMs);
    });
  }
</script>

<?php render_footer(); ?>
