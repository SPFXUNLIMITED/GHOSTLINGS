<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

const MAX_PRODUCTION_LEAD_TIME_DAYS = 730;
const DEFAULT_DEPOSIT_PERCENT = 30.00;

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['rfq_order_csrf'])) {
  $_SESSION['rfq_order_csrf'] = bin2hex(random_bytes(24));
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
$incoterm_options = ['EXW', 'FOB', 'CIF', 'CFR', 'DDP', 'DAP'];
$errors = [];
$success = isset($_GET['saved']) ? 'Purchase order saved.' : '';

function format_order_money($value, string $currency): string {
  return $value !== null ? h($currency) . ' ' . h(number_format((float)$value, 2)) : '—';
}

function format_order_field($value): string {
  $value = trim((string)$value);
  return $value === '' ? '—' : h($value);
}

function format_order_date(?string $value): string {
  return $value !== null && $value !== '' ? h($value) : '—';
}

function is_valid_order_date(string $value): bool {
  $dt = DateTime::createFromFormat('Y-m-d', $value);
  return $dt && $dt->format('Y-m-d') === $value;
}

function normalize_nullable_text(string $value): ?string {
  $value = trim($value);
  return $value === '' ? null : $value;
}

function format_percentage_label(float $value): string {
  return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function format_order_shipping(?string $origin, ?string $method): string {
  $origin = trim((string)$origin);
  $method = trim((string)$method);
  if ($origin === '' && $method === '') {
    return '—';
  }
  if ($origin !== '' && $method !== '') {
    return h($origin . ' • ' . $method);
  }
  return h($origin !== '' ? $origin : $method);
}

function order_value($data, string $key, $default = '') {
  return array_key_exists($key, $data) ? $data[$key] : $default;
}

function generate_po_number(int $id): string {
  return 'PO-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : (int)($_POST['order_id'] ?? 0);
$rfq_id = isset($_GET['rfq_id']) ? (int)$_GET['rfq_id'] : (int)($_POST['rfq_id'] ?? 0);
$quote_id = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : (int)($_POST['quote_id'] ?? 0);

$order = null;
$source_quote = null;

if ($order_id > 0) {
  $stmt = $pdo->prepare(
    "SELECT o.*, r.request_title, r.request_status,
            q.quote_status, q.quote_amount AS source_quote_amount, q.shipping_cost AS source_shipping_cost
     FROM rfq_orders o
     INNER JOIN rfq_requests r ON r.id = o.rfq_request_id
     INNER JOIN rfq_quotes q ON q.id = o.rfq_quote_id
     WHERE o.id = ?
     LIMIT 1"
  );
  $stmt->execute([$order_id]);
  $order = $stmt->fetch();
  if (!$order) {
    http_response_code(404);
    render_header('Purchase Order Not Found');
    echo '<div class="card"><p class="muted">Purchase order not found.</p><a class="btn" href="order_tracker.php">← Back to Order Tracker</a></div>';
    render_footer();
    exit;
  }
  $rfq_id = (int)$order['rfq_request_id'];
  $quote_id = (int)$order['rfq_quote_id'];
}

if ($rfq_id > 0 && $quote_id > 0) {
  $quote_stmt = $pdo->prepare(
    "SELECT q.*, r.request_title, r.quantity, r.request_status
     FROM rfq_quotes q
     INNER JOIN rfq_requests r ON r.id = q.rfq_request_id
     WHERE q.id = ? AND q.rfq_request_id = ?
     LIMIT 1"
  );
  $quote_stmt->execute([$quote_id, $rfq_id]);
  $source_quote = $quote_stmt->fetch();

  if (!$source_quote && !$order) {
    http_response_code(404);
    render_header('Accepted Quote Not Found');
    echo '<div class="card"><p class="muted">The selected RFQ quote could not be found.</p><a class="btn" href="rfq_tracker.php">← Back to RFQ Tracker</a></div>';
    render_footer();
    exit;
  }

  if (!$order) {
    $existing_stmt = $pdo->prepare("SELECT id FROM rfq_orders WHERE rfq_quote_id = ? ORDER BY id DESC LIMIT 1");
    $existing_stmt->execute([$quote_id]);
    $existing_id = (int)$existing_stmt->fetchColumn();
    if ($existing_id > 0) {
      header('Location: order_form.php?order_id=' . $existing_id);
      exit;
    }
  }
}

if (!$order && $source_quote && $source_quote['quote_status'] !== 'accepted') {
  http_response_code(400);
  render_header('Accepted Quote Required');
  echo '<div class="card"><p class="muted">Only accepted quotes can be converted into purchase orders.</p><a class="btn" href="rfq_tracker.php?rfq_id=' . (int)$rfq_id . '">← Back to RFQ Quotes</a></div>';
  render_footer();
  exit;
}

if (!$order && $source_quote && (string)($source_quote['request_status'] ?? '') === 'closed') {
  http_response_code(400);
  render_header('RFQ Closed');
  echo '<div class="card"><p class="muted">Closed RFQs cannot be converted into new purchase orders.</p><a class="btn" href="rfq_tracker.php?rfq_id=' . (int)$rfq_id . '">← Back to RFQ Quotes</a></div>';
  render_footer();
  exit;
}

if (!$order && !$source_quote) {
  header('Location: order_tracker.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['rfq_order_csrf']) || !hash_equals((string)$_SESSION['rfq_order_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $order_status = (string)($_POST['order_status'] ?? 'draft');
    $order_date = trim((string)($_POST['order_date'] ?? ''));
    $expected_ready_date = trim((string)($_POST['expected_ready_date'] ?? ''));
    $expected_ship_date = trim((string)($_POST['expected_ship_date'] ?? ''));
    $supplier_name = trim((string)($_POST['supplier_name'] ?? ''));
    $model_name = trim((string)($_POST['model_name'] ?? ''));
    $sku = trim((string)($_POST['sku'] ?? ''));
    $quantity_raw = trim((string)($_POST['quantity'] ?? ''));
    $unit_price_raw = trim((string)($_POST['unit_price'] ?? ''));
    $order_total_raw = trim((string)($_POST['order_total'] ?? ''));
    $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
    $deposit_percent_raw = trim((string)($_POST['deposit_percent'] ?? ''));
    $deposit_amount_raw = trim((string)($_POST['deposit_amount'] ?? ''));
    $balance_amount_raw = trim((string)($_POST['balance_amount'] ?? ''));
    $payment_terms = trim((string)($_POST['payment_terms'] ?? ''));
    $incoterm = strtoupper(trim((string)($_POST['incoterm'] ?? '')));
    $shipping_method = trim((string)($_POST['shipping_method'] ?? ''));
    $shipping_origin = trim((string)($_POST['shipping_origin'] ?? ''));
    $destination_port = trim((string)($_POST['destination_port'] ?? ''));
    $destination_address = trim((string)($_POST['destination_address'] ?? ''));
    $production_lead_time_days_raw = trim((string)($_POST['production_lead_time_days'] ?? ''));
    $trade_assurance_order_no = trim((string)($_POST['trade_assurance_order_no'] ?? ''));
    $proforma_invoice_no = trim((string)($_POST['proforma_invoice_no'] ?? ''));
    $warranty_terms = trim((string)($_POST['warranty_terms'] ?? ''));
    $included_accessories = trim((string)($_POST['included_accessories'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!isset($order_statuses[$order_status])) {
      $errors[] = 'Invalid order status selected.';
    }
    if ($supplier_name === '') {
      $errors[] = 'Supplier name is required.';
    }
    if ($quantity_raw === '' || !ctype_digit($quantity_raw) || (int)$quantity_raw <= 0) {
      $errors[] = 'Quantity must be a whole number greater than zero.';
    }
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
      $errors[] = 'Currency must be a 3-letter code.';
    }
    foreach ([
      'Order date' => $order_date,
      'Expected ready date' => $expected_ready_date,
      'Expected ship date' => $expected_ship_date,
    ] as $label => $value) {
      if ($value !== '' && !is_valid_order_date($value)) {
        $errors[] = $label . ' must be in YYYY-MM-DD format.';
      }
    }
    foreach ([
      'Unit price' => $unit_price_raw,
      'Order total' => $order_total_raw,
      'Deposit percent' => $deposit_percent_raw,
      'Deposit amount' => $deposit_amount_raw,
      'Balance amount' => $balance_amount_raw,
    ] as $label => $value) {
      if ($value !== '' && (!is_numeric($value) || (float)$value < 0)) {
        $errors[] = $label . ' must be a non-negative number.';
      }
    }
    if ($deposit_percent_raw !== '' && (float)$deposit_percent_raw > 100) {
      $errors[] = 'Deposit percent cannot exceed 100.';
    }
    if ($production_lead_time_days_raw !== '' && (!ctype_digit($production_lead_time_days_raw) || (int)$production_lead_time_days_raw > MAX_PRODUCTION_LEAD_TIME_DAYS)) {
      $errors[] = 'Production lead time must be a whole number of days up to ' . MAX_PRODUCTION_LEAD_TIME_DAYS . '.';
    }
    if (strlen($incoterm) > 20) {
      $errors[] = 'Incoterm must be 20 characters or fewer.';
    }

    $quantity = (int)$quantity_raw;
    $unit_price = $unit_price_raw === '' ? null : round((float)$unit_price_raw, 2);
    $order_total = $order_total_raw === '' ? null : round((float)$order_total_raw, 2);
    $deposit_percent = $deposit_percent_raw === '' ? null : round((float)$deposit_percent_raw, 2);
    $deposit_amount = $deposit_amount_raw === '' ? null : round((float)$deposit_amount_raw, 2);
    $balance_amount = $balance_amount_raw === '' ? null : round((float)$balance_amount_raw, 2);

    if (!$errors) {
      if ($order_total === null && $unit_price !== null) {
        $order_total = round($unit_price * $quantity, 2);
      }
      if ($unit_price === null && $order_total !== null && $quantity > 0) {
        $unit_price = round($order_total / $quantity, 2);
      }
      if ($order_total === null) {
        $errors[] = 'Provide either a unit price or order total.';
      }
      if ($deposit_amount === null && $deposit_percent !== null && $order_total !== null) {
        $deposit_amount = round($order_total * ($deposit_percent / 100), 2);
      }
      if ($deposit_percent === null && $deposit_amount !== null && $order_total !== null && $order_total > 0) {
        $deposit_percent = round(($deposit_amount / $order_total) * 100, 2);
      }
      if ($balance_amount === null && $order_total !== null) {
        $balance_amount = round($order_total - (float)($deposit_amount ?? 0), 2);
      }
      if ($balance_amount !== null && $balance_amount < 0) {
        $errors[] = 'Balance amount cannot be negative.';
      }
    }

    if (!$errors) {
      $is_new_order = !$order;

      if ($order) {
        $update = $pdo->prepare(
          "UPDATE rfq_orders
           SET order_status = ?, order_date = ?, expected_ready_date = ?, expected_ship_date = ?, supplier_name = ?,
               model_name = ?, sku = ?, quantity = ?, unit_price = ?, order_total = ?, currency = ?, deposit_percent = ?,
               deposit_amount = ?, balance_amount = ?, payment_terms = ?, incoterm = ?, shipping_method = ?, shipping_origin = ?,
               destination_port = ?, destination_address = ?, production_lead_time_days = ?, trade_assurance_order_no = ?,
               proforma_invoice_no = ?, warranty_terms = ?, included_accessories = ?, notes = ?
           WHERE id = ?"
        );
        $update->execute([
          $order_status,
          normalize_nullable_text($order_date),
          normalize_nullable_text($expected_ready_date),
          normalize_nullable_text($expected_ship_date),
          $supplier_name,
          normalize_nullable_text($model_name),
          normalize_nullable_text($sku),
          $quantity,
          $unit_price,
          $order_total,
          $currency,
          $deposit_percent,
          $deposit_amount,
          $balance_amount,
          normalize_nullable_text($payment_terms),
          normalize_nullable_text($incoterm),
          normalize_nullable_text($shipping_method),
          normalize_nullable_text($shipping_origin),
          normalize_nullable_text($destination_port),
          normalize_nullable_text($destination_address),
          $production_lead_time_days_raw === '' ? null : (int)$production_lead_time_days_raw,
          normalize_nullable_text($trade_assurance_order_no),
          normalize_nullable_text($proforma_invoice_no),
          normalize_nullable_text($warranty_terms),
          normalize_nullable_text($included_accessories),
          normalize_nullable_text($notes),
          (int)$order['id'],
        ]);
        $saved_order_id = (int)$order['id'];
      } else {
        $insert = $pdo->prepare(
          "INSERT INTO rfq_orders
           (rfq_request_id, rfq_quote_id, po_number, order_status, order_date, expected_ready_date, expected_ship_date, supplier_name,
            model_name, sku, quantity, unit_price, order_total, currency, deposit_percent, deposit_amount, balance_amount, payment_terms,
            incoterm, shipping_method, shipping_origin, destination_port, destination_address, production_lead_time_days,
            trade_assurance_order_no, proforma_invoice_no, warranty_terms, included_accessories, notes, created_by)
           VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insert->execute([
          $rfq_id,
          $quote_id,
          $order_status,
          normalize_nullable_text($order_date),
          normalize_nullable_text($expected_ready_date),
          normalize_nullable_text($expected_ship_date),
          $supplier_name,
          normalize_nullable_text($model_name),
          normalize_nullable_text($sku),
          $quantity,
          $unit_price,
          $order_total,
          $currency,
          $deposit_percent,
          $deposit_amount,
          $balance_amount,
          normalize_nullable_text($payment_terms),
          normalize_nullable_text($incoterm),
          normalize_nullable_text($shipping_method),
          normalize_nullable_text($shipping_origin),
          normalize_nullable_text($destination_port),
          normalize_nullable_text($destination_address),
          $production_lead_time_days_raw === '' ? null : (int)$production_lead_time_days_raw,
          normalize_nullable_text($trade_assurance_order_no),
          normalize_nullable_text($proforma_invoice_no),
          normalize_nullable_text($warranty_terms),
          normalize_nullable_text($included_accessories),
          normalize_nullable_text($notes),
          (int)current_user_id(),
        ]);
        $saved_order_id = (int)$pdo->lastInsertId();
        $generated_po_number = generate_po_number($saved_order_id);
        $pdo->prepare("UPDATE rfq_orders SET po_number = ? WHERE id = ?")->execute([$generated_po_number, $saved_order_id]);
      }

      if ($is_new_order) {
        $rfq_status_update = $pdo->prepare("UPDATE rfq_requests SET request_status = 'ordered' WHERE id = ? AND request_status NOT IN ('ordered', 'closed')");
        $rfq_status_update->execute([$rfq_id]);
      }

      header('Location: order_form.php?order_id=' . $saved_order_id . '&saved=1');
      exit;
    }

    $order = array_merge($order ?? [], [
      'id' => $order['id'] ?? 0,
      'rfq_request_id' => $rfq_id,
      'rfq_quote_id' => $quote_id,
      'request_title' => $order['request_title'] ?? ($source_quote['request_title'] ?? ''),
      'po_number' => $order['po_number'] ?? '',
      'order_status' => $order_status,
      'order_date' => $order_date,
      'expected_ready_date' => $expected_ready_date,
      'expected_ship_date' => $expected_ship_date,
      'supplier_name' => $supplier_name,
      'model_name' => $model_name,
      'sku' => $sku,
      'quantity' => $quantity_raw,
      'unit_price' => $unit_price_raw,
      'order_total' => $order_total_raw,
      'currency' => $currency,
      'deposit_percent' => $deposit_percent_raw,
      'deposit_amount' => $deposit_amount_raw,
      'balance_amount' => $balance_amount_raw,
      'payment_terms' => $payment_terms,
      'incoterm' => $incoterm,
      'shipping_method' => $shipping_method,
      'shipping_origin' => $shipping_origin,
      'destination_port' => $destination_port,
      'destination_address' => $destination_address,
      'production_lead_time_days' => $production_lead_time_days_raw,
      'trade_assurance_order_no' => $trade_assurance_order_no,
      'proforma_invoice_no' => $proforma_invoice_no,
      'warranty_terms' => $warranty_terms,
      'included_accessories' => $included_accessories,
      'notes' => $notes,
    ]);
  }
}

if (!$order && $source_quote) {
  $prefill_order_date = date('Y-m-d');
  $prefill_quantity = max(1, (int)($source_quote['quantity'] ?? 1));
  $prefill_total = (float)$source_quote['quote_amount'];
  $prefill_unit = $prefill_quantity > 0 ? round($prefill_total / $prefill_quantity, 2) : $prefill_total;
  $prefill_ready_date = '';
  if (!empty($source_quote['lead_time_days'])) {
    $prefill_ready_date = date('Y-m-d', strtotime($prefill_order_date . ' +' . (int)$source_quote['lead_time_days'] . ' days'));
  }

  $order = [
    'id' => 0,
    'rfq_request_id' => $rfq_id,
    'rfq_quote_id' => $quote_id,
    'request_title' => $source_quote['request_title'],
    'po_number' => 'Will be generated on save',
    'order_status' => 'draft',
    'order_date' => $prefill_order_date,
    'expected_ready_date' => $prefill_ready_date,
    'expected_ship_date' => '',
    'supplier_name' => $source_quote['supplier_name'],
    'model_name' => (string)($source_quote['model_name'] ?? ''),
    'sku' => (string)($source_quote['sku'] ?? ''),
    'quantity' => (string)$prefill_quantity,
    'unit_price' => number_format($prefill_unit, 2, '.', ''),
    'order_total' => number_format($prefill_total, 2, '.', ''),
    'currency' => (string)$source_quote['currency'],
    'deposit_percent' => number_format(DEFAULT_DEPOSIT_PERCENT, 2, '.', ''),
    'deposit_amount' => number_format(round($prefill_total * (DEFAULT_DEPOSIT_PERCENT / 100), 2), 2, '.', ''),
    'balance_amount' => number_format(round($prefill_total * ((100 - DEFAULT_DEPOSIT_PERCENT) / 100), 2), 2, '.', ''),
    'payment_terms' => format_percentage_label(DEFAULT_DEPOSIT_PERCENT) . '% deposit, ' . format_percentage_label(100 - DEFAULT_DEPOSIT_PERCENT) . '% balance before shipment',
    'incoterm' => '',
    'shipping_method' => (string)($source_quote['shipping_method'] ?? ''),
    'shipping_origin' => (string)($source_quote['shipping_origin'] ?? ''),
    'destination_port' => '',
    'destination_address' => '',
    'production_lead_time_days' => $source_quote['lead_time_days'] !== null ? (string)$source_quote['lead_time_days'] : '',
    'trade_assurance_order_no' => '',
    'proforma_invoice_no' => '',
    'warranty_terms' => '',
    'included_accessories' => '',
    'notes' => (string)($source_quote['notes'] ?? ''),
  ];
}

render_header('Purchase Order Form');
?>

<div class="card">
  <div class="page-header" style="margin-bottom:0;">
    <div class="page-header-body">
      <h1 style="margin:0;">Purchase Order</h1>
      <p class="muted" style="margin:6px 0 0 0;">
        <?= h((string)($order['po_number'] ?? 'Will be generated on save')) ?> · RFQ #<?= (int)$rfq_id ?> · Quote #<?= (int)$quote_id ?>
      </p>
    </div>
    <div class="row">
      <a class="btn" href="order_tracker.php">Order Tracker</a>
      <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$rfq_id ?>">Back to RFQ Quotes</a>
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

<?php if ($source_quote): ?>
  <div class="card">
    <h2 style="margin-top:0;">Accepted Quote Reference</h2>
    <div class="row" style="gap:20px; flex-wrap:wrap;">
      <div><strong>RFQ:</strong> <?= h((string)$source_quote['request_title']) ?></div>
      <div><strong>Supplier:</strong> <?= h((string)$source_quote['supplier_name']) ?></div>
      <div><strong>Quote:</strong> <?= format_order_money($source_quote['quote_amount'], (string)$source_quote['currency']) ?></div>
      <div><strong>Lead Time:</strong> <?= $source_quote['lead_time_days'] !== null ? h((string)$source_quote['lead_time_days']) . ' days' : '—' ?></div>
      <div><strong>Shipping:</strong> <?= format_order_shipping($source_quote['shipping_origin'] ?? null, $source_quote['shipping_method'] ?? null) ?></div>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;">Order Details</h2>
  <p class="muted" style="margin-top:0;">Prefilled from the accepted quote so you can complete supplier, deposit, logistics, and Alibaba/China ordering details.</p>
  <form method="post" class="form-grid" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_order_csrf']) ?>">
    <input type="hidden" name="order_id" value="<?= (int)($order['id'] ?? 0) ?>">
    <input type="hidden" name="rfq_id" value="<?= (int)$rfq_id ?>">
    <input type="hidden" name="quote_id" value="<?= (int)$quote_id ?>">

    <div>
      <label>PO Number</label>
      <input type="text" value="<?= h((string)($order['po_number'] ?? 'Will be generated on save')) ?>" readonly>
    </div>
    <div>
      <label>Order Status</label>
      <select name="order_status">
        <?php foreach ($order_statuses as $value => $label): ?>
          <option value="<?= h($value) ?>" <?= order_value($order, 'order_status', 'draft') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Order Date</label>
      <input type="date" name="order_date" value="<?= h((string)order_value($order, 'order_date', '')) ?>">
    </div>
    <div>
      <label>Expected Ready Date</label>
      <input type="date" name="expected_ready_date" value="<?= h((string)order_value($order, 'expected_ready_date', '')) ?>">
    </div>
    <div>
      <label>Expected Ship Date</label>
      <input type="date" name="expected_ship_date" value="<?= h((string)order_value($order, 'expected_ship_date', '')) ?>">
    </div>
    <div>
      <label>Production Lead Time (days)</label>
      <input type="number" name="production_lead_time_days" min="0" max="<?= MAX_PRODUCTION_LEAD_TIME_DAYS ?>" value="<?= h((string)order_value($order, 'production_lead_time_days', '')) ?>">
    </div>
    <div>
      <label>Supplier Name <span style="color:var(--d)">*</span></label>
      <input type="text" name="supplier_name" maxlength="255" required value="<?= h((string)order_value($order, 'supplier_name', '')) ?>">
    </div>
    <div>
      <label>Model Name</label>
      <input type="text" name="model_name" maxlength="255" value="<?= h((string)order_value($order, 'model_name', '')) ?>">
    </div>
    <div>
      <label>SKU</label>
      <input type="text" name="sku" maxlength="100" value="<?= h((string)order_value($order, 'sku', '')) ?>">
    </div>
    <div>
      <label>Quantity <span style="color:var(--d)">*</span></label>
      <input type="number" name="quantity" min="1" step="1" required value="<?= h((string)order_value($order, 'quantity', '1')) ?>">
    </div>
    <div>
      <label>Unit Price</label>
      <input type="number" name="unit_price" min="0" step="0.01" value="<?= h((string)order_value($order, 'unit_price', '')) ?>">
    </div>
    <div>
      <label>Order Total</label>
      <input type="number" name="order_total" min="0" step="0.01" value="<?= h((string)order_value($order, 'order_total', '')) ?>">
    </div>
    <div>
      <label>Currency <span style="color:var(--d)">*</span></label>
      <input type="text" name="currency" maxlength="3" required value="<?= h((string)order_value($order, 'currency', 'USD')) ?>">
    </div>
    <div>
      <label>Deposit %</label>
      <input type="number" name="deposit_percent" min="0" max="100" step="0.01" value="<?= h((string)order_value($order, 'deposit_percent', '')) ?>">
    </div>
    <div>
      <label>Deposit Amount</label>
      <input type="number" name="deposit_amount" min="0" step="0.01" value="<?= h((string)order_value($order, 'deposit_amount', '')) ?>">
    </div>
    <div>
      <label>Balance Amount</label>
      <input type="number" name="balance_amount" min="0" step="0.01" value="<?= h((string)order_value($order, 'balance_amount', '')) ?>">
    </div>
    <div>
      <label>Payment Terms</label>
      <input type="text" name="payment_terms" maxlength="255" placeholder="e.g. 30% deposit, 70% before shipment" value="<?= h((string)order_value($order, 'payment_terms', '')) ?>">
    </div>
    <div>
      <label>Incoterm</label>
      <input list="incoterm-options" name="incoterm" maxlength="20" placeholder="e.g. FOB" value="<?= h((string)order_value($order, 'incoterm', '')) ?>">
      <datalist id="incoterm-options">
        <?php foreach ($incoterm_options as $option): ?>
          <option value="<?= h($option) ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </div>
    <div>
      <label>Shipping Method</label>
      <input type="text" name="shipping_method" maxlength="100" placeholder="e.g. Sea freight / DDP door-to-door" value="<?= h((string)order_value($order, 'shipping_method', '')) ?>">
    </div>
    <div>
      <label>Shipping Origin</label>
      <input type="text" name="shipping_origin" maxlength="255" placeholder="e.g. Qingdao, China" value="<?= h((string)order_value($order, 'shipping_origin', '')) ?>">
    </div>
    <div>
      <label>Destination Port</label>
      <input type="text" name="destination_port" maxlength="255" placeholder="e.g. Los Angeles, CA" value="<?= h((string)order_value($order, 'destination_port', '')) ?>">
    </div>
    <div class="full">
      <label>Destination Address</label>
      <textarea name="destination_address" rows="2" maxlength="500"><?= h((string)order_value($order, 'destination_address', '')) ?></textarea>
    </div>
    <div>
      <label>Alibaba Trade Assurance Order #</label>
      <input type="text" name="trade_assurance_order_no" maxlength="100" value="<?= h((string)order_value($order, 'trade_assurance_order_no', '')) ?>">
    </div>
    <div>
      <label>Proforma Invoice #</label>
      <input type="text" name="proforma_invoice_no" maxlength="100" value="<?= h((string)order_value($order, 'proforma_invoice_no', '')) ?>">
    </div>
    <div class="full">
      <label>Included Accessories / Options</label>
      <textarea name="included_accessories" rows="3"><?= h((string)order_value($order, 'included_accessories', '')) ?></textarea>
    </div>
    <div class="full">
      <label>Warranty Terms</label>
      <textarea name="warranty_terms" rows="3"><?= h((string)order_value($order, 'warranty_terms', '')) ?></textarea>
    </div>
    <div class="full">
      <label>Order Notes</label>
      <textarea name="notes" rows="5" placeholder="Record packing requirements, inspection notes, spare parts, customs docs, or supplier commitments."><?= h((string)order_value($order, 'notes', '')) ?></textarea>
    </div>
    <div class="full row" style="margin-top:8px;">
      <button type="submit" class="btn primary">Save Purchase Order</button>
      <a class="btn" href="order_tracker.php">Cancel</a>
    </div>
  </form>
</div>

<?php render_footer(); ?>
