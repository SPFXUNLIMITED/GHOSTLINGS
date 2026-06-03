<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

// ─── Helpers (same logic as sourcing_rfq_tracker.php) ────────────────────────

function format_acquisition_purpose_submitted(array $rfq): string {
  $purpose = strtolower(trim((string)($rfq['acquisition_purpose'] ?? '')));
  $customer_name = trim((string)($rfq['buyer_name'] ?? ''));
  if ($customer_name === '') {
    $customer_name = trim((string)($rfq['contact_name'] ?? ''));
  }
  if ($purpose === 'customer' || $purpose === 'customer request') {
    return $customer_name !== '' ? ('Customer Request: ' . $customer_name) : 'Customer Request';
  }
  if ($purpose === 'internal' || $purpose === 'internal use') {
    return 'Internal Use';
  }
  if ($purpose === '') {
    return 'N/A';
  }
  return ucwords(str_replace('_', ' ', $purpose));
}

function format_po_amount_submitted($value): string {
  if ($value === null || $value === '') {
    return 'N/A';
  }
  if (is_numeric($value)) {
    return '$' . number_format((float)$value, 2);
  }
  return trim((string)$value);
}

function build_rfq_email_text_submitted(array $rfq): string {
  $sep  = str_repeat('=', 60);
  $sep2 = str_repeat('-', 60);
  $date = date('F j, Y', strtotime((string)$rfq['created_at']));
  $request_title = trim((string)($rfq['request_title'] ?? ''));
  $request_category = trim((string)($rfq['request_category'] ?? ''));
  $is_purchase_order = (strtolower($request_category) === 'po');
  $email_heading = $is_purchase_order ? 'PURCHASE ORDER (PO)' : 'REQUEST FOR QUOTATION (RFQ)';
  $request_number_label = $is_purchase_order ? 'PO #:         ' : 'RFQ #:        ';

  $contact_name  = trim((string)($rfq['contact_name']  ?? ''));
  $company_name  = trim((string)($rfq['company_name']  ?? ''));
  $contact_email = trim((string)($rfq['contact_email'] ?? ''));
  $contact_phone = trim((string)($rfq['contact_phone'] ?? ''));
  $requested_by  = trim((string)($rfq['requested_by_username'] ?? 'Unknown'));

  $lines = [
    $sep,
    $email_heading,
    $sep,
    '',
    $request_number_label . (int)$rfq['id'],
    'Date:         ' . $date,
    'Status:       ' . ucfirst(str_replace('_', ' ', trim((string)$rfq['request_status']))),
    'Acquisition:  ' . format_acquisition_purpose_submitted($rfq),
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
  } else {
    $lines[] = 'Requested By: ' . $requested_by;
  }
  if ($contact_email !== '') {
    $lines[] = 'Email:        ' . $contact_email;
  }
  if ($contact_phone !== '') {
    $lines[] = 'Phone:        ' . $contact_phone;
  }

  $is_parts_request = strtolower($request_category !== '' ? $request_category : 'machine') === 'parts';
  $lines = array_merge($lines, [
    '',
    $sep2,
    $is_parts_request ? 'PARTS REQUEST DETAILS:' : 'MACHINE SPECIFICATIONS:',
    $sep2,
    'Title:        ' . $request_title,
    'Quantity:     ' . (int)$rfq['quantity'],
  ]);

  if ($is_parts_request) {
    $lines[] = 'Part Category: ' . trim((string)($rfq['part_category'] ?? ''));
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'PART SPECS:';
    $lines[] = $sep2;
    $lines[] = trim((string)($rfq['part_specs'] ?? ''));
  } else {
    $lines[] = 'Machine Size: ' . trim((string)$rfq['machine_size']);
    $lines[] = 'Laser Watts:  ' . trim((string)$rfq['laser_watts']);
    $lines[] = 'Tube Type:    ' . trim((string)$rfq['tube_type']);
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'REQUIRED FEATURES:';
    $lines[] = $sep2;
    $lines[] = trim((string)$rfq['required_features']);
  }

  $additional_notes = trim((string)($rfq['additional_notes'] ?? ''));
  if ($additional_notes !== '') {
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'ADDITIONAL NOTES:';
    $lines[] = $sep2;
    $lines[] = $additional_notes;
  }

  if ($is_purchase_order) {
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'PURCHASE ORDER DETAILS:';
    $lines[] = $sep2;
    $lines[] = 'Supplier:      ' . trim((string)($rfq['po_supplier_info'] ?? 'N/A'));
    $lines[] = 'Unit Price:    ' . format_po_amount_submitted($rfq['po_unit_price'] ?? null);
    $lines[] = 'Line Total:    ' . format_po_amount_submitted($rfq['po_line_total'] ?? null);
    $lines[] = 'Delivery Date: ' . trim((string)($rfq['po_expected_delivery_date'] ?? 'N/A'));
    $lines[] = 'Ship Method:   ' . trim((string)($rfq['po_shipping_method'] ?? 'N/A'));
    $lines[] = 'Shipping Cost: ' . format_po_amount_submitted($rfq['po_shipping_cost'] ?? null);
    $lines[] = 'Total Amount:  ' . format_po_amount_submitted($rfq['po_total_amount'] ?? null);
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'DELIVERY ADDRESS:';
    $lines[] = $sep2;
    $lines[] = trim((string)($rfq['po_delivery_address'] ?? 'N/A'));
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'PAYMENT TERMS:';
    $lines[] = $sep2;
    $lines[] = trim((string)($rfq['po_payment_terms'] ?? 'N/A'));
  }

  $lines[] = '';
  $lines[] = $sep;
  $lines[] = 'Please reply with your best quotation at your earliest convenience.';
  $lines[] = $sep;

  return implode("\n", $lines);
}

// ─── Validate rfq_id ─────────────────────────────────────────────────────────

$rfq_id = max(0, (int)($_GET['rfq_id'] ?? 0));
if ($rfq_id === 0) {
  header('Location: sourcing_rfq_form.php');
  exit;
}

// ─── Fetch RFQ ───────────────────────────────────────────────────────────────

$stmt = $pdo->prepare(
  "SELECT r.id, r.request_category, r.request_title, r.machine_size, r.laser_watts, r.tube_type, r.part_category, r.part_specs, r.quantity,
          r.required_features, r.additional_notes, r.request_status, r.acquisition_purpose, r.buyer_name, r.created_at,
          r.contact_name, r.company_name, r.contact_email, r.contact_phone, r.po_supplier_info, r.po_unit_price, r.po_line_total,
          r.po_expected_delivery_date, r.po_delivery_address, r.po_payment_terms, r.po_shipping_method, r.po_shipping_cost, r.po_total_amount,
          u.username AS requested_by_username
   FROM rfq_requests r
   LEFT JOIN users u ON u.id = r.requested_by
   WHERE r.id = ? LIMIT 1"
);
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch();

if (!$rfq) {
  header('Location: sourcing_rfq_form.php');
  exit;
}

$request_category = trim((string)($rfq['request_category'] ?? ''));
$is_purchase_order = (strtolower($request_category) === 'po');
$page_title        = $is_purchase_order ? 'Purchase Order Submitted' : 'RFQ Submitted';
$email_text_title  = $is_purchase_order ? 'PO Email Text' : 'RFQ Email Text';
$rfq_email_text    = build_rfq_email_text_submitted($rfq);

render_header($page_title);
?>

<div class="card laser-rfq-hero page-header">
  <div>
    <h1>
      <?= $is_purchase_order ? '📋 Purchase Order (PO) Submitted' : '📋 RFQ Submitted' ?>
    </h1>
    <p class="muted">
      Your <?= $is_purchase_order ? 'Purchase Order' : 'Request for Quotation' ?> #<?= (int)$rfq['id'] ?> has been submitted.
      <?= $is_purchase_order
        ? 'Copy this text and send the Purchase Order to the supplier.'
        : 'Copy the formatted text below and paste it into your Alibaba message to request quotes from suppliers.' ?>
    </p>
  </div>
  <a class="btn" href="sourcing_rfq_tracker.php?rfq_id=<?= (int)$rfq['id'] ?>">Go to Quotes →</a>
</div>

<?php render_alibaba_workflow_banner($is_purchase_order ? 'send_po' : 'copy_rfq_text'); ?>

<div class="card">
  <h2 style="margin-top:0;"><?= h($email_text_title) ?></h2>
  <p class="muted" style="margin-top:0;">
    Click <strong>Copy to Clipboard</strong> below, then
    <?= $is_purchase_order
      ? 'send the Purchase Order directly to the supplier.'
      : 'paste the text directly into your Alibaba supplier message or email.' ?>
  </p>

  <label id="rfq_submitted_text_label" for="rfq_submitted_text">Email / message content</label>
  <textarea
    id="rfq_submitted_text"
    rows="24"
    readonly
    aria-labelledby="rfq_submitted_text_label"
    style="width:100%; font-family:monospace; font-size:13px; resize:vertical;"
  ><?= h($rfq_email_text) ?></textarea>

  <div class="row" style="margin-top:16px; gap:12px; align-items:center; flex-wrap:wrap;">
    <button
      type="button"
      class="btn"
      style="font-size:16px; padding:12px 28px; font-weight:700;"
      onclick="copyRfqSubmittedText()"
    >
      📋 Copy to Clipboard
    </button>
    <span id="rfq_submitted_copy_status" class="muted" role="status" aria-live="polite"></span>
  </div>
</div>

<div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
  <p style="margin:0;">
    <?php if ($is_purchase_order): ?>
      Next step: send this Purchase Order to the supplier, then track it in
      <a href="sourcing_rfq_tracker.php?rfq_id=<?= (int)$rfq['id'] ?>">PO #<?= (int)$rfq['id'] ?></a>.
    <?php else: ?>
      Next step: paste this text into your Alibaba supplier message, then add received quotes directly to
      <a href="sourcing_rfq_tracker.php?rfq_id=<?= (int)$rfq['id'] ?>">RFQ #<?= (int)$rfq['id'] ?></a>.
    <?php endif; ?>
  </p>
  <div class="row" style="gap:8px;">
    <a class="btn" href="sourcing_rfq_tracker.php?rfq_id=<?= (int)$rfq['id'] ?>"><?= $is_purchase_order ? 'Track Purchase Order' : 'Add Received Quotes' ?></a>
    <a class="btn" href="sourcing_rfq_form.php"><?= $is_purchase_order ? 'New PO' : 'New RFQ' ?></a>
  </div>
</div>

<script>
  function copyRfqSubmittedText() {
    const text   = document.getElementById('rfq_submitted_text').value;
    const status = document.getElementById('rfq_submitted_copy_status');
    const successMsg  = 'Copied to clipboard!';
    const failMsg     = 'Failed to copy. Please select the text and copy manually.';
    const successMs   = 3000;
    const failMs      = 5000;

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
