<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['rfq_order_tracker_csrf'])) {
  $_SESSION['rfq_order_tracker_csrf'] = bin2hex(random_bytes(24));
}

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

function apply_order_stage_milestone(PDO $pdo, int $order_id, string $order_status): void {
  $sql_by_stage = [
    'make_deposit_payment' => "UPDATE rfq_orders SET deposit_paid_at = COALESCE(deposit_paid_at, NOW()) WHERE id = ?",
    'vendor_accepts_po' => "UPDATE rfq_orders SET po_accepted_at = COALESCE(po_accepted_at, NOW()) WHERE id = ?",
    'vendor_produces_machine' => "UPDATE rfq_orders SET production_started_at = COALESCE(production_started_at, NOW()) WHERE id = ?",
    'make_final_payment' => "UPDATE rfq_orders SET final_payment_paid_at = COALESCE(final_payment_paid_at, NOW()) WHERE id = ?",
    'vendor_ships_machine' => "UPDATE rfq_orders SET shipped_at = COALESCE(shipped_at, NOW()) WHERE id = ?",
    'receive_tracking_documents' => "UPDATE rfq_orders SET tracking_docs_received_at = COALESCE(tracking_docs_received_at, NOW()) WHERE id = ?",
    'arrives_clears_customs' => "UPDATE rfq_orders SET customs_cleared_at = COALESCE(customs_cleared_at, NOW()) WHERE id = ?",
    'final_inspection_acceptance' => "UPDATE rfq_orders SET accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?",
  ];
  if (!isset($sql_by_stage[$order_status])) {
    return;
  }
  $stmt = $pdo->prepare($sql_by_stage[$order_status]);
  $stmt->execute([$order_id]);
}

function record_order_stage_history(PDO $pdo, int $order_id, ?string $from_stage, string $to_stage, int $changed_by): void {
  $stmt = $pdo->prepare(
    "INSERT INTO rfq_order_stage_history (order_id, from_stage, to_stage, changed_by, change_note)
     VALUES (?, ?, ?, ?, NULL)"
  );
  $stmt->execute([$order_id, $from_stage, $to_stage, $changed_by]);
}
$errors = [];
$success = '';
$search = trim((string)($_GET['q'] ?? ''));
$status_filter = trim((string)($_GET['status'] ?? ''));
$rfq_filter = isset($_GET['rfq_id']) ? (int)$_GET['rfq_id'] : 0;
$can_manage_stage = is_admin_or_moderator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['rfq_order_tracker_csrf']) || !hash_equals((string)$_SESSION['rfq_order_tracker_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'update_order_status') {
      $order_id = (int)($_POST['order_id'] ?? 0);
      $order_status = (string)($_POST['order_status'] ?? '');
      if (!$can_manage_stage) {
        $errors[] = 'Only admins and moderators can update workflow stages.';
      } elseif ($order_id <= 0) {
        $errors[] = 'Invalid order.';
      } elseif (!isset($order_statuses[$order_status])) {
        $errors[] = 'Invalid order status selected.';
      } else {
        try {
          $current_stmt = $pdo->prepare("SELECT order_status FROM rfq_orders WHERE id = ?");
          $current_stmt->execute([$order_id]);
          $current = $current_stmt->fetch();
          if (!$current) {
            $errors[] = 'Order not found.';
          } elseif ((string)$current['order_status'] === $order_status) {
            $errors[] = 'Order is already set to that status.';
          } else {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE rfq_orders SET order_status = ? WHERE id = ?");
            $stmt->execute([$order_status, $order_id]);
            apply_order_stage_milestone($pdo, $order_id, $order_status);
            record_order_stage_history($pdo, $order_id, (string)$current['order_status'], $order_status, (int)current_user_id());
            $pdo->commit();
            $success = 'Order status updated.';
          }
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }
          error_log('Failed to update order status for order #' . $order_id . ': ' . $e->getMessage());
          $errors[] = $e instanceof PDOException && $e->getCode() === '45000'
            ? (string)$e->getMessage()
            : 'Unable to update order status right now. Please try again.';
        }
      }
    } elseif ($action === 'delete_order') {
      $order_id = (int)($_POST['order_id'] ?? 0);
      if ($order_id <= 0) {
        $errors[] = 'Invalid order.';
      } else {
        try {
          $doc_stmt = $pdo->prepare("SELECT stored_name FROM order_documents WHERE order_id = ?");
          $doc_stmt->execute([$order_id]);
          $documents = $doc_stmt->fetchAll();

          $pdo->beginTransaction();

          $delete_docs_stmt = $pdo->prepare("DELETE FROM order_documents WHERE order_id = ?");
          $delete_docs_stmt->execute([$order_id]);

          $delete_order_stmt = $pdo->prepare("DELETE FROM rfq_orders WHERE id = ?");
          $delete_order_stmt->execute([$order_id]);

          if ($delete_order_stmt->rowCount() <= 0) {
            $pdo->rollBack();
            $errors[] = 'Order not found.';
          } else {
            $pdo->commit();
            foreach ($documents as $document) {
              $stored_name = (string)($document['stored_name'] ?? '');
              if ($stored_name === '') {
                continue;
              }
              if (!preg_match('/^[A-Za-z0-9._-]+$/', $stored_name)) {
                error_log('Skipped deleting order document with unsafe stored name for order #' . $order_id . ': ' . $stored_name);
                continue;
              }
              $path = __DIR__ . '/uploads/' . $stored_name;
              if (is_file($path) && !unlink($path)) {
                error_log('Failed to delete order document file for order #' . $order_id . ': ' . $stored_name);
              }
            }
            $success = 'Order deleted.';
          }
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }
          error_log('Failed to delete order #' . $order_id . ': ' . $e->getMessage());
          $errors[] = 'Unable to delete order right now. Please try again.';
        }
      }
    }
  }
}

$where = [];
$params = [];
if ($search !== '') {
  $where[] = "(o.po_number LIKE ? OR r.request_title LIKE ? OR o.supplier_name LIKE ? OR o.model_name LIKE ? OR o.trade_assurance_order_no LIKE ?)";
  $needle = '%' . $search . '%';
  array_push($params, $needle, $needle, $needle, $needle, $needle);
}
if ($status_filter !== '' && isset($order_statuses[$status_filter])) {
  $where[] = "o.order_status = ?";
  $params[] = $status_filter;
}
if ($rfq_filter > 0) {
  $where[] = "o.rfq_request_id = ?";
  $params[] = $rfq_filter;
}

$sql = "SELECT o.*, r.request_title, q.quote_status
        FROM rfq_orders o
        INNER JOIN rfq_requests r ON r.id = o.rfq_request_id
        INNER JOIN rfq_quotes q ON q.id = o.rfq_quote_id";
if ($where) {
  $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY o.updated_at DESC, o.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$hero_stmt = $pdo->query("SELECT order_status FROM rfq_orders");
$hero_rows  = $hero_stmt->fetchAll();
$hero_total = count($hero_rows);
$hero_active = $hero_shipped = $hero_completed = 0;
foreach ($hero_rows as $_hr) {
  if (in_array($_hr['order_status'], [
    'create_rfq',
    'receive_quotes',
    'evaluate_select_quote',
    'negotiate_terms',
    'send_purchase_order',
    'vendor_accepts_po',
    'make_deposit_payment',
    'vendor_produces_machine',
    'make_final_payment',
  ], true)) {
    $hero_active++;
  } elseif (in_array($_hr['order_status'], ['vendor_ships_machine', 'receive_tracking_documents', 'arrives_clears_customs'], true)) {
    $hero_shipped++;
  } elseif ($_hr['order_status'] === 'final_inspection_acceptance') {
    $hero_completed++;
  }
}

render_header('Order Tracker');
?>

<style>
.order-hero {
  position: relative;
  overflow: hidden;
  background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 55%, #312e81 100%);
  border-radius: 14px;
  padding: 44px 36px;
  margin: 12px 0 20px;
  color: #fff;
}
.order-hero::before,
.order-hero::after {
  content: '';
  position: absolute;
  border-radius: 50%;
  pointer-events: none;
}
.order-hero::before {
  width: 340px; height: 340px;
  top: -100px; right: -80px;
  background: radial-gradient(circle, rgba(96,165,250,.22) 0%, transparent 70%);
  animation: oh-pulse 5s ease-in-out infinite;
}
.order-hero::after {
  width: 280px; height: 280px;
  bottom: -100px; left: 30px;
  background: radial-gradient(circle, rgba(167,139,250,.18) 0%, transparent 70%);
  animation: oh-pulse 7s ease-in-out infinite reverse;
}
@keyframes oh-pulse {
  0%,100% { transform: scale(1); }
  50%      { transform: scale(1.12); }
}
.order-hero-grid-lines {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px);
  background-size: 40px 40px;
  pointer-events: none;
}
.order-hero-inner {
  position: relative;
  z-index: 1;
}
.order-hero-eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: rgba(147,197,253,1);
  background: rgba(147,197,253,.12);
  border: 1px solid rgba(147,197,253,.25);
  border-radius: 20px;
  padding: 3px 12px;
  margin-bottom: 14px;
}
.order-hero-title {
  font-size: 34px;
  font-weight: 800;
  letter-spacing: -.5px;
  line-height: 1.1;
  margin: 0 0 10px;
}
.order-hero-title span {
  background: linear-gradient(90deg, #93c5fd, #c4b5fd);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}
.order-hero-subtitle {
  font-size: 15px;
  color: rgba(255,255,255,.65);
  margin: 0 0 30px;
  max-width: 580px;
  line-height: 1.65;
}
.order-hero-stats {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 28px;
}
.order-hero-stat {
  background: rgba(255,255,255,.08);
  border: 1px solid rgba(255,255,255,.14);
  border-radius: 10px;
  padding: 12px 20px;
  min-width: 110px;
  transition: background .2s;
}
.order-hero-stat:hover { background: rgba(255,255,255,.13); }
.order-hero-stat-value {
  font-size: 26px;
  font-weight: 800;
  line-height: 1;
  margin-bottom: 4px;
}
.order-hero-stat-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: rgba(255,255,255,.52);
}
.order-hero-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.order-hero-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 9px 22px;
  border-radius: 9px;
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
  border: none;
  transition: transform .13s ease, box-shadow .13s ease, background .13s ease;
}
.order-hero-btn:active { transform: translateY(1px); }
.order-hero-btn.ohb-white {
  background: #fff;
  color: #1e3a8a;
  box-shadow: 0 2px 8px rgba(0,0,0,.25);
}
.order-hero-btn.ohb-white:hover {
  background: #eff6ff;
  transform: translateY(-2px);
  box-shadow: 0 6px 18px rgba(0,0,0,.3);
}
.order-hero-btn.ohb-ghost {
  background: rgba(255,255,255,.1);
  color: #fff;
  border: 1px solid rgba(255,255,255,.25);
}
.order-hero-btn.ohb-ghost:hover {
  background: rgba(255,255,255,.18);
  transform: translateY(-2px);
}
.order-hero-deco {
  position: absolute;
  right: 40px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 110px;
  opacity: .06;
  user-select: none;
  pointer-events: none;
  line-height: 1;
  animation: oh-float 6s ease-in-out infinite;
}
@keyframes oh-float {
  0%,100% { transform: translateY(-50%); }
  50%      { transform: translateY(calc(-50% - 10px)); }
}
@media (max-width: 640px) {
  .order-hero { padding: 28px 20px; }
  .order-hero-title { font-size: 24px; }
  .order-hero-deco { display: none; }
}
</style>

<div class="order-hero">
  <div class="order-hero-grid-lines"></div>
  <div class="order-hero-deco">📦</div>
  <div class="order-hero-inner">
    <div class="order-hero-eyebrow">🚚 Procurement &amp; Logistics</div>
    <h1 class="order-hero-title">Order <span>Tracker</span></h1>
    <p class="order-hero-subtitle">
      Track purchase orders through the Alibaba workflow timeline from RFQ creation to final inspection and acceptance.
    </p>
    <div class="order-hero-stats">
      <div class="order-hero-stat">
        <div class="order-hero-stat-value"><?= (int)$hero_total ?></div>
        <div class="order-hero-stat-label">Total Orders</div>
      </div>
      <div class="order-hero-stat">
        <div class="order-hero-stat-value"><?= (int)$hero_active ?></div>
        <div class="order-hero-stat-label">Active</div>
      </div>
      <div class="order-hero-stat">
        <div class="order-hero-stat-value"><?= (int)$hero_shipped ?></div>
        <div class="order-hero-stat-label">Shipped</div>
      </div>
      <div class="order-hero-stat">
        <div class="order-hero-stat-value"><?= (int)$hero_completed ?></div>
        <div class="order-hero-stat-label">Completed</div>
      </div>
    </div>
    <div class="order-hero-actions">
      <a href="sourcing_rfq_tracker.php" class="order-hero-btn ohb-white">📋 RFQ Tracker</a>
      <a href="order_tracker.php" class="order-hero-btn ohb-ghost">↺ View All Orders</a>
    </div>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;"><?= h($success) ?></div>
<?php endif; ?>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <div style="flex:1 1 280px;">
      <label>Search Orders</label>
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search PO number, RFQ, supplier, model, or Trade Assurance number">
    </div>
    <div style="width:220px;">
      <label>Status</label>
      <select name="status">
        <option value="">All statuses</option>
        <?php foreach ($order_statuses as $value => $label): ?>
          <option value="<?= h($value) ?>" <?= $status_filter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:120px;">
      <label>RFQ #</label>
      <input type="number" name="rfq_id" min="1" value="<?= $rfq_filter > 0 ? (int)$rfq_filter : '' ?>">
    </div>
    <div class="row">
      <button type="submit" class="btn primary">Filter</button>
      <a class="btn" href="order_tracker.php">Clear</a>
      <a class="btn" href="sourcing_rfq_tracker.php">RFQ Tracker</a>
    </div>
  </form>
</div>

<div class="card">
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:980px;">
      <thead>
        <tr>
          <th>PO</th>
          <th>RFQ</th>
          <th>Supplier</th>
          <th>Financials</th>
          <th>Dates</th>
          <th>Status</th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$orders): ?>
          <tr><td colspan="7" class="muted">No purchase orders found.</td></tr>
        <?php endif; ?>
        <?php foreach ($orders as $order): ?>
          <tr>
            <td>
              <strong><?= h((string)($order['po_number'] ?: ('PO #' . (int)$order['id']))) ?></strong><br>
              <span class="muted">Quote #<?= (int)$order['rfq_quote_id'] ?></span>
            </td>
            <td>
              <div><strong>RFQ #<?= (int)$order['rfq_request_id'] ?></strong></div>
              <div class="muted" style="font-size:12px;"><?= h((string)$order['request_title']) ?></div>
            </td>
            <td>
              <div><?= h((string)$order['supplier_name']) ?></div>
              <?php if (!empty($order['model_name'])): ?>
                <div class="muted" style="font-size:12px;">Model: <?= h((string)$order['model_name']) ?></div>
              <?php endif; ?>
              <?php if (!empty($order['trade_assurance_order_no'])): ?>
                <div class="muted" style="font-size:12px;">Trade Assurance: <?= h((string)$order['trade_assurance_order_no']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div><strong><?= h((string)$order['currency']) ?> <?= h(number_format((float)$order['order_total'], 2)) ?></strong></div>
              <div class="muted" style="font-size:12px;">Deposit: <?= $order['deposit_amount'] !== null ? h(number_format((float)$order['deposit_amount'], 2)) : '—' ?></div>
              <div class="muted" style="font-size:12px;">Balance: <?= $order['balance_amount'] !== null ? h(number_format((float)$order['balance_amount'], 2)) : '—' ?></div>
            </td>
            <td>
              <div class="muted" style="font-size:12px;">Order: <?= !empty($order['order_date']) ? h((string)$order['order_date']) : '—' ?></div>
              <div class="muted" style="font-size:12px;">Ready: <?= !empty($order['expected_ready_date']) ? h((string)$order['expected_ready_date']) : '—' ?></div>
              <div class="muted" style="font-size:12px;">Ship: <?= !empty($order['expected_ship_date']) ? h((string)$order['expected_ship_date']) : '—' ?></div>
            </td>
            <td>
              <?php if ($can_manage_stage): ?>
                <form method="post" class="row" style="gap:6px; align-items:center;">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_order_tracker_csrf']) ?>">
                  <input type="hidden" name="action" value="update_order_status">
                  <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                  <select name="order_status" style="min-width:170px;">
                    <?php foreach ($order_statuses as $value => $label): ?>
                      <option value="<?= h($value) ?>" <?= $order['order_status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn">Save</button>
                </form>
              <?php else: ?>
                <span class="muted"><?= h((string)($order_statuses[(string)$order['order_status']] ?? $order['order_status'])) ?></span>
              <?php endif; ?>
            </td>
            <td class="col-actions">
              <a class="btn" href="order_form.php?order_id=<?= (int)$order['id'] ?>">Edit</a>
              <a class="btn" href="sourcing_rfq_tracker.php?rfq_id=<?= (int)$order['rfq_request_id'] ?>">RFQ</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Delete this order? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_order_tracker_csrf']) ?>">
                <input type="hidden" name="action" value="delete_order">
                <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                <button type="submit" class="btn danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
