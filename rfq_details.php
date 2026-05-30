<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

$request_statuses = [
  'draft' => 'Draft',
  'sourcing' => 'Sourcing',
  'quotes_received' => 'Quotes Received',
  'shortlisted' => 'Shortlisted',
  'ordered' => 'Ordered',
  'closed' => 'Closed',
];

$quote_statuses = [
  'received' => 'Received',
  'under_review' => 'Under Review',
  'negotiating' => 'Negotiating',
  'accepted' => 'Accepted',
  'rejected' => 'Rejected',
];

function format_shipping_details_for_rfq(?string $origin, ?string $method): string {
  $origin = trim((string)$origin);
  $method = trim((string)$method);
  if ($origin === '' && $method === '') {
    return '—';
  }
  if ($origin !== '' && $method !== '') {
    return $origin . ' • ' . $method;
  }
  return $origin !== '' ? $origin : $method;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  header('Location: rfq_tracker.php');
  exit;
}

$stmt = $pdo->prepare(
  "SELECT r.*,
          u.username AS requested_by_username
   FROM rfq_requests r
   LEFT JOIN users u ON u.id = r.requested_by
   WHERE r.id = ?
   LIMIT 1"
);
$stmt->execute([$id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rfq) {
  http_response_code(404);
  render_header('RFQ Not Found');
  echo '<div class="card"><p class="muted">RFQ request not found.</p><a class="btn" href="rfq_tracker.php">← Back to RFQ Tracker</a></div>';
  render_footer();
  exit;
}

$qstmt = $pdo->prepare(
  "SELECT q.*, u.username AS created_by_username
   FROM rfq_quotes q
   LEFT JOIN users u ON u.id = q.created_by
   WHERE q.rfq_request_id = ?
   ORDER BY COALESCE(q.received_on, DATE(q.created_at)) DESC, q.id DESC"
);
$qstmt->execute([$id]);
$quotes = $qstmt->fetchAll(PDO::FETCH_ASSOC);

$quote_count = count($quotes);
$lowest_quote = null;
$best_lead = null;
$lowest_shipping = null;
$currencies = [];
foreach ($quotes as $q) {
  if ($q['quote_amount'] !== null) {
    $amount = (float)$q['quote_amount'];
    if ($lowest_quote === null || $amount < $lowest_quote) {
      $lowest_quote = $amount;
    }
  }
  if ($q['lead_time_days'] !== null) {
    $lead = (int)$q['lead_time_days'];
    if ($best_lead === null || $lead < $best_lead) {
      $best_lead = $lead;
    }
  }
  if ($q['shipping_cost'] !== null) {
    $ship = (float)$q['shipping_cost'];
    if ($lowest_shipping === null || $ship < $lowest_shipping) {
      $lowest_shipping = $ship;
    }
  }
  $currency = trim((string)($q['currency'] ?? ''));
  if ($currency !== '') {
    $currencies[$currency] = true;
  }
}
$quote_currencies = $currencies ? implode(', ', array_keys($currencies)) : '—';

render_header('RFQ Details');
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div>
      <h1 style="margin:0;">RFQ #<?= (int)$rfq['id'] ?> — <?= h($rfq['request_title']) ?></h1>
      <p class="muted" style="margin:6px 0 0 0;">Created <?= h((string)$rfq['created_at']) ?></p>
    </div>
    <div class="actions">
      <a class="btn" href="rfq_tracker.php">Back to RFQ Tracker</a>
      <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$rfq['id'] ?>">Track Quotes</a>
      <a class="btn" href="rfq_tracker.php?edit_rfq_id=<?= (int)$rfq['id'] ?>">Edit RFQ</a>
    </div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">RFQ Information</h2>
  <table>
    <tbody>
      <tr>
        <th style="width:220px;">Status</th>
        <td><?= h($request_statuses[$rfq['request_status']] ?? (string)$rfq['request_status']) ?></td>
      </tr>
      <tr>
        <th>Requested By</th>
        <td><?= h((string)($rfq['requested_by_username'] ?? 'Unknown')) ?></td>
      </tr>
      <tr>
        <th>Contact Name</th>
        <td><?= $rfq['contact_name'] !== null && $rfq['contact_name'] !== '' ? h($rfq['contact_name']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Company Name</th>
        <td><?= $rfq['company_name'] !== null && $rfq['company_name'] !== '' ? h($rfq['company_name']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Contact Email</th>
        <td>
          <?php if (!empty($rfq['contact_email'])): ?>
            <a href="mailto:<?= h($rfq['contact_email']) ?>"><?= h($rfq['contact_email']) ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Contact Phone</th>
        <td><?= $rfq['contact_phone'] !== null && $rfq['contact_phone'] !== '' ? h($rfq['contact_phone']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Machine Size</th>
        <td><?= h((string)$rfq['machine_size']) ?></td>
      </tr>
      <tr>
        <th>Laser Watts</th>
        <td><?= h((string)$rfq['laser_watts']) ?></td>
      </tr>
      <tr>
        <th>Tube Type</th>
        <td><?= h((string)$rfq['tube_type']) ?></td>
      </tr>
      <tr>
        <th>Quantity</th>
        <td><?= (int)$rfq['quantity'] ?></td>
      </tr>
      <tr>
        <th>Required Features</th>
        <td><?= nl2br(h((string)$rfq['required_features'])) ?></td>
      </tr>
      <tr>
        <th>Additional Notes</th>
        <td><?= !empty($rfq['additional_notes']) ? nl2br(h((string)$rfq['additional_notes'])) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Last Updated</th>
        <td><?= h((string)$rfq['updated_at']) ?></td>
      </tr>
    </tbody>
  </table>
</div>

<div class="card">
  <h2 style="margin-top:0;">Quote Summary</h2>
  <div class="row" style="gap:18px; flex-wrap:wrap;">
    <div><strong>Total Quotes:</strong> <?= $quote_count ?></div>
    <div><strong>Best Quote:</strong> <?= $lowest_quote !== null ? h(number_format($lowest_quote, 2)) : '—' ?></div>
    <div><strong>Best Lead:</strong> <?= $best_lead !== null ? h((string)$best_lead) . ' days' : '—' ?></div>
    <div><strong>Lowest Shipping:</strong> <?= $lowest_shipping !== null ? h(number_format($lowest_shipping, 2)) : '—' ?></div>
    <div><strong>Currencies:</strong> <?= h($quote_currencies) ?></div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">Supplier Quotes</h2>
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:980px;">
      <thead>
        <tr>
          <th>Supplier</th>
          <th>Quote</th>
          <th>Lead Time</th>
          <th>Shipping</th>
          <th>Status</th>
          <th>Received</th>
          <th>Attachment</th>
          <th>Added By</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$quotes): ?>
          <tr><td colspan="8" class="muted">No quotes added yet for this RFQ.</td></tr>
        <?php endif; ?>
        <?php foreach ($quotes as $q): ?>
          <tr>
            <td><?= h((string)$q['supplier_name']) ?></td>
            <td><?= h((string)$q['currency']) ?> <?= h(number_format((float)$q['quote_amount'], 2)) ?></td>
            <td><?= $q['lead_time_days'] !== null ? h((string)$q['lead_time_days']) . ' days' : '—' ?></td>
            <td>
              <?= $q['shipping_cost'] !== null ? h(number_format((float)$q['shipping_cost'], 2)) : '—' ?><br>
              <span class="muted"><?= h(format_shipping_details_for_rfq($q['shipping_origin'] ?? null, $q['shipping_method'] ?? null)) ?></span>
            </td>
            <td><?= h($quote_statuses[$q['quote_status']] ?? (string)$q['quote_status']) ?></td>
            <td><?= h((string)($q['received_on'] ?? '')) ?></td>
            <td>
              <?php if (!empty($q['quote_file_stored_name'])): ?>
                <a class="btn" href="rfq_quote_file.php?quote_id=<?= (int)$q['id'] ?>" target="_blank" rel="noopener noreferrer">Open</a>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="muted"><?= h((string)($q['created_by_username'] ?? 'Unknown')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
