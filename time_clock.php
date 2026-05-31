<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$uid = current_user_id();
$tz  = new DateTimeZone('America/Los_Angeles');
$errors  = [];
$success = '';

// ── Fetch projects for the manual-entry dropdown ──────────────────────────
if (is_admin()) {
  $projects = $pdo->query("SELECT id, name FROM projects WHERE playbook = 0 AND archived = 0 ORDER BY name ASC")->fetchAll();
} else {
  $proj_stmt = $pdo->prepare("
    SELECT DISTINCT p.id, p.name
    FROM projects p
    LEFT JOIN tasks t ON t.project_id = p.id AND t.assigned_to = ?
    WHERE p.playbook = 0
      AND p.archived = 0
      AND (p.owner_id = ? OR t.id IS NOT NULL)
    ORDER BY p.name ASC
  ");
  $proj_stmt->execute([$uid, $uid]);
  $projects = $proj_stmt->fetchAll();
}

// ── Fetch playbooks for the clock-in dropdown ─────────────────────────────
$playbooks = $pdo->query("SELECT id, name FROM projects WHERE playbook = 1 AND archived = 0 ORDER BY name ASC")->fetchAll();

// ── Fetch active sourcing RFQs for the RFQ dropdown ───────────────────────
$sourcing_rfqs = $pdo->query("SELECT id, request_title FROM rfq_requests WHERE request_status = 'sourcing' ORDER BY created_at DESC")->fetchAll();

// ── Check for an open clock-in (clock_out IS NULL) ────────────────────────
$open_stmt = $pdo->prepare("
  SELECT id, clock_in, project_id, description
  FROM time_entries
  WHERE user_id = ? AND clock_out IS NULL AND hours_override IS NULL
  ORDER BY clock_in DESC
  LIMIT 1
");
$open_stmt->execute([$uid]);
$open_entry = $open_stmt->fetch();
$has_project_or_text = static function (?int $project_id, string $text): bool {
  return $project_id !== null || $text !== '';
};

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  // CLOCK IN
  if ($action === 'clock_in') {
    if ($open_entry) {
      $errors[] = 'You are already clocked in. Clock out first.';
    } else {
      $proj = (int)($_POST['project_id'] ?? 0) ?: null;
      if (!$proj) {
        $proj = (int)($_POST['playbook_id'] ?? 0) ?: null;
      }
      $rfq_id_in = (int)($_POST['rfq_id'] ?? 0) ?: null;
      $desc = trim($_POST['description'] ?? '');
      if (!$has_project_or_text($proj, $desc) && !$rfq_id_in) {
        $errors[] = 'Please select a project, playbook, RFQ, or enter a note before clocking in.';
      } else {
        $now  = (new DateTime('now', $tz))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
          INSERT INTO time_entries (user_id, project_id, rfq_id, description, clock_in)
          VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$uid, $proj, $rfq_id_in, $desc ?: null, $now]);
        $success = 'Clocked in at ' . (new DateTime('now', $tz))->format('g:i A');
        // Refresh open entry
        $open_stmt->execute([$uid]);
        $open_entry = $open_stmt->fetch();
      }
    }
  }

  // CLOCK OUT
  elseif ($action === 'clock_out') {
    $entry_id = (int)($_POST['entry_id'] ?? 0);
    if (!$open_entry || (int)$open_entry['id'] !== $entry_id) {
      $errors[] = 'No matching open entry found.';
    } else {
      $now = (new DateTime('now', $tz))->format('Y-m-d H:i:s');
      $pdo->prepare("
        UPDATE time_entries SET clock_out = ? WHERE id = ? AND user_id = ?
      ")->execute([$now, $entry_id, $uid]);
      $success = 'Clocked out at ' . (new DateTime('now', $tz))->format('g:i A');
      $open_entry = null;
    }
  }

  // MANUAL ENTRY
  elseif ($action === 'manual') {
    $date  = trim($_POST['entry_date'] ?? '');
    $start = trim($_POST['start_time'] ?? '');
    $end   = trim($_POST['end_time'] ?? '');
    $proj  = (int)($_POST['project_id'] ?? 0) ?: null;
    $rfq_id_man = (int)($_POST['rfq_id'] ?? 0) ?: null;
    $desc  = trim($_POST['description'] ?? '');

    $clock_in_obj  = DateTime::createFromFormat('Y-m-d H:i', "$date $start", $tz);
    $clock_out_obj = DateTime::createFromFormat('Y-m-d H:i', "$date $end",   $tz);

    if (!$date || !$start || !$end) {
      $errors[] = 'Date, start time, and end time are required.';
    } elseif (!$has_project_or_text($proj, $desc) && !$rfq_id_man) {
      $errors[] = 'Please select a project, RFQ, or enter a description for manual entry.';
    } elseif (!$clock_in_obj || !$clock_out_obj) {
      $errors[] = 'Invalid date or time format.';
    } elseif ($clock_out_obj <= $clock_in_obj) {
      $errors[] = 'End time must be after start time.';
    } else {
      $diff_hours = ($clock_out_obj->getTimestamp() - $clock_in_obj->getTimestamp()) / 3600;
      $pdo->prepare("
        INSERT INTO time_entries (user_id, project_id, rfq_id, description, clock_in, clock_out, hours_override)
        VALUES (?, ?, ?, ?, ?, ?, ?)
      ")->execute([
        $uid,
        $proj,
        $rfq_id_man,
        $desc ?: null,
        $clock_in_obj->format('Y-m-d H:i:s'),
        $clock_out_obj->format('Y-m-d H:i:s'),
        round($diff_hours, 2),
      ]);
      $success = sprintf('Manual entry saved: %.2f hour(s).', $diff_hours);
    }
  }

  // DELETE own entry
  elseif ($action === 'delete') {
    $entry_id = (int)($_POST['entry_id'] ?? 0);
    if ($entry_id > 0) {
      $pdo->prepare("DELETE FROM time_entries WHERE id = ? AND user_id = ?")->execute([$entry_id, $uid]);
      $success = 'Entry deleted.';
      if ($open_entry && (int)$open_entry['id'] === $entry_id) {
        $open_entry = null;
      }
    }
  }
}

// ── View filter ───────────────────────────────────────────────────────────
$view   = $_GET['view'] ?? 'week';   // today | week | all
$today  = (new DateTime('now', $tz))->format('Y-m-d');
$week_start = (new DateTime('monday this week', $tz))->format('Y-m-d');

$where_date = '';
$params     = [$uid];
if ($view === 'today') {
  $where_date = "AND DATE(clock_in) = ?";
  $params[]   = $today;
} elseif ($view === 'week') {
  $where_date = "AND DATE(clock_in) >= ?";
  $params[]   = $week_start;
}

$entries_stmt = $pdo->prepare("
  SELECT
    te.id, te.clock_in, te.clock_out, te.hours_override,
    te.description, te.project_id, te.rfq_id,
    p.name AS project_name,
    r.request_title AS rfq_title
  FROM time_entries te
  LEFT JOIN projects p ON p.id = te.project_id
  LEFT JOIN rfq_requests r ON r.id = te.rfq_id
  WHERE te.user_id = ?
    $where_date
  ORDER BY te.clock_in DESC
");
$entries_stmt->execute($params);
$entries = $entries_stmt->fetchAll();

// Running total
$total_hours = 0.0;
foreach ($entries as $e) {
  if ($e['hours_override'] !== null) {
    $total_hours += (float)$e['hours_override'];
  } elseif ($e['clock_out']) {
    $ci = new DateTime($e['clock_in'],  $tz);
    $co = new DateTime($e['clock_out'], $tz);
    $total_hours += ($co->getTimestamp() - $ci->getTimestamp()) / 3600;
  }
}

// Helper to compute hours for a single entry
function entry_hours(array $e, DateTimeZone $tz): ?float {
  if ($e['hours_override'] !== null) return (float)$e['hours_override'];
  if ($e['clock_out']) {
    $ci = new DateTime($e['clock_in'],  $tz);
    $co = new DateTime($e['clock_out'], $tz);
    return ($co->getTimestamp() - $ci->getTimestamp()) / 3600;
  }
  return null; // still open
}

render_header('Time Clock');
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Time Clock</h1>
  </div>
  <p class="muted">Clock in and out, log manual entries, and review your time.</p>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
    <?= h($success) ?>
  </div>
<?php endif; ?>

<!-- ── Clock In / Out ────────────────────────────────────────────────────── -->
<div class="card">
  <h2 style="margin-top:0;">
    <?= $open_entry ? 'Clock Out' : 'Clock In' ?>
  </h2>

  <?php if ($open_entry): ?>
    <?php
      $ci = new DateTime($open_entry['clock_in'], $tz);
      $elapsed_mins = (int)(((new DateTime('now', $tz))->getTimestamp() - $ci->getTimestamp()) / 60);
      $elapsed_h = intdiv($elapsed_mins, 60);
      $elapsed_m = $elapsed_mins % 60;
    ?>
    <p>
      Clocked in at <strong><?= h($ci->format('g:i A')) ?></strong>
      on <?= h($ci->format('m-d-Y')) ?>
      &mdash; <span class="muted"><?= $elapsed_h ?>h <?= $elapsed_m ?>m elapsed</span>
    </p>
    <?php if (!empty($open_entry['description'])): ?>
      <p class="muted">Note: <?= h($open_entry['description']) ?></p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="action"   value="clock_out">
      <input type="hidden" name="entry_id" value="<?= (int)$open_entry['id'] ?>">
      <button type="submit" class="btn danger">Clock Out Now</button>
    </form>

  <?php else: ?>
    <form method="post" style="max-width:480px;">
      <input type="hidden" name="action" value="clock_in">

      <label>Project (or add a note)</label>
      <select name="project_id" id="ci_project_id" onchange="if(this.value){document.getElementById('ci_playbook_id').value='';}">
        <option value="">— No project —</option>
        <?php foreach ($projects as $pr): ?>
          <option value="<?= (int)$pr['id'] ?>"><?= h($pr['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <label>Playbook</label>
      <select name="playbook_id" id="ci_playbook_id" onchange="if(this.value){document.getElementById('ci_project_id').value='';}">
        <option value="">— No playbook —</option>
        <?php foreach ($playbooks as $pb): ?>
          <option value="<?= (int)$pb['id'] ?>"><?= h($pb['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <label>Sourcing RFQ</label>
      <select name="rfq_id" id="ci_rfq_id">
        <option value="">— No RFQ —</option>
        <?php foreach ($sourcing_rfqs as $rfq): ?>
          <option value="<?= (int)$rfq['id'] ?>">RFQ #<?= (int)$rfq['id'] ?> – <?= h($rfq['request_title']) ?></option>
        <?php endforeach; ?>
        <?php if (!$sourcing_rfqs): ?>
          <option value="" disabled>No active sourcing RFQs</option>
        <?php endif; ?>
      </select>

      <label>Note (or select a project)</label>
      <input type="text" name="description" maxlength="255" placeholder="What are you working on?" />

      <div class="row" style="margin-top:14px;">
        <button type="submit" class="btn primary">Clock In</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<!-- ── Manual Entry ──────────────────────────────────────────────────────── -->
<div class="card">
  <h2 style="margin-top:0;">Log Manual Entry</h2>
  <form method="post" style="max-width:560px;">
    <input type="hidden" name="action" value="manual">

    <div class="form-grid">
      <div>
        <label>Date</label>
        <input type="date" name="entry_date" value="<?= h($today) ?>" required />
      </div>
      <div>
        <label>Project (or add a description)</label>
        <select name="project_id">
          <option value="">— No project —</option>
          <?php foreach ($projects as $pr): ?>
            <option value="<?= (int)$pr['id'] ?>"><?= h($pr['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Sourcing RFQ</label>
        <select name="rfq_id">
          <option value="">— No RFQ —</option>
          <?php foreach ($sourcing_rfqs as $rfq): ?>
            <option value="<?= (int)$rfq['id'] ?>">RFQ #<?= (int)$rfq['id'] ?> – <?= h($rfq['request_title']) ?></option>
          <?php endforeach; ?>
          <?php if (!$sourcing_rfqs): ?>
            <option value="" disabled>No active sourcing RFQs</option>
          <?php endif; ?>
        </select>
      </div>
      <div>
        <label>Start Time</label>
        <input type="time" name="start_time" required />
      </div>
      <div>
        <label>End Time</label>
        <input type="time" name="end_time" required />
      </div>
      <div class="full">
        <label>Description (or select a project)</label>
        <input type="text" name="description" maxlength="255" placeholder="What did you work on?" />
      </div>
      <div class="full">
        <button type="submit" class="btn primary">Save Entry</button>
      </div>
    </div>
  </form>
</div>

<!-- ── My Time Log ───────────────────────────────────────────────────────── -->
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
    <h2 style="margin:0;">My Time Log</h2>
    <div class="row" style="gap:6px;">
      <a class="btn <?= $view === 'today' ? 'primary' : '' ?>" href="?view=today">Today</a>
      <a class="btn <?= $view === 'week'  ? 'primary' : '' ?>" href="?view=week">This Week</a>
      <a class="btn <?= $view === 'all'   ? 'primary' : '' ?>" href="?view=all">All</a>
    </div>
  </div>

  <p class="muted" style="margin-top:8px;">
    Total: <strong><?= number_format($total_hours, 2) ?> hour(s)</strong>
    for <?= $view === 'today' ? 'today' : ($view === 'week' ? 'this week' : 'all time') ?>
  </p>

  <div class="table-wrap">
    <table class="table-auto">
      <thead>
        <tr>
          <th>Date</th>
          <th>Clock In</th>
          <th>Clock Out</th>
          <th>Hours</th>
          <th>Project</th>
          <th>RFQ</th>
          <th>Description</th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$entries): ?>
          <tr><td colspan="8" class="muted">No entries for this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($entries as $e): ?>
          <?php
            $ci  = new DateTime($e['clock_in'], $tz);
            $co  = $e['clock_out'] ? new DateTime($e['clock_out'], $tz) : null;
            $hrs = entry_hours($e, $tz);
          ?>
          <tr>
            <td><?= h($ci->format('m-d-Y')) ?></td>
            <td><?= h($ci->format('g:i A')) ?></td>
            <td>
              <?php if ($co): ?>
                <?= h($co->format('g:i A')) ?>
              <?php else: ?>
                <span class="badge clocked-in">Open</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($hrs !== null): ?>
                <?= number_format($hrs, 2) ?>h
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= $e['project_name'] ? h($e['project_name']) : '<span class="muted">—</span>' ?></td>
            <td><?= $e['rfq_title'] ? '<a href="rfq_tracker.php?id=' . (int)$e['rfq_id'] . '">' . h($e['rfq_title']) . '</a>' : '<span class="muted">—</span>' ?></td>
            <td class="col-desc"><?= $e['description'] ? h($e['description']) : '<span class="muted">—</span>' ?></td>
            <td class="col-actions">
              <form method="post" style="display:inline;"
                onsubmit="return confirm('Delete this time entry?');">
                <input type="hidden" name="action"   value="delete">
                <input type="hidden" name="entry_id" value="<?= (int)$e['id'] ?>">
                <button type="submit" class="btn danger" style="padding:4px 8px; font-size:13px;">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
