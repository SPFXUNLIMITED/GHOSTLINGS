<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const INVOICE_TRACKER_TABLE_COLUMN_COUNT = 4;
const INVOICE_TRACKER_BASE_FILTER = "((converted_invoice_no IS NOT NULL AND converted_invoice_no <> '') OR status = 'converted')";

function invoice_tracker_format_money($value): string {
  return number_format((float)$value, 2);
}

function invoice_tracker_number(array $row, string $stamp): string {
  $existing = trim((string)($row['converted_invoice_no'] ?? ''));
  if ($existing !== '') {
    return $existing;
  }

  $quote_id = (int)($row['id'] ?? 0);
  if ($quote_id <= 0) {
    return '—';
  }

  return 'INV-' . $stamp . '-' . str_pad((string)$quote_id, 5, '0', STR_PAD_LEFT);
}

function invoice_tracker_status_label(string $status): string {
  $status = trim($status);
  if ($status === '') {
    return '—';
  }

  return ucwords(str_replace('_', ' ', $status));
}

function invoice_tracker_status_style(string $status): array {
  $styles = [
    'converted' => ['#dcfce7', '#166534'],
    'sent' => ['#dbeafe', '#1d4ed8'],
    'draft' => ['#f3f4f6', '#374151'],
  ];

  return $styles[$status] ?? ['#e2e8f0', '#334155'];
}

function invoice_tracker_effective_date(array $invoice): string {
  $invoice_date = trim((string)($invoice['quote_date'] ?? ''));
  if ($invoice_date !== '') {
    return $invoice_date;
  }

  return substr(trim((string)($invoice['created_at'] ?? '')), 0, 10);
}

$now = new DateTime('now', new DateTimeZone(APP_TZ));
$invoice_number_stamp = $now->format('Ymd');
$current_month = $now->format('Y-m');

$search = trim((string)($_GET['q'] ?? ''));
$status_filter = trim((string)($_GET['status'] ?? ''));
$invoice_statuses = [
  'converted' => 'Converted',
  'sent' => 'Sent',
  'draft' => 'Draft',
];

$where_parts = [INVOICE_TRACKER_BASE_FILTER];
$params = [];

if ($search !== '') {
  $where_parts[] = "(customer_name LIKE :q OR company_name LIKE :q OR converted_invoice_no LIKE :q)";
  $params[':q'] = '%' . $search . '%';
}

if ($status_filter !== '' && isset($invoice_statuses[$status_filter])) {
  $where_parts[] = "status = :status";
  $params[':status'] = $status_filter;
}

$stmt = $pdo->prepare(
  "SELECT id, customer_name, company_name, quote_date, subtotal_amount, status, converted_invoice_no, created_at
   FROM quotes
   WHERE " . implode(' AND ', $where_parts) . "
   ORDER BY created_at DESC, id DESC
   LIMIT 300"
);
foreach ($params as $key => $value) {
  $stmt->bindValue($key, $value);
}
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hero_total_invoices = count($invoices);
$hero_month_invoices = 0;
$hero_total_billed = 0.0;
$hero_customer_keys = [];
foreach ($invoices as $invoice) {
  $hero_total_billed += (float)($invoice['subtotal_amount'] ?? 0);

  $hero_date = invoice_tracker_effective_date($invoice);
  if ($hero_date !== '' && substr($hero_date, 0, 7) === $current_month) {
    $hero_month_invoices++;
  }

  $customer_name = trim((string)($invoice['customer_name'] ?? ''));
  $company_name = trim((string)($invoice['company_name'] ?? ''));
  $customer_key = strtolower($customer_name . '|' . $company_name);
  if ($customer_key !== '|') {
    $hero_customer_keys[$customer_key] = true;
  }
}
$hero_unique_customers = count($hero_customer_keys);

render_header('Invoice Tracker');
?>

<?php if (($_GET['success'] ?? '') === 'created'): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Invoice saved successfully.</div>
<?php endif; ?>

<div class="card laser-rfq-hero page-header">
  <div class="laser-rfq-hero-beams" aria-hidden="true">
    <span class="laser-rfq-beam laser-rfq-beam-1"></span>
    <span class="laser-rfq-beam laser-rfq-beam-2"></span>
    <span class="laser-rfq-beam laser-rfq-beam-3"></span>
  </div>
  <div class="laser-rfq-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body laser-rfq-hero-body">
    <span class="laser-rfq-hero-tag">💳 Billing Operations Hub</span>
    <h1>Invoice Tracking Dashboard <span class="laser-rfq-hero-count">(<?= (int)$hero_total_invoices ?>)</span></h1>
    <p class="muted">Track converted quotes, review customer billing details, and jump straight into invoice view or edit workflows.</p>
    <ul class="laser-rfq-hero-pills" aria-label="Invoice tracker highlights">
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🧾</span> Clean invoice registry</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">👀</span> Fast invoice review</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">✏️</span> One-click edits</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">💼</span> Customer billing context</li>
    </ul>
    <div class="laser-rfq-hero-stats" aria-label="Invoice summary">
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_total_invoices ?></strong>
        <span>Total Invoices</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_month_invoices ?></strong>
        <span>This Month</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong>$<?= h(invoice_tracker_format_money($hero_total_billed)) ?></strong>
        <span>Total Billed</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_unique_customers ?></strong>
        <span>Customers</span>
      </div>
    </div>
  </div>
  <div class="laser-rfq-hero-actions">
    <a class="btn primary" href="invoice_form.php">+ New Invoice</a>
  </div>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <div style="flex:1 1 320px;">
      <label for="invoice_tracker_search">Search Invoices</label>
      <input
        id="invoice_tracker_search"
        type="text"
        name="q"
        value="<?= h($search) ?>"
        placeholder="Search invoice #, customer, or company..."
      />
    </div>
    <div style="width:220px;">
      <label for="invoice_tracker_status">Status</label>
      <select id="invoice_tracker_status" name="status">
        <option value="">All statuses</option>
        <?php foreach ($invoice_statuses as $status_value => $status_label): ?>
          <option value="<?= h($status_value) ?>" <?= $status_filter === $status_value ? 'selected' : '' ?>><?= h($status_label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <button type="submit" class="btn primary">Filter</button>
      <a class="btn" href="invoice_tracker.php">Clear</a>
    </div>
  </form>
</div>

<div class="card">
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:760px;">
      <thead>
        <tr>
          <th>#</th>
          <th>Invoice</th>
          <th class="col-status">Status</th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$invoices): ?>
          <tr><td colspan="<?= INVOICE_TRACKER_TABLE_COLUMN_COUNT ?>" class="muted">No invoices found.</td></tr>
        <?php endif; ?>

        <?php foreach ($invoices as $invoice): ?>
          <?php
            $customer_name = trim((string)($invoice['customer_name'] ?? ''));
            $company_name = trim((string)($invoice['company_name'] ?? ''));
            $customer_display = $customer_name !== '' ? $customer_name : '—';
            $invoice_date = invoice_tracker_effective_date($invoice);
            $status_raw = trim((string)($invoice['status'] ?? ''));
            $status_label = invoice_tracker_status_label($status_raw);
            [$status_bg, $status_color] = invoice_tracker_status_style($status_raw);
          ?>
          <tr>
            <td class="muted"><?= (int)$invoice['id'] ?></td>
            <td>
              <strong><?= h(invoice_tracker_number($invoice, $invoice_number_stamp)) ?></strong><br>
              <span class="muted">
                <?= h($customer_display) ?>
                <?php if ($company_name !== ''): ?>
                  · <?= h($company_name) ?>
                <?php endif; ?>
                <br>
                Date: <?= h($invoice_date !== '' ? fmt_date_mdY($invoice_date) : '—') ?> · Total: $<?= h(invoice_tracker_format_money($invoice['subtotal_amount'] ?? 0)) ?>
              </span>
            </td>
            <td class="col-status">
              <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:0.82em; font-weight:600; letter-spacing:0.02em; background:<?= h($status_bg) ?>; color:<?= h($status_color) ?>;">
                <?= h($status_label) ?>
              </span>
            </td>
            <td class="col-actions">
              <a class="btn" href="invoice_form.php?id=<?= (int)$invoice['id'] ?>&mode=view">View</a>
              <a class="btn" href="invoice_form.php?id=<?= (int)$invoice['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer();
