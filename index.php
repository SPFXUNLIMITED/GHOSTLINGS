<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';

require_login();

function dashboard_table_exists(PDO $pdo, string $table): bool {
  static $cache = [];
  if (array_key_exists($table, $cache)) {
    return $cache[$table];
  }

  try {
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    return $cache[$table] = (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    error_log('Dashboard table check failed: ' . $e->getMessage());
    return $cache[$table] = false;
  }
}

function dashboard_money(float $value): string {
  return '$' . number_format($value, 2);
}

function dashboard_invoice_condition(): string {
  return "(status = 'converted' OR (converted_invoice_no IS NOT NULL AND converted_invoice_no <> ''))";
}

$tz = new DateTimeZone(defined('APP_TZ') ? APP_TZ : date_default_timezone_get());
$today = new DateTimeImmutable('now', $tz);
$json_safe_flags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$kpis = [
  'total_quoted' => 0.0,
  'total_invoiced' => 0.0,
  'total_received' => 0.0,
  'outstanding' => 0.0,
  'converted_quotes' => 0,
  'total_quotes' => 0,
];

$week_kpis = [
  'week_quoted' => 0.0,
  'week_invoiced' => 0.0,
  'week_received' => 0.0,
  'week_outstanding' => 0.0,
];

$month_kpis = [
  'month_quoted' => 0.0,
  'month_invoiced' => 0.0,
  'month_received' => 0.0,
  'month_outstanding' => 0.0,
  'month_converted_quotes' => 0,
  'month_total_quotes' => 0,
];

$recent_quotes = [];
$recent_invoices = [];

$month_series = [];
for ($i = 5; $i >= 0; $i--) {
  $month_date = $today->modify("first day of -{$i} month");
  $month_key = $month_date->format('Y-m');
  $month_series[$month_key] = [
    'label' => $month_date->format('M Y'),
    'value' => 0.0,
  ];
}
$months_start = array_key_first($month_series) . '-01';

$cash_series = [];
foreach ($month_series as $key => $entry) {
  $cash_series[$key] = ['label' => $entry['label'], 'value' => 0.0];
}

if (dashboard_table_exists($pdo, 'quotes')) {
  $invoice_condition = dashboard_invoice_condition();
  try {
    $kpi_stmt = $pdo->query("
      SELECT
        COALESCE(SUM(subtotal_amount), 0) AS total_quoted,
        COALESCE(SUM(CASE WHEN {$invoice_condition} THEN subtotal_amount ELSE 0 END), 0) AS total_invoiced,
        COALESCE(SUM(CASE WHEN {$invoice_condition} THEN 1 ELSE 0 END), 0) AS converted_quotes,
        COUNT(*) AS total_quotes
      FROM quotes
    ");
    $kpi_row = $kpi_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $kpis['total_quoted'] = (float)($kpi_row['total_quoted'] ?? 0);
    $kpis['total_invoiced'] = (float)($kpi_row['total_invoiced'] ?? 0);
    $kpis['converted_quotes'] = (int)($kpi_row['converted_quotes'] ?? 0);
    $kpis['total_quotes'] = (int)($kpi_row['total_quotes'] ?? 0);

    $week_stmt = $pdo->query("
      SELECT
        COALESCE(SUM(CASE WHEN YEARWEEK(COALESCE(quote_date, created_at), 1) = YEARWEEK(CURDATE(), 1)
                          THEN subtotal_amount ELSE 0 END), 0) AS week_quoted,
        COALESCE(SUM(CASE WHEN {$invoice_condition}
                          AND YEARWEEK(COALESCE(converted_at, quote_date, created_at), 1) = YEARWEEK(CURDATE(), 1)
                          THEN subtotal_amount ELSE 0 END), 0) AS week_invoiced
      FROM quotes
    ");
    $week_row = $week_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $week_kpis['week_quoted'] = (float)($week_row['week_quoted'] ?? 0);
    $week_kpis['week_invoiced'] = (float)($week_row['week_invoiced'] ?? 0);

    $month_stmt = $pdo->query("
      SELECT
        COALESCE(SUM(CASE WHEN YEAR(COALESCE(quote_date, created_at)) = YEAR(CURDATE())
                          AND MONTH(COALESCE(quote_date, created_at)) = MONTH(CURDATE())
                          THEN subtotal_amount ELSE 0 END), 0) AS month_quoted,
        COALESCE(SUM(CASE WHEN {$invoice_condition}
                          AND YEAR(COALESCE(converted_at, quote_date, created_at)) = YEAR(CURDATE())
                          AND MONTH(COALESCE(converted_at, quote_date, created_at)) = MONTH(CURDATE())
                          THEN subtotal_amount ELSE 0 END), 0) AS month_invoiced,
        COALESCE(SUM(CASE WHEN {$invoice_condition}
                          AND YEAR(COALESCE(converted_at, quote_date, created_at)) = YEAR(CURDATE())
                          AND MONTH(COALESCE(converted_at, quote_date, created_at)) = MONTH(CURDATE())
                          THEN 1 ELSE 0 END), 0) AS month_converted_quotes,
        COALESCE(SUM(CASE WHEN YEAR(COALESCE(quote_date, created_at)) = YEAR(CURDATE())
                          AND MONTH(COALESCE(quote_date, created_at)) = MONTH(CURDATE())
                          THEN 1 ELSE 0 END), 0) AS month_total_quotes
      FROM quotes
    ");
    $month_row = $month_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $month_kpis['month_quoted'] = (float)($month_row['month_quoted'] ?? 0);
    $month_kpis['month_invoiced'] = (float)($month_row['month_invoiced'] ?? 0);
    $month_kpis['month_converted_quotes'] = (int)($month_row['month_converted_quotes'] ?? 0);
    $month_kpis['month_total_quotes'] = (int)($month_row['month_total_quotes'] ?? 0);

    $revenue_stmt = $pdo->prepare("
      SELECT
        DATE_FORMAT(COALESCE(converted_at, quote_date, created_at), '%Y-%m') AS month_key,
        COALESCE(SUM(subtotal_amount), 0) AS month_total
      FROM quotes
      WHERE {$invoice_condition}
        AND COALESCE(converted_at, quote_date, created_at) >= :months_start
      GROUP BY month_key
      ORDER BY month_key ASC
    ");
    $revenue_stmt->execute([':months_start' => $months_start]);

    foreach ($revenue_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $month_key = trim((string)($row['month_key'] ?? ''));
      if ($month_key !== '' && isset($month_series[$month_key])) {
        $month_series[$month_key]['value'] = (float)($row['month_total'] ?? 0);
      }
    }

    // Pull received totals and cash trend from actual customer payment records
    if (dashboard_table_exists($pdo, 'customer_payments')) {
      $cp_totals_stmt = $pdo->query("
        SELECT
          COALESCE(SUM(amount), 0) AS total_received,
          COALESCE(SUM(CASE WHEN YEARWEEK(payment_date, 1) = YEARWEEK(CURDATE(), 1)
                            THEN amount ELSE 0 END), 0) AS week_received,
          COALESCE(SUM(CASE WHEN YEAR(payment_date) = YEAR(CURDATE())
                            AND MONTH(payment_date) = MONTH(CURDATE())
                            THEN amount ELSE 0 END), 0) AS month_received
        FROM customer_payments
      ");
      $cp_row = $cp_totals_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

      $kpis['total_received']       = (float)($cp_row['total_received'] ?? 0);
      $week_kpis['week_received']   = (float)($cp_row['week_received'] ?? 0);
      $month_kpis['month_received'] = (float)($cp_row['month_received'] ?? 0);

      $cash_stmt = $pdo->prepare("
        SELECT
          DATE_FORMAT(payment_date, '%Y-%m') AS month_key,
          COALESCE(SUM(amount), 0) AS month_total
        FROM customer_payments
        WHERE payment_date >= :months_start
        GROUP BY month_key
        ORDER BY month_key ASC
      ");
      $cash_stmt->execute([':months_start' => $months_start]);
      foreach ($cash_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $month_key = trim((string)($row['month_key'] ?? ''));
        if ($month_key !== '' && isset($cash_series[$month_key])) {
          $cash_series[$month_key]['value'] = (float)($row['month_total'] ?? 0);
        }
      }
    }

    // Derive outstanding from invoiced minus received (received defaults to 0 if table unavailable)
    $kpis['outstanding']             = max(0, $kpis['total_invoiced'] - $kpis['total_received']);
    $week_kpis['week_outstanding']   = max(0, $week_kpis['week_invoiced'] - $week_kpis['week_received']);
    $month_kpis['month_outstanding'] = max(0, $month_kpis['month_invoiced'] - $month_kpis['month_received']);

    $recent_quotes_stmt = $pdo->query("
      SELECT id, customer_name, quote_date, status, subtotal_amount, created_at
      FROM quotes
      ORDER BY created_at DESC, id DESC
      LIMIT 8
    ");
    $recent_quotes = $recent_quotes_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $recent_invoices_stmt = $pdo->query("
      SELECT id, customer_name, COALESCE(converted_invoice_no, '') AS converted_invoice_no,
             COALESCE(converted_at, quote_date, created_at) AS invoice_date,
             payment_status, subtotal_amount
      FROM quotes
      WHERE {$invoice_condition}
      ORDER BY COALESCE(converted_at, quote_date, created_at) DESC, id DESC
      LIMIT 8
    ");
    $recent_invoices = $recent_invoices_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    error_log('Dashboard data load failed: ' . $e->getMessage());
  }
}

$revenue_series = array_slice($month_series, -3, 3, true);
$line_chart_labels = array_values(array_map(static fn(array $m): string => (string)$m['label'], $revenue_series));
$line_chart_values = array_values(array_map(static fn(array $m): float => (float)$m['value'], $revenue_series));

$conversion_converted = max(0, (int)$month_kpis['month_converted_quotes']);
$conversion_open = max(0, (int)$month_kpis['month_total_quotes'] - $conversion_converted);

$cash_chart_values = array_values(array_map(static fn(array $m): float => (float)$m['value'], $cash_series));
$cash_collected = max(0.0, (float)$month_kpis['month_received']);
$cash_outstanding = max(0.0, (float)$month_kpis['month_outstanding']);

render_header('ERP Dashboard');
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<style>
.dashboard {
  max-width: 1320px;
  margin: 0 auto;
  display: grid;
  gap: 20px;
  color: #0f172a;
}

.dashboard-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
  padding: 20px;
}

.dashboard-hero {
  background: linear-gradient(120deg, #0f172a, #1d4ed8 60%, #0f766e);
  color: #f8fafc;
  border: 0;
  padding: 28px;
}

.dashboard-hero h1 {
  margin: 0 0 8px;
  font-size: 2rem;
  letter-spacing: -0.02em;
}

.dashboard-hero p {
  margin: 0;
  color: #dbeafe;
}

.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
}

.kpi-label {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: #64748b;
  font-weight: 700;
}

.kpi-value {
  margin-top: 10px;
  font-size: 2rem;
  line-height: 1.1;
  font-weight: 800;
}

.kpi-note {
  margin-top: 8px;
  font-size: 12px;
  color: #64748b;
}

.charts-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 16px;
}

.chart-wrap {
  min-height: 320px;
}

.chart-wrap canvas {
  width: 100% !important;
  height: 260px !important;
  display: block;
}

.section-title {
  margin: 0 0 4px;
  font-size: 1.1rem;
  font-weight: 700;
}

.section-subtitle {
  margin: 0 0 16px;
  color: #64748b;
  font-size: 13px;
}

.tables-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.erp-table {
  width: 100%;
  border-collapse: collapse;
}

.erp-table th,
.erp-table td {
  text-align: left;
  padding: 10px 8px;
  border-bottom: 1px solid #e2e8f0;
  font-size: 13px;
}

.erp-table th {
  font-size: 11px;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: .08em;
}

.erp-table .erp-row-link {
  cursor: pointer;
  transition: background-color .15s ease;
}

.erp-table .erp-row-link:hover td,
.erp-table .erp-row-link:focus-visible td {
  background: #f8fafc;
}

.status-pill {
  display: inline-flex;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
}

.status-pill.converted,
.status-pill.paid {
  background: #dcfce7;
  color: #166534;
}

.status-pill.sent {
  background: #dbeafe;
  color: #1d4ed8;
}

.status-pill.draft,
.status-pill.unpaid {
  background: #f1f5f9;
  color: #475569;
}

@media (max-width: 1080px) {
  .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .charts-grid,
  .tables-grid { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
  .kpi-grid { grid-template-columns: 1fr; }
  .dashboard-hero h1 { font-size: 1.5rem; }
}
</style>

<div class="dashboard">
  <div class="dashboard-card dashboard-hero">
    <h1>Executive ERP Dashboard</h1>
    <p>Live financial and sales visibility across quoting, invoicing, and collections.</p>
  </div>

  <?php if (is_admin()): ?>
  <div class="kpi-grid">
    <div class="dashboard-card admin-only-stat">
      <div class="kpi-label">Total Quoted</div>
      <div class="kpi-value" style="color:#1d4ed8;"><?= h(dashboard_money((float)$kpis['total_quoted'])) ?></div>
      <div class="kpi-note">All quotes in system</div>
    </div>
    <div class="dashboard-card admin-only-stat">
      <div class="kpi-label">Total Invoiced</div>
      <div class="kpi-value" style="color:#0f766e;"><?= h(dashboard_money((float)$kpis['total_invoiced'])) ?></div>
      <div class="kpi-note">Converted from quotes</div>
    </div>
    <div class="dashboard-card admin-only-stat">
      <div class="kpi-label">Total Received</div>
      <div class="kpi-value" style="color:#16a34a;"><?= h(dashboard_money((float)$kpis['total_received'])) ?></div>
      <div class="kpi-note">Actual recorded payments</div>
    </div>
    <div class="dashboard-card admin-only-stat">
      <div class="kpi-label">Outstanding</div>
      <div class="kpi-value" style="color:#b45309;"><?= h(dashboard_money((float)$kpis['outstanding'])) ?></div>
      <div class="kpi-note">Invoiced minus received</div>
    </div>
  </div>
  <?php endif; ?>

  <h2 class="section-title" style="margin:0;">This Month</h2>
  <div class="kpi-grid">
    <div class="dashboard-card">
      <div class="kpi-label">Total Quoted This Month</div>
      <div class="kpi-value" style="color:#1d4ed8;"><?= h(dashboard_money((float)$month_kpis['month_quoted'])) ?></div>
      <div class="kpi-note">Quotes dated or created this month</div>
    </div>
    <div class="dashboard-card">
      <div class="kpi-label">Total Invoiced This Month</div>
      <div class="kpi-value" style="color:#0f766e;"><?= h(dashboard_money((float)$month_kpis['month_invoiced'])) ?></div>
      <div class="kpi-note">Converted invoices dated this month</div>
    </div>
    <div class="dashboard-card">
      <div class="kpi-label">Total Received This Month</div>
      <div class="kpi-value" style="color:#16a34a;"><?= h(dashboard_money((float)$month_kpis['month_received'])) ?></div>
      <div class="kpi-note">Paid invoices this month</div>
    </div>
    <div class="dashboard-card">
      <div class="kpi-label">Outstanding This Month</div>
      <div class="kpi-value" style="color:#b45309;"><?= h(dashboard_money((float)$month_kpis['month_outstanding'])) ?></div>
      <div class="kpi-note">Invoiced minus received this month</div>
    </div>
  </div>

  <div class="charts-grid">
    <div class="dashboard-card chart-wrap">
      <h2 class="section-title">Revenue Trend (Past 3 Months)</h2>
      <p class="section-subtitle">Monthly invoiced revenue from converted quotes.</p>
      <canvas id="revenueChart" aria-label="Revenue trend line chart" role="img"></canvas>
    </div>
    <div class="dashboard-card chart-wrap">
      <h2 class="section-title">Quote-to-Invoice Conversion</h2>
      <p class="section-subtitle">This Month: converted vs. open quotes.</p>
      <canvas id="conversionChart" aria-label="Quote conversion pie chart" role="img"></canvas>
    </div>
  </div>

  <div class="kpi-grid">
    <div class="dashboard-card">
      <div class="kpi-label">Total Quoted This Week</div>
      <div class="kpi-value" style="color:#1d4ed8;"><?= h(dashboard_money((float)$week_kpis['week_quoted'])) ?></div>
      <div class="kpi-note">Quotes dated or created this week</div>
    </div>
    <div class="dashboard-card">
      <div class="kpi-label">Total Invoiced This Week</div>
      <div class="kpi-value" style="color:#0f766e;"><?= h(dashboard_money((float)$week_kpis['week_invoiced'])) ?></div>
      <div class="kpi-note">Converted invoices dated this week</div>
    </div>
    <div class="dashboard-card">
      <div class="kpi-label">Total Received This Week</div>
      <div class="kpi-value" style="color:#16a34a;"><?= h(dashboard_money((float)$week_kpis['week_received'])) ?></div>
      <div class="kpi-note">Paid invoices this week</div>
    </div>
    <div class="dashboard-card">
      <div class="kpi-label">Outstanding This Week</div>
      <div class="kpi-value" style="color:#b45309;"><?= h(dashboard_money((float)$week_kpis['week_outstanding'])) ?></div>
      <div class="kpi-note">Invoiced minus received this week</div>
    </div>
  </div>
  
  
  <div class="charts-grid">
    <div class="dashboard-card chart-wrap">
      <h2 class="section-title">Cash Received Trend (Past 3 Months)</h2>
      <p class="section-subtitle">Invoice amounts for paid-status invoices, grouped by invoice date.</p>
      <canvas id="cashReceivedChart" aria-label="Cash received trend line chart" role="img"></canvas>
    </div>
    <div class="dashboard-card chart-wrap">
      <h2 class="section-title">Invoice to Cash Conversion</h2>
      <p class="section-subtitle">This Month: collected versus outstanding</p>
      <canvas id="cashConversionChart" aria-label="Invoice to cash conversion pie chart" role="img"></canvas>
    </div>
  </div>

  <div class="tables-grid">
    <div class="dashboard-card">
      <h2 class="section-title">Recent Quotes</h2>
      <p class="section-subtitle">Latest quote activity.</p>
      <table class="erp-table">
        <thead>
          <tr>
            <th>Quote #</th>
            <th>Customer</th>
            <th>Date</th>
            <th>Status</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($recent_quotes): ?>
            <?php foreach ($recent_quotes as $row):
              $status = strtolower(trim((string)($row['status'] ?? 'draft')));
              $quote_date = trim((string)($row['quote_date'] ?? ''));
              $display_date = $quote_date !== '' ? $quote_date : substr((string)($row['created_at'] ?? ''), 0, 10);
            ?>
              <tr class="erp-row-link" data-href="quotes.php?view=id&id=<?= (int)($row['id'] ?? 0) ?>" tabindex="0">
                <td>#<?= (int)($row['id'] ?? 0) ?></td>
                <td><?= h((string)($row['customer_name'] ?? '—')) ?></td>
                <td><?= h($display_date !== '' ? $display_date : '—') ?></td>
                <td><span class="status-pill <?= h($status) ?>"><?= h(ucfirst($status)) ?></span></td>
                <td><?= h(dashboard_money((float)($row['subtotal_amount'] ?? 0))) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" style="color:#64748b;">No quotes available.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="dashboard-card">
      <h2 class="section-title">Recent Invoices</h2>
      <p class="section-subtitle">Latest converted invoice activity.</p>
      <table class="erp-table">
        <thead>
          <tr>
            <th>Invoice #</th>
            <th>Customer</th>
            <th>Date</th>
            <th>Payment</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($recent_invoices): ?>
            <?php foreach ($recent_invoices as $row):
              $payment = strtolower(trim((string)($row['payment_status'] ?? 'unpaid')));
              $invoice_no = trim((string)($row['converted_invoice_no'] ?? ''));
              $invoice_date = substr((string)($row['invoice_date'] ?? ''), 0, 10);
            ?>
              <tr class="erp-row-link" data-href="invoice_form.php?id=<?= (int)($row['id'] ?? 0) ?>&mode=view" tabindex="0">
                <td><?= h($invoice_no !== '' ? $invoice_no : ('INV-' . str_pad((string)((int)($row['id'] ?? 0)), 5, '0', STR_PAD_LEFT))) ?></td>
                <td><?= h((string)($row['customer_name'] ?? '—')) ?></td>
                <td><?= h($invoice_date !== '' ? $invoice_date : '—') ?></td>
                <td><span class="status-pill <?= h($payment) ?>"><?= h(ucfirst($payment)) ?></span></td>
                <td><?= h(dashboard_money((float)($row['subtotal_amount'] ?? 0))) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" style="color:#64748b;">No converted invoices available.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(() => {
  const interactiveSelector = "a, button, input, select, textarea, label, [role='button'], [role='link']";

  document.querySelectorAll('.erp-row-link[data-href]').forEach((row) => {
    const href = row.getAttribute('data-href');
    if (!href) return;
    row.addEventListener('click', (event) => {
      if (event.target.closest(interactiveSelector)) return;
      window.location.href = href;
    });
    row.addEventListener('keydown', (event) => {
      const isEnter = event.key === 'Enter';
      const isSpace = event.key === ' ' || event.code === 'Space';
      if (!isEnter && !isSpace) return;
      event.preventDefault();
      window.location.href = href;
    });
  });

  const revenueLabels = <?= json_encode($line_chart_labels, $json_safe_flags) ?>;
  const revenueValues = <?= json_encode($line_chart_values, $json_safe_flags) ?>;
  const conversionValues = <?= json_encode([$conversion_converted, $conversion_open], $json_safe_flags) ?>;

  const currencyTooltip = {
    callbacks: {
      label(ctx) {
        const parsed = ctx.parsed;
        const val = Number(parsed && typeof parsed === 'object' ? (parsed.y ?? 0) : (parsed ?? 0));
        return '$' + val.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }
    }
  };

  const revenueEl = document.getElementById('revenueChart');
  if (revenueEl && window.Chart) {
    new Chart(revenueEl, {
      type: 'line',
      data: {
        labels: revenueLabels,
        datasets: [{
          label: 'Revenue',
          data: revenueValues,
          borderColor: '#2563eb',
          backgroundColor: 'rgba(37, 99, 235, 0.12)',
          borderWidth: 3,
          fill: true,
          pointRadius: 4,
          pointHoverRadius: 6,
          tension: 0.35
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: currencyTooltip
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { color: '#64748b', font: { weight: '600' } }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(148, 163, 184, 0.2)' },
            ticks: {
              color: '#64748b',
              callback(value) {
                return '$' + Number(value).toLocaleString();
              }
            }
          }
        }
      }
    });
  }

  const conversionEl = document.getElementById('conversionChart');
  if (conversionEl && window.Chart) {
    new Chart(conversionEl, {
      type: 'pie',
      data: {
        labels: ['Converted', 'Open'],
        datasets: [{
          data: conversionValues,
          backgroundColor: ['#0f766e', '#cbd5e1'],
          borderColor: '#ffffff',
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: { color: '#334155', boxWidth: 12, usePointStyle: true }
          },
          tooltip: {
            callbacks: {
              label(ctx) {
                const total = (ctx.dataset.data || []).reduce((sum, n) => sum + Number(n || 0), 0);
                const value = Number(ctx.parsed || 0);
                const pct = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                return `${ctx.label}: ${value} (${pct}%)`;
              }
            }
          }
        }
      }
    });
  }

  const cashReceivedEl = document.getElementById('cashReceivedChart');
  if (cashReceivedEl && window.Chart) {
    new Chart(cashReceivedEl, {
      type: 'line',
      data: {
        labels: <?= json_encode($line_chart_labels, $json_safe_flags) ?>,
        datasets: [{
          label: 'Cash Received',
          data: <?= json_encode($cash_chart_values, $json_safe_flags) ?>,
          borderColor: '#16a34a',
          backgroundColor: 'rgba(22, 163, 74, 0.12)',
          borderWidth: 3,
          fill: true,
          pointRadius: 4,
          pointHoverRadius: 6,
          tension: 0.35
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: currencyTooltip
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { color: '#64748b', font: { weight: '600' } }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(148, 163, 184, 0.2)' },
            ticks: {
              color: '#64748b',
              callback(value) {
                return '$' + Number(value).toLocaleString();
              }
            }
          }
        }
      }
    });
  }

  const cashConversionEl = document.getElementById('cashConversionChart');
  if (cashConversionEl && window.Chart) {
    new Chart(cashConversionEl, {
      type: 'pie',
      data: {
        labels: ['Collected', 'Outstanding'],
        datasets: [{
          data: <?= json_encode([$cash_collected, $cash_outstanding], $json_safe_flags) ?>,
          backgroundColor: ['#16a34a', '#f59e0b'],
          borderColor: '#ffffff',
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: { color: '#334155', boxWidth: 12, usePointStyle: true }
          },
          tooltip: {
            callbacks: {
              label(ctx) {
                const val = Number(ctx.parsed || 0);
                const total = (ctx.dataset.data || []).reduce((sum, n) => sum + Number(n || 0), 0);
                const pct = total > 0 ? ((val / total) * 100).toFixed(1) : '0.0';
                return `${ctx.label}: $${val.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} (${pct}%)`;
              }
            }
          }
        }
      }
    });
  }
})();
</script>

<?php render_footer(); ?>
