<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

function tx_escape_like(string $value): string {
  return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

function tx_sort_link(string $column, string $label, string $currentSort, string $currentDir): string {
  $params = $_GET;
  $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
  $params['sort'] = $column;
  $params['dir'] = $nextDir;
  $arrow = '';
  if ($currentSort === $column) {
    $arrow = $currentDir === 'asc' ? ' ↑' : ' ↓';
  }
  return '<a href="?' . h(http_build_query($params)) . '">' . h($label . $arrow) . '</a>';
}

$search = trim((string)($_GET['q'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? 'credit'));
$sourceFilter = trim((string)($_GET['source'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$minAmountRaw = trim((string)($_GET['min_amount'] ?? ''));
$maxAmountRaw = trim((string)($_GET['max_amount'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'date'));
$dir = strtolower(trim((string)($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';

$validTypes = ['all', 'credit', 'debit', 'transfer', 'other'];
if (!in_array($typeFilter, $validTypes, true)) {
  $typeFilter = 'credit';
}

$sourceOptions = [
  '' => 'All sources',
  'bank_of_america_csv' => 'Bank of America CSV',
  'deposit' => 'Deposit',
  'atm' => 'ATM',
  'zelle' => 'Zelle',
  'stripe' => 'Stripe',
  'ach' => 'ACH / Wire',
];
if ($sourceFilter !== '' && !array_key_exists($sourceFilter, $sourceOptions)) {
  $sourceFilter = '';
}

$sortMap = [
  'date' => 'bt.transaction_date',
  'description' => 'bt.description',
  'customer' => 'bt.customer_name',
  'source' => 'bt.source',
  'amount' => 'bt.amount',
  'status' => 'bt.match_status',
];
if (!isset($sortMap[$sort])) {
  $sort = 'date';
}

$where = ['1=1'];
$params = [];

if ($typeFilter !== 'all') {
  if ($typeFilter === 'credit') {
    $where[] = 'bt.amount > 0';
  } else {
    $where[] = 'bt.transaction_type = :type';
    $params[':type'] = $typeFilter;
  }
}
if ($search !== '') {
  $where[] = "(bt.description LIKE :q ESCAPE '\\\\'
               OR COALESCE(bt.customer_name, '') LIKE :q ESCAPE '\\\\'
               OR COALESCE(bt.reference, '') LIKE :q ESCAPE '\\\\'
               OR COALESCE(c.company, '') LIKE :q ESCAPE '\\\\'
               OR TRIM(CONCAT_WS(' ', NULLIF(c.first_name,''), NULLIF(c.last_name,''))) LIKE :q ESCAPE '\\\\')";
  $params[':q'] = '%' . tx_escape_like($search) . '%';
}
if ($sourceFilter !== '') {
  $where[] = 'bt.source = :source';
  $params[':source'] = $sourceFilter;
}
if ($dateFrom !== '') {
  $where[] = 'bt.transaction_date >= :date_from';
  $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
  $where[] = 'bt.transaction_date <= :date_to';
  $params[':date_to'] = $dateTo;
}
if ($minAmountRaw !== '' && is_numeric($minAmountRaw)) {
  $where[] = 'bt.amount >= :min_amount';
  $params[':min_amount'] = number_format((float)$minAmountRaw, 2, '.', '');
}
if ($maxAmountRaw !== '' && is_numeric($maxAmountRaw)) {
  $where[] = 'bt.amount <= :max_amount';
  $params[':max_amount'] = number_format((float)$maxAmountRaw, 2, '.', '');
}

$stmt = $pdo->prepare(
  "SELECT bt.*, cp.id AS payment_id,
          COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ', NULLIF(c.first_name,''), NULLIF(c.last_name,''))), ''),
            NULLIF(c.company, ''),
            NULLIF(c.email, ''),
            NULLIF(bt.customer_name, ''),
            ''
          ) AS matched_customer_name,
          q.id AS invoice_id,
          q.converted_invoice_no
   FROM bank_transactions bt
   LEFT JOIN customer_payments cp ON cp.id = bt.linked_payment_id
   LEFT JOIN customers c ON c.id = COALESCE(bt.matched_customer_id, cp.customer_id)
   LEFT JOIN quotes q ON q.id = bt.matched_invoice_id
   WHERE " . implode(' AND ', $where) . "
   ORDER BY {$sortMap[$sort]} {$dir}, bt.id DESC
   LIMIT 300"
);
foreach ($params as $key => $value) {
  $stmt->bindValue($key, $value);
}
$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$heroTotal = count($transactions);
$heroCredits = 0;
$heroAmount = 0.0;
$heroUnmatched = 0;
foreach ($transactions as $tx) {
  $heroAmount += (float)($tx['amount'] ?? 0);
  if (($tx['transaction_type'] ?? '') === 'credit') {
    $heroCredits++;
  }
  if (($tx['match_status'] ?? '') !== 'matched') {
    $heroUnmatched++;
  }
}

render_header('Bank Transactions');
?>

<div class="card laser-rfq-hero page-header">
  <div class="laser-rfq-hero-beams" aria-hidden="true">
    <span class="laser-rfq-beam laser-rfq-beam-1"></span>
    <span class="laser-rfq-beam laser-rfq-beam-2"></span>
    <span class="laser-rfq-beam laser-rfq-beam-3"></span>
  </div>
  <div class="laser-rfq-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body laser-rfq-hero-body">
    <span class="laser-rfq-hero-tag">💵 Imported Ledger</span>
    <h1>Bank Transactions <span class="laser-rfq-hero-count">(<?= (int)$heroTotal ?>)</span></h1>
    <p class="muted">Review imported deposits, search Zelle and Stripe activity, and link into payment and invoice matching.</p>
    <ul class="laser-rfq-hero-pills" aria-label="Bank transaction highlights">
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🔎</span> Search & filter</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">↕️</span> Sortable columns</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">💳</span> Credit-first view</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🧩</span> Invoice matching links</li>
    </ul>
    <div class="laser-rfq-hero-stats" aria-label="Bank transaction summary">
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$heroTotal ?></strong>
        <span>Visible Rows</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$heroCredits ?></strong>
        <span>Credits</span>
      </div>
      <?php if (is_admin()): ?>
      <div class="laser-rfq-hero-stat admin-only-stat" title="Visible to admins only">
        <strong>$<?= h(number_format($heroAmount, 2)) ?></strong>
        <span>Total Amount</span>
        <span class="admin-only-badge">Admin</span>
      </div>
      <?php endif; ?>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$heroUnmatched ?></strong>
        <span>Need Matching</span>
      </div>
    </div>
  </div>
  <div class="laser-rfq-hero-actions">
    <a class="btn primary" href="bank_import.php">Import CSV</a>
  </div>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <div style="flex:1 1 260px;">
      <label for="tx_q">Search</label>
      <input id="tx_q" type="text" name="q" value="<?= h($search) ?>" placeholder="Customer name, Zelle, Stripe, reference..." />
    </div>
    <div style="width:180px;">
      <label for="tx_type">Type</label>
      <select id="tx_type" name="type">
        <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All types</option>
        <option value="credit" <?= $typeFilter === 'credit' ? 'selected' : '' ?>>Credits only</option>
        <option value="debit" <?= $typeFilter === 'debit' ? 'selected' : '' ?>>Debits only</option>
        <option value="transfer" <?= $typeFilter === 'transfer' ? 'selected' : '' ?>>Transfers</option>
        <option value="other" <?= $typeFilter === 'other' ? 'selected' : '' ?>>Other</option>
      </select>
    </div>
    <div style="width:180px;">
      <label for="tx_source">Source</label>
      <select id="tx_source" name="source">
        <?php foreach ($sourceOptions as $value => $label): ?>
          <option value="<?= h($value) ?>" <?= $sourceFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:160px;">
      <label for="tx_date_from">From</label>
      <input id="tx_date_from" type="date" name="date_from" value="<?= h($dateFrom) ?>" />
    </div>
    <div style="width:160px;">
      <label for="tx_date_to">To</label>
      <input id="tx_date_to" type="date" name="date_to" value="<?= h($dateTo) ?>" />
    </div>
    <div style="width:140px;">
      <label for="tx_min_amount">Min Amount</label>
      <input id="tx_min_amount" type="number" step="0.01" name="min_amount" value="<?= h($minAmountRaw) ?>" />
    </div>
    <div style="width:140px;">
      <label for="tx_max_amount">Max Amount</label>
      <input id="tx_max_amount" type="number" step="0.01" name="max_amount" value="<?= h($maxAmountRaw) ?>" />
    </div>
    <div class="row">
      <button type="submit" class="btn primary">Filter</button>
      <a class="btn" href="transactions.php">Clear</a>
    </div>
  </form>
</div>

<style>
.tx-status-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:600;white-space:nowrap;}
.tx-actions{display:flex;flex-wrap:wrap;gap:6px;}
.tx-actions .btn{font-size:.78em;padding:3px 8px;white-space:nowrap;}
.admin-only-stat{background:linear-gradient(135deg,#b8860b22,#d4af3733);border:1.5px solid #d4af37;border-radius:10px;padding:10px 16px;position:relative;}
.admin-only-badge{display:block;font-size:10px;font-weight:700;letter-spacing:.08em;color:#92700a;text-transform:uppercase;margin-top:4px;}
</style>

<div class="card">
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:1180px;">
      <thead>
        <tr>
          <th><?= tx_sort_link('date', 'Date', $sort, $dir) ?></th>
          <th><?= tx_sort_link('description', 'Description', $sort, $dir) ?></th>
          <th><?= tx_sort_link('customer', 'Customer', $sort, $dir) ?></th>
          <th><?= tx_sort_link('source', 'Source', $sort, $dir) ?></th>
          <th>Reference</th>
          <th><?= tx_sort_link('amount', 'Amount', $sort, $dir) ?></th>
          <th><?= tx_sort_link('status', 'Status', $sort, $dir) ?></th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$transactions): ?>
          <tr><td colspan="8" class="muted">No bank transactions found for the current filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($transactions as $tx): ?>
          <?php
            $status = (string)($tx['match_status'] ?? 'unmatched');
            [$statusBg, $statusFg, $statusLabel] = match ($status) {
              'matched' => ['#dcfce7', '#166534', 'Matched'],
              'ignored' => ['#f1f5f9', '#475569', 'Ignored'],
              default => ['#fef3c7', '#92400e', 'Unmatched'],
            };
            $invoiceSearch = bank_tx_invoice_search_term(
              (string)($tx['matched_customer_name'] ?? $tx['customer_name'] ?? ''),
              (string)($tx['reference'] ?? ''),
              (string)($tx['description'] ?? '')
            );
          ?>
          <tr>
            <td><?= h(fmt_date_mdY((string)$tx['transaction_date'])) ?></td>
            <td>
              <strong>#<?= (int)$tx['id'] ?></strong><br>
              <?= h((string)$tx['description']) ?>
            </td>
            <td>
              <?php if (trim((string)($tx['matched_customer_name'] ?? '')) !== ''): ?>
                <?= h((string)$tx['matched_customer_name']) ?>
              <?php elseif (trim((string)($tx['customer_name'] ?? '')) !== ''): ?>
                <?= h((string)$tx['customer_name']) ?>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= h((string)$tx['source']) ?></td>
            <td>
              <?php if (trim((string)($tx['reference'] ?? '')) !== ''): ?>
                <?= h((string)$tx['reference']) ?>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td><strong>$<?= h(number_format((float)$tx['amount'], 2)) ?></strong></td>
            <td>
              <span class="tx-status-badge" style="background:<?= h($statusBg) ?>;color:<?= h($statusFg) ?>;"><?= h($statusLabel) ?></span>
              <?php if ((int)($tx['payment_id'] ?? 0) > 0): ?>
                <div class="muted" style="font-size:.82em;margin-top:4px;">Payment #<?= (int)$tx['payment_id'] ?></div>
              <?php endif; ?>
              <?php if ((int)($tx['invoice_id'] ?? 0) > 0): ?>
                <div class="muted" style="font-size:.82em;">Invoice <?= h((string)($tx['converted_invoice_no'] ?: '#' . (int)$tx['invoice_id'])) ?></div>
              <?php endif; ?>
            </td>
            <td class="col-actions">
              <div class="tx-actions">
                <?php if ((int)($tx['payment_id'] ?? 0) > 0): ?>
                  <a class="btn" href="customer_payments.php?payment_id=<?= (int)$tx['payment_id'] ?>">View Payment</a>
                <?php else: ?>
                  <a class="btn primary" href="customer_payments.php?bank_transaction_id=<?= (int)$tx['id'] ?>">Record Payment</a>
                <?php endif; ?>
                <a class="btn" href="invoice_tracker.php?<?= h(http_build_query(['q' => $invoiceSearch])) ?>">Find Invoice</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
