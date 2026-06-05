<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const INVOICE_DEFAULT_QTY = '1.00';
const INVOICE_DEFAULT_COST = '0.00';
const INVOICE_DEFAULT_MARKUP = '20.00';
const INVOICE_DEFAULT_PRICE = '0.00';

// ---------- CSRF ----------
if (empty($_SESSION['invoice_form_csrf'])) {
  $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));
}

// ---------- POST: Save invoice ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['invoice_form_csrf'], $csrf)) {
    http_response_code(403);
    exit('Invalid CSRF token.');
  }
  $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));

  $post_source_quote_id = (int)trim((string)($_POST['source_quote_id'] ?? ''));
  $post_invoice_number  = trim((string)($_POST['invoice_number'] ?? ''));
  $post_invoice_date    = trim((string)($_POST['invoice_date'] ?? ''));
  $post_customer_name   = trim((string)($_POST['customer_name'] ?? ''));
  $post_company_name    = trim((string)($_POST['company_name'] ?? ''));
  $post_phone_number    = trim((string)($_POST['phone_number'] ?? ''));
  $post_email           = trim((string)($_POST['email'] ?? ''));
  $post_notes           = trim((string)($_POST['notes'] ?? ''));

  // Validate date; fall back to today
  $tz = new DateTimeZone(APP_TZ);
  $post_invoice_date_obj = DateTime::createFromFormat('Y-m-d', $post_invoice_date, $tz);
  if (!$post_invoice_date_obj) {
    $post_invoice_date = (new DateTime('now', $tz))->format('Y-m-d');
  }

  $item_descs   = (array)($_POST['item_desc']   ?? []);
  $item_qtys    = (array)($_POST['item_qty']    ?? []);
  $item_costs   = (array)($_POST['item_cost']   ?? []);
  $item_markups = (array)($_POST['item_markup'] ?? []);

  $subtotal = 0.0;
  $line_items_to_save = [];
  $count = count($item_descs);
  for ($i = 0; $i < $count; $i++) {
    $desc      = trim((string)($item_descs[$i]   ?? ''));
    $qty       = max(0.01, (float)($item_qtys[$i]    ?? 1));
    $cost      = max(0.0,  (float)($item_costs[$i]   ?? 0));
    $markup    = max(0.0,  (float)($item_markups[$i] ?? 0));
    $price     = $cost * (1 + $markup / 100);
    $line_total = $qty * $price;
    $subtotal  += $line_total;
    $line_items_to_save[] = [
      'description'   => $desc,
      'quantity'      => $qty,
      'cost'          => $cost,
      'markup_percent'=> $markup,
      'unit_price'    => $price,
      'line_total'    => $line_total,
    ];
  }

  $created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
  if ($created_by !== null && $created_by <= 0) {
    $created_by = null;
  }

  $pdo->beginTransaction();
  try {
    if ($post_source_quote_id > 0) {
      // Update the existing quote row and mark it as converted
      $inv_no = $post_invoice_number !== '' ? $post_invoice_number
        : 'INV-' . (new DateTime('now', $tz))->format('Ymd') . '-' . str_pad((string)$post_source_quote_id, 5, '0', STR_PAD_LEFT);

      $upd = $pdo->prepare(
        "UPDATE quotes
            SET customer_name     = ?,
                company_name      = ?,
                phone_number      = ?,
                email             = ?,
                quote_date        = ?,
                notes             = ?,
                subtotal_amount   = ?,
                status            = 'converted',
                converted_invoice_no = ?,
                converted_at      = COALESCE(converted_at, NOW())
          WHERE id = ?"
      );
      $upd->execute([
        $post_customer_name,
        $post_company_name !== '' ? $post_company_name : null,
        $post_phone_number  !== '' ? $post_phone_number  : null,
        $post_email         !== '' ? $post_email         : null,
        $post_invoice_date,
        $post_notes         !== '' ? $post_notes         : null,
        round($subtotal, 2),
        $inv_no,
        $post_source_quote_id,
      ]);

      // Replace line items
      $pdo->prepare("DELETE FROM quote_items WHERE quote_id = ?")->execute([$post_source_quote_id]);

      $ins = $pdo->prepare(
        "INSERT INTO quote_items
           (quote_id, line_position, description, quantity, cost, markup_percent, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
      );
      foreach ($line_items_to_save as $pos => $item) {
        $ins->execute([
          $post_source_quote_id, $pos + 1,
          $item['description'], $item['quantity'], $item['cost'],
          $item['markup_percent'], $item['unit_price'], $item['line_total'],
        ]);
      }
    } else {
      // Insert a new quote row representing the standalone invoice
      $ins_q = $pdo->prepare(
        "INSERT INTO quotes
           (customer_name, company_name, phone_number, email, quote_date,
            status, notes, subtotal_amount, converted_invoice_no, converted_at, created_by)
         VALUES (?, ?, ?, ?, ?, 'converted', ?, ?, '', NOW(), ?)"
      );
      $ins_q->execute([
        $post_customer_name,
        $post_company_name !== '' ? $post_company_name : null,
        $post_phone_number  !== '' ? $post_phone_number  : null,
        $post_email         !== '' ? $post_email         : null,
        $post_invoice_date,
        $post_notes         !== '' ? $post_notes         : null,
        round($subtotal, 2),
        $created_by,
      ]);
      $new_id = (int)$pdo->lastInsertId();

      // Assign invoice number using the new row's ID
      $inv_no = 'INV-' . (new DateTime('now', $tz))->format('Ymd') . '-' . str_pad((string)$new_id, 5, '0', STR_PAD_LEFT);
      $pdo->prepare("UPDATE quotes SET converted_invoice_no = ? WHERE id = ?")->execute([$inv_no, $new_id]);

      // Insert line items
      $ins = $pdo->prepare(
        "INSERT INTO quote_items
           (quote_id, line_position, description, quantity, cost, markup_percent, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
      );
      foreach ($line_items_to_save as $pos => $item) {
        $ins->execute([
          $new_id, $pos + 1,
          $item['description'], $item['quantity'], $item['cost'],
          $item['markup_percent'], $item['unit_price'], $item['line_total'],
        ]);
      }
    }

    $pdo->commit();
    header('Location: invoice_tracker.php?success=created');
    exit;
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function invoice_format_money($value): string {
  return number_format((float)$value, 2);
}

function invoice_number_from_quote(array $quote, int $quote_id): string {
  $existing = trim((string)($quote['converted_invoice_no'] ?? ''));
  if ($existing !== '') {
    return $existing;
  }

  $stamp = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Ymd');
  return 'INV-' . $stamp . '-' . str_pad((string)$quote_id, 5, '0', STR_PAD_LEFT);
}

function invoice_default_number(): string {
  $stamp = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Ymd');
  return 'INV-' . $stamp . '-NEW';
}

$quote_id_param = trim((string)($_GET['id'] ?? ''));
$has_quote_id = $quote_id_param !== '';
$quote_id = $has_quote_id ? (int)$quote_id_param : 0;
$quote = null;
$rows = [];

if ($has_quote_id) {
  if ($quote_id <= 0) {
    http_response_code(404);
    render_header('Invoice Not Found');
    ?>
    <div class="card">
      <h1 style="margin-top:0;">Invoice Not Found</h1>
      <p class="muted">The requested quote ID is invalid.</p>
      <a class="btn" href="quotes.php?view=all">Back to Quotes</a>
    </div>
    <?php
    render_footer();
    exit;
  }

  $quote_stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = ? LIMIT 1");
  $quote_stmt->execute([$quote_id]);
  $quote = $quote_stmt->fetch(PDO::FETCH_ASSOC);
  if (!$quote) {
    http_response_code(404);
    render_header('Invoice Not Found');
    ?>
    <div class="card">
      <h1 style="margin-top:0;">Invoice Not Found</h1>
      <p class="muted">We couldn’t find the quote used to pre-fill this invoice.</p>
      <a class="btn" href="quotes.php?view=all">Back to Quotes</a>
    </div>
    <?php
    render_footer();
    exit;
  }

  $item_stmt = $pdo->prepare("SELECT description, quantity, cost, markup_percent, unit_price, line_total FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC");
  $item_stmt->execute([$quote_id]);
  $rows = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$today = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Y-m-d');
$fields = [
  'invoice_number' => $quote ? invoice_number_from_quote($quote, $quote_id) : invoice_default_number(),
  'source_quote_id' => $quote ? (string)$quote_id : '',
  'customer_name' => (string)($quote['customer_name'] ?? ''),
  'company_name' => (string)($quote['company_name'] ?? ''),
  'phone_number' => (string)($quote['phone_number'] ?? ''),
  'email' => (string)($quote['email'] ?? ''),
  'invoice_date' => $today,
  'notes' => (string)($quote['notes'] ?? ''),
];

$line_items = [];
foreach ($rows as $row) {
  $line_items[] = [
    'description' => (string)($row['description'] ?? ''),
    'quantity' => invoice_format_money($row['quantity'] ?? 0),
    'cost' => invoice_format_money($row['cost'] ?? 0),
    'markup_percent' => number_format((float)($row['markup_percent'] ?? 0), 2),
    'unit_price' => invoice_format_money($row['unit_price'] ?? 0),
    'line_total' => invoice_format_money($row['line_total'] ?? 0),
  ];
}
if (!$line_items) {
  $line_items[] = [
    'description' => '',
    'quantity' => INVOICE_DEFAULT_QTY,
    'cost' => INVOICE_DEFAULT_COST,
    'markup_percent' => INVOICE_DEFAULT_MARKUP,
    'unit_price' => INVOICE_DEFAULT_PRICE,
    'line_total' => INVOICE_DEFAULT_PRICE,
  ];
}

$invoice_converted = isset($_GET['invoice_converted']) && $_GET['invoice_converted'] === '1';

render_header('Invoice Form');
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1>Invoice Form</h1>
    <p class="muted"><?= $quote ? 'Basic invoice scaffold pre-filled from quote #' . (int)$quote_id . '.' : 'Basic invoice scaffold for creating a standalone invoice.' ?></p>
  </div>
  <div class="actions">
    <?php if ($quote): ?>
      <a class="btn" href="quotes.php?view=id&id=<?= (int)$quote_id ?>">Back to Quote</a>
    <?php endif; ?>
    <a class="btn" href="quotes.php?view=all">All Quotes</a>
  </div>
</div>

<div class="card">
  <?php if ($invoice_converted): ?>
    <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; margin-bottom:14px;">Quote converted to invoice successfully.</div>
  <?php endif; ?>

  <form method="post" action="">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
    <input type="hidden" name="source_quote_id" value="<?= h($fields['source_quote_id']) ?>" />

    <div style="display:grid; gap:14px; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));">
      <div>
        <label for="invoice_number">Invoice #</label>
        <input id="invoice_number" type="text" name="invoice_number" value="<?= h($fields['invoice_number']) ?>" readonly style="background:var(--surface,#f8fafc); color:var(--muted,#64748b);" />
      </div>
      <div>
        <label for="invoice_date">Invoice Date</label>
        <input id="invoice_date" type="date" name="invoice_date" value="<?= h($fields['invoice_date']) ?>" />
      </div>
      <div>
        <label for="source_quote_label">Source Quote</label>
        <input id="source_quote_label" type="text" value="<?= h($quote ? 'Quote #' . (int)$quote_id : 'Standalone Invoice') ?>" readonly style="background:var(--surface,#f8fafc); color:var(--muted,#64748b);" />
      </div>
    </div>

    <div style="display:grid; gap:14px; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); margin-top:16px;">
      <div>
        <label for="customer_name">Customer Name</label>
        <input id="customer_name" type="text" name="customer_name" maxlength="255" value="<?= h($fields['customer_name']) ?>" />
      </div>
      <div>
        <label for="company_name">Company</label>
        <input id="company_name" type="text" name="company_name" maxlength="255" value="<?= h($fields['company_name']) ?>" />
      </div>
      <div>
        <label for="phone_number">Phone</label>
        <input id="phone_number" type="text" name="phone_number" maxlength="100" value="<?= h($fields['phone_number']) ?>" />
      </div>
      <div>
        <label for="email">Email</label>
        <input id="email" type="email" name="email" maxlength="255" value="<?= h($fields['email']) ?>" />
      </div>
    </div>

    <div style="margin-top:16px; overflow-x:auto;">
      <table style="min-width:900px;" id="lineItemsTable">
        <thead>
          <tr>
            <th>Description</th>
            <th style="width:100px;">Qty</th>
            <th style="width:130px;">Cost</th>
            <th style="width:110px;">Markup %</th>
            <th style="width:130px;">Price</th>
            <th style="width:150px;">Line Total</th>
            <th style="width:90px;">Remove</th>
          </tr>
        </thead>
        <tbody id="lineItemsBody">
          <?php foreach ($line_items as $row): ?>
            <tr class="line-item-row">
              <td><input type="text" name="item_desc[]" maxlength="500" value="<?= h((string)$row['description']) ?>" /></td>
              <td><input type="number" step="0.01" min="0.01" name="item_qty[]" value="<?= h((string)$row['quantity']) ?>" /></td>
              <td><input type="number" step="0.01" min="0" name="item_cost[]" value="<?= h((string)$row['cost']) ?>" /></td>
              <td><input type="number" step="0.01" min="0" name="item_markup[]" value="<?= h((string)$row['markup_percent']) ?>" /></td>
              <td><input type="number" step="0.01" min="0" name="item_price[]" value="<?= h((string)$row['unit_price']) ?>" readonly style="background:var(--surface,#f8fafc); color:var(--muted,#64748b);" /></td>
              <td class="line-total" style="white-space:nowrap;">$<?= h((string)$row['line_total']) ?></td>
              <td><button type="button" class="btn remove-line">×</button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
        <button type="button" class="btn" id="addLineItem">+ Add Line Item</button>
        <div><strong>Subtotal: $<span id="invoiceSubtotal">0.00</span></strong></div>
      </div>
    </div>

    <div style="margin-top:14px;">
      <label for="notes">Notes</label>
      <textarea id="notes" name="notes" rows="5"><?= h($fields['notes']) ?></textarea>
    </div>

    <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
      <button type="submit" class="btn primary" style="font-size:18px; padding:14px 22px;">Save Invoice</button>
      <?php if ($quote): ?>
        <a class="btn" href="quotes.php?view=id&id=<?= (int)$quote_id ?>">Back to Quote</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<script>
(() => {
  const lineItemsBody = document.getElementById('lineItemsBody');
  const addLineItem = document.getElementById('addLineItem');
  const subtotalNode = document.getElementById('invoiceSubtotal');
  const defaultQty = '<?= h(INVOICE_DEFAULT_QTY) ?>';
  const defaultCost = '<?= h(INVOICE_DEFAULT_COST) ?>';
  const defaultMarkup = '<?= h(INVOICE_DEFAULT_MARKUP) ?>';
  const defaultPrice = '<?= h(INVOICE_DEFAULT_PRICE) ?>';

  function parseNumber(value) {
    const n = parseFloat(value);
    return Number.isFinite(n) ? n : 0;
  }

  function computeTotals() {
    let subtotal = 0;
    Array.from(lineItemsBody.querySelectorAll('tr.line-item-row')).forEach((row) => {
      const qtyInput = row.querySelector('input[name="item_qty[]"]');
      const costInput = row.querySelector('input[name="item_cost[]"]');
      const markupInput = row.querySelector('input[name="item_markup[]"]');
      const priceInput = row.querySelector('input[name="item_price[]"]');
      const lineTotalCell = row.querySelector('.line-total');
      const cost = parseNumber(costInput?.value);
      const markup = parseNumber(markupInput?.value);
      const price = cost * (1 + markup / 100);
      if (priceInput) { priceInput.value = price.toFixed(2); }
      const lineTotal = parseNumber(qtyInput?.value) * price;
      subtotal += lineTotal;
      if (lineTotalCell) {
        lineTotalCell.textContent = '$' + lineTotal.toFixed(2);
      }
    });
    subtotalNode.textContent = subtotal.toFixed(2);
  }

  function bindRow(row) {
    row.querySelectorAll('input').forEach((input) => {
      input.addEventListener('input', computeTotals);
    });

    const removeBtn = row.querySelector('.remove-line');
    if (!removeBtn) {
      return;
    }

    removeBtn.addEventListener('click', () => {
      if (lineItemsBody.querySelectorAll('tr.line-item-row').length <= 1) {
        row.querySelector('input[name="item_desc[]"]').value = '';
        row.querySelector('input[name="item_qty[]"]').value = defaultQty;
        row.querySelector('input[name="item_cost[]"]').value = defaultCost;
        row.querySelector('input[name="item_markup[]"]').value = defaultMarkup;
        row.querySelector('input[name="item_price[]"]').value = defaultPrice;
      } else {
        row.remove();
      }
      computeTotals();
    });
  }

  addLineItem.addEventListener('click', () => {
    const tr = document.createElement('tr');
    tr.className = 'line-item-row';
    tr.innerHTML = '<td><input type="text" name="item_desc[]" maxlength="500" /></td>'
      + '<td><input type="number" step="0.01" min="0.01" name="item_qty[]" value="' + defaultQty + '" /></td>'
      + '<td><input type="number" step="0.01" min="0" name="item_cost[]" value="' + defaultCost + '" /></td>'
      + '<td><input type="number" step="0.01" min="0" name="item_markup[]" value="' + defaultMarkup + '" /></td>'
      + '<td><input type="number" step="0.01" min="0" name="item_price[]" value="' + defaultPrice + '" readonly style="background:var(--surface,#f8fafc); color:var(--muted,#64748b);" /></td>'
      + '<td class="line-total" style="white-space:nowrap;">$0.00</td>'
      + '<td><button type="button" class="btn remove-line">×</button></td>';
    lineItemsBody.appendChild(tr);
    bindRow(tr);
    computeTotals();
  });

  Array.from(lineItemsBody.querySelectorAll('tr.line-item-row')).forEach(bindRow);
  computeTotals();
})();
</script>
<?php render_footer(); ?>
