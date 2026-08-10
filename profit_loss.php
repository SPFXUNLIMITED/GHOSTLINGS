<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const PROFIT_LOSS_DEFAULT_BASIS = 'accrual';
const PROFIT_LOSS_INVOICE_CONDITION = "(q.status = 'converted' OR (q.converted_invoice_no IS NOT NULL AND q.converted_invoice_no <> ''))";

function profit_loss_money(float $value): string {
  return '$' . number_format($value, 2);
}

function profit_loss_month_keys(string $dateFrom, string $dateTo): array {
  $start = DateTimeImmutable::createFromFormat('Y-m-d', $dateFrom);
  $end = DateTimeImmutable::createFromFormat('Y-m-d', $dateTo);
  if (!$start || !$end || $start > $end) {
    return [];
  }

  $start = $start->modify('first day of this month');
  $end = $end->modify('first day of this month');

  $keys = [];
  $cursor = $start;
  while ($cursor <= $end) {
    $keys[$cursor->format('Y-m')] = [
      'label' => $cursor->format('M Y'),
      'revenue' => 0.0,
      'tax' => 0.0,
      'cogs' => 0.0,
      'opex' => 0.0,
      'net' => 0.0,
    ];
    $cursor = $cursor->modify('+1 month');
  }

  return $keys;
}

$today = new DateTimeImmutable('now', new DateTimeZone(APP_TZ));
$defaultFrom = $today->modify('first day of January')->format('Y-m-d');
$defaultTo = $today->format('Y-m-d');

$dateFrom = trim((string)($_GET['date_from'] ?? $defaultFrom));
$dateTo = trim((string)($_GET['date_to'] ?? $defaultTo));
$basis = trim((string)($_GET['basis'] ?? PROFIT_LOSS_DEFAULT_BASIS));
if (!in_array($basis, ['accrual', 'cash'], true)) {
  $basis = PROFIT_LOSS_DEFAULT_BASIS;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
  $dateFrom = $defaultFrom;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
  $dateTo = $defaultTo;
}
if ($dateFrom > $dateTo) {
  [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$params = [
  ':date_from' => $dateFrom,
  ':date_to' => $dateTo,
];

if ($basis === 'cash') {
  $revenueSql =
    "SELECT
       COALESCE(SUM(cp.amount), 0) AS total_revenue,
       0 AS total_tax,
       COUNT(*) AS revenue_count
     FROM customer_payments cp
     WHERE cp.payment_date BETWEEN :date_from AND :date_to";
} else {
  $revenueSql =
    "SELECT
       COALESCE(SUM(q.subtotal_amount), 0) AS total_revenue,
       COALESCE(SUM(q.tax_amount), 0) AS total_tax,
       COUNT(*) AS revenue_count
     FROM quotes q
     WHERE " . PROFIT_LOSS_INVOICE_CONDITION . "
       AND DATE(COALESCE(q.converted_at, q.quote_date, q.created_at)) BETWEEN :date_from AND :date_to";
}

$revenueStmt = $pdo->prepare($revenueSql);
$revenueStmt->execute($params);
$revenue = $revenueStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalRevenue = (float)($revenue['total_revenue'] ?? 0);
$totalSalesTax = (float)($revenue['total_tax'] ?? 0);
$revenueCount = (int)($revenue['revenue_count'] ?? 0);

$expenseStmt = $pdo->prepare(
  "SELECT
     ec.group_type,
     ec.code,
     ec.name,
     COALESCE(SUM(e.amount), 0) AS total_amount
   FROM expenses e
   INNER JOIN expense_categories ec ON ec.id = e.category_id
   WHERE e.expense_date BETWEEN :date_from AND :date_to
   GROUP BY ec.group_type, ec.code, ec.name
   ORDER BY ec.group_type ASC, total_amount DESC"
);
$expenseStmt->execute($params);
$expenseRows = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);

$cogsTotal = 0.0;
$opexTotal = 0.0;
$cogsRows = [];
$opexRows = [];
foreach ($expenseRows as $row) {
  $amount = (float)($row['total_amount'] ?? 0);
  if (($row['group_type'] ?? '') === 'cogs') {
    $cogsTotal += $amount;
    $cogsRows[] = $row;
  } else {
    $opexTotal += $amount;
    $opexRows[] = $row;
  }
}

$grossProfit = $totalRevenue - $cogsTotal;
$netProfit = $grossProfit - $opexTotal;

$months = profit_loss_month_keys($dateFrom, $dateTo);
if ($months) {
  if ($basis === 'cash') {
    $revenueByMonthStmt = $pdo->prepare(
      "SELECT DATE_FORMAT(cp.payment_date, '%Y-%m') AS month_key,
              COALESCE(SUM(cp.amount), 0) AS revenue_total
       FROM customer_payments cp
       WHERE cp.payment_date BETWEEN :date_from AND :date_to
       GROUP BY month_key"
    );
    $revenueByMonthStmt->execute($params);

    foreach ($revenueByMonthStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $key = (string)($row['month_key'] ?? '');
      if ($key !== '' && isset($months[$key])) {
        $months[$key]['revenue'] = (float)($row['revenue_total'] ?? 0);
      }
    }
  } else {
    $revenueByMonthStmt = $pdo->prepare(
      "SELECT DATE_FORMAT(COALESCE(q.converted_at, q.quote_date, q.created_at), '%Y-%m') AS month_key,
              COALESCE(SUM(q.subtotal_amount), 0) AS revenue_total,
              COALESCE(SUM(q.tax_amount), 0) AS tax_total
       FROM quotes q
       WHERE " . PROFIT_LOSS_INVOICE_CONDITION . "
         AND DATE(COALESCE(q.converted_at, q.quote_date, q.created_at)) BETWEEN :date_from AND :date_to
       GROUP BY month_key"
    );
    $revenueByMonthStmt->execute($params);

    foreach ($revenueByMonthStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $key = (string)($row['month_key'] ?? '');
      if ($key !== '' && isset($months[$key])) {
        $months[$key]['revenue'] = (float)($row['revenue_total'] ?? 0);
        $months[$key]['tax'] = (float)($row['tax_total'] ?? 0);
      }
    }
  }

  $expenseByMonthStmt = $pdo->prepare(
    "SELECT DATE_FORMAT(e.expense_date, '%Y-%m') AS month_key,
            ec.group_type,
            COALESCE(SUM(e.amount), 0) AS expense_total
     FROM expenses e
     INNER JOIN expense_categories ec ON ec.id = e.category_id
     WHERE e.expense_date BETWEEN :date_from AND :date_to
     GROUP BY month_key, ec.group_type"
  );
  $expenseByMonthStmt->execute($params);

  foreach ($expenseByMonthStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = (string)($row['month_key'] ?? '');
    if ($key === '' || !isset($months[$key])) {
      continue;
    }
    if (($row['group_type'] ?? '') === 'cogs') {
      $months[$key]['cogs'] = (float)($row['expense_total'] ?? 0);
    } else {
      $months[$key]['opex'] = (float)($row['expense_total'] ?? 0);
    }
  }

  foreach ($months as $key => $month) {
    $months[$key]['net'] = $month['revenue'] - $month['cogs'] - $month['opex'];
  }
}

render_header('Profit & Loss');
?>

<div class="card laser-rfq-hero page-header">
  <div class="laser-rfq-hero-beams" aria-hidden="true">
    <span class="laser-rfq-beam laser-rfq-beam-1"></span>
    <span class="laser-rfq-beam laser-rfq-beam-2"></span>
    <span class="laser-rfq-beam laser-rfq-beam-3"></span>
  </div>
  <div class="laser-rfq-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body laser-rfq-hero-body">
    <span class="laser-rfq-hero-tag">Accounting Report</span>
    <h1>Profit &amp; Loss</h1>
    <p class="muted">
      Basis: <strong><?= h(strtoupper($basis)) ?></strong>. Default is accrual for tax prep. Sales tax is tracked separately and excluded from operating revenue totals.
    </p>
    <ul class="laser-rfq-hero-pills" aria-label="P&amp;L highlights">
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">💰</span> Revenue from invoices</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🧾</span> Expenses by category</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🏭</span> COGS vs OpEx</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">📈</span> Monthly rollup</li>
    </ul>
  </div>
  <div class="laser-rfq-hero-actions">
    <a class="btn" href="invoice_tracker.php">Invoice Tracker</a>
    <a class="btn" href="expenses.php?<?= h(http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo])) ?>">View Expenses</a>
  </div>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <div style="width:180px;">
      <label for="pl_basis">Basis</label>
      <select id="pl_basis" name="basis">
        <option value="accrual" <?= $basis === 'accrual' ? 'selected' : '' ?>>Accrual</option>
        <option value="cash" <?= $basis === 'cash' ? 'selected' : '' ?>>Cash</option>
      </select>
    </div>
    <div style="width:180px;">
      <label for="pl_date_from">From</label>
      <input id="pl_date_from" type="date" name="date_from" value="<?= h($dateFrom) ?>" required />
    </div>
    <div style="width:180px;">
      <label for="pl_date_to">To</label>
      <input id="pl_date_to" type="date" name="date_to" value="<?= h($dateTo) ?>" required />
    </div>
    <div class="row">
      <button type="submit" class="btn primary">Run Report</button>
      <a class="btn" href="profit_loss.php">Reset</a>
    </div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;">Summary (<?= h(fmt_date_mdY($dateFrom)) ?> - <?= h(fmt_date_mdY($dateTo)) ?>)</h2>
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:760px;">
      <tbody>
        <tr>
          <th>Gross Revenue</th>
          <td><strong><?= h(profit_loss_money($totalRevenue)) ?></strong></td>
        </tr>
        <tr>
          <th>Less COGS</th>
          <td><strong>-<?= h(profit_loss_money($cogsTotal)) ?></strong></td>
        </tr>
        <tr>
          <th>Gross Profit</th>
          <td><strong><?= h(profit_loss_money($grossProfit)) ?></strong></td>
        </tr>
        <tr>
          <th>Less Operating Expenses</th>
          <td><strong>-<?= h(profit_loss_money($opexTotal)) ?></strong></td>
        </tr>
        <tr>
          <th>Net Profit / Loss</th>
          <td><strong><?= h(profit_loss_money($netProfit)) ?></strong></td>
        </tr>
        <tr>
          <th>Sales Tax Collected (informational)</th>
          <td><?= h(profit_loss_money($totalSalesTax)) ?></td>
        </tr>
        <tr>
          <th>Revenue Rows Count</th>
          <td><?= (int)$revenueCount ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">Expense Breakdown</h2>
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:820px;">
      <thead>
        <tr>
          <th>Group</th>
          <th>Category Code</th>
          <th>Category Name</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$expenseRows): ?>
          <tr><td colspan="4" class="muted">No expense records found in this range.</td></tr>
        <?php endif; ?>
        <?php foreach ($expenseRows as $row): ?>
          <tr>
            <td><?= h(strtoupper((string)$row['group_type'])) ?></td>
            <td><?= h((string)$row['code']) ?></td>
            <td><?= h((string)$row['name']) ?></td>
            <td><strong><?= h(profit_loss_money((float)$row['total_amount'])) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">Monthly P&amp;L</h2>
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:980px;">
      <thead>
        <tr>
          <th>Month</th>
          <th>Revenue</th>
          <th>Sales Tax</th>
          <th>COGS</th>
          <th>OpEx</th>
          <th>Net</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$months): ?>
          <tr><td colspan="6" class="muted">No monthly data for selected range.</td></tr>
        <?php endif; ?>
        <?php foreach ($months as $month): ?>
          <tr>
            <td><?= h((string)$month['label']) ?></td>
            <td><?= h(profit_loss_money((float)$month['revenue'])) ?></td>
            <td><?= h(profit_loss_money((float)$month['tax'])) ?></td>
            <td>-<?= h(profit_loss_money((float)$month['cogs'])) ?></td>
            <td>-<?= h(profit_loss_money((float)$month['opex'])) ?></td>
            <td><strong><?= h(profit_loss_money((float)$month['net'])) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
