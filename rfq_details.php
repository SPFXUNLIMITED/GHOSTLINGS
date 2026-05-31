<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

if (empty($_SESSION['rfq_tracker_csrf'])) {
  $_SESSION['rfq_tracker_csrf'] = bin2hex(random_bytes(24));
}

$request_statuses = [
  'draft' => 'Draft',
  'sourcing' => 'Sourcing',
  'quotes_received' => 'Quotes Received',
  'shortlisted' => 'Shortlisted',
  'ordered' => 'Ordered',
  'closed' => 'Closed',
];
// Stage level badges: [label, background-color, text-color]
$stage_badges = [
  'draft'           => ['Low',      '#e2e8f0', '#475569'],
  'sourcing'        => ['Moderate', '#fef9c3', '#854d0e'],
  'quotes_received' => ['Moderate', '#fef9c3', '#854d0e'],
  'shortlisted'     => ['High',     '#ffedd5', '#9a3412'],
  'ordered'         => ['Critical', '#fee2e2', '#991b1b'],
  'closed'          => ['Closed',   '#f1f5f9', '#64748b'],
];
$quote_statuses = [
  'received'     => 'Received',
  'under_review' => 'Under Review',
  'negotiating'  => 'Negotiating',
  'accepted'     => 'Accepted',
  'rejected'     => 'Rejected',
];
// Quote status badges: [label, background-color, text-color]
$quote_badges = [
  'received'     => ['Received',     '#dbeafe', '#1e40af'],
  'under_review' => ['Under Review', '#fef9c3', '#854d0e'],
  'negotiating'  => ['Negotiating',  '#ffedd5', '#9a3412'],
  'accepted'     => ['Accepted',     '#dcfce7', '#166534'],
  'rejected'     => ['Rejected',     '#fee2e2', '#991b1b'],
];

function is_safe_stored_upload_name(string $name): bool {
  return (bool)preg_match('/^[a-zA-Z0-9._-]+$/', $name);
}

$errors = [];
$success = '';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['rfq_tracker_csrf']) || !hash_equals((string)$_SESSION['rfq_tracker_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete_quote') {
      $quote_id = (int)($_POST['quote_id'] ?? 0);
      $rfq_id = (int)($_POST['rfq_id'] ?? 0);
      if ($quote_id <= 0 || $rfq_id !== (int)$rfq['id']) {
        $errors[] = 'Invalid quote or RFQ.';
      } else {
        $quote_stmt = $pdo->prepare("SELECT quote_file_stored_name FROM rfq_quotes WHERE id = ? AND rfq_request_id = ? LIMIT 1");
        $quote_stmt->execute([$quote_id, $rfq_id]);
        $del_quote = $quote_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$del_quote) {
          $errors[] = 'Quote not found.';
        } else {
          $stored = (string)($del_quote['quote_file_stored_name'] ?? '');
          if ($stored !== '' && is_safe_stored_upload_name($stored)) {
            $old_path = __DIR__ . '/uploads/' . $stored;
            if (is_file($old_path)) {
              @unlink($old_path);
            }
          }
          $delete_stmt = $pdo->prepare("DELETE FROM rfq_quotes WHERE id = ? AND rfq_request_id = ?");
          $delete_stmt->execute([$quote_id, $rfq_id]);
          $success = 'Quote deleted successfully.';
        }
      }
    } elseif ($action === 'update_request_status') {
      $new_status = (string)($_POST['request_status'] ?? '');
      if (!isset($request_statuses[$new_status])) {
        $errors[] = 'Invalid RFQ status selected.';
      } else {
        $stmt = $pdo->prepare("UPDATE rfq_requests SET request_status = ? WHERE id = ?");
        $stmt->execute([$new_status, $rfq['id']]);
        if ($stmt->rowCount() > 0) {
          $rfq['request_status'] = $new_status;
          $success = 'RFQ status updated.';
        } else {
          $errors[] = 'RFQ not found.';
        }
      }
    } elseif ($action === 'update_quote_status') {
      $quote_id = (int)($_POST['quote_id'] ?? 0);
      $new_status = (string)($_POST['quote_status'] ?? '');
      if ($quote_id <= 0) {
        $errors[] = 'Invalid quote.';
      } elseif (!isset($quote_statuses[$new_status])) {
        $errors[] = 'Invalid quote status selected.';
      } else {
        $stmt = $pdo->prepare("UPDATE rfq_quotes SET quote_status = ? WHERE id = ? AND rfq_request_id = ?");
        $stmt->execute([$new_status, $quote_id, $rfq['id']]);
        if ($stmt->rowCount() > 0) {
          $success = 'Quote status updated.';
        } else {
          $errors[] = 'Quote not found.';
        }
      }
    }
  }
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

<?php if ($errors): ?>
  <div class="card">
    <?php foreach ($errors as $e): ?>
      <div class="notice error"><?= h($e) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="card"><div class="notice success"><?= h($success) ?></div></div>
<?php endif; ?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div>
      <h1 style="margin:0;">RFQ #<?= (int)$rfq['id'] ?> — <?= h($rfq['request_title']) ?></h1>
      <p class="muted" style="margin:6px 0 0 0;">Created <?= h((string)$rfq['created_at']) ?></p>
    </div>
    <div class="actions">
      <a class="btn" href="rfq_tracker.php">Back to RFQ Tracker</a>
      <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$rfq['id'] ?>">Track Quotes</a>
      <a class="btn" href="rfq_tracker.php?edit_rfq_id=<?= (int)$rfq['id'] ?>">Edit Request</a>
    </div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">RFQ Information</h2>
  <?php $is_parts_request = (($rfq['request_category'] ?? 'machine') === 'parts'); ?>
  <table>
    <tbody>
      <tr>
        <th style="width:220px;">Status</th>
        <td>
          <?php
            $sb = $stage_badges[$rfq['request_status']] ?? ['Unknown', '#e2e8f0', '#475569'];
          ?>
          <span style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:0.72em; font-weight:600; letter-spacing:0.04em; background:<?= $sb[1] ?>; color:<?= $sb[2] ?>; vertical-align:middle;"><?= h($sb[0]) ?></span>
          <form method="post" class="row" style="gap:6px; align-items:center; margin-top:6px;">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
            <input type="hidden" name="action" value="update_request_status" />
            <select name="request_status" style="min-width:160px;">
              <?php foreach ($request_statuses as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= $rfq['request_status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn">Save</button>
          </form>
        </td>
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
        <th>Request Category</th>
        <td><?= $is_parts_request ? 'Parts' : 'Machine' ?></td>
      </tr>
      <tr>
        <th>Acquisition Purpose</th>
        <td><?= (($rfq['acquisition_purpose'] ?? 'customer') === 'internal') ? 'Internal Use (Inventory / Repairs)' : 'Customer Request' ?></td>
      </tr>
      <tr>
        <th>Urgency</th>
        <td><?php
          $urgency_labels = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'critical' => 'Critical'];
          $urgency_val = (string)($rfq['urgency'] ?? 'normal');
          echo h($urgency_labels[$urgency_val] ?? ucfirst($urgency_val));
        ?></td>
      </tr>
      <tr>
        <th>Contact Phone</th>
        <td><?= $rfq['contact_phone'] !== null && $rfq['contact_phone'] !== '' ? h($rfq['contact_phone']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <?php if ($is_parts_request): ?>
        <tr>
          <th>Part Category</th>
          <td><?= !empty($rfq['part_category']) ? h((string)$rfq['part_category']) : '<span class="muted">—</span>' ?></td>
        </tr>
      <?php else: ?>
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
      <?php endif; ?>
      <tr>
        <th>Quantity</th>
        <td><?= (int)$rfq['quantity'] ?></td>
      </tr>
      <?php if ($is_parts_request): ?>
        <tr>
          <th>Part Specs</th>
          <td><?= !empty($rfq['part_specs']) ? nl2br(h((string)$rfq['part_specs'])) : '<span class="muted">—</span>' ?></td>
        </tr>
      <?php else: ?>
        <tr>
          <th>Required Features</th>
          <td><?= nl2br(h((string)$rfq['required_features'])) ?></td>
        </tr>
      <?php endif; ?>
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
  <div class="table-wrap" style="overflow-x:auto; margin-top:14px;">
    <table class="table-auto" style="min-width:760px;">
      <thead>
        <tr>
          <th>Supplier</th>
          <th>Quote</th>
          <th>Status</th>
          <th>Attachment</th>
          <th>Added By</th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$quotes): ?>
          <tr><td colspan="6" class="muted">No quotes added yet for this RFQ.</td></tr>
        <?php endif; ?>
        <?php foreach ($quotes as $q): ?>
          <tr>
            <td>
              <div><?= h((string)$q['supplier_name']) ?></div>
              <?php if (!empty($q['model_name'])): ?>
                <div class="muted" style="font-size:12px;">Model: <?= h((string)$q['model_name']) ?></div>
              <?php endif; ?>
              <?php if (!empty($q['sku'])): ?>
                <div class="muted" style="font-size:12px;">SKU: <?= h((string)$q['sku']) ?></div>
              <?php endif; ?>
              <?php if ($q['msrp'] !== null): ?>
                <div class="muted" style="font-size:12px;">MSRP: <?= h((string)$q['currency']) ?> <?= h(number_format((float)$q['msrp'], 2)) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($q['quote_amount'] !== null): ?>
                <?= h((string)$q['currency']) ?> <?= h(number_format((float)$q['quote_amount'], 2)) ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td>
              <?php
                $qb = $quote_badges[$q['quote_status']] ?? ['Unknown', '#e2e8f0', '#475569'];
              ?>
              <span style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:0.72em; font-weight:600; letter-spacing:0.04em; background:<?= $qb[1] ?>; color:<?= $qb[2] ?>;"><?= h($qb[0]) ?></span>
              <form method="post" class="row" style="gap:4px; align-items:center; margin-top:4px;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
                <input type="hidden" name="action" value="update_quote_status" />
                <input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>" />
                <select name="quote_status" style="min-width:120px;">
                  <?php foreach ($quote_statuses as $k => $ql): ?>
                    <option value="<?= h($k) ?>" <?= $q['quote_status'] === $k ? 'selected' : '' ?>><?= h($ql) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn">Save</button>
              </form>
            </td>
            <td>
              <?php
                $file_name = (string)($q['quote_file_stored_name'] ?? '');
                $file_url = '';
                $preview_url = '';
                if ($file_name !== '' && is_safe_stored_upload_name($file_name)) {
                  $file_url = 'rfq_quote_file.php?quote_id=' . (int)$q['id'];
                  $preview_url = $file_url . '&inline=1';
                }
              ?>
              <?php if ($file_url !== ''): ?>
                <?= render_attachment_preview(
                  $file_url,
                  (string)($q['quote_file_original_name'] ?? 'Attachment'),
                  (string)($q['quote_file_mime_type'] ?? ''),
                  $preview_url
                ) ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="muted"><?= h((string)($q['created_by_username'] ?? 'Unknown')) ?></td>
            <td class="col-actions">
              <a class="btn" href="rfq_quote_details.php?rfq_id=<?= (int)$rfq['id'] ?>&quote_id=<?= (int)$q['id'] ?>">View</a>
              <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$rfq['id'] ?>&edit_quote_id=<?= (int)$q['id'] ?>">Edit</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Delete this quote? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
                <input type="hidden" name="action" value="delete_quote" />
                <input type="hidden" name="rfq_id" value="<?= (int)$rfq['id'] ?>" />
                <input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>" />
                <button type="submit" class="btn" style="color:#b91c1c;">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
