<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

$quote_statuses = [
  'received' => 'Received',
  'under_review' => 'Under Review',
  'negotiating' => 'Negotiating',
  'accepted' => 'Accepted',
  'rejected' => 'Rejected',
];

function format_shipping_details(?string $origin, ?string $method): string {
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

$rfq_id = isset($_GET['rfq_id']) ? (int)$_GET['rfq_id'] : 0;
$quote_id = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : 0;
if ($rfq_id <= 0 || $quote_id <= 0) {
  header('Location: rfq_tracker.php');
  exit;
}

$stmt = $pdo->prepare(
  "SELECT q.*,
          r.request_title,
          u.username AS created_by_username
   FROM rfq_quotes q
   INNER JOIN rfq_requests r ON r.id = q.rfq_request_id
   LEFT JOIN users u ON u.id = q.created_by
   WHERE q.id = ? AND q.rfq_request_id = ?
   LIMIT 1"
);
$stmt->execute([$quote_id, $rfq_id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
  http_response_code(404);
  render_header('Quote Not Found');
  echo '<div class="card"><p class="muted">Quote not found.</p><a class="btn" href="rfq_tracker.php?rfq_id=' . (int)$rfq_id . '">← Back to RFQ Quotes</a></div>';
  render_footer();
  exit;
}

render_header('Quote Details');
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div>
      <h1 style="margin:0;">Quote #<?= (int)$quote['id'] ?> — <?= h((string)$quote['supplier_name']) ?></h1>
      <p class="muted" style="margin:6px 0 0 0;">RFQ #<?= (int)$quote['rfq_request_id'] ?> — <?= h((string)$quote['request_title']) ?></p>
    </div>
    <div class="actions">
      <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$quote['rfq_request_id'] ?>">Back to RFQ Quotes</a>
      <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$quote['rfq_request_id'] ?>&edit_quote_id=<?= (int)$quote['id'] ?>">Edit Quote</a>
    </div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">Quote Details</h2>
  <table>
    <tbody>
      <tr>
        <th style="width:220px;">Supplier</th>
        <td><?= h((string)$quote['supplier_name']) ?></td>
      </tr>
      <tr>
        <th>Model Name</th>
        <td><?= !empty($quote['model_name']) ? h((string)$quote['model_name']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>SKU</th>
        <td><?= !empty($quote['sku']) ? h((string)$quote['sku']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>MSRP</th>
        <td><?= $quote['msrp'] !== null ? h((string)$quote['currency']) . ' ' . h(number_format((float)$quote['msrp'], 2)) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Quote Amount</th>
        <td><?= h((string)$quote['currency']) ?> <?= h(number_format((float)$quote['quote_amount'], 2)) ?></td>
      </tr>
      <tr>
        <th>Lead Time</th>
        <td><?= $quote['lead_time_days'] !== null ? h((string)$quote['lead_time_days']) . ' days' : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Shipping Cost</th>
        <td><?= $quote['shipping_cost'] !== null ? h(number_format((float)$quote['shipping_cost'], 2)) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Shipping Details</th>
        <td><?= h(format_shipping_details($quote['shipping_origin'] ?? null, $quote['shipping_method'] ?? null)) ?></td>
      </tr>
      <tr>
        <th>Status</th>
        <td><?= h($quote_statuses[$quote['quote_status']] ?? (string)$quote['quote_status']) ?></td>
      </tr>
      <tr>
        <th>Quote Received On</th>
        <td><?= !empty($quote['received_on']) ? h((string)$quote['received_on']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Notes</th>
        <td><?= !empty($quote['notes']) ? nl2br(h((string)$quote['notes'])) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Attachment</th>
        <td>
          <?php if (!empty($quote['quote_file_stored_name'])): ?>
            <?php
              $attachment_url = 'rfq_quote_file.php?quote_id=' . (int)$quote['id'];
              $preview_url = $attachment_url . '&inline=1';
            ?>
            <?= render_attachment_preview(
              $attachment_url,
              (string)($quote['quote_file_original_name'] ?? 'Attachment'),
              (string)($quote['quote_file_mime_type'] ?? ''),
              $preview_url
            ) ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Added By</th>
        <td><?= h((string)($quote['created_by_username'] ?? 'Unknown')) ?></td>
      </tr>
      <tr>
        <th>Created</th>
        <td><?= h((string)$quote['created_at']) ?></td>
      </tr>
      <tr>
        <th>Updated</th>
        <td><?= h((string)$quote['updated_at']) ?></td>
      </tr>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
