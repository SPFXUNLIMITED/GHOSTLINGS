<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const PROFIT_LOSS_EXPORT_DEFAULT_BASIS = 'accrual';

function pl_export_money(float $value): string {
  return '$' . number_format($value, 2);
}

function pl_export_month_keys(string $dateFrom, string $dateTo): array {
  $start = DateTimeImmutable::createFromFormat('Y-m-d', $dateFrom);
  $end   = DateTimeImmutable::createFromFormat('Y-m-d', $dateTo);
  if (!$start || !$end || $start > $end) {
    return [];
  }
  $start = $start->modify('first day of this month');
  $end   = $end->modify('first day of this month');
  $keys  = [];
  $cursor = $start;
  while ($cursor <= $end) {
    $keys[$cursor->format('Y-m')] = [
      'label'   => $cursor->format('M Y'),
      'revenue' => 0.0,
      'tax'     => 0.0,
      'cogs'    => 0.0,
      'opex'    => 0.0,
      'net'     => 0.0,
    ];
    $cursor = $cursor->modify('+1 month');
  }
  return $keys;
}

$today       = new DateTimeImmutable('now', new DateTimeZone(APP_TZ));
$defaultFrom = $today->modify('first day of January')->format('Y-m-d');
$defaultTo   = $today->format('Y-m-d');

$dateFrom = trim((string)($_GET['date_from'] ?? $defaultFrom));
$dateTo   = trim((string)($_GET['date_to']   ?? $defaultTo));
$basis    = trim((string)($_GET['basis']      ?? PROFIT_LOSS_EXPORT_DEFAULT_BASIS));
$format   = trim((string)($_GET['format']     ?? 'csv'));

if (!in_array($basis, ['accrual', 'cash'], true)) {
  $basis = PROFIT_LOSS_EXPORT_DEFAULT_BASIS;
}
if (!in_array($format, ['csv', 'pdf'], true)) {
  $format = 'csv';
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

$params = [':date_from' => $dateFrom, ':date_to' => $dateTo];

// -- Revenue summary --
$revenueStmt = $pdo->prepare(
  "SELECT COALESCE(SUM(cp.amount), 0) AS total_revenue,
          0 AS total_tax,
          COUNT(*) AS revenue_count
   FROM customer_payments cp
   WHERE cp.payment_date BETWEEN :date_from AND :date_to"
);
$revenueStmt->execute($params);
$revenue      = $revenueStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalRevenue = (float)($revenue['total_revenue'] ?? 0);
$totalSalesTax = (float)($revenue['total_tax']    ?? 0);
$revenueCount  = (int)($revenue['revenue_count']  ?? 0);

// -- Expenses --
$expenseStmt = $pdo->prepare(
  "SELECT COALESCE(e.group_type, ec.group_type) AS group_type,
          ec.code,
          ec.name,
          COALESCE(SUM(e.amount), 0) AS total_amount
   FROM expenses e
   INNER JOIN expense_categories ec ON ec.id = e.category_id
   WHERE e.expense_date BETWEEN :date_from AND :date_to
   GROUP BY COALESCE(e.group_type, ec.group_type), ec.code, ec.name
   ORDER BY COALESCE(e.group_type, ec.group_type) ASC, total_amount DESC"
);
$expenseStmt->execute($params);
$expenseRows = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);

$cogsTotal    = 0.0;
$opexTotal    = 0.0;
$excludedTotal = 0.0;
$cogsRows = [];
$opexRows = [];
foreach ($expenseRows as $row) {
  $amount = (float)($row['total_amount'] ?? 0);
  if (($row['group_type'] ?? '') === 'cogs') {
    $cogsTotal += $amount;
    $cogsRows[] = $row;
  } elseif (($row['group_type'] ?? '') === 'excluded') {
    $excludedTotal += $amount;
  } elseif (($row['group_type'] ?? '') === 'opex') {
    $opexTotal += $amount;
    $opexRows[] = $row;
  } else {
    $opexTotal += $amount;
    $opexRows[] = $row;
  }
}

$grossProfit = $totalRevenue - $cogsTotal;
$netProfit   = $grossProfit - $opexTotal;

// -- Monthly breakdown --
$months = pl_export_month_keys($dateFrom, $dateTo);
if ($months) {
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

  $expenseByMonthStmt = $pdo->prepare(
    "SELECT DATE_FORMAT(e.expense_date, '%Y-%m') AS month_key,
            COALESCE(e.group_type, ec.group_type) AS group_type,
            COALESCE(SUM(e.amount), 0) AS expense_total
     FROM expenses e
     INNER JOIN expense_categories ec ON ec.id = e.category_id
     WHERE e.expense_date BETWEEN :date_from AND :date_to
     GROUP BY month_key, COALESCE(e.group_type, ec.group_type)"
  );
  $expenseByMonthStmt->execute($params);
  foreach ($expenseByMonthStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = (string)($row['month_key'] ?? '');
    if ($key === '' || !isset($months[$key])) {
      continue;
    }
    if (($row['group_type'] ?? '') === 'cogs') {
      $months[$key]['cogs'] = (float)($row['expense_total'] ?? 0);
    } elseif (($row['group_type'] ?? '') === 'excluded') {
      continue;
    } else {
      $months[$key]['opex'] = (float)($row['expense_total'] ?? 0);
    }
  }

  foreach ($months as $key => $month) {
    $months[$key]['net'] = $month['revenue'] - $month['cogs'] - $month['opex'];
  }
}

// ---- CSV export ----
if ($format === 'csv') {
  $filename = 'profit_loss_' . $dateFrom . '_to_' . $dateTo . '.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));

  $out = fopen('php://output', 'w');
  if ($out === false) {
    http_response_code(500);
    exit('Unable to open export stream.');
  }

  // Summary section
  fputcsv($out, ['PROFIT & LOSS STATEMENT']);
  fputcsv($out, ['Period', $dateFrom . ' to ' . $dateTo]);
  fputcsv($out, ['Basis', ucfirst($basis)]);
  fputcsv($out, []);

  fputcsv($out, ['SUMMARY']);
  fputcsv($out, ['Metric', 'Amount']);
  fputcsv($out, ['Gross Revenue',              number_format($totalRevenue, 2, '.', '')]);
  fputcsv($out, ['Less COGS',                  number_format(-$cogsTotal,   2, '.', '')]);
  fputcsv($out, ['Gross Profit',               number_format($grossProfit,  2, '.', '')]);
  fputcsv($out, ['Less Operating Expenses',    number_format(-$opexTotal,   2, '.', '')]);
  fputcsv($out, ['Net Profit / Loss',          number_format($netProfit,    2, '.', '')]);
  if ($excludedTotal !== 0.0) {
    fputcsv($out, ['Excluded Expenses (informational)', number_format($excludedTotal, 2, '.', '')]);
  }
  fputcsv($out, ['Sales Tax Collected (informational)', number_format($totalSalesTax, 2, '.', '')]);
  fputcsv($out, ['Revenue Rows Count',         $revenueCount]);
  fputcsv($out, []);

  // Expense breakdown
  fputcsv($out, ['EXPENSE BREAKDOWN']);
  fputcsv($out, ['Group', 'Category Code', 'Category Name', 'Total']);
  foreach ($expenseRows as $row) {
    fputcsv($out, [
      strtoupper((string)($row['group_type'] ?? '')),
      (string)($row['code'] ?? ''),
      (string)($row['name'] ?? ''),
      number_format((float)($row['total_amount'] ?? 0), 2, '.', ''),
    ]);
  }
  fputcsv($out, []);

  // Monthly P&L
  fputcsv($out, ['MONTHLY P&L']);
  fputcsv($out, ['Month', 'Revenue', 'Sales Tax', 'COGS', 'OpEx', 'Net']);
  foreach ($months as $month) {
    fputcsv($out, [
      (string)($month['label'] ?? ''),
      number_format((float)($month['revenue'] ?? 0), 2, '.', ''),
      number_format((float)($month['tax']     ?? 0), 2, '.', ''),
      number_format(-(float)($month['cogs']   ?? 0), 2, '.', ''),
      number_format(-(float)($month['opex']   ?? 0), 2, '.', ''),
      number_format((float)($month['net']     ?? 0), 2, '.', ''),
    ]);
  }

  fclose($out);
  exit;
}

// ---- PDF export (print-optimised HTML) ----
function pl_h(mixed $v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Profit &amp; Loss &mdash; <?= pl_h($dateFrom) ?> to <?= pl_h($dateTo) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; background: #fff; padding: 24px; }
  h1 { font-size: 20px; margin-bottom: 4px; }
  .meta { font-size: 11px; color: #555; margin-bottom: 20px; }
  h2 { font-size: 14px; margin: 24px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
  th, td { padding: 5px 8px; text-align: left; border: 1px solid #ddd; vertical-align: top; }
  thead th { background: #f0f0f0; font-weight: bold; }
  tbody tr:nth-child(even) { background: #fafafa; }
  .summary-th { width: 55%; }
  .amount { text-align: right; white-space: nowrap; }
  .total-row td, .total-row th { font-weight: bold; background: #eef2ff; }
  .net-positive { color: #166534; }
  .net-negative { color: #991b1b; }
  .print-btn { display: inline-block; margin-bottom: 20px; padding: 8px 18px; background: #2563eb; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; text-decoration: none; }
  @media print {
    .print-btn { display: none; }
    body { padding: 0; }
    h2 { page-break-after: avoid; }
    table { page-break-inside: auto; }
    tr { page-break-inside: avoid; page-break-after: auto; }
  }
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">&#128438; Print / Save as PDF</button>

<h1>Profit &amp; Loss Statement</h1>
<p class="meta">
  Period: <strong><?= pl_h($dateFrom) ?> to <?= pl_h($dateTo) ?></strong>
  &nbsp;&bull;&nbsp; Basis: <strong><?= pl_h(ucfirst($basis)) ?></strong>
  &nbsp;&bull;&nbsp; Generated: <strong><?= pl_h((new DateTimeImmutable('now', new DateTimeZone(APP_TZ)))->format('M j, Y g:i A T')) ?></strong>
</p>

<h2>Summary</h2>
<table>
  <tbody>
    <tr><th class="summary-th">Gross Revenue</th><td class="amount"><?= pl_h(pl_export_money($totalRevenue)) ?></td></tr>
    <tr><th class="summary-th">Less COGS (Cost of Goods Sold)</th><td class="amount"><?= ($cogsTotal > 0 ? '-' : '') . pl_h(pl_export_money($cogsTotal)) ?></td></tr>
    <tr><th class="summary-th">Gross Profit</th><td class="amount"><?= pl_h(pl_export_money($grossProfit)) ?></td></tr>
    <tr><th class="summary-th">Less Operating Expenses</th><td class="amount"><?= ($opexTotal > 0 ? '-' : '') . pl_h(pl_export_money($opexTotal)) ?></td></tr>
    <tr class="total-row">
      <th class="summary-th">Net Profit / Loss</th>
      <td class="amount <?= $netProfit >= 0 ? 'net-positive' : 'net-negative' ?>"><?= pl_h(pl_export_money($netProfit)) ?></td>
    </tr>
    <?php if ($excludedTotal !== 0.0): ?>
    <tr><th class="summary-th">Excluded Expenses (not included above)</th><td class="amount"><?= pl_h(pl_export_money($excludedTotal)) ?></td></tr>
    <?php endif; ?>
    <tr><th class="summary-th">Sales Tax Collected (informational)</th><td class="amount"><?= pl_h(pl_export_money($totalSalesTax)) ?></td></tr>
    <tr><th class="summary-th">Revenue Rows Count</th><td class="amount"><?= (int)$revenueCount ?></td></tr>
  </tbody>
</table>

<h2>Expense Breakdown</h2>
<table>
  <thead>
    <tr>
      <th>Group</th>
      <th>Category Code</th>
      <th>Category Name</th>
      <th class="amount">Total</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$expenseRows): ?>
      <tr><td colspan="4" style="color:#777;">No expense records found in this range.</td></tr>
    <?php endif; ?>
    <?php foreach ($expenseRows as $row): ?>
    <tr>
      <td><?= pl_h(strtoupper((string)($row['group_type'] ?? ''))) ?></td>
      <td><?= pl_h((string)($row['code'] ?? '')) ?></td>
      <td><?= pl_h((string)($row['name'] ?? '')) ?></td>
      <td class="amount"><?= pl_h(pl_export_money((float)($row['total_amount'] ?? 0))) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<h2>Monthly P&amp;L</h2>
<table>
  <thead>
    <tr>
      <th>Month</th>
      <th class="amount">Revenue</th>
      <th class="amount">Sales Tax</th>
      <th class="amount">COGS</th>
      <th class="amount">OpEx</th>
      <th class="amount">Net</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$months): ?>
      <tr><td colspan="6" style="color:#777;">No monthly data for selected range.</td></tr>
    <?php endif; ?>
    <?php foreach ($months as $month): ?>
    <?php $net = (float)($month['net'] ?? 0); ?>
    <tr>
      <td><?= pl_h((string)($month['label'] ?? '')) ?></td>
      <td class="amount"><?= pl_h(pl_export_money((float)($month['revenue'] ?? 0))) ?></td>
      <td class="amount"><?= pl_h(pl_export_money((float)($month['tax']     ?? 0))) ?></td>
      <td class="amount"><?= ((float)($month['cogs'] ?? 0) > 0 ? '-' : '') . pl_h(pl_export_money((float)($month['cogs'] ?? 0))) ?></td>
      <td class="amount"><?= ((float)($month['opex'] ?? 0) > 0 ? '-' : '') . pl_h(pl_export_money((float)($month['opex'] ?? 0))) ?></td>
      <td class="amount <?= $net >= 0 ? 'net-positive' : 'net-negative' ?>"><strong><?= pl_h(pl_export_money($net)) ?></strong></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

</body>
</html>
