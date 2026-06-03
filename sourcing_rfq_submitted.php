<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

$rfq_id = max(0, (int)($_GET['rfq_id'] ?? 0));

if ($rfq_id === 0) {
  render_header('RFQ Submitted');
  echo '<div class="alert error">Invalid or missing RFQ ID.</div>';
  render_footer();
  exit;
}

$stmt = $pdo->prepare(
  "SELECT r.id, r.request_category, r.request_title, r.machine_size, r.laser_watts, r.tube_type,
          r.part_category, r.part_specs, r.quantity, r.required_features, r.additional_notes,
          r.request_status, r.acquisition_purpose, r.buyer_name, r.contact_name, r.company_name,
          r.contact_email, r.contact_phone, r.po_supplier_info, r.po_unit_price, r.po_line_total,
          r.po_expected_delivery_date, r.po_delivery_address, r.po_payment_terms,
          r.po_shipping_method, r.po_shipping_cost, r.po_total_amount, r.created_at,
          u.username AS requested_by_username
   FROM rfq_requests r
   LEFT JOIN users u ON u.id = r.requested_by
   WHERE r.id = ? LIMIT 1"
);
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch();

if (!$rfq) {
  render_header('RFQ Submitted');
  echo '<div class="alert error">RFQ not found.</div>';
  render_footer();
  exit;
}

// ── Helper functions (mirrors of the ones in sourcing_rfq_tracker.php) ──

function srfqs_format_acquisition_purpose(array $rfq): string {
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

function srfqs_infer_request_type_from_title(?string $request_title): string {
  $request_title = trim((string)$request_title);
  if (preg_match('/^\s*(PO|Purchase\s+Order)\s*:/i', $request_title) === 1) {
    return 'PO';
  }
  if (preg_match('/^\s*Sourcing\s*:/i', $request_title) === 1) {
    return 'Sourcing';
  }
  return 'RFQ';
}

function srfqs_format_po_amount($value): string {
  if ($value === null || $value === '') {
    return 'N/A';
  }
  if (is_numeric($value)) {
    return '$' . number_format((float)$value, 2);
  }
  return trim((string)$value);
}

function srfqs_build_rfq_email_text(array $rfq): string {
  $sep  = str_repeat('=', 60);
  $sep2 = str_repeat('-', 60);
  $date = date('F j, Y', strtotime((string)$rfq['created_at']));
  $request_title = trim((string)($rfq['request_title'] ?? ''));
  $is_purchase_order = srfqs_infer_request_type_from_title($request_title) === 'PO';
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
    'Acquisition:  ' . srfqs_format_acquisition_purpose($rfq),
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

  $request_category = trim((string)($rfq['request_category'] ?? 'machine'));
  $is_parts_request = $request_category === 'parts';
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
    $lines[] = 'Unit Price:    ' . srfqs_format_po_amount($rfq['po_unit_price'] ?? null);
    $lines[] = 'Line Total:    ' . srfqs_format_po_amount($rfq['po_line_total'] ?? null);
    $lines[] = 'Delivery Date: ' . trim((string)($rfq['po_expected_delivery_date'] ?? 'N/A'));
    $lines[] = 'Ship Method:   ' . trim((string)($rfq['po_shipping_method'] ?? 'N/A'));
    $lines[] = 'Shipping Cost: ' . srfqs_format_po_amount($rfq['po_shipping_cost'] ?? null);
    $lines[] = 'Total Amount:  ' . srfqs_format_po_amount($rfq['po_total_amount'] ?? null);
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

$is_po = srfqs_infer_request_type_from_title((string)($rfq['request_title'] ?? '')) === 'PO';
$rfq_text = srfqs_build_rfq_email_text($rfq);
$page_title = $is_po ? ('Purchase Order #' . $rfq_id . ' Submitted') : ('RFQ #' . $rfq_id . ' Submitted');

render_header($page_title);
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1>✅ <?= $is_po ? 'Purchase Order' : 'RFQ' ?> #<?= (int)$rfq_id ?> Submitted</h1>
    <p class="muted">Your request has been saved. Copy the text below and send it to your suppliers to begin receiving quotes.</p>
  </div>
  <a class="btn" href="sourcing_rfq_tracker.php">Sourcing RFQ Tracker →</a>
</div>

<?php render_alibaba_workflow_banner('receive_quotes'); ?>

<div class="card" style="margin-top:18px;">
  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; gap:12px; flex-wrap:wrap;">
    <h2 style="margin:0;"><?= $is_po ? 'Purchase Order (PO)' : 'RFQ' ?> Email Text</h2>
    <button id="copyBtn" type="button" class="btn primary" style="font-size:1.05rem; padding:10px 28px;">
      📋 Copy to Clipboard
    </button>
  </div>
  <pre id="rfqText" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px; white-space:pre-wrap; word-break:break-word; font-family:monospace; font-size:0.9rem; line-height:1.6; margin:0;"><?= h($rfq_text) ?></pre>
  <div id="copyFeedback" style="display:none; margin-top:10px; color:#166534; font-weight:600;">✅ Copied to clipboard!</div>
</div>

<div class="row" style="margin-top:18px;">
  <a class="btn primary" href="sourcing_rfq_tracker.php">Go to RFQ Tracker</a>
  <a class="btn" href="sourcing_rfq_form.php">Submit Another RFQ</a>
  <a class="btn" href="sourcing_rfq_form.php?edit_rfq_id=<?= (int)$rfq_id ?>">Edit This Request</a>
</div>

<script>
(function () {
  var btn = document.getElementById('copyBtn');
  var pre = document.getElementById('rfqText');
  var feedback = document.getElementById('copyFeedback');
  if (!btn || !pre) return;
  btn.addEventListener('click', function () {
    var text = pre.innerText || pre.textContent || '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        showFeedback();
      }).catch(function () {
        fallbackCopy(text);
      });
    } else {
      fallbackCopy(text);
    }
  });
  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { document.execCommand('copy'); showFeedback(); } catch (e) {}
    document.body.removeChild(ta);
  }
  function showFeedback() {
    if (!feedback) return;
    feedback.style.display = 'block';
    setTimeout(function () { feedback.style.display = 'none'; }, 3000);
  }
})();
</script>

<?php render_footer(); ?>
