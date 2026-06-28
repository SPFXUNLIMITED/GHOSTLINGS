<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';

require_login();

function dashboard_validate_identifier(string $name): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    throw new InvalidArgumentException('Invalid SQL identifier: only letters, numbers, and underscores are allowed.');
  }
  return "`{$name}`";
}


function dashboard_sql_fragment(string $key): string {
  static $allowed = [
    'created_at' => 'created_at',
    'received_on' => 'received_on',
    'rfq_quote_received' => 'COALESCE(received_on, DATE(created_at))',
    'rfq_order_created' => 'COALESCE(order_date, DATE(created_at))',
    'shipping_quote_received' => 'COALESCE(received_on, DATE(created_at))',
    'shipped_at' => 'shipped_at',
    'count_all' => 'COUNT(*)',
    'sum_quantity' => 'SUM(COALESCE(quantity, 1))',
  ];

  if (!isset($allowed[$key])) {
    throw new InvalidArgumentException('Invalid dashboard SQL fragment key.');
  }

  return $allowed[$key];
}

function dashboard_table_exists(PDO $pdo, string $table): bool {
  static $cache = [];
  if (array_key_exists($table, $cache)) {
    return $cache[$table];
  }

  $validated_table = trim(dashboard_validate_identifier($table), '`');
  $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($validated_table));
  return $cache[$table] = (bool)$stmt->fetchColumn();
}

function dashboard_safe_count(PDO $pdo, string $sql, array $params = []): int {
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    error_log('Dashboard count query failed: ' . $e->getMessage());
    return 0;
  }
}

function dashboard_weekly_count(PDO $pdo, string $table, string $date_expression_key, string $start_date, string $end_date): int {
  if (!dashboard_table_exists($pdo, $table)) {
    return 0;
  }

  $date_expression = dashboard_sql_fragment($date_expression_key);
  $sql = "
    SELECT COUNT(*)
    FROM " . dashboard_validate_identifier($table) . "
    WHERE {$date_expression} IS NOT NULL
      AND DATE({$date_expression}) >= :start_date
      AND DATE({$date_expression}) < :end_date
  ";

  return dashboard_safe_count($pdo, $sql, [
    ':start_date' => $start_date,
    ':end_date' => $end_date,
  ]);
}

function dashboard_weekly_total(PDO $pdo, string $table, string $date_expression_key, string $value_expression_key, string $start_date, string $end_date): int {
  if (!dashboard_table_exists($pdo, $table)) {
    return 0;
  }

  $date_expression = dashboard_sql_fragment($date_expression_key);
  $value_expression = dashboard_sql_fragment($value_expression_key);
  $sql = "
    SELECT COALESCE({$value_expression}, 0)
    FROM " . dashboard_validate_identifier($table) . "
    WHERE {$date_expression} IS NOT NULL
      AND DATE({$date_expression}) >= :start_date
      AND DATE({$date_expression}) < :end_date
  ";

  return dashboard_safe_count($pdo, $sql, [
    ':start_date' => $start_date,
    ':end_date' => $end_date,
  ]);
}

function dashboard_goal_state(int $value, int $target, float $expected_ratio): array {
  $expected_ratio = max(0.0, min(1.0, $expected_ratio));
  $expected_total = $target > 0 ? ($target * $expected_ratio) : 0.0;
  $pace_ratio = $expected_total > 0 ? ($value / $expected_total) : ($value > 0 ? 1.0 : 0.0);

  if ($value >= $target || $pace_ratio >= 1) {
    return [
      'label' => 'On Track',
      'badge_classes' => 'tw-border-emerald-200 tw-bg-emerald-50 tw-text-emerald-700',
      'bar_classes' => 'tw-bg-gradient-to-r tw-from-emerald-400 tw-to-teal-500',
      'ring_classes' => 'tw-ring-emerald-200',
      'message' => 'Excellent pace — keep the Alibaba pipeline moving and protect the win.',
      'tone' => 'green',
    ];
  }

  if ($pace_ratio >= 0.65) {
    return [
      'label' => 'Push Today',
      'badge_classes' => 'tw-border-amber-200 tw-bg-amber-50 tw-text-amber-700',
      'bar_classes' => 'tw-bg-gradient-to-r tw-from-amber-400 tw-to-orange-400',
      'ring_classes' => 'tw-ring-amber-200',
      'message' => 'A focused follow-up block today gets this goal back on pace.',
      'tone' => 'yellow',
    ];
  }

  return [
    'label' => 'Needs Attention',
    'badge_classes' => 'tw-border-rose-200 tw-bg-rose-50 tw-text-rose-700',
    'bar_classes' => 'tw-bg-gradient-to-r tw-from-rose-400 tw-to-red-500',
    'ring_classes' => 'tw-ring-rose-200',
    'message' => 'Prioritize this now so the week stays pointed at shipments and supplier wins.',
    'tone' => 'red',
  ];
}

function dashboard_weekly_series(PDO $pdo, string $table, string $date_expression_key, string $value_expression_key, DateTimeImmutable $first_week_start): array {
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

  $date_expression = dashboard_sql_fragment($date_expression_key);
  $value_expression = dashboard_sql_fragment($value_expression_key);

  try {
    $stmt = $pdo->prepare("
      SELECT DATE({$date_expression}) AS activity_date, {$value_expression} AS total
      FROM " . dashboard_validate_identifier($table) . "
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

      $weekday = (int)$activity->format('N');
      $bucket = $activity->modify('-' . ($weekday - 1) . ' days')->format('Y-m-d');
      if (!isset($weeks[$bucket])) {
        continue;
      }

      $weeks[$bucket]['total'] += (int)round((float)($row['total'] ?? 0));
    }
  } catch (Throwable $e) {
    error_log('Dashboard series query failed: ' . $e->getMessage());
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
$week_progress_ratio = ((int)$today->format('N')) / 7;

$weekly_goals = [
  [
    'title' => 'RFQs Sent',
    'label' => 'RFQs Sent This Week',
    'value' => dashboard_weekly_count($pdo, 'rfq_requests', 'created_at', $week_start->format('Y-m-d'), $next_week_start->format('Y-m-d')),
    'accent_key' => 'sky',
    'target' => 10,
  ],
  [
    'title' => 'Quotes Received',
    'label' => 'Quotes Received This Week',
    'value' => dashboard_weekly_count($pdo, 'rfq_quotes', 'received_on', $week_start->format('Y-m-d'), $next_week_start->format('Y-m-d')),
    'accent_key' => 'violet',
    'target' => 15,
  ],
  [
    'title' => 'Purchase Orders Sent',
    'label' => 'Purchase Orders Sent This Week',
    'value' => dashboard_weekly_count($pdo, 'rfq_orders', 'rfq_order_created', $week_start->format('Y-m-d'), $next_week_start->format('Y-m-d')),
    'accent_key' => 'emerald',
    'target' => 4,
  ],
  [
    'title' => 'Items Shipped',
    'label' => 'Items Shipped This Week',
    'value' => dashboard_weekly_total($pdo, 'rfq_orders', 'shipped_at', 'sum_quantity', $week_start->format('Y-m-d'), $next_week_start->format('Y-m-d')),
    'accent_key' => 'amber',
    'target' => 3,
  ],
];

foreach ($weekly_goals as &$goal) {
  $goal['pace_target'] = min((int)$goal['target'], (int)ceil($goal['target'] * $week_progress_ratio));
  $goal['remaining'] = max(0, (int)$goal['target'] - (int)$goal['value']);
  $goal['progress_percent'] = $goal['target'] > 0 ? min(100, ((int)$goal['value'] / (int)$goal['target']) * 100) : 0;
  $goal['state'] = dashboard_goal_state((int)$goal['value'], (int)$goal['target'], $week_progress_ratio);
}
unset($goal);

$weekly_activity_total = (int)array_sum(array_column($weekly_goals, 'value'));
$goals_on_track = count(array_filter($weekly_goals, static fn(array $goal): bool => ($goal['state']['tone'] ?? '') === 'green'));
$today_priorities = [
  'Send the next round of high-priority Alibaba RFQs so supplier conversations keep moving.',
  'Follow up on open supplier responses and pull in the quotes still needed for this week.',
  'Issue approved purchase orders and confirm acknowledgements before the day ends.',
  'Check production and shipment updates so Patty finishes the day with a clear next step.',
];

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

$rfq_chart = dashboard_weekly_series($pdo, 'rfq_requests', 'created_at', 'count_all', $chart_week_start);
$shipped_chart = dashboard_weekly_series($pdo, 'rfq_orders', 'shipped_at', 'sum_quantity', $chart_week_start);
$last_updated = (new DateTimeImmutable('now', $tz))->format('M j, Y g:i A T');
$json_safe_flags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

render_header('Alibaba Sourcing Dashboard - Weekly Goals');
?>

<script>
window.tailwind = window.tailwind || {};
window.tailwind.config = {
  prefix: 'tw-',
  corePlugins: { preflight: false }
};
</script>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<style>
  .dash-shell {
    background: #f1f5f9;
  }
  .dash-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 4px 12px rgba(15, 23, 42, 0.04);
  }
  .dash-card:hover {
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.09), 0 8px 20px rgba(15, 23, 42, 0.06);
  }
  .dash-shell canvas {
    width: 100% !important;
    height: 280px !important;
  }
  .dash-shell .chart-panel {
    min-height: 280px;
  }
</style>

<div class="dash-shell tw-px-4 tw-py-6 lg:tw-px-6">
  <div class="tw-mx-auto tw-max-w-7xl tw-space-y-6">

    <!-- Hero banner -->
    <section class="tw-relative tw-overflow-hidden tw-rounded-2xl tw-bg-gradient-to-br tw-from-blue-600 tw-to-teal-500 tw-px-6 tw-py-8 tw-text-white lg:tw-px-10">
      <div class="tw-pointer-events-none tw-absolute tw-inset-0 tw-bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.12),transparent_55%)]"></div>
      <div class="tw-relative tw-flex tw-flex-col tw-gap-6 lg:tw-flex-row lg:tw-items-end lg:tw-justify-between">
        <div class="tw-max-w-3xl">
          <p class="tw-m-0 tw-text-sm tw-font-semibold tw-uppercase tw-tracking-widest tw-text-blue-100">Alibaba Sourcing Dashboard</p>
          <h1 class="tw-mt-2 tw-text-3xl tw-font-bold tw-tracking-tight lg:tw-text-5xl">Keep Patty&apos;s Weekly Procurement Goals Charging Forward</h1>
          <p class="tw-mt-3 tw-max-w-2xl tw-text-base tw-leading-7 tw-text-blue-50 lg:tw-text-lg">
            Stay locked on the Alibaba sourcing workflow: send more RFQs, pull in more quotes, release more purchase orders, and keep shipments moving.
          </p>
          <div class="tw-mt-5 tw-flex tw-flex-wrap tw-gap-2">
            <span class="tw-rounded-full tw-bg-white/20 tw-px-4 tw-py-1.5 tw-text-sm tw-font-medium tw-text-white">Goal-Driven Sourcing</span>
            <span class="tw-rounded-full tw-bg-white/20 tw-px-4 tw-py-1.5 tw-text-sm tw-font-medium tw-text-white">Supplier Momentum Focus</span>
          </div>
        </div>
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-3">
          <div class="tw-rounded-xl tw-bg-white/15 tw-px-5 tw-py-4 tw-backdrop-blur-sm">
            <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-blue-100">This Week Starts</p>
            <p class="tw-mt-2 tw-text-xl tw-font-bold tw-text-white"><?= h($week_start->format('M j, Y')) ?></p>
          </div>
          <div class="tw-rounded-xl tw-bg-white/15 tw-px-5 tw-py-4 tw-backdrop-blur-sm">
            <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-blue-100">Weekly Activity</p>
            <p class="tw-mt-2 tw-text-3xl tw-font-bold tw-text-white"><?= number_format($weekly_activity_total) ?></p>
          </div>
          <div class="tw-rounded-xl tw-bg-white/15 tw-px-5 tw-py-4 tw-backdrop-blur-sm">
            <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-blue-100">Last Updated</p>
            <p class="tw-mt-2 tw-text-base tw-font-semibold tw-text-white"><?= h($last_updated) ?></p>
          </div>
        </div>
      </div>
    </section>

    <!-- Weekly Goals -->
    <section aria-labelledby="weekly-goals">
      <div class="tw-mb-4 tw-flex tw-items-center tw-justify-between tw-gap-4">
        <div>
          <h2 id="weekly-goals" class="tw-m-0 tw-text-xl tw-font-semibold tw-text-slate-800">Weekly Goals</h2>
          <p class="tw-mt-1 tw-text-sm tw-text-slate-500">Big targets, clear pacing, and a fast read on where Patty should push next.</p>
        </div>
        <div class="dash-card tw-rounded-xl tw-px-4 tw-py-3 tw-text-right">
          <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-slate-500">Goals On Track</p>
          <p class="tw-mt-1 tw-text-3xl tw-font-bold tw-text-teal-600"><?= number_format($goals_on_track) ?><span class="tw-text-lg tw-font-semibold tw-text-slate-400"> / <?= number_format(count($weekly_goals)) ?></span></p>
        </div>
      </div>
      <div class="tw-grid tw-gap-4 xl:tw-grid-cols-2">
        <?php foreach ($weekly_goals as $goal): ?>
          <article class="dash-card tw-rounded-2xl tw-p-6 tw-ring-1 <?= h((string)$goal['state']['ring_classes']) ?>">
            <div class="tw-flex tw-flex-col tw-gap-3 lg:tw-flex-row lg:tw-items-start lg:tw-justify-between">
              <div>
                <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-slate-400"><?= h((string)$goal['title']) ?></p>
                <p class="tw-mt-2 tw-text-5xl tw-font-bold tw-tracking-tight tw-text-slate-800">
                  <?= number_format((int)$goal['value']) ?>
                  <span class="tw-text-2xl tw-font-medium tw-text-slate-400">/ <?= number_format((int)$goal['target']) ?></span>
                </p>
              </div>
              <span class="tw-inline-flex tw-items-center tw-rounded-full tw-border tw-px-3 tw-py-1.5 tw-text-sm tw-font-semibold <?= h((string)$goal['state']['badge_classes']) ?>">
                <?= h((string)$goal['state']['label']) ?>
              </span>
            </div>
            <div class="tw-mt-5">
              <div class="tw-mb-2 tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-text-sm">
                <span class="tw-font-semibold tw-text-slate-700"><?= number_format((int)round((float)$goal['progress_percent'])) ?>% complete</span>
                <span class="tw-text-slate-500">Pace target by today: <?= number_format((int)$goal['pace_target']) ?></span>
              </div>
              <div class="tw-h-3 tw-overflow-hidden tw-rounded-full tw-bg-slate-100">
                <div class="tw-h-full tw-rounded-full <?= h((string)$goal['state']['bar_classes']) ?>" style="width: <?= number_format((float)$goal['progress_percent'], 2, '.', '') ?>%;"></div>
              </div>
            </div>
            <div class="tw-mt-4 tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
              <p class="tw-m-0 tw-text-sm tw-leading-6 tw-text-slate-600"><?= h((string)$goal['state']['message']) ?></p>
              <p class="tw-m-0 tw-text-sm tw-font-semibold tw-text-slate-700">
                <?= $goal['remaining'] > 0 ? number_format((int)$goal['remaining']) . ' more to goal' : 'Goal reached — keep stacking wins' ?>
              </p>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Weekly KPI Cards -->
    <section aria-labelledby="weekly-kpis">
      <div class="tw-mb-4">
        <h2 id="weekly-kpis" class="tw-m-0 tw-text-xl tw-font-semibold tw-text-slate-800">Weekly Execution Snapshot</h2>
        <p class="tw-mt-1 tw-text-sm tw-text-slate-500">Action-oriented scorecards for the Alibaba sourcing and procurement workflow.</p>
      </div>
      <div class="tw-grid tw-gap-4 md:tw-grid-cols-2 xl:tw-grid-cols-4">
        <?php
        $kpi_accent_classes = [
          'sky'     => ['badge' => 'tw-bg-sky-50 tw-text-sky-700 tw-border-sky-200',     'num' => 'tw-text-sky-600',     'bar' => 'tw-bg-sky-500'],
          'violet'  => ['badge' => 'tw-bg-violet-50 tw-text-violet-700 tw-border-violet-200', 'num' => 'tw-text-violet-600', 'bar' => 'tw-bg-violet-500'],
          'emerald' => ['badge' => 'tw-bg-emerald-50 tw-text-emerald-700 tw-border-emerald-200', 'num' => 'tw-text-emerald-600', 'bar' => 'tw-bg-emerald-500'],
          'amber'   => ['badge' => 'tw-bg-amber-50 tw-text-amber-700 tw-border-amber-200', 'num' => 'tw-text-amber-600',   'bar' => 'tw-bg-amber-500'],
          'fuchsia' => ['badge' => 'tw-bg-fuchsia-50 tw-text-fuchsia-700 tw-border-fuchsia-200', 'num' => 'tw-text-fuchsia-600', 'bar' => 'tw-bg-fuchsia-500'],
          'slate'   => ['badge' => 'tw-bg-slate-100 tw-text-slate-700 tw-border-slate-200', 'num' => 'tw-text-slate-600', 'bar' => 'tw-bg-slate-500'],
        ];
        foreach ($weekly_goals as $card):
          $accent = $kpi_accent_classes[$card['accent_key']] ?? $kpi_accent_classes['slate'];
        ?>
          <article class="dash-card tw-relative tw-overflow-hidden tw-rounded-2xl tw-p-5">
            <span class="tw-inline-flex tw-items-center tw-rounded-full tw-border tw-px-2.5 tw-py-1 tw-text-[11px] tw-font-semibold tw-uppercase tw-tracking-widest <?= $accent['badge'] ?>">This Week</span>
            <p class="tw-mt-3 tw-text-sm tw-font-medium tw-leading-5 tw-text-slate-600"><?= h($card['label']) ?></p>
            <p class="tw-mt-4 tw-text-5xl tw-font-bold tw-tracking-tight <?= $accent['num'] ?>"><?= number_format((int)$card['value']) ?></p>
            <p class="tw-mt-3 tw-text-sm tw-text-slate-500">Weekly goal: <span class="tw-font-semibold tw-text-slate-700"><?= number_format((int)$card['target']) ?></span></p>
            <div class="tw-mt-4 tw-h-1 tw-rounded-full tw-bg-slate-100">
              <div class="tw-h-full tw-rounded-full <?= $accent['bar'] ?>" style="width: <?= number_format(min(100, (float)$card['progress_percent']), 2, '.', '') ?>%;"></div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Today's Priorities + Motivation -->
    <section aria-labelledby="today-priorities">
      <div class="tw-grid tw-gap-4 xl:tw-grid-cols-[1.4fr_0.6fr]">
        <article class="dash-card tw-rounded-2xl tw-p-6">
          <h2 id="today-priorities" class="tw-m-0 tw-text-xl tw-font-semibold tw-text-slate-800">Today&apos;s Priorities</h2>
          <p class="tw-mt-1 tw-text-sm tw-leading-6 tw-text-slate-500">Win the day by moving the next supplier action forward in every stage of the Alibaba workflow.</p>
          <div class="tw-mt-5 tw-space-y-3">
            <?php foreach ($today_priorities as $priority): ?>
              <label class="tw-flex tw-cursor-pointer tw-items-start tw-gap-3 tw-rounded-xl tw-border tw-border-slate-100 tw-bg-slate-50 tw-p-4 tw-transition-colors hover:tw-bg-blue-50 hover:tw-border-blue-100">
                <input type="checkbox" class="tw-mt-0.5 tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-500">
                <span class="tw-text-sm tw-leading-6 tw-text-slate-700"><?= h($priority) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </article>
        <article class="dash-card tw-rounded-2xl tw-bg-gradient-to-br tw-from-teal-500 tw-to-blue-600 tw-p-6 tw-text-white">
          <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-teal-100">Motivation</p>
          <h3 class="tw-mt-3 tw-text-2xl tw-font-bold tw-leading-snug tw-text-white">Every supplier touchpoint today sets up next week&apos;s wins.</h3>
          <p class="tw-mt-4 tw-text-sm tw-leading-7 tw-text-teal-50">Keep Patty focused on fast follow-up, clean PO handoff, and shipment visibility so the sourcing pipeline keeps converting.</p>
        </article>
      </div>
    </section>

    <!-- Pipeline Snapshot -->
    <section aria-labelledby="pipeline-status">
      <div class="tw-mb-4">
        <h2 id="pipeline-status" class="tw-m-0 tw-text-xl tw-font-semibold tw-text-slate-800">Procurement Pipeline Snapshot</h2>
        <p class="tw-mt-1 tw-text-sm tw-text-slate-500">Stay close to production and shipment movement so today&apos;s sourcing work turns into delivered machines.</p>
      </div>
      <div class="tw-grid tw-gap-4 lg:tw-grid-cols-3">
        <article class="dash-card tw-rounded-2xl tw-p-6">
          <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-slate-400">Items In Production</p>
          <p class="tw-mt-4 tw-text-6xl tw-font-bold tw-tracking-tight tw-text-blue-600"><?= number_format($items_in_production) ?></p>
          <p class="tw-mt-3 tw-text-sm tw-leading-6 tw-text-slate-600">Orders with production underway that have not shipped yet.</p>
        </article>
        <article class="dash-card tw-rounded-2xl tw-p-6">
          <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-slate-400">Shipments In Transit</p>
          <p class="tw-mt-4 tw-text-6xl tw-font-bold tw-tracking-tight tw-text-teal-600"><?= number_format($shipments_in_transit) ?></p>
          <p class="tw-mt-3 tw-text-sm tw-leading-6 tw-text-slate-600">Incoming shipments currently marked In Transit.</p>
        </article>
        <article class="dash-card tw-rounded-2xl tw-p-6">
          <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-slate-400">Expected Arrivals (30 Days)</p>
          <p class="tw-mt-4 tw-text-6xl tw-font-bold tw-tracking-tight tw-text-sky-600"><?= number_format($expected_arrivals) ?></p>
          <p class="tw-mt-3 tw-text-sm tw-leading-6 tw-text-slate-600">Open incoming shipments scheduled to arrive within the next month.</p>
        </article>
      </div>
    </section>

    <!-- Trend Charts -->
    <section aria-labelledby="dashboard-charts">
      <div class="tw-mb-4">
        <h2 id="dashboard-charts" class="tw-m-0 tw-text-xl tw-font-semibold tw-text-slate-800">Momentum Trends</h2>
        <p class="tw-mt-1 tw-text-sm tw-text-slate-500">Use the last eight weeks of sourcing and shipping activity to keep momentum building.</p>
      </div>
      <div class="tw-grid tw-gap-4 xl:tw-grid-cols-2">
        <article class="chart-panel dash-card tw-rounded-2xl tw-p-6">
          <div class="tw-mb-4 tw-flex tw-items-start tw-justify-between tw-gap-4">
            <div>
              <h3 class="tw-m-0 tw-text-base tw-font-semibold tw-text-slate-800">RFQs Sent Per Week</h3>
              <p class="tw-mt-0.5 tw-text-sm tw-text-slate-400">Line chart · last 8 weeks</p>
            </div>
            <div class="tw-rounded-xl tw-border tw-border-blue-100 tw-bg-blue-50 tw-px-4 tw-py-3 tw-text-right">
              <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-blue-500">8-Week Total</p>
              <p class="tw-mt-1 tw-text-3xl tw-font-bold tw-text-blue-700"><?= number_format(array_sum($rfq_chart['values'])) ?></p>
            </div>
          </div>
          <canvas id="rfqWeeklyChart" aria-label="RFQs sent per week line chart" role="img"></canvas>
        </article>

        <article class="chart-panel dash-card tw-rounded-2xl tw-p-6">
          <div class="tw-mb-4 tw-flex tw-items-start tw-justify-between tw-gap-4">
            <div>
              <h3 class="tw-m-0 tw-text-base tw-font-semibold tw-text-slate-800">Items Shipped Per Week</h3>
              <p class="tw-mt-0.5 tw-text-sm tw-text-slate-400">Bar chart · last 8 weeks</p>
            </div>
            <div class="tw-rounded-xl tw-border tw-border-teal-100 tw-bg-teal-50 tw-px-4 tw-py-3 tw-text-right">
              <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-teal-500">8-Week Total</p>
              <p class="tw-mt-1 tw-text-3xl tw-font-bold tw-text-teal-700"><?= number_format(array_sum($shipped_chart['values'])) ?></p>
            </div>
          </div>
          <canvas id="shippedWeeklyChart" aria-label="Items shipped per week bar chart" role="img"></canvas>
        </article>
      </div>
    </section>

  </div>
</div>

<script>
(() => {
  const labels = <?= json_encode($rfq_chart['labels'], $json_safe_flags) ?>;
  const rfqValues = <?= json_encode($rfq_chart['values'], $json_safe_flags) ?>;
  const shippedValues = <?= json_encode($shipped_chart['values'], $json_safe_flags) ?>;

  const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1e293b',
        titleColor: '#f8fafc',
        bodyColor: '#cbd5e1',
        padding: 12,
        cornerRadius: 8
      }
    },
    scales: {
      x: {
        grid: { color: 'rgba(148, 163, 184, 0.15)' },
        ticks: { color: '#64748b', font: { size: 12, weight: '600' } }
      },
      y: {
        beginAtZero: true,
        grid: { color: 'rgba(148, 163, 184, 0.2)' },
        ticks: {
          precision: 0,
          color: '#64748b',
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
          borderColor: '#2563eb',
          backgroundColor: 'rgba(37, 99, 235, 0.08)',
          fill: true,
          tension: 0.35,
          borderWidth: 2.5,
          pointRadius: 4,
          pointHoverRadius: 6,
          pointBackgroundColor: '#2563eb',
          pointBorderColor: '#ffffff',
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
          backgroundColor: 'rgba(20, 184, 166, 0.75)',
          borderColor: '#0d9488',
          borderWidth: 1,
          borderRadius: 8,
          maxBarThickness: 44
        }]
      },
      options: baseOptions
    });
  }
})();
</script>

<?php render_footer(); ?>
