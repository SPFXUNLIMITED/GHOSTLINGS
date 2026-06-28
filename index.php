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
      'label'   => 'On Track',
      'tone'    => 'green',
      'message' => 'Excellent pace — keep the Alibaba pipeline moving and protect the win.',
    ];
  }

  if ($pace_ratio >= 0.65) {
    return [
      'label'   => 'Push Today',
      'tone'    => 'yellow',
      'message' => 'A focused follow-up block today gets this goal back on pace.',
    ];
  }

  return [
    'label'   => 'Needs Attention',
    'tone'    => 'red',
    'message' => 'Prioritize this now so the week stays pointed at shipments and supplier wins.',
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<style>
/* Strong dashboard overrides to avoid collisions with shared styles.css */
.dash-shell {
  --dash-text: #0f172a;
  --dash-muted: #475569;
  --dash-border: #d8e1ee;
  --dash-card-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
  --dash-card-shadow-hover: 0 14px 28px rgba(15, 23, 42, 0.12);
  --dash-blue: #1d4ed8;
}
.dash-shell::before {
  content: '';
  position: fixed;
  inset: 0;
  z-index: -1;
  pointer-events: none;
  background: #f2f6fc;
}
.dash-shell,
.dash-shell * {
  color: var(--dash-text);
}
.dash-shell .muted {
  color: var(--dash-muted) !important;
}
.dash-shell .card {
  background: #ffffff !important;
  border: 1px solid var(--dash-border) !important;
  border-radius: 14px !important;
  box-shadow: var(--dash-card-shadow) !important;
  padding: 18px !important;
  margin: 0 !important;
}
.dash-shell .card:hover {
  box-shadow: var(--dash-card-shadow-hover) !important;
}
.dash-shell .badge {
  border-radius: 999px;
  border-width: 1px;
  font-weight: 700;
}
.dash-mini-metric,
.dash-kpi-card,
.dash-metric-card {
  background: #f8fbff !important;
}
.dash-chart-total {
  margin: 0 !important;
  padding: 8px 14px !important;
  text-align: right;
  border-radius: 12px !important;
}
.dash-chart-total-rfq { background:#edf4ff !important; border-color:#c7dbff !important; }
.dash-chart-total-ship { background:#ecfeff !important; border-color:#99f6e4 !important; }

/* Hero banner */
.dash-shell .card.dash-hero {
  position: relative;
  overflow: hidden;
  background: linear-gradient(135deg, #0b1220 0%, #1e3a8a 55%, #0f766e 100%) !important;
  border: 1px solid #1f3b7d !important;
  color: #ffffff !important;
}
.dash-shell .dash-hero::after {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: linear-gradient(110deg, rgba(255,255,255,0.12), rgba(255,255,255,0));
}
.dash-shell .dash-hero h1,
.dash-shell .dash-hero p,
.dash-shell .dash-hero .muted,
.dash-shell .dash-hero .dash-hero-tag,
.dash-shell .dash-hero .dash-pill,
.dash-shell .dash-hero .dash-hero-stat-label,
.dash-shell .dash-hero .dash-hero-stat-value {
  position: relative;
  z-index: 1;
  color: #f8fafc !important;
}
.dash-shell .dash-hero h1 { margin: 8px 0 10px; font-size: 2rem; letter-spacing: -0.01em; }
.dash-shell .dash-hero p { margin: 0; max-width: 860px; }
.dash-shell .dash-hero-tag {
  display: inline-block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-bottom: 8px;
  padding: 5px 12px;
  border: 1px solid rgba(191, 219, 254, 0.6);
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.45);
}
.dash-shell .dash-hero-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 10px;
  margin-top: 18px;
}
.dash-shell .dash-hero-stat {
  background: rgba(15, 23, 42, 0.42) !important;
  border: 1px solid rgba(191, 219, 254, 0.5) !important;
  border-radius: 12px;
  padding: 10px 14px;
}
.dash-shell .dash-hero-stat-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}
.dash-shell .dash-hero-stat-value {
  font-size: 1.2rem;
  font-weight: 700;
  margin-top: 4px;
}
.dash-shell .dash-pill {
  display: inline-block;
  padding: 6px 12px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.45) !important;
  border: 1px solid rgba(191, 219, 254, 0.5) !important;
  font-size: 12px;
  margin: 10px 8px 0 0;
  font-weight: 600;
}

.dash-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.dash-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.dash-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.dash-section-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin: 26px 0 14px;
}
.dash-section-header h2 { margin: 0 0 4px; color: var(--dash-text); letter-spacing: -0.01em; }
.dash-section-header p  { margin: 0; max-width: 760px; }
.dash-progress {
  height: 9px;
  border-radius: 999px;
  background: #e8eef7;
  overflow: hidden;
  margin-top: 10px;
}
.dash-progress-bar { height: 100%; border-radius: 999px; }

/* Goal tone colours */
.goal-badge-green  { background: #dcfce7; color: #166534; border-color: #86efac; }
.goal-badge-yellow { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.goal-badge-red    { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
.goal-bar-green    { background: linear-gradient(90deg,#10b981,#0d9488); }
.goal-bar-yellow   { background: linear-gradient(90deg,#f59e0b,#ea580c); }
.goal-bar-red      { background: linear-gradient(90deg,#ef4444,#dc2626); }
.goal-card-green   { border-left: 4px solid #10b981 !important; }
.goal-card-yellow  { border-left: 4px solid #f59e0b !important; }
.goal-card-red     { border-left: 4px solid #ef4444 !important; }
.dash-big-num { font-size: 2.8rem; font-weight: 700; line-height: 1.06; margin: 8px 0 6px; letter-spacing: -0.02em; color: #0f172a !important; }

.dash-shell .dash-motivation {
  position: relative;
  overflow: hidden;
  background: linear-gradient(136deg,#0f172a 0%,#1e3a8a 52%,#0f766e 100%) !important;
  border: 1px solid #1f3b7d !important;
}
.dash-shell .dash-motivation::after {
  content:'';
  position:absolute;
  width:240px;
  height:240px;
  right:-110px;
  top:-95px;
  border-radius:999px;
  background:radial-gradient(circle, rgba(191,219,254,0.3), rgba(191,219,254,0));
}
.dash-shell .dash-motivation h3,
.dash-shell .dash-motivation p,
.dash-shell .dash-motivation .muted {
  position:relative;
  z-index:1;
  color: #f1f5f9 !important;
}

/* Today's priorities cards */
.dash-shell .priority-list {
  display: grid;
  gap: 10px;
}
.dash-shell label.card.priority-item {
  display: flex !important;
  align-items: flex-start;
  gap: 12px;
  padding: 14px !important;
  background: #ffffff !important;
  border: 1px solid #cbd8ea !important;
  border-radius: 12px !important;
  box-shadow: 0 8px 20px rgba(15,23,42,0.07) !important;
  margin: 0 !important;
  cursor: pointer;
}
.dash-shell label.card.priority-item:hover {
  border-color: #93c5fd !important;
  background: #f8fbff !important;
}
.dash-shell label.card.priority-item span {
  color: #0f172a !important;
  font-weight: 500;
}
.dash-shell label.card.priority-item input[type=checkbox] {
  margin-top: 3px;
  flex-shrink: 0;
  accent-color: var(--dash-blue);
}

.chart-panel canvas { width: 100% !important; height: 260px !important; display: block; }
.chart-panel {
  min-height: 300px;
  border: 1px solid #dbe4f2 !important;
  box-shadow: 0 10px 26px rgba(15,23,42,0.08) !important;
}
@media (max-width: 860px) {
  .dash-grid-4 { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 640px) {
  .dash-grid-2,
  .dash-grid-3,
  .dash-grid-4 { grid-template-columns: 1fr; }
}
</style>


<div class="container dash-shell">

  <!-- Hero banner -->
  <div class="card dash-hero">
    <div class="dash-hero-tag">Alibaba Sourcing Dashboard</div>
    <h1>Keep Your Weekly Procurement Goals Charging Forward</h1>
    <p class="muted">Stay locked on the Alibaba sourcing workflow: send more RFQs, pull in more quotes, release more purchase orders, and keep shipments moving.</p>
    <div>
      <span class="dash-pill">Goal-Driven Sourcing</span>
      <span class="dash-pill">Supplier Momentum Focus</span>
    </div>
    <div class="dash-hero-stats">
      <div class="dash-hero-stat">
        <div class="dash-hero-stat-label">This Week Starts</div>
        <div class="dash-hero-stat-value"><?= h($week_start->format('M j, Y')) ?></div>
      </div>
      <div class="dash-hero-stat">
        <div class="dash-hero-stat-label">Weekly Activity</div>
        <div class="dash-hero-stat-value"><?= number_format($weekly_activity_total) ?></div>
      </div>
      <div class="dash-hero-stat">
        <div class="dash-hero-stat-label">Last Updated</div>
        <div class="dash-hero-stat-value" style="font-size:0.95rem;"><?= h($last_updated) ?></div>
      </div>
    </div>
  </div>

  <!-- Weekly Goals header -->
  <div class="dash-section-header">
    <div>
      <h2>Weekly Goals</h2>
      <p class="muted">Big targets, clear pacing, and a fast read on where Patty should push next.</p>
    </div>
    <div class="card dash-mini-metric" style="margin:0;text-align:right;">
      <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:0.07em;">Goals On Track</div>
      <div style="font-size:2rem;font-weight:700;color:#0d9488;line-height:1.1;">
        <?= number_format($goals_on_track) ?><span style="font-size:1.1rem;font-weight:600;color:#94a3b8;"> / <?= number_format(count($weekly_goals)) ?></span>
      </div>
    </div>
  </div>

  <!-- Weekly Goal cards -->
  <div class="dash-grid-2">
    <?php foreach ($weekly_goals as $goal): ?>
      <div class="card <?= h('goal-card-' . $goal['state']['tone']) ?>">
        <div class="topbar" style="align-items:flex-start;margin-bottom:0;">
          <div>
            <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:0.07em;"><?= h((string)$goal['title']) ?></div>
            <div class="dash-big-num">
              <?= number_format((int)$goal['value']) ?>
              <span style="font-size:1.3rem;font-weight:500;color:#94a3b8;"> / <?= number_format((int)$goal['target']) ?></span>
            </div>
          </div>
          <span class="badge <?= h('goal-badge-' . $goal['state']['tone']) ?>"><?= h((string)$goal['state']['label']) ?></span>
        </div>
        <div style="margin-top:12px;">
          <div class="topbar" style="margin-bottom:4px;">
            <span style="font-size:13px;font-weight:600;"><?= number_format((int)round((float)$goal['progress_percent'])) ?>% complete</span>
            <span class="muted" style="font-size:13px;">Pace target today: <?= number_format((int)$goal['pace_target']) ?></span>
          </div>
          <div class="dash-progress">
            <div class="dash-progress-bar <?= h('goal-bar-' . $goal['state']['tone']) ?>" style="width:<?= number_format((float)$goal['progress_percent'], 2, '.', '') ?>%;"></div>
          </div>
        </div>
        <div class="topbar" style="margin-top:10px;margin-bottom:0;align-items:flex-start;">
          <span class="muted" style="font-size:13px;flex:1;margin-right:12px;"><?= h((string)$goal['state']['message']) ?></span>
          <span style="font-size:13px;font-weight:600;white-space:nowrap;">
            <?= $goal['remaining'] > 0 ? number_format((int)$goal['remaining']) . ' more to goal' : 'Goal reached &#10003;' ?>
          </span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Weekly Execution KPI Cards -->
  <div class="dash-section-header" style="margin-top:24px;">
    <div>
      <h2>Weekly Execution Snapshot</h2>
      <p class="muted">Action-oriented scorecards for the Alibaba sourcing and procurement workflow.</p>
    </div>
  </div>

  <?php
  $kpi_colors = [
    'sky'     => ['accent' => '#1d4ed8', 'light' => '#eaf2ff', 'border' => '#c7dbff'],
    'violet'  => ['accent' => '#4338ca', 'light' => '#eeedff', 'border' => '#c9c5ff'],
    'emerald' => ['accent' => '#0f766e', 'light' => '#ebfffb', 'border' => '#99f6e4'],
    'amber'   => ['accent' => '#b45309', 'light' => '#fff8eb', 'border' => '#fcd34d'],
  ];
  ?>
  <div class="dash-grid-4">
    <?php foreach ($weekly_goals as $card):
      $kc = $kpi_colors[$card['accent_key']] ?? ['accent' => '#2563eb', 'light' => '#eff6ff', 'border' => '#93c5fd'];
    ?>
      <div class="card dash-kpi-card">
        <span class="badge" style="background:<?= h($kc['light']) ?>;color:<?= h($kc['accent']) ?>;border-color:<?= h($kc['border']) ?>;font-size:11px;text-transform:uppercase;letter-spacing:0.07em;">This Week</span>
        <div class="muted" style="font-size:13px;margin-top:8px;"><?= h($card['label']) ?></div>
        <div style="font-size:3rem;font-weight:700;line-height:1.1;color:<?= h($kc['accent']) ?>;margin:8px 0 4px;"><?= number_format((int)$card['value']) ?></div>
        <div class="muted" style="font-size:13px;">Goal: <strong><?= number_format((int)$card['target']) ?></strong></div>
        <div class="dash-progress">
          <div class="dash-progress-bar" style="width:<?= number_format(min(100, (float)$card['progress_percent']), 2, '.', '') ?>%;background:<?= h($kc['accent']) ?>;"></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Today's Priorities -->
  <div style="margin-top:24px;">
    <h2>Today's Priorities</h2>
    <p>Win the day by moving the next supplier action forward.</p>
  </div>

  <!-- Procurement Pipeline Snapshot -->
  <div class="dash-section-header" style="margin-top:24px;">
    <div>
      <h2>Procurement Pipeline Snapshot</h2>
      <p class="muted">Stay close to production and shipment movement so today&rsquo;s sourcing work turns into delivered machines.</p>
    </div>
  </div>
  <div class="dash-grid-3">
    <div class="card dash-metric-card">
      <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:0.07em;">Items In Production</div>
      <div class="dash-big-num" style="color:#2563eb;"><?= number_format($items_in_production) ?></div>
      <p class="muted" style="font-size:13px;margin:4px 0 0;">Orders with production underway that have not shipped yet.</p>
    </div>
    <div class="card dash-metric-card">
      <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:0.07em;">Shipments In Transit</div>
      <div class="dash-big-num" style="color:#0d9488;"><?= number_format($shipments_in_transit) ?></div>
      <p class="muted" style="font-size:13px;margin:4px 0 0;">Incoming shipments currently marked In Transit.</p>
    </div>
    <div class="card dash-metric-card">
      <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:0.07em;">Expected Arrivals (30 Days)</div>
      <div class="dash-big-num" style="color:#0284c7;"><?= number_format($expected_arrivals) ?></div>
      <p class="muted" style="font-size:13px;margin:4px 0 0;">Open incoming shipments scheduled to arrive within the next month.</p>
    </div>
  </div>

  <!-- Momentum Trend Charts -->
  <div class="dash-section-header" style="margin-top:24px;">
    <div>
      <h2>Momentum Trends</h2>
      <p class="muted">Use the last eight weeks of sourcing and shipping activity to keep momentum building.</p>
    </div>
  </div>
  <div class="dash-grid-2">
    <div class="card chart-panel">
      <div class="topbar" style="margin-bottom:12px;align-items:flex-start;">
        <div>
          <h3 style="margin:0 0 2px;">RFQs Sent Per Week</h3>
          <p class="muted" style="font-size:13px;margin:0;">Line chart &middot; last 8 weeks</p>
        </div>
        <div class="card dash-chart-total dash-chart-total-rfq">
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.07em;color:#2563eb;font-weight:600;">8-Week Total</div>
          <div style="font-size:1.5rem;font-weight:700;color:#1d4ed8;"><?= number_format(array_sum($rfq_chart['values'])) ?></div>
        </div>
      </div>
      <canvas id="rfqWeeklyChart" aria-label="RFQs sent per week line chart" role="img"></canvas>
    </div>
    <div class="card chart-panel">
      <div class="topbar" style="margin-bottom:12px;align-items:flex-start;">
        <div>
          <h3 style="margin:0 0 2px;">Items Shipped Per Week</h3>
          <p class="muted" style="font-size:13px;margin:0;">Bar chart &middot; last 8 weeks</p>
        </div>
        <div class="card dash-chart-total dash-chart-total-ship">
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.07em;color:#0d9488;font-weight:600;">8-Week Total</div>
          <div style="font-size:1.5rem;font-weight:700;color:#0f766e;"><?= number_format(array_sum($shipped_chart['values'])) ?></div>
        </div>
      </div>
      <canvas id="shippedWeeklyChart" aria-label="Items shipped per week bar chart" role="img"></canvas>
    </div>
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
