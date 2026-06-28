<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';

require_login();

$tz = new DateTimeZone('America/Los_Angeles');
$now = new DateTimeImmutable('now', $tz);
$monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
$weekStart = $now->modify('monday this week')->setTime(0, 0, 0);

$monthStartDateTime = $monthStart->format('Y-m-d H:i:s');
$weekStartDateTime = $weekStart->format('Y-m-d H:i:s');
$monthStartDate = $monthStart->format('Y-m-d');
$weekStartDate = $weekStart->format('Y-m-d');

$countScalar = static function (PDO $pdo, string $sql, array $params = []): int {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return (int)$stmt->fetchColumn();
};

$rfqMonth = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM rfq_requests WHERE created_at >= ?",
  [$monthStartDateTime]
);
$rfqWeek = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM rfq_requests WHERE created_at >= ?",
  [$weekStartDateTime]
);

$quotesMonth = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM rfq_quotes WHERE COALESCE(received_on, DATE(created_at)) >= ?",
  [$monthStartDate]
);
$quotesWeek = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM rfq_quotes WHERE COALESCE(received_on, DATE(created_at)) >= ?",
  [$weekStartDate]
);

$poSentFilter = "
  (
    (po_number IS NOT NULL AND po_number <> '')
    OR order_status NOT IN ('create_rfq', 'receive_quotes', 'evaluate_select_quote', 'negotiate_terms', 'cancelled', 'draft')
  )
";

$poMonth = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM rfq_orders WHERE COALESCE(order_date, DATE(created_at)) >= ? AND {$poSentFilter}",
  [$monthStartDate]
);
$poWeek = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM rfq_orders WHERE COALESCE(order_date, DATE(created_at)) >= ? AND {$poSentFilter}",
  [$weekStartDate]
);

$inTransitSoon = $countScalar(
  $pdo,
  "
    SELECT COUNT(*)
    FROM rfq_orders
    WHERE order_status <> 'cancelled'
      AND (
        order_status IN ('vendor_ships_machine', 'receive_tracking_documents', 'arrives_clears_customs', 'shipped', 'ready_to_ship')
        OR (shipped_at IS NOT NULL AND accepted_at IS NULL)
        OR (expected_ship_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY))
        OR (expected_ready_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY))
      )
  "
);

$chartStart = $weekStart->modify('-7 weeks');
$chartStartDate = $chartStart->format('Y-m-d');

$weekMeta = [];
$rfqSeriesMap = [];
$quoteSeriesMap = [];
for ($i = 7; $i >= 0; $i--) {
  $weekDate = $weekStart->modify("-{$i} weeks");
  $key = $weekDate->format('Y-m-d');
  $weekMeta[] = [
    'key' => $key,
    'label' => $weekDate->format('M j'),
  ];
  $rfqSeriesMap[$key] = 0;
  $quoteSeriesMap[$key] = 0;
}

$rfqSeriesStmt = $pdo->prepare("
  SELECT DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY) AS week_start, COUNT(*) AS total
  FROM rfq_requests
  WHERE created_at >= ?
  GROUP BY week_start
");
$rfqSeriesStmt->execute([$chartStart->format('Y-m-d H:i:s')]);
foreach ($rfqSeriesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $weekKey = (string)($row['week_start'] ?? '');
  if (isset($rfqSeriesMap[$weekKey])) {
    $rfqSeriesMap[$weekKey] = (int)$row['total'];
  }
}

$quotesSeriesStmt = $pdo->prepare("
  SELECT DATE_SUB(COALESCE(received_on, DATE(created_at)), INTERVAL WEEKDAY(COALESCE(received_on, DATE(created_at))) DAY) AS week_start, COUNT(*) AS total
  FROM rfq_quotes
  WHERE COALESCE(received_on, DATE(created_at)) >= ?
  GROUP BY week_start
");
$quotesSeriesStmt->execute([$chartStartDate]);
foreach ($quotesSeriesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $weekKey = (string)($row['week_start'] ?? '');
  if (isset($quoteSeriesMap[$weekKey])) {
    $quoteSeriesMap[$weekKey] = (int)$row['total'];
  }
}

$chartLabels = array_map(static fn(array $w): string => $w['label'], $weekMeta);
$rfqWeekly = array_map(static fn(array $w): int => $rfqSeriesMap[$w['key']] ?? 0, $weekMeta);
$quotesWeekly = array_map(static fn(array $w): int => $quoteSeriesMap[$w['key']] ?? 0, $weekMeta);

render_header('CEO Dashboard');
?>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
  <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
      <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Executive Overview</p>
      <h1 class="mt-1 text-3xl font-bold text-slate-900">CEO Dashboard</h1>
      <p class="mt-2 text-sm text-slate-600">Live sourcing and procurement metrics for the current week and month.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Updated</p>
      <p class="mt-1 text-sm font-medium text-slate-700"><?= htmlspecialchars($now->format('M j, Y g:i A T'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </div>

  <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Total RFQs Created</p>
      <p class="mt-3 text-3xl font-bold text-slate-900"><?= number_format($rfqMonth) ?></p>
      <p class="mt-2 text-sm text-slate-500">This month</p>
      <p class="mt-1 text-sm font-medium text-slate-700">Week: <?= number_format($rfqWeek) ?></p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <p class="text-xs font-semibold uppercase tracking-wide text-emerald-600">Quotes Received</p>
      <p class="mt-3 text-3xl font-bold text-slate-900"><?= number_format($quotesMonth) ?></p>
      <p class="mt-2 text-sm text-slate-500">This month</p>
      <p class="mt-1 text-sm font-medium text-slate-700">Week: <?= number_format($quotesWeek) ?></p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <p class="text-xs font-semibold uppercase tracking-wide text-blue-600">Purchase Orders Sent</p>
      <p class="mt-3 text-3xl font-bold text-slate-900"><?= number_format($poMonth) ?></p>
      <p class="mt-2 text-sm text-slate-500">This month</p>
      <p class="mt-1 text-sm font-medium text-slate-700">Week: <?= number_format($poWeek) ?></p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <p class="text-xs font-semibold uppercase tracking-wide text-amber-600">In Transit / Expected Soon</p>
      <p class="mt-3 text-3xl font-bold text-slate-900"><?= number_format($inTransitSoon) ?></p>
      <p class="mt-2 text-sm text-slate-500">Active items</p>
      <p class="mt-1 text-sm font-medium text-slate-700">Next 14 days + in-transit</p>
    </div>
  </div>

  <div class="mt-8 grid grid-cols-1 gap-6 xl:grid-cols-2">
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="mb-4">
        <h2 class="text-lg font-semibold text-slate-900">Weekly RFQ Activity</h2>
        <p class="text-sm text-slate-500">Last 8 weeks</p>
      </div>
      <div class="h-80"><canvas id="rfqWeeklyChart"></canvas></div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="mb-4">
        <h2 class="text-lg font-semibold text-slate-900">Weekly Quotes Received</h2>
        <p class="text-sm text-slate-500">Last 8 weeks</p>
      </div>
      <div class="h-80"><canvas id="quotesWeeklyChart"></canvas></div>
    </section>
  </div>
</div>

<script>
  (() => {
    const labels = <?= json_encode($chartLabels, JSON_UNESCAPED_SLASHES) ?>;
    const rfqWeekly = <?= json_encode($rfqWeekly, JSON_UNESCAPED_SLASHES) ?>;
    const quotesWeekly = <?= json_encode($quotesWeekly, JSON_UNESCAPED_SLASHES) ?>;

    const axisBase = {
      beginAtZero: true,
      ticks: { precision: 0, color: '#64748b' },
      grid: { color: 'rgba(148, 163, 184, 0.25)' }
    };

    new Chart(document.getElementById('rfqWeeklyChart'), {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'RFQs',
          data: rfqWeekly,
          borderRadius: 6,
          backgroundColor: 'rgba(79, 70, 229, 0.82)',
          hoverBackgroundColor: 'rgba(67, 56, 202, 0.95)'
        }]
      },
      options: {
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          x: { ticks: { color: '#64748b' }, grid: { display: false } },
          y: axisBase
        }
      }
    });

    new Chart(document.getElementById('quotesWeeklyChart'), {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Quotes',
          data: quotesWeekly,
          borderColor: 'rgba(5, 150, 105, 1)',
          backgroundColor: 'rgba(5, 150, 105, 0.18)',
          pointBackgroundColor: 'rgba(5, 150, 105, 1)',
          pointRadius: 4,
          pointHoverRadius: 5,
          borderWidth: 3,
          tension: 0.35,
          fill: true
        }]
      },
      options: {
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          x: { ticks: { color: '#64748b' }, grid: { display: false } },
          y: axisBase
        }
      }
    });
  })();
</script>

<?php render_footer(); ?>
