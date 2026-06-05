<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const INVOICE_TABLE_COLUMN_COUNT = 8;

function invoice_format_money($value): string {
  return number_format((float)$value, 2);
}

$view = (string)($_GET['view'] ?? 'all');
if (!in_array($view, ['all', 'id'], true)) {
  $view = 'all';
}

$detail_id = $view === 'id' ? (int)($_GET['id'] ?? 0) : 0;
if ($view === 'id' && $detail_id <= 0) {
  $view = 'all';
  $detail_id = 0;
}

$print_mode = isset($_GET['print']) && $_GET['print'] === '1';
$show_all = $view === 'all';
$show_detail = $view === 'id' && $detail_id > 0;
$per_page = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$total_invoices = 0;
$total_pages = 1;

$invoices = [];
if ($show_all) {
  $total_stmt = $pdo->query(
    "SELECT COUNT(*)
     FROM quotes q
     WHERE q.converted_invoice_no IS NOT NULL AND q.converted_invoice_no <> ''"
  );
  $total_invoices = (int)$total_stmt->fetchColumn();
  $total_pages = max(1, (int)ceil($total_invoices / $per_page));
  if ($page > $total_pages) {
    $page = $total_pages;
  }
  $offset = ($page - 1) * $per_page;

  $stmt = $pdo->prepare(
    "SELECT q.id, q.converted_invoice_no, q.customer_name, q.company_name, q.quote_date,
            q.subtotal_amount, q.converted_at, q.created_at, COUNT(qi.id) AS line_count
     FROM quotes q
     LEFT JOIN quote_items qi ON qi.quote_id = q.id
     WHERE q.converted_invoice_no IS NOT NULL AND q.converted_invoice_no <> ''
     GROUP BY q.id
     ORDER BY COALESCE(q.converted_at, q.created_at) DESC, q.id DESC
     LIMIT :limit OFFSET :offset"
  );
  $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $invoices = $stmt->fetchAll();
}

$detail_invoice = null;
$detail_items = [];
if ($show_detail) {
  $stmt = $pdo->prepare(
    "SELECT q.*, u.username AS created_by_username
     FROM quotes q
     LEFT JOIN users u ON u.id = q.created_by
     WHERE q.id = ? AND q.converted_invoice_no IS NOT NULL AND q.converted_invoice_no <> ''
     LIMIT 1"
  );
  $stmt->execute([$detail_id]);
  $detail_invoice = $stmt->fetch();

  if (!$detail_invoice) {
    http_response_code(404);
    render_header('Invoice Not Found');
    ?>
    <div class="card">
      <h1 style="margin-top:0;">Invoice Not Found</h1>
      <p class="muted">We couldn’t find that invoice.</p>
      <a class="btn" href="invoices.php?view=all">Back to Invoices</a>
    </div>
    <?php
    render_footer();
    exit;
  }

  $item_stmt = $pdo->prepare(
    "SELECT id, quote_id, line_position, description, quantity, cost, markup_percent, unit_price, line_total
     FROM quote_items
     WHERE quote_id = ?
     ORDER BY line_position ASC, id ASC"
  );
  $item_stmt->execute([$detail_id]);
  $detail_items = $item_stmt->fetchAll();
}

render_header('Invoices');
?>

<style>
  @media print {
    .no-print { display: none !important; }
  }
</style>

<div class="card page-header no-print">
  <div class="page-header-body">
    <h1>Invoices</h1>
    <p class="muted">View all quotes converted into invoices.</p>
  </div>
  <?php if (!$show_all): ?>
    <div class="actions">
      <a class="btn" href="invoices.php?view=all">Back to Invoices</a>
    </div>
  <?php endif; ?>
</div>

<?php if ($show_detail): ?>
  <div class="card">
    <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
      <div>
        <h2 style="margin:0;">Invoice <?= h((string)$detail_invoice['converted_invoice_no']) ?></h2>
        <p class="muted" style="margin:6px 0 0;">
          Customer: <?= h((string)$detail_invoice['customer_name']) ?> • Date: <?= h((string)$detail_invoice['quote_date']) ?>
        </p>
      </div>
      <span style="display:inline-flex; align-items:center; border-radius:999px; padding:6px 12px; font-weight:600; background:#dcfce7; color:#166534;">Converted</span>
    </div>
  </div>

  <div class="card" style="overflow-x:auto;">
    <table>
      <tbody>
        <tr><th style="width:220px;">Invoice #</th><td><?= h((string)$detail_invoice['converted_invoice_no']) ?></td></tr>
        <tr><th>Quote #</th><td>#<?= (int)$detail_invoice['id'] ?></td></tr>
        <tr><th>Customer</th><td><?= h((string)$detail_invoice['customer_name']) ?></td></tr>
        <tr><th>Company</th><td><?= h((string)($detail_invoice['company_name'] ?: '—')) ?></td></tr>
        <tr><th>Phone</th><td><?= h((string)($detail_invoice['phone_number'] ?: '—')) ?></td></tr>
        <tr><th>Email</th><td><?= h((string)($detail_invoice['email'] ?: '—')) ?></td></tr>
        <tr><th>Invoice Date</th><td><?= h((string)$detail_invoice['quote_date']) ?></td></tr>
        <tr><th>Total</th><td><strong>$<?= h(invoice_format_money($detail_invoice['subtotal_amount'])) ?></strong></td></tr>
        <tr><th>Notes</th><td style="white-space:pre-wrap;"><?= h((string)($detail_invoice['notes'] ?: '—')) ?></td></tr>
      </tbody>
    </table>
  </div>

  <div class="card" style="overflow-x:auto;">
    <h3 style="margin-top:0;">Line Items</h3>
    <table>
      <thead>
        <tr>
          <th style="width:60px;">#</th>
          <th>Description</th>
          <th style="width:100px;">Qty</th>
          <th style="width:130px;">Cost</th>
          <th style="width:110px;">Markup %</th>
          <th style="width:130px;">Price</th>
          <th style="width:150px;">Line Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($detail_items as $idx => $item): ?>
          <tr>
            <td><?= (int)$idx + 1 ?></td>
            <td><?= h((string)$item['description']) ?></td>
            <td><?= h(invoice_format_money($item['quantity'])) ?></td>
            <td>$<?= h(invoice_format_money($item['cost'])) ?></td>
            <td><?= h(number_format((float)$item['markup_percent'], 2)) ?>%</td>
            <td>$<?= h(invoice_format_money($item['unit_price'])) ?></td>
            <td><strong>$<?= h(invoice_format_money($item['line_total'])) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card no-print">
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <a class="btn" href="invoices.php?view=all">Back to Invoices</a>
      <a class="btn" href="quotes.php?view=id&id=<?= (int)$detail_invoice['id'] ?>">View Source Quote</a>
      <a class="btn primary" href="invoices.php?view=id&id=<?= (int)$detail_invoice['id'] ?>&print=1" target="_blank" rel="noopener">Print Invoice</a>
    </div>
  </div>

  <?php if ($print_mode): ?>
    <script>window.print();</script>
  <?php endif; ?>

<?php else: ?>
  <div class="card" style="overflow-x:auto;">
    <h2 style="margin-top:0;">All Converted Invoices</h2>
    <table style="min-width:920px;">
      <thead>
        <tr>
          <th>Converted At</th>
          <th>Invoice #</th>
          <th>Quote #</th>
          <th>Customer</th>
          <th>Company</th>
          <th>Items</th>
          <th>Total</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$invoices): ?>
          <tr><td colspan="<?= INVOICE_TABLE_COLUMN_COUNT ?>" class="muted">No converted invoices yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($invoices as $invoice): ?>
          <tr>
            <td><?= h((string)($invoice['converted_at'] ?: $invoice['created_at'])) ?></td>
            <td><?= h((string)$invoice['converted_invoice_no']) ?></td>
            <td>#<?= (int)$invoice['id'] ?></td>
            <td><?= h((string)$invoice['customer_name']) ?></td>
            <td><?= h((string)($invoice['company_name'] ?: '—')) ?></td>
            <td><?= (int)$invoice['line_count'] ?></td>
            <td><strong>$<?= h(invoice_format_money($invoice['subtotal_amount'])) ?></strong></td>
            <td style="white-space:nowrap;">
              <a class="btn" href="invoices.php?view=id&id=<?= (int)$invoice['id'] ?>">View</a>
              <a class="btn" href="invoices.php?view=id&id=<?= (int)$invoice['id'] ?>&print=1" target="_blank" rel="noopener">Print</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($total_invoices > $per_page): ?>
      <div class="no-print" style="margin-top:14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <p class="muted" style="margin:0;">Page <?= (int)$page ?> of <?= (int)$total_pages ?> • <?= (int)$total_invoices ?> invoices</p>
        <div style="display:flex; gap:8px;">
          <?php if ($page > 1): ?>
            <a class="btn" href="invoices.php?view=all&page=<?= (int)($page - 1) ?>">Previous</a>
          <?php endif; ?>
          <?php if ($page < $total_pages): ?>
            <a class="btn" href="invoices.php?view=all&page=<?= (int)($page + 1) ?>">Next</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
