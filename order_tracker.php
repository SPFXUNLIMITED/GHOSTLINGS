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
  'draft' => 'Draft',
  'deposit_pending' => 'Deposit Pending',
  'deposit_paid' => 'Deposit Paid',
  'in_production' => 'In Production',
  'ready_to_ship' => 'Ready to Ship',
  'shipped' => 'Shipped',
  'delivered' => 'Delivered',
  'completed' => 'Completed',
  'cancelled' => 'Cancelled',
];
$errors = [];
$success = '';
$search = trim((string)($_GET['q'] ?? ''));
$status_filter = trim((string)($_GET['status'] ?? ''));
$rfq_filter = isset($_GET['rfq_id']) ? (int)$_GET['rfq_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['rfq_order_tracker_csrf']) || !hash_equals((string)$_SESSION['rfq_order_tracker_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'update_order_status') {
      $order_id = (int)($_POST['order_id'] ?? 0);
      $order_status = (string)($_POST['order_status'] ?? '');
      if ($order_id <= 0) {
        $errors[] = 'Invalid order.';
      } elseif (!isset($order_statuses[$order_status])) {
        $errors[] = 'Invalid order status selected.';
      } else {
        $stmt = $pdo->prepare("UPDATE rfq_orders SET order_status = ? WHERE id = ?");
        $stmt->execute([$order_status, $order_id]);
        if ($stmt->rowCount() > 0) {
          $success = 'Order status updated.';
        } else {
          $errors[] = 'Order not found or already set to that status.';
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

render_header('Order Tracker');
?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">Order Tracker</h1>
  <p class="muted" style="margin:0;">Track purchase orders created from accepted RFQ quotes, including deposit status, logistics, and shipment milestones.</p>
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
      <a class="btn" href="rfq_tracker.php">RFQ Tracker</a>
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
            </td>
            <td class="col-actions">
              <a class="btn" href="order_form.php?order_id=<?= (int)$order['id'] ?>">Edit</a>
              <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$order['rfq_request_id'] ?>">RFQ</a>
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
