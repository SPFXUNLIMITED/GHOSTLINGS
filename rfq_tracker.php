<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const MAX_LEAD_TIME_DAYS = 3650;

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
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
$quote_statuses = [
  'received' => 'Received',
  'under_review' => 'Under Review',
  'negotiating' => 'Negotiating',
  'accepted' => 'Accepted',
  'rejected' => 'Rejected',
];

$errors = [];
$success = '';
$selected_rfq_id = 0;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['rfq_tracker_csrf']) || !hash_equals((string)$_SESSION['rfq_tracker_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'update_request_status') {
      $rfq_id = (int)($_POST['rfq_id'] ?? 0);
      $new_status = (string)($_POST['request_status'] ?? '');
      if ($rfq_id <= 0) {
        $errors[] = 'Invalid RFQ request.';
      } elseif (!isset($request_statuses[$new_status])) {
        $errors[] = 'Invalid RFQ status selected.';
      } else {
        $stmt = $pdo->prepare("UPDATE rfq_requests SET request_status = ? WHERE id = ?");
        $stmt->execute([$new_status, $rfq_id]);
        if ($stmt->rowCount() > 0) {
          $success = 'RFQ status updated.';
        } else {
          $errors[] = 'RFQ not found.';
        }
      }
    } elseif ($action === 'add_quote') {
      $rfq_id = (int)($_POST['rfq_id'] ?? 0);
      $supplier_name = trim((string)($_POST['supplier_name'] ?? ''));
      $quote_amount_raw = trim((string)($_POST['quote_amount'] ?? ''));
      $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
      $lead_time_days_raw = trim((string)($_POST['lead_time_days'] ?? ''));
      $shipping_cost_raw = trim((string)($_POST['shipping_cost'] ?? ''));
      $shipping_origin = trim((string)($_POST['shipping_origin'] ?? ''));
      $shipping_method = trim((string)($_POST['shipping_method'] ?? ''));
      $quote_status = (string)($_POST['quote_status'] ?? 'received');
      $received_on = trim((string)($_POST['received_on'] ?? ''));
      $notes = trim((string)($_POST['notes'] ?? ''));

      if ($rfq_id <= 0) $errors[] = 'Invalid RFQ request selected.';
      if ($supplier_name === '') $errors[] = 'Supplier name is required.';
      if ($quote_amount_raw === '' || !is_numeric($quote_amount_raw) || (float)$quote_amount_raw < 0) {
        $errors[] = 'Quote amount must be a non-negative number.';
      }
      if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $errors[] = 'Currency must be a 3-letter code (e.g. USD, CNY).';
      }
      if ($lead_time_days_raw !== '' && (!ctype_digit($lead_time_days_raw) || (int)$lead_time_days_raw > MAX_LEAD_TIME_DAYS)) {
        $errors[] = 'Lead time must be a whole number of days up to ' . MAX_LEAD_TIME_DAYS . '.';
      }
      if ($shipping_cost_raw !== '' && (!is_numeric($shipping_cost_raw) || (float)$shipping_cost_raw < 0)) {
        $errors[] = 'Shipping cost must be a non-negative number.';
      }
      if (!isset($quote_statuses[$quote_status])) {
        $errors[] = 'Invalid quote status selected.';
      }
      if ($received_on !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $received_on);
        if (!$dt || $dt->format('Y-m-d') !== $received_on) {
          $errors[] = 'Received date must be in YYYY-MM-DD format.';
        } else {
          $today = new DateTime('today');
          if ($dt > $today) {
            $errors[] = 'Received date cannot be in the future.';
          }
        }
      }
      if (strlen($notes) > 5000) {
        $errors[] = 'Notes must be 5000 characters or fewer.';
      }

      if (!$errors) {
        $exists = $pdo->prepare("SELECT id FROM rfq_requests WHERE id = ? LIMIT 1");
        $exists->execute([$rfq_id]);
        if (!$exists->fetch()) {
          $errors[] = 'RFQ request not found.';
        } else {
          $ins = $pdo->prepare(
            "INSERT INTO rfq_quotes
              (rfq_request_id, supplier_name, quote_amount, currency, lead_time_days, shipping_cost,
               shipping_origin, shipping_method, quote_status, received_on, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
          );
          $ins->execute([
            $rfq_id,
            $supplier_name,
            (float)$quote_amount_raw,
            $currency,
            $lead_time_days_raw === '' ? null : (int)$lead_time_days_raw,
            $shipping_cost_raw === '' ? null : (float)$shipping_cost_raw,
            $shipping_origin === '' ? null : $shipping_origin,
            $shipping_method === '' ? null : $shipping_method,
            $quote_status,
            $received_on === '' ? null : $received_on,
            $notes === '' ? null : $notes,
            (int)current_user_id(),
          ]);
          $success = 'Quote added to RFQ tracker.';
          $selected_rfq_id = $rfq_id;
        }
      }
    }
  }
}

$search = trim((string)($_GET['q'] ?? ''));
$status_filter = trim((string)($_GET['status'] ?? ''));
if ($selected_rfq_id <= 0) {
  $selected_rfq_id = max(0, (int)($_GET['rfq_id'] ?? 0));
}

$where_parts = [];
$params = [];
if ($search !== '') {
  $where_parts[] = "(r.request_title LIKE :q OR r.machine_size LIKE :q OR r.laser_watts LIKE :q OR r.tube_type LIKE :q OR r.required_features LIKE :q)";
  $params[':q'] = '%' . $search . '%';
}
if ($status_filter !== '' && isset($request_statuses[$status_filter])) {
  $where_parts[] = "r.request_status = :status";
  $params[':status'] = $status_filter;
}
$where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

$sql = "
  SELECT
    r.id, r.request_title, r.machine_size, r.laser_watts, r.tube_type, r.quantity,
    r.required_features, r.additional_notes, r.request_status, r.created_at, r.updated_at,
    u.username AS requested_by_username,
    COUNT(q.id) AS quote_count,
    MIN(q.quote_amount) AS lowest_quote_amount,
    MIN(q.lead_time_days) AS best_lead_time_days,
    MIN(q.shipping_cost) AS lowest_shipping_cost,
    GROUP_CONCAT(DISTINCT q.currency ORDER BY q.currency SEPARATOR ', ') AS quote_currencies
  FROM rfq_requests r
  LEFT JOIN users u ON u.id = r.requested_by
  LEFT JOIN rfq_quotes q ON q.rfq_request_id = r.id
  $where_sql
  GROUP BY r.id
  ORDER BY r.created_at DESC, r.id DESC
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
  $stmt->bindValue($k, $v);
}
$stmt->execute();
$rfqs = $stmt->fetchAll();

$selected_rfq = null;
$quotes = [];
if ($selected_rfq_id > 0) {
  $sel = $pdo->prepare("SELECT id, request_title FROM rfq_requests WHERE id = ? LIMIT 1");
  $sel->execute([$selected_rfq_id]);
  $selected_rfq = $sel->fetch();
  if ($selected_rfq) {
    $qs = $pdo->prepare(
      "SELECT q.*, u.username AS created_by_username
       FROM rfq_quotes q
       LEFT JOIN users u ON u.id = q.created_by
       WHERE q.rfq_request_id = ?
       ORDER BY COALESCE(q.received_on, DATE(q.created_at)) DESC, q.id DESC"
    );
    $qs->execute([$selected_rfq_id]);
    $quotes = $qs->fetchAll();
  }
}

render_header('RFQ Tracker');
?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">RFQ Quote Tracking</h1>
  <p class="muted" style="margin:0;">
    Track supplier quotes, lead times, and shipping costs for CO2 laser cutter purchases.
  </p>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
    <?= h($success) ?>
  </div>
<?php endif; ?>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <div style="flex:1 1 300px;">
      <label>Search RFQs</label>
      <input type="text" name="q" value="<?= h($search) ?>"
             placeholder="Search title, size, watts, tube type, features..." />
    </div>
    <div style="width:220px;">
      <label>Status</label>
      <select name="status">
        <option value="">All statuses</option>
        <?php foreach ($request_statuses as $k => $label): ?>
          <option value="<?= h($k) ?>" <?= $status_filter === $k ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <button type="submit" class="btn primary">Filter</button>
      <a class="btn" href="rfq_tracker.php">Clear</a>
      <a class="btn" href="rfq_form.php">New RFQ</a>
    </div>
  </form>
</div>

<div class="card">
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:1100px;">
      <thead>
        <tr>
          <th>#</th>
          <th>RFQ</th>
          <th>Specs</th>
          <th>Features</th>
          <th>Quotes</th>
          <th>Status</th>
          <th>Requested By</th>
          <th>Created</th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rfqs): ?>
          <tr><td colspan="9" class="muted">No RFQ requests found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rfqs as $r): ?>
          <tr>
            <td class="muted"><?= (int)$r['id'] ?></td>
            <td>
              <strong><?= h($r['request_title']) ?></strong><br>
              <span class="muted">Qty: <?= (int)$r['quantity'] ?></span>
            </td>
            <td>
              Size: <?= h($r['machine_size']) ?><br>
              Watts: <?= h($r['laser_watts']) ?><br>
              Tube: <?= h($r['tube_type']) ?>
            </td>
            <td style="max-width:260px; white-space:normal;">
              <?= nl2br(h(mb_strimwidth((string)$r['required_features'], 0, 180, '…'))) ?>
            </td>
            <td>
              <span class="badge"><?= (int)$r['quote_count'] ?> quote(s)</span><br>
              <span class="muted">
                Best quote:
                <?php if ($r['lowest_quote_amount'] !== null): ?>
                  <?= h(number_format((float)$r['lowest_quote_amount'], 2)) ?>
                <?php else: ?>
                  —
                <?php endif; ?>
                <br>
                Best lead: <?= $r['best_lead_time_days'] !== null ? h((string)$r['best_lead_time_days']) . ' days' : '—' ?><br>
                Lowest ship:
                <?php if ($r['lowest_shipping_cost'] !== null): ?>
                  <?= h(number_format((float)$r['lowest_shipping_cost'], 2)) ?>
                <?php else: ?>
                  —
                <?php endif; ?>
                <br>
                Currencies in quotes: <?= h((string)($r['quote_currencies'] ?: '—')) ?>
              </span>
            </td>
            <td>
              <form method="post" class="row" style="gap:6px; align-items:center;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
                <input type="hidden" name="action" value="update_request_status" />
                <input type="hidden" name="rfq_id" value="<?= (int)$r['id'] ?>" />
                <select name="request_status" style="min-width:150px;">
                  <?php foreach ($request_statuses as $k => $label): ?>
                    <option value="<?= h($k) ?>" <?= $r['request_status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn">Save</button>
              </form>
            </td>
            <td><?= h($r['requested_by_username'] ?? 'Unknown') ?></td>
            <td class="muted" style="white-space:nowrap;"><?= h($r['created_at']) ?></td>
            <td class="col-actions">
              <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$r['id'] ?>">Quotes</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($selected_rfq): ?>
  <div class="card">
    <h2 style="margin-top:0;">Quotes for RFQ #<?= (int)$selected_rfq['id'] ?> — <?= h($selected_rfq['request_title']) ?></h2>
    <form method="post" class="form-grid" novalidate>
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
      <input type="hidden" name="action" value="add_quote" />
      <input type="hidden" name="rfq_id" value="<?= (int)$selected_rfq['id'] ?>" />

      <div>
        <label>Supplier Name <span style="color:var(--d)">*</span></label>
        <input type="text" name="supplier_name" maxlength="255" required placeholder="e.g. ABC Laser Systems" />
      </div>
      <div>
        <label>Quote Amount <span style="color:var(--d)">*</span></label>
        <input type="number" name="quote_amount" min="0" step="0.01" required placeholder="e.g. 10800.00" />
      </div>
      <div>
        <label>Currency <span style="color:var(--d)">*</span></label>
        <input type="text" name="currency" maxlength="3" required value="USD" />
      </div>
      <div>
        <label>Lead Time (days)</label>
        <input type="number" name="lead_time_days" min="0" max="<?= MAX_LEAD_TIME_DAYS ?>" placeholder="e.g. 35" />
      </div>
      <div>
        <label>Shipping Cost</label>
        <input type="number" name="shipping_cost" min="0" step="0.01" placeholder="e.g. 1800.00" />
      </div>
      <div>
        <label>Shipping Method</label>
        <input type="text" name="shipping_method" maxlength="100" placeholder="e.g. Sea freight / Air cargo" />
      </div>
      <div>
        <label>Shipping Origin</label>
        <input type="text" name="shipping_origin" maxlength="255" placeholder="e.g. Qingdao, China" />
      </div>
      <div>
        <label>Quote Status</label>
        <select name="quote_status">
          <?php foreach ($quote_statuses as $k => $label): ?>
            <option value="<?= h($k) ?>"><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Quote Received On</label>
        <input type="date" name="received_on" />
      </div>
      <div class="full">
        <label>Notes</label>
        <textarea name="notes" rows="4" maxlength="5000"
                  placeholder="Include quote terms, included accessories, warranty, or negotiation details."></textarea>
      </div>
      <div class="full row" style="margin-top:8px;">
        <button type="submit" class="btn primary">Add Quote</button>
      </div>
    </form>

    <div class="table-wrap" style="overflow-x:auto; margin-top:14px;">
      <table class="table-auto" style="min-width:980px;">
        <thead>
          <tr>
            <th>Supplier</th>
            <th>Quote</th>
            <th>Lead Time</th>
            <th>Shipping</th>
            <th>Status</th>
            <th>Received</th>
            <th>Notes</th>
            <th>Added By</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$quotes): ?>
            <tr><td colspan="8" class="muted">No quotes added yet for this RFQ.</td></tr>
          <?php endif; ?>
          <?php foreach ($quotes as $q): ?>
            <tr>
              <td><?= h($q['supplier_name']) ?></td>
              <td>
                <?= h($q['currency']) ?> <?= h(number_format((float)$q['quote_amount'], 2)) ?>
              </td>
              <td><?= $q['lead_time_days'] !== null ? h((string)$q['lead_time_days']) . ' days' : '—' ?></td>
              <td>
                <?= $q['shipping_cost'] !== null ? h(number_format((float)$q['shipping_cost'], 2)) : '—' ?><br>
                <span class="muted"><?= h(format_shipping_details($q['shipping_origin'] ?? null, $q['shipping_method'] ?? null)) ?></span>
              </td>
              <td><?= h($quote_statuses[$q['quote_status']] ?? $q['quote_status']) ?></td>
              <td><?= h($q['received_on'] ?? '') ?></td>
              <td style="max-width:240px; white-space:normal;"><?= nl2br(h(mb_strimwidth((string)($q['notes'] ?? ''), 0, 180, '…'))) ?></td>
              <td class="muted"><?= h($q['created_by_username'] ?? 'Unknown') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
