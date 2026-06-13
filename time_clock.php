<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$uid = current_user_id();
$tz = new DateTimeZone('America/Los_Angeles');
$errors = [];
$success = '';
$today = (new DateTime('now', $tz))->format('Y-m-d');
$week_start = (new DateTime('monday this week', $tz))->format('Y-m-d');

// Bi-weekly pay period: anchor Monday June 15, 2026; 14-day intervals in both directions.
$pay_anchor = new DateTime('2026-06-15', $tz);
$now_date   = new DateTime('today', $tz);
$days_diff  = (int)(($now_date->getTimestamp() - $pay_anchor->getTimestamp()) / 86400);
$period_offset = (int)floor($days_diff / 14);
$pay_period_start_obj = clone $pay_anchor;
if ($period_offset !== 0) {
  $pay_period_start_obj->modify(($period_offset * 14) . ' days');
}
$pay_period_start = $pay_period_start_obj->format('Y-m-d');
$idle_session_key = defined('ATTENDANCE_IDLE_SESSION_KEY')
  ? ATTENDANCE_IDLE_SESSION_KEY
  : 'attendance_idle_logged';

$load_open_entry = static function () use ($pdo, $uid) {
  $stmt = $pdo->prepare("
    SELECT id, clock_in, lunch_start, lunch_end, clock_out, hours_override
    FROM time_entries
    WHERE user_id = ? AND clock_out IS NULL AND hours_override IS NULL
    ORDER BY clock_in DESC
    LIMIT 1
  ");
  $stmt->execute([$uid]);
  return $stmt->fetch() ?: null;
};

$entry_hours = static function (array $entry, DateTimeZone $tz): ?float {
  if ($entry['hours_override'] !== null) {
    return (float)$entry['hours_override'];
  }

  $clock_in = new DateTime($entry['clock_in'], $tz);
  $clock_out = !empty($entry['clock_out'])
    ? new DateTime($entry['clock_out'], $tz)
    : new DateTime('now', $tz);

  $work_seconds = max(0, $clock_out->getTimestamp() - $clock_in->getTimestamp());

  if (!empty($entry['lunch_start'])) {
    $lunch_start = new DateTime($entry['lunch_start'], $tz);
    $lunch_end = !empty($entry['lunch_end'])
      ? new DateTime($entry['lunch_end'], $tz)
      : clone $clock_out;

    if ($lunch_end > $lunch_start) {
      $lunch_seconds = max(0, $lunch_end->getTimestamp() - $lunch_start->getTimestamp());
      $work_seconds = max(0, $work_seconds - $lunch_seconds);
    }
  }

  return $work_seconds / 3600;
};

$open_entry = $load_open_entry();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['action'] ?? ''));
  $now_obj = new DateTime('now', $tz);
  $now = $now_obj->format('Y-m-d H:i:s');

  if ($action === 'clock_in') {
    if ($open_entry) {
      $errors[] = 'You are already clocked in.';
    } else {
      $pdo->prepare("
        INSERT INTO time_entries (user_id, clock_in)
        VALUES (?, ?)
      ")->execute([$uid, $now]);
      unset($_SESSION[$idle_session_key]);
      $success = 'Clocked in at ' . $now_obj->format('g:i A') . '.';
    }
  } elseif ($action === 'start_lunch') {
    if (!$open_entry) {
      $errors[] = 'Clock in before starting lunch.';
    } elseif (!empty($open_entry['lunch_start']) && empty($open_entry['lunch_end'])) {
      $errors[] = 'Lunch has already started.';
    } elseif (!empty($open_entry['lunch_end'])) {
      $errors[] = 'Lunch has already been completed for this shift.';
    } else {
      $pdo->prepare("
        UPDATE time_entries
        SET lunch_start = ?
        WHERE id = ? AND user_id = ?
      ")->execute([$now, (int)$open_entry['id'], $uid]);
      $success = 'Lunch started at ' . $now_obj->format('g:i A') . '.';
    }
  } elseif ($action === 'end_lunch') {
    if (!$open_entry) {
      $errors[] = 'Clock in before ending lunch.';
    } elseif (empty($open_entry['lunch_start'])) {
      $errors[] = 'Start lunch first.';
    } elseif (!empty($open_entry['lunch_end'])) {
      $errors[] = 'Lunch has already ended.';
    } else {
      $pdo->prepare("
        UPDATE time_entries
        SET lunch_end = ?
        WHERE id = ? AND user_id = ?
      ")->execute([$now, (int)$open_entry['id'], $uid]);
      $success = 'Lunch ended at ' . $now_obj->format('g:i A') . '.';
    }
  } elseif ($action === 'clock_out') {
    if (!$open_entry) {
      $errors[] = 'You are not currently clocked in.';
    } else {
      $params = [$now];
      $sql = "UPDATE time_entries SET clock_out = ?";
      if (!empty($open_entry['lunch_start']) && empty($open_entry['lunch_end'])) {
        $sql .= ", lunch_end = ?";
        $params[] = $now;
      }
      $sql .= " WHERE id = ? AND user_id = ?";
      $params[] = (int)$open_entry['id'];
      $params[] = $uid;
      $pdo->prepare($sql)->execute($params);
      unset($_SESSION[$idle_session_key]);
      $success = 'Clocked out at ' . $now_obj->format('g:i A') . '.';
    }
  }

  $open_entry = $load_open_entry();
}

// Query from pay_period_start (always <= week_start) so one query covers all three totals.
$summary_stmt = $pdo->prepare("
  SELECT id, clock_in, lunch_start, lunch_end, clock_out, hours_override
  FROM time_entries
  WHERE user_id = ? AND DATE(clock_in) >= ?
  ORDER BY clock_in DESC
");
$summary_stmt->execute([$uid, $pay_period_start]);
$summary_entries = $summary_stmt->fetchAll();

$today_hours       = 0.0;
$week_hours        = 0.0;
$pay_period_hours  = 0.0;
foreach ($summary_entries as $entry) {
  $hours = $entry_hours($entry, $tz);
  if ($hours === null) {
    continue;
  }
  $entry_date = substr((string)$entry['clock_in'], 0, 10);
  $pay_period_hours += $hours;
  if ($entry_date >= $week_start) {
    $week_hours += $hours;
  }
  if ($entry_date === $today) {
    $today_hours += $hours;
  }
}

$recent_stmt = $pdo->prepare("
  SELECT id, clock_in, lunch_start, lunch_end, clock_out, hours_override
  FROM time_entries
  WHERE user_id = ?
  ORDER BY clock_in DESC
  LIMIT 20
");
$recent_stmt->execute([$uid]);
$recent_entries = $recent_stmt->fetchAll();

$is_on_lunch = $open_entry && !empty($open_entry['lunch_start']) && empty($open_entry['lunch_end']);

render_header('Time Clock');
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <h1 style="margin:0;">Attendance</h1>
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <span class="muted">Week starts Monday</span>
      <button type="button" class="btn" onclick="window.print()" style="display:flex; align-items:center; gap:6px;">
        <span>🖨️</span> Print My Timesheet
      </button>
    </div>
  </div>
  <p class="muted">Use the buttons below to manage your shift and lunch break.</p>
  <div style="margin-top:12px; padding:12px 16px; border-radius:6px; background:#f8f9fa; border:1px solid #dee2e6; color:#6c757d; font-size:14px; line-height:1.6;">
    This page tracks your daily work hours for payroll. Your activity on the website is automatically monitored to help us understand what you're working on. If you are inactive for an extended period, it will be logged in the admin activity report. Please remember to Clock In when you start work, use the lunch buttons, and Clock Out at the end of your day.
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
    <?= h($success) ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="form-grid" style="grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));">
    <div>
      <div class="muted" style="font-size:12px; text-transform:uppercase; letter-spacing:.08em;">Today</div>
      <div style="font-size:32px; font-weight:700; margin-top:6px;"><?= number_format($today_hours, 2) ?>h</div>
    </div>
    <div>
      <div class="muted" style="font-size:12px; text-transform:uppercase; letter-spacing:.08em;">This Week</div>
      <div style="font-size:32px; font-weight:700; margin-top:6px;"><?= number_format($week_hours, 2) ?>h</div>
    </div>
    <div>
      <div class="muted" style="font-size:12px; text-transform:uppercase; letter-spacing:.08em;">Current Pay Period</div>
      <div style="font-size:32px; font-weight:700; margin-top:6px;"><?= number_format($pay_period_hours, 2) ?>h</div>
      <div class="muted" style="font-size:11px; margin-top:4px;">Since <?= h($pay_period_start_obj->format('M j, Y')) ?></div>
    </div>
  </div>

  <?php if ($open_entry): ?>
    <?php $clock_in = new DateTime($open_entry['clock_in'], $tz); ?>
    <p class="muted" style="margin:16px 0 0;">
      <strong>Status:</strong>
      <?php if ($is_on_lunch): ?>
        On Lunch —
      <?php elseif ($open_entry): ?>
        Clocked In —
      <?php endif; ?>
      Shift started at <strong><?= h($clock_in->format('g:i A')) ?></strong>
      on <?= h($clock_in->format('m-d-Y')) ?>.
      <?php if ($is_on_lunch): ?>
        Lunch started at <strong><?= h((new DateTime($open_entry['lunch_start'], $tz))->format('g:i A')) ?></strong>.
      <?php elseif (!empty($open_entry['lunch_end'])): ?>
        Lunch completed.
      <?php endif; ?>
    </p>
  <?php else: ?>
    <p class="muted" style="margin:16px 0 0;"><strong>Status:</strong> Clocked Out</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0;">Attendance Actions</h2>
  <div class="form-grid" style="grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));">
    <form method="post">
      <input type="hidden" name="action" value="clock_in">
      <button type="submit" class="btn primary" style="width:100%;" <?= $open_entry ? 'disabled' : '' ?>>Clock In</button>
    </form>
    <form method="post">
      <input type="hidden" name="action" value="start_lunch">
      <button type="submit" class="btn" style="width:100%;" <?= (!$open_entry || $is_on_lunch || !empty($open_entry['lunch_end'])) ? 'disabled' : '' ?>>Start Lunch</button>
    </form>
    <form method="post">
      <input type="hidden" name="action" value="end_lunch">
      <button type="submit" class="btn" style="width:100%;" <?= $is_on_lunch ? '' : 'disabled' ?>>End Lunch</button>
    </form>
    <form method="post">
      <input type="hidden" name="action" value="clock_out">
      <button type="submit" class="btn danger" style="width:100%;" <?= $open_entry ? '' : 'disabled' ?>>Clock Out</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <h2 style="margin:0;">Recent Attendance</h2>
    <span class="muted">Lunch time is automatically excluded from worked hours.</span>
  </div>

  <div class="table-wrap">
    <table class="table-auto">
      <thead>
        <tr>
          <th>Date</th>
          <th>Clock In</th>
          <th>Lunch</th>
          <th>Clock Out</th>
          <th>Hours</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$recent_entries): ?>
          <tr><td colspan="6" class="muted">No attendance entries yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recent_entries as $entry): ?>
          <?php
            $clock_in = new DateTime($entry['clock_in'], $tz);
            $clock_out = !empty($entry['clock_out']) ? new DateTime($entry['clock_out'], $tz) : null;
            $hours = $entry_hours($entry, $tz);
            $row_on_lunch = !$clock_out && !empty($entry['lunch_start']) && empty($entry['lunch_end']);
            $status = $row_on_lunch ? 'On Lunch' : ($clock_out ? 'Complete' : 'Clocked In');
            $lunch_display = '<span class="muted">—</span>';
            if (!empty($entry['lunch_start'])) {
              $lunch_start = new DateTime($entry['lunch_start'], $tz);
              if (!empty($entry['lunch_end'])) {
                $lunch_end = new DateTime($entry['lunch_end'], $tz);
                $lunch_display = h($lunch_start->format('g:i A')) . ' – ' . h($lunch_end->format('g:i A'));
              } else {
                $lunch_display = 'Started ' . h($lunch_start->format('g:i A'));
              }
            }
          ?>
          <tr>
            <td><?= h($clock_in->format('m-d-Y')) ?></td>
            <td><?= h($clock_in->format('g:i A')) ?></td>
            <td><?= $lunch_display ?></td>
            <td><?= $clock_out ? h($clock_out->format('g:i A')) : '<span class="muted">Open</span>' ?></td>
            <td><?= $hours !== null ? number_format($hours, 2) . 'h' : '<span class="muted">—</span>' ?></td>
            <td><?= h($status) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
