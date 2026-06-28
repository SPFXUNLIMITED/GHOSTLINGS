<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';

require_login();

$tz = new DateTimeZone('America/Los_Angeles');
$now = new DateTimeImmutable('now', $tz);
$weekStart = $now->modify('monday this week')->setTime(0, 0, 0);

$weekStartDatetime = $weekStart->format('Y-m-d H:i:s');
$weekStartDate     = $weekStart->format('Y-m-d');

$countScalar = static function (PDO $pdo, string $sql, array $params = []): int {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return (int)$stmt->fetchColumn();
};

// 1. RFQs Created this week
$rfqWeek = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM rfq_requests WHERE created_at >= ?",
  [$weekStartDatetime]
);

// 2. Quotes Received this week
$quotesWeek = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM rfq_quotes WHERE COALESCE(received_on, DATE(created_at)) >= ?",
  [$weekStartDate]
);

// 3. Purchase Orders Sent this week
$poSentFilter = "(
  (po_number IS NOT NULL AND po_number <> '')
  OR order_status NOT IN ('create_rfq','receive_quotes','evaluate_select_quote','negotiate_terms','cancelled','draft')
)";
$poWeek = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM rfq_orders WHERE COALESCE(order_date, DATE(created_at)) >= ? AND {$poSentFilter}",
  [$weekStartDate]
);

// 4. Freight Quotes Received this week
$freightQuotesWeek = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM shipping_rfq_quotes WHERE COALESCE(received_on, DATE(created_at)) >= ?",
  [$weekStartDate]
);

// 5. Shipments Booked / In Transit
//    rfq_orders that are shipped/in-transit stages + incoming_shipments that are Ordered or In Transit
$shipmentsBooked = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM rfq_orders WHERE order_status IN (
    'send_purchase_order','vendor_accepts_po','make_deposit_payment',
    'vendor_produces_machine','make_final_payment','vendor_ships_machine',
    'receive_tracking_documents','arrives_clears_customs'
  )"
);
$shipmentsIncoming = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM incoming_shipments WHERE status IN ('Ordered','In Transit')"
);
$shipmentsTotal = $shipmentsBooked + $shipmentsIncoming;

// 6. Items Currently In Production
$inProduction = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM rfq_orders WHERE order_status IN (
    'vendor_accepts_po','make_deposit_payment','vendor_produces_machine','make_final_payment'
  )"
);

// 7. Items Expected to Arrive in next 30 days
$arrivingSoon = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM rfq_orders
   WHERE order_status <> 'cancelled'
     AND (
       (expected_ship_date IS NOT NULL AND expected_ship_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
       OR (expected_ready_date IS NOT NULL AND expected_ready_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
     )"
);
$arrivingIncoming = $countScalar(
  $pdo,
  "SELECT COUNT(*) FROM incoming_shipments
   WHERE status NOT IN ('Received')
     AND expected_arrival BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
);
$arrivingSoonTotal = $arrivingSoon + $arrivingIncoming;

// --- Chart: Items Shipped per Week (last 8 weeks) ---
$chartStart      = $weekStart->modify('-7 weeks');
$chartStartDatetime = $chartStart->format('Y-m-d H:i:s');

$weekMeta       = [];
$shippedSeriesMap = [];
for ($i = 7; $i >= 0; $i--) {
  $weekDate = $weekStart->modify("-{$i} weeks");
  $key      = $weekDate->format('Y-m-d');
  $weekMeta[]               = ['key' => $key, 'label' => $weekDate->format('M j')];
  $shippedSeriesMap[$key]   = 0;
}

$shippedStmt = $pdo->prepare("
  SELECT DATE_SUB(DATE(shipped_at), INTERVAL WEEKDAY(shipped_at) DAY) AS week_start,
         COUNT(*) AS total
  FROM rfq_orders
  WHERE shipped_at IS NOT NULL
    AND shipped_at >= ?
  GROUP BY week_start
");
$shippedStmt->execute([$chartStartDatetime]);
foreach ($shippedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $wk = (string)($row['week_start'] ?? '');
  if (isset($shippedSeriesMap[$wk])) {
    $shippedSeriesMap[$wk] = (int)$row['total'];
  }
}

$chartLabels   = array_map(static fn(array $w): string => $w['label'], $weekMeta);
$shippedWeekly = array_map(static fn(array $w): int => $shippedSeriesMap[$w['key']] ?? 0, $weekMeta);

render_header('CEO Dashboard');
?>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

  <!-- Page header -->
  <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
      <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Executive Overview</p>
      <h1 class="mt-1 text-3xl font-bold text-slate-900">CEO Dashboard</h1>
      <p class="mt-2 text-sm text-slate-600">Full procurement pipeline &mdash; live metrics for the current week.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Updated</p>
      <p class="mt-1 text-sm font-medium text-slate-700"><?= htmlspecialchars($now->format('M j, Y g:i A T'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </div>

  <!-- Section label: Weekly Activity -->
  <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-slate-400">This Week</p>

  <!-- KPI cards row 1: weekly activity (4 cards) -->
  <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex items-center gap-3 mb-4">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 text-base font-bold">RFQ</span>
        <p class="text-sm font-semibold text-slate-600">RFQs Created</p>
      </div>
      <p class="text-4xl font-extrabold text-slate-900"><?= number_format($rfqWeek) ?></p>
      <p class="mt-2 text-xs text-slate-400">This week</p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex items-center gap-3 mb-4">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 text-base font-bold">Q</span>
        <p class="text-sm font-semibold text-slate-600">Quotes Received</p>
      </div>
      <p class="text-4xl font-extrabold text-slate-900"><?= number_format($quotesWeek) ?></p>
      <p class="mt-2 text-xs text-slate-400">This week</p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex items-center gap-3 mb-4">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600 text-base font-bold">PO</span>
        <p class="text-sm font-semibold text-slate-600">Purchase Orders Sent</p>
      </div>
      <p class="text-4xl font-extrabold text-slate-900"><?= number_format($poWeek) ?></p>
      <p class="mt-2 text-xs text-slate-400">This week</p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex items-center gap-3 mb-4">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-cyan-50 text-cyan-600 text-base font-bold">FQ</span>
        <p class="text-sm font-semibold text-slate-600">Freight Quotes Received</p>
      </div>
      <p class="text-4xl font-extrabold text-slate-900"><?= number_format($freightQuotesWeek) ?></p>
      <p class="mt-2 text-xs text-slate-400">This week</p>
    </div>

  </div>

  <!-- Section label: Pipeline Status -->
  <p class="mt-8 mb-3 text-xs font-semibold uppercase tracking-widest text-slate-400">Pipeline Status</p>

  <!-- KPI cards row 2: pipeline status (3 cards) -->
  <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex items-center gap-3 mb-4">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600 text-xl">🚢</span>
        <p class="text-sm font-semibold text-slate-600">Shipments Booked / In Transit</p>
      </div>
      <p class="text-4xl font-extrabold text-slate-900"><?= number_format($shipmentsTotal) ?></p>
      <p class="mt-2 text-xs text-slate-400">Active orders &amp; tracked shipments</p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex items-center gap-3 mb-4">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-orange-50 text-orange-600 text-xl">⚙️</span>
        <p class="text-sm font-semibold text-slate-600">Items Currently In Production</p>
      </div>
      <p class="text-4xl font-extrabold text-slate-900"><?= number_format($inProduction) ?></p>
      <p class="mt-2 text-xs text-slate-400">Vendor accepted &rarr; pre-shipment</p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex items-center gap-3 mb-4">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-50 text-teal-600 text-xl">📦</span>
        <p class="text-sm font-semibold text-slate-600">Expected to Arrive (30 days)</p>
      </div>
      <p class="text-4xl font-extrabold text-slate-900"><?= number_format($arrivingSoonTotal) ?></p>
      <p class="mt-2 text-xs text-slate-400">Orders + incoming shipments due</p>
    </div>

  </div>

  <!-- Section label: Chart -->
  <p class="mt-8 mb-3 text-xs font-semibold uppercase tracking-widest text-slate-400">Key Performance Trend</p>

  <!-- Full-width Items Shipped per Week chart -->
  <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold text-slate-900">Items Shipped per Week</h2>
        <p class="mt-1 text-sm text-slate-500">Count of purchase orders that reached the <em>Shipped</em> milestone, by week &mdash; last 8 weeks.</p>
      </div>
      <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-600">8-week view</span>
    </div>
    <div class="h-80"><canvas id="shippedWeeklyChart"></canvas></div>
  </section>

</div>

<script>
  (() => {
    const labels       = <?= json_encode($chartLabels,   JSON_UNESCAPED_SLASHES) ?>;
    const shippedData  = <?= json_encode($shippedWeekly, JSON_UNESCAPED_SLASHES) ?>;

    new Chart(document.getElementById('shippedWeeklyChart'), {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Items Shipped',
          data: shippedData,
          borderRadius: 8,
          backgroundColor: 'rgba(79, 70, 229, 0.85)',
          hoverBackgroundColor: 'rgba(67, 56, 202, 1)'
        }]
      },
      options: {
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: (items) => 'Week of ' + items[0].label,
              label: (item) => ' ' + item.raw + ' item' + (item.raw === 1 ? '' : 's') + ' shipped'
            }
          }
        },
        scales: {
          x: { ticks: { color: '#64748b' }, grid: { display: false } },
          y: {
            beginAtZero: true,
            ticks: { precision: 0, color: '#64748b' },
            grid: { color: 'rgba(148, 163, 184, 0.25)' }
          }
        }
      }
    });
  })();
</script>

<?php render_footer(); ?>
