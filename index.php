<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';

require_login();

function dashboard_ident(string $name): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    throw new InvalidArgumentException('Invalid SQL identifier.');
  }
  return "`{$name}`";
}

function dashboard_table_exists(PDO $pdo, string $table): bool {
  static $cache = [];
  if (array_key_exists($table, $cache)) {
    return $cache[$table];
  }

  dashboard_ident($table);
  $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
  return $cache[$table] = (bool)$stmt->fetchColumn();
}

function dashboard_safe_count(PDO $pdo, string $sql, array $params = []): int {
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    error_log('CEO dashboard count query failed: ' . $e->getMessage());
    return 0;
  }
}

function dashboard_weekly_count(PDO $pdo, string $table, string $date_expression, string $start_date, string $end_date): int {
  if (!dashboard_table_exists($pdo, $table)) {
    return 0;
  }

  $sql = "
    SELECT COUNT(*)
    FROM " . dashboard_ident($table) . "
    WHERE {$date_expression} IS NOT NULL
      AND DATE({$date_expression}) >= :start_date
      AND DATE({$date_expression}) < :end_date
  ";

  return dashboard_safe_count($pdo, $sql, [
    ':start_date' => $start_date,
    ':end_date' => $end_date,
  ]);
}

function dashboard_weekly_series(PDO $pdo, string $table, string $date_expression, string $value_expression, DateTimeImmutable $first_week_start): array {
  $weeks = [];
  $cursor = $first_week_start;
  for ($i = 0; $i < 8; $i++) {
    $key = $cursor->format('Y-m-d');
    $weeks[$key] = [
      'label' => $cursor->format('M j'),
      'total' => 0,
    ];
    $cursor = $cursor->modify('+1 week');
  }

  if (!dashboard_table_exists($pdo, $table)) {
    return [
      'labels' => array_column($weeks, 'label'),
      'values' => array_column($weeks, 'total'),
    ];
  }

  try {
    $stmt = $pdo->prepare("
      SELECT DATE({$date_expression}) AS activity_date, {$value_expression} AS total
      FROM " . dashboard_ident($table) . "
      WHERE {$date_expression} IS NOT NULL
        AND DATE({$date_expression}) >= :start_date
        AND DATE({$date_expression}) < :end_date
      GROUP BY DATE({$date_expression})
      ORDER BY activity_date ASC
    ");
    $stmt->execute([
      ':start_date' => $first_week_start->format('Y-m-d'),
      ':end_date' => $first_week_start->modify('+8 weeks')->format('Y-m-d'),
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $activity_date = trim((string)($row['activity_date'] ?? ''));
      if ($activity_date === '') {
        continue;
      }

      $activity = DateTimeImmutable::createFromFormat('Y-m-d', $activity_date);
      if (!$activity) {
        continue;
      }

      $bucket = $activity->modify('monday this week')->format('Y-m-d');
      if (!isset($weeks[$bucket])) {
        continue;
      }

      $weeks[$bucket]['total'] += (int)round((float)($row['total'] ?? 0));
    }
  } catch (Throwable $e) {
    error_log('CEO dashboard series query failed: ' . $e->getMessage());
  }

  return [
    'labels' => array_column($weeks, 'label'),
    'values' => array_column($weeks, 'total'),
  ];
}

$tz = new DateTimeZone(defined('APP_TZ') ? APP_TZ : date_default_timezone_get());
$today = new DateTimeImmutable('today', $tz);
$week_start = $today->modify('monday this week');
$next_week_start = $week_start->modify('+1 week');
$chart_week_start = $week_start->modify('-7 weeks');
$thirty_days_out = $today->modify('+30 days');

$kpis = [
  [
    'label' => 'New RFQs Created This Week',
    'value' => dashboard_weekly_count($pdo, 'rfq_requests', 'created_at', $week_start->format('Y-m-d'), $next_week_start->format('Y-m-d')),
    'accent' => 'tw-from-sky-500 tw-to-blue-600',
  ],
  [
    'label' => 'Quotes Received This Week',
    'value' => dashboard_weekly_count($pdo, 'rfq_quotes', 'COALESCE(received_on, DATE(created_at))', $week_start->format('Y-m-d'), $next_week_start->format('Y-m-d')),
    'accent' => 'tw-from-violet-500 tw-to-indigo-600',
  ],
  [
    'label' => 'Purchase Orders Created This Week',
    'value' => dashboard_weekly_count($pdo, 'rfq_orders', 'COALESCE(order_date, DATE(created_at))', $week_start->format('Y-m-d'), $next_week_start->format('Y-m-d')),
    'accent' => 'tw-from-emerald-500 tw-to-teal-600',
  ],
  [
    'label' => 'New Freight Quotes This Week',
    'value' => dashboard_weekly_count($pdo, 'shipping_rfq_quotes', 'COALESCE(received_on, DATE(created_at))', $week_start->format('Y-m-d'), $next_week_start->format('Y-m-d')),
    'accent' => 'tw-from-amber-500 tw-to-orange-600',
  ],
  [
    'label' => 'New Incoming Shipments This Week',
    'value' => dashboard_weekly_count($pdo, 'incoming_shipments', 'created_at', $week_start->format('Y-m-d'), $next_week_start->format('Y-m-d')),
    'accent' => 'tw-from-fuchsia-500 tw-to-pink-600',
  ],
  [
    'label' => 'New Inventory Items Added This Week',
    'value' => dashboard_weekly_count($pdo, 'inventory_items', 'created_at', $week_start->format('Y-m-d'), $next_week_start->format('Y-m-d')),
    'accent' => 'tw-from-slate-500 tw-to-slate-700',
  ],
];

$weekly_activity_total = array_sum(array_map(static fn(array $card): int => (int)$card['value'], $kpis));

$items_in_production = dashboard_table_exists($pdo, 'rfq_orders')
  ? dashboard_safe_count(
      $pdo,
      "
        SELECT COUNT(*)
        FROM rfq_orders
        WHERE order_status <> 'cancelled'
          AND (
            (production_started_at IS NOT NULL AND shipped_at IS NULL)
            OR order_status IN ('vendor_produces_machine', 'in_production', 'ready_to_ship')
          )
      "
    )
  : 0;

$shipments_in_transit = dashboard_table_exists($pdo, 'incoming_shipments')
  ? dashboard_safe_count(
      $pdo,
      "
        SELECT COUNT(*)
        FROM incoming_shipments
        WHERE status = 'In Transit'
      "
    )
  : 0;

$expected_arrivals = dashboard_table_exists($pdo, 'incoming_shipments')
  ? dashboard_safe_count(
      $pdo,
      "
        SELECT COUNT(*)
        FROM incoming_shipments
        WHERE expected_arrival >= :today
          AND expected_arrival <= :thirty_days_out
          AND status <> 'Received'
      ",
      [
        ':today' => $today->format('Y-m-d'),
        ':thirty_days_out' => $thirty_days_out->format('Y-m-d'),
      ]
    )
  : 0;

$rfq_chart = dashboard_weekly_series($pdo, 'rfq_requests', 'created_at', 'COUNT(*)', $chart_week_start);
$shipped_chart = dashboard_weekly_series($pdo, 'rfq_orders', 'shipped_at', 'SUM(COALESCE(quantity, 1))', $chart_week_start);
$last_updated = (new DateTimeImmutable('now', $tz))->format('M j, Y g:i A T');

render_header('CEO Dashboard');
?>

<script>
window.tailwind = window.tailwind || {};
window.tailwind.config = {
  prefix: 'tw-',
  corePlugins: { preflight: false },
  theme: {
    extend: {
      boxShadow: {
        executive: '0 0 0 1px rgba(56, 189, 248, 0.12), 0 30px 90px rgba(37, 99, 235, 0.18), 0 0 80px rgba(168, 85, 247, 0.15)'
      }
    }
  }
};
</script>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<style>
  .ceo-dashboard-shell {
    background:
      radial-gradient(circle at top left, rgba(34, 211, 238, 0.18), transparent 30%),
      radial-gradient(circle at top right, rgba(168, 85, 247, 0.16), transparent 28%),
      linear-gradient(180deg, #020617 0%, #020617 100%);
  }
  .ceo-grid-pattern {
    background-image:
      linear-gradient(rgba(148, 163, 184, 0.08) 1px, transparent 1px),
      linear-gradient(90deg, rgba(148, 163, 184, 0.08) 1px, transparent 1px);
    background-size: 36px 36px;
    mask-image: linear-gradient(180deg, rgba(255,255,255,0.38), transparent 78%);
  }
  .ceo-neon-card {
    background:
      linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(2, 6, 23, 0.9)),
      linear-gradient(135deg, rgba(34, 211, 238, 0.1), rgba(168, 85, 247, 0.08));
    box-shadow:
      0 0 0 1px rgba(96, 165, 250, 0.15),
      0 18px 55px rgba(2, 6, 23, 0.55),
      inset 0 1px 0 rgba(255, 255, 255, 0.03);
  }
  .ceo-card-glow::before {
    content: '';
    position: absolute;
    inset: -40% auto auto 55%;
    width: 180px;
    height: 180px;
    border-radius: 999px;
    background: radial-gradient(circle, rgba(34, 211, 238, 0.22), transparent 68%);
    filter: blur(12px);
    pointer-events: none;
  }
  .ceo-dashboard-shell canvas {
    width: 100% !important;
    height: 320px !important;
  }
  .ceo-dashboard-shell .chart-panel {
    min-height: 320px;
  }
</style>

<div class="ceo-dashboard-shell tw-relative tw-overflow-hidden tw-px-4 tw-py-6 lg:tw-px-6">
  <div class="ceo-grid-pattern tw-pointer-events-none tw-absolute tw-inset-0 tw-opacity-60"></div>
  <div class="tw-pointer-events-none tw-absolute tw-left-[-6rem] tw-top-[-5rem] tw-h-72 tw-w-72 tw-rounded-full tw-bg-cyan-400/20 tw-blur-3xl"></div>
  <div class="tw-pointer-events-none tw-absolute tw-right-[-4rem] tw-top-24 tw-h-80 tw-w-80 tw-rounded-full tw-bg-violet-500/20 tw-blur-3xl"></div>
  <div class="tw-pointer-events-none tw-absolute tw-bottom-[-8rem] tw-left-1/3 tw-h-80 tw-w-80 tw-rounded-full tw-bg-sky-500/10 tw-blur-3xl"></div>
  <div class="tw-relative tw-mx-auto tw-max-w-7xl tw-space-y-6">
    <section class="tw-relative tw-overflow-hidden tw-rounded-[32px] tw-border tw-border-cyan-400/15 tw-bg-[linear-gradient(135deg,rgba(8,47,73,0.92),rgba(15,23,42,0.96)_38%,rgba(59,7,100,0.92))] tw-px-6 tw-py-8 tw-text-white tw-shadow-executive lg:tw-px-10">
      <div class="tw-pointer-events-none tw-absolute tw-inset-y-0 tw-right-0 tw-w-1/2 tw-bg-[radial-gradient(circle_at_top,rgba(34,211,238,0.25),transparent_45%),radial-gradient(circle_at_bottom_right,rgba(168,85,247,0.22),transparent_42%)]"></div>
      <div class="tw-relative tw-flex tw-flex-col tw-gap-6 lg:tw-flex-row lg:tw-items-end lg:tw-justify-between">
        <div class="tw-max-w-3xl">
          <p class="tw-m-0 tw-text-sm tw-font-semibold tw-uppercase tw-tracking-[0.3em] tw-text-cyan-300">CEO Dashboard</p>
          <h1 class="tw-mt-3 tw-text-3xl tw-font-black tw-tracking-[-0.04em] lg:tw-text-6xl">Full picture of business activity.</h1>
          <p class="tw-mt-3 tw-max-w-2xl tw-text-base tw-leading-7 tw-text-slate-300 lg:tw-text-lg">
            Monitor weekly demand, supplier momentum, purchase activity, shipment flow, and near-term arrivals from one executive view.
          </p>
          <div class="tw-mt-6 tw-flex tw-flex-wrap tw-gap-3">
            <div class="tw-rounded-full tw-border tw-border-cyan-400/20 tw-bg-cyan-400/10 tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-text-cyan-100">Neon Operations View</div>
            <div class="tw-rounded-full tw-border tw-border-violet-400/20 tw-bg-violet-400/10 tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-text-violet-100">Live Pipeline Signal</div>
          </div>
        </div>
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-3">
          <div class="tw-rounded-3xl tw-border tw-border-white/10 tw-bg-slate-950/35 tw-px-5 tw-py-4 tw-backdrop-blur">
            <p class="tw-m-0 tw-text-xs tw-font-medium tw-uppercase tw-tracking-[0.22em] tw-text-slate-300">This Week Starts</p>
            <p class="tw-mt-2 tw-text-2xl tw-font-bold tw-text-white"><?= h($week_start->format('M j, Y')) ?></p>
          </div>
          <div class="tw-rounded-3xl tw-border tw-border-white/10 tw-bg-slate-950/35 tw-px-5 tw-py-4 tw-backdrop-blur">
            <p class="tw-m-0 tw-text-xs tw-font-medium tw-uppercase tw-tracking-[0.22em] tw-text-slate-300">Weekly Activity</p>
            <p class="tw-mt-2 tw-text-3xl tw-font-black tw-text-cyan-300"><?= number_format($weekly_activity_total) ?></p>
          </div>
          <div class="tw-rounded-3xl tw-border tw-border-white/10 tw-bg-slate-950/35 tw-px-5 tw-py-4 tw-backdrop-blur">
            <p class="tw-m-0 tw-text-xs tw-font-medium tw-uppercase tw-tracking-[0.22em] tw-text-slate-300">Last Updated</p>
            <p class="tw-mt-2 tw-text-lg tw-font-bold tw-text-white"><?= h($last_updated) ?></p>
          </div>
        </div>
      </div>
    </section>

    <section aria-labelledby="weekly-kpis">
      <div class="tw-mb-4 tw-flex tw-items-center tw-justify-between tw-gap-4">
        <div>
          <h2 id="weekly-kpis" class="tw-m-0 tw-text-2xl tw-font-semibold tw-text-white">Weekly Activity KPI Cards</h2>
          <p class="tw-mt-1 tw-text-sm tw-text-slate-400">Large-format weekly totals across the requested business systems.</p>
        </div>
      </div>
      <div class="tw-grid tw-gap-4 md:tw-grid-cols-2 xl:tw-grid-cols-3 2xl:tw-grid-cols-6">
        <?php foreach ($kpis as $card): ?>
          <article class="ceo-neon-card ceo-card-glow tw-relative tw-overflow-hidden tw-rounded-[28px] tw-p-6 tw-ring-1 tw-ring-cyan-400/10">
            <div class="tw-absolute tw-right-5 tw-top-5 tw-h-3 tw-w-3 tw-rounded-full tw-bg-cyan-300 tw-shadow-[0_0_18px_rgba(103,232,249,0.9)]"></div>
            <div class="tw-inline-flex tw-rounded-full tw-bg-gradient-to-r <?= h($card['accent']) ?> tw-p-[1px]">
              <span class="tw-rounded-full tw-bg-slate-950/90 tw-px-3 tw-py-1 tw-text-[11px] tw-font-semibold tw-uppercase tw-tracking-[0.18em] tw-text-cyan-100">This Week</span>
            </div>
            <p class="tw-mt-4 tw-max-w-[16rem] tw-text-sm tw-font-medium tw-leading-6 tw-text-slate-300"><?= h($card['label']) ?></p>
            <p class="tw-mt-6 tw-text-6xl tw-font-black tw-tracking-[-0.05em] tw-text-white"><?= number_format((int)$card['value']) ?></p>
            <div class="tw-mt-5 tw-h-px tw-w-full tw-bg-gradient-to-r tw-from-cyan-400/40 tw-via-violet-400/20 tw-to-transparent"></div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section aria-labelledby="pipeline-status">
      <div class="tw-mb-4">
        <h2 id="pipeline-status" class="tw-m-0 tw-text-2xl tw-font-semibold tw-text-white">Pipeline Status</h2>
        <p class="tw-mt-1 tw-text-sm tw-text-slate-400">Current production and logistics counts that matter most.</p>
      </div>
      <div class="tw-grid tw-gap-4 lg:tw-grid-cols-3">
        <article class="ceo-neon-card tw-rounded-[30px] tw-p-6 tw-ring-1 tw-ring-cyan-400/10">
          <p class="tw-m-0 tw-text-sm tw-font-semibold tw-uppercase tw-tracking-[0.18em] tw-text-slate-400">Items Currently In Production</p>
          <p class="tw-mt-4 tw-text-6xl tw-font-black tw-tracking-[-0.05em] tw-text-cyan-300"><?= number_format($items_in_production) ?></p>
          <p class="tw-mt-3 tw-text-sm tw-leading-6 tw-text-slate-300">Orders with production underway that have not shipped yet.</p>
        </article>
        <article class="ceo-neon-card tw-rounded-[30px] tw-p-6 tw-ring-1 tw-ring-violet-400/10">
          <p class="tw-m-0 tw-text-sm tw-font-semibold tw-uppercase tw-tracking-[0.18em] tw-text-slate-400">Shipments In Transit</p>
          <p class="tw-mt-4 tw-text-6xl tw-font-black tw-tracking-[-0.05em] tw-text-violet-300"><?= number_format($shipments_in_transit) ?></p>
          <p class="tw-mt-3 tw-text-sm tw-leading-6 tw-text-slate-300">Incoming shipments currently marked In Transit.</p>
        </article>
        <article class="ceo-neon-card tw-rounded-[30px] tw-p-6 tw-ring-1 tw-ring-sky-400/10">
          <p class="tw-m-0 tw-text-sm tw-font-semibold tw-uppercase tw-tracking-[0.18em] tw-text-slate-400">Expected Arrivals in Next 30 Days</p>
          <p class="tw-mt-4 tw-text-6xl tw-font-black tw-tracking-[-0.05em] tw-text-sky-300"><?= number_format($expected_arrivals) ?></p>
          <p class="tw-mt-3 tw-text-sm tw-leading-6 tw-text-slate-300">Open incoming shipments scheduled to arrive within the next month.</p>
        </article>
      </div>
    </section>

    <section aria-labelledby="dashboard-charts">
      <div class="tw-mb-4">
        <h2 id="dashboard-charts" class="tw-m-0 tw-text-2xl tw-font-semibold tw-text-white">Charts</h2>
        <p class="tw-mt-1 tw-text-sm tw-text-slate-400">Eight-week activity trends for RFQ creation and shipped item volume.</p>
      </div>
      <div class="tw-grid tw-gap-4 xl:tw-grid-cols-2">
        <article class="chart-panel ceo-neon-card tw-rounded-[30px] tw-p-6 tw-ring-1 tw-ring-cyan-400/10">
          <div class="tw-mb-4 tw-flex tw-items-start tw-justify-between tw-gap-4">
            <div>
              <h3 class="tw-m-0 tw-text-xl tw-font-semibold tw-text-white">Weekly RFQs Created</h3>
              <p class="tw-mt-1 tw-text-sm tw-text-slate-400">Line chart · last 8 weeks</p>
            </div>
            <div class="tw-rounded-2xl tw-border tw-border-cyan-400/15 tw-bg-cyan-400/10 tw-px-4 tw-py-3 tw-text-right">
              <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-[0.18em] tw-text-cyan-200">8-Week Total</p>
              <p class="tw-mt-1 tw-text-3xl tw-font-black tw-text-cyan-300"><?= number_format(array_sum($rfq_chart['values'])) ?></p>
            </div>
          </div>
          <canvas id="rfqWeeklyChart" aria-label="Weekly RFQs Created line chart" role="img"></canvas>
        </article>

        <article class="chart-panel ceo-neon-card tw-rounded-[30px] tw-p-6 tw-ring-1 tw-ring-violet-400/10">
          <div class="tw-mb-4 tw-flex tw-items-start tw-justify-between tw-gap-4">
            <div>
              <h3 class="tw-m-0 tw-text-xl tw-font-semibold tw-text-white">Items Shipped Per Week</h3>
              <p class="tw-mt-1 tw-text-sm tw-text-slate-400">Bar chart · last 8 weeks</p>
            </div>
            <div class="tw-rounded-2xl tw-border tw-border-violet-400/15 tw-bg-violet-400/10 tw-px-4 tw-py-3 tw-text-right">
              <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-[0.18em] tw-text-violet-200">8-Week Total</p>
              <p class="tw-mt-1 tw-text-3xl tw-font-black tw-text-violet-300"><?= number_format(array_sum($shipped_chart['values'])) ?></p>
            </div>
          </div>
          <canvas id="shippedWeeklyChart" aria-label="Items Shipped Per Week bar chart" role="img"></canvas>
        </article>
      </div>
    </section>
  </div>
</div>

<script>
(() => {
  const labels = <?= json_encode($rfq_chart['labels'], JSON_UNESCAPED_SLASHES) ?>;
  const rfqValues = <?= json_encode($rfq_chart['values'], JSON_UNESCAPED_SLASHES) ?>;
  const shippedValues = <?= json_encode($shipped_chart['values'], JSON_UNESCAPED_SLASHES) ?>;

  const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(2, 6, 23, 0.96)',
        titleColor: '#f8fafc',
        bodyColor: '#cbd5e1',
        padding: 12
      }
    },
    scales: {
      x: {
        grid: { color: 'rgba(51, 65, 85, 0.22)' },
        ticks: { color: '#94a3b8', font: { size: 12, weight: '600' } }
      },
      y: {
        beginAtZero: true,
        grid: { color: 'rgba(51, 65, 85, 0.32)' },
        ticks: {
          precision: 0,
          color: '#94a3b8',
          font: { size: 12, weight: '600' }
        }
      }
    }
  };

  const rfqCtx = document.getElementById('rfqWeeklyChart');
  if (rfqCtx && window.Chart) {
    new Chart(rfqCtx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          data: rfqValues,
          borderColor: '#22d3ee',
          backgroundColor: 'rgba(34, 211, 238, 0.12)',
          fill: true,
          tension: 0.35,
          borderWidth: 3,
          pointRadius: 4,
          pointHoverRadius: 5,
          pointBackgroundColor: '#67e8f9',
          pointBorderColor: '#082f49',
          pointBorderWidth: 2
        }]
      },
      options: baseOptions
    });
  }

  const shippedCtx = document.getElementById('shippedWeeklyChart');
  if (shippedCtx && window.Chart) {
    new Chart(shippedCtx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          data: shippedValues,
          backgroundColor: 'rgba(129, 140, 248, 0.88)',
          borderColor: '#38bdf8',
          borderWidth: 1,
          borderRadius: 14,
          maxBarThickness: 44
        }]
      },
      options: baseOptions
    });
  }
})();
</script>

<?php render_footer(); ?>
