<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin();

$tz = new DateTimeZone('America/Los_Angeles');

// ── Delete time entry ─────────────────────────────────────────────────────
if (isset($_POST['delete_entry'])) {
  $entry_id = (int)($_POST['entry_id'] ?? 0);
  if ($entry_id > 0) {
    $chk = $pdo->prepare("SELECT id FROM time_entries WHERE id = ?");
    $chk->execute([$entry_id]);
    if ($chk->fetch()) {
      $pdo->prepare("DELETE FROM time_entries WHERE id = ?")->execute([$entry_id]);
    }
  }
  $qs = http_build_query(array_filter([
    'filter_from'    => $_POST['filter_from']    ?? '',
    'filter_to'      => $_POST['filter_to']      ?? '',
    'filter_user'    => $_POST['filter_user']    ?? '',
    'filter_project' => $_POST['filter_project'] ?? '',
  ], fn($v) => $v !== '' && $v !== '0'));
  header('Location: time_report.php' . ($qs ? '?' . $qs : ''));
  exit;
}

// ── Save edited time entry ────────────────────────────────────────────────
$save_errors = [];
if (isset($_POST['save_entry'])) {
  $entry_id   = (int)($_POST['entry_id'] ?? 0);

  if ($entry_id > 0) {
    $clock_in_raw     = trim($_POST['clock_in']       ?? '');
    $clock_out_raw    = trim($_POST['clock_out']      ?? '');
    $hours_override_raw = trim($_POST['hours_override'] ?? '');
    $description      = trim($_POST['description']    ?? '');
    $user_id_save     = (int)($_POST['user_id']       ?? 0);
    $project_id_save  = (int)($_POST['project_id_entry'] ?? 0) ?: null;

    $ci = $clock_in_raw !== ''
      ? DateTime::createFromFormat('Y-m-d\TH:i', $clock_in_raw, $tz)
      : false;
    $co = $clock_out_raw !== ''
      ? DateTime::createFromFormat('Y-m-d\TH:i', $clock_out_raw, $tz)
      : null;
    $ho = $hours_override_raw !== '' ? (float)$hours_override_raw : null;

    if (!$ci)          $save_errors[] = "Clock-in date/time is required and must be valid.";
    if ($user_id_save <= 0) $save_errors[] = "Employee is required.";

    if (!$save_errors) {
      $pdo->prepare("UPDATE time_entries
                     SET user_id=?, project_id=?, clock_in=?, clock_out=?,
                         hours_override=?, description=?
                     WHERE id=?")
          ->execute([
            $user_id_save,
            $project_id_save,
            $ci->format('Y-m-d H:i:s'),
            $co ? $co->format('Y-m-d H:i:s') : null,
            $ho,
            $description ?: null,
            $entry_id,
          ]);

      $qs = http_build_query(array_filter([
        'filter_from'    => $_POST['filter_from']    ?? '',
        'filter_to'      => $_POST['filter_to']      ?? '',
        'filter_user'    => $_POST['filter_user']    ?? '',
        'filter_project' => $_POST['filter_project'] ?? '',
      ], fn($v) => $v !== '' && $v !== '0'));
      header('Location: time_report.php' . ($qs ? '?' . $qs : ''));
      exit;
    }
  }
}

// ── CSV export (must happen before any output) ────────────────────────────
if (isset($_POST['export_csv'])) {
  $f_user    = (int)($_POST['filter_user']    ?? 0) ?: null;
  $f_project = (int)($_POST['filter_project'] ?? 0) ?: null;
  $f_from    = trim($_POST['filter_from'] ?? '');
  $f_to      = trim($_POST['filter_to']   ?? '');

  [$rows, , , ,] = time_report_query($pdo, $tz, $f_user, $f_project, $f_from, $f_to);

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="time_report_' . date('Ymd_His') . '.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Employee', 'Project', 'Clock In', 'Clock Out', 'Hours', 'Description']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['username'],
      $r['project_name'] ?? '',
      $r['clock_in'],
      $r['clock_out'] ?? '',
      $r['hours'] !== null ? number_format((float)$r['hours'], 2) : '',
      $r['description'] ?? '',
    ]);
  }
  fclose($out);
  exit;
}

// ── Helper: run the main filtered query + return components ───────────────
function time_report_query(PDO $pdo, DateTimeZone $tz,
  ?int $f_user, ?int $f_project, string $f_from, string $f_to): array
{
  $where  = [];
  $params = [];

  if ($f_user) {
    $where[]  = 'te.user_id = ?';
    $params[] = $f_user;
  }
  if ($f_project) {
    $where[]  = 'te.project_id = ?';
    $params[] = $f_project;
  }
  if ($f_from !== '') {
    $where[]  = 'DATE(te.clock_in) >= ?';
    $params[] = $f_from;
  }
  if ($f_to !== '') {
    $where[]  = 'DATE(te.clock_in) <= ?';
    $params[] = $f_to;
  }

  $sql = "
    SELECT
      te.id,
      te.clock_in,
      te.clock_out,
      te.hours_override,
      te.description,
      u.username,
      p.name AS project_name,
      CASE
        WHEN te.hours_override IS NOT NULL THEN te.hours_override
        WHEN te.clock_out IS NOT NULL
          THEN ROUND(TIMESTAMPDIFF(SECOND, te.clock_in, te.clock_out) / 3600, 2)
        ELSE NULL
      END AS hours
    FROM time_entries te
    JOIN users u ON u.id = te.user_id
    LEFT JOIN projects p ON p.id = te.project_id
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY te.clock_in DESC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();

  // Per-employee summary
  $by_employee = [];
  foreach ($rows as $r) {
    $name = $r['username'];
    if (!isset($by_employee[$name])) {
      $by_employee[$name] = ['username' => $name, 'total_hours' => 0.0, 'entries' => 0];
    }
    $by_employee[$name]['entries']++;
    if ($r['hours'] !== null) {
      $by_employee[$name]['total_hours'] += (float)$r['hours'];
    }
  }
  usort($by_employee, fn($a, $b) => $b['total_hours'] <=> $a['total_hours']);

  // Per-project summary
  $by_project = [];
  foreach ($rows as $r) {
    $pname = $r['project_name'] ?? '(No project)';
    if (!isset($by_project[$pname])) {
      $by_project[$pname] = ['project_name' => $pname, 'total_hours' => 0.0, 'employees' => []];
    }
    if ($r['hours'] !== null) {
      $by_project[$pname]['total_hours'] += (float)$r['hours'];
    }
    $by_project[$pname]['employees'][$r['username']] = true;
  }
  usort($by_project, fn($a, $b) => $b['total_hours'] <=> $a['total_hours']);

  return [$rows, $by_employee, $by_project];
}

// ── Filters from GET ──────────────────────────────────────────────────────
$f_user    = (int)($_GET['filter_user']    ?? 0) ?: null;
$f_project = (int)($_GET['filter_project'] ?? 0) ?: null;

// Default date range: current week Mon–today
$today      = (new DateTime('now', $tz))->format('Y-m-d');
$week_start = (new DateTime('monday this week', $tz))->format('Y-m-d');
$f_from = $_GET['filter_from'] ?? $week_start;
$f_to   = $_GET['filter_to']   ?? $today;

[$detail_rows, $by_employee, $by_project] = time_report_query(
  $pdo, $tz, $f_user, $f_project, $f_from, $f_to
);

// ── Load entry being edited (if requested) ────────────────────────────────
$editing_entry = null;
if (($_GET['action'] ?? '') === 'edit' && isset($_GET['id'])) {
  $edit_id = (int)$_GET['id'];
  $stmt    = $pdo->prepare("SELECT * FROM time_entries WHERE id = ?");
  $stmt->execute([$edit_id]);
  $editing_entry = $stmt->fetch() ?: null;
}

// ── Top-level summary stats ───────────────────────────────────────────────
$stmt = $pdo->prepare("
  SELECT COALESCE(SUM(
    CASE
      WHEN hours_override IS NOT NULL THEN hours_override
      WHEN clock_out IS NOT NULL
        THEN ROUND(TIMESTAMPDIFF(SECOND, clock_in, clock_out) / 3600, 2)
      ELSE 0
    END
  ), 0)
  FROM time_entries
  WHERE DATE(clock_in) >= ?
");
$stmt->execute([$week_start]);
$week_hours = (float)$stmt->fetchColumn();

$month_start = (new DateTime('first day of this month', $tz))->format('Y-m-d');
$stmt2 = $pdo->prepare("
  SELECT COALESCE(SUM(
    CASE
      WHEN hours_override IS NOT NULL THEN hours_override
      WHEN clock_out IS NOT NULL
        THEN ROUND(TIMESTAMPDIFF(SECOND, clock_in, clock_out) / 3600, 2)
      ELSE 0
    END
  ), 0)
  FROM time_entries
  WHERE DATE(clock_in) >= ?
");
$stmt2->execute([$month_start]);
$month_hours = (float)$stmt2->fetchColumn();

$active_employees = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM time_entries")->fetchColumn();
$active_projects  = (int)$pdo->query("SELECT COUNT(DISTINCT project_id) FROM time_entries WHERE project_id IS NOT NULL")->fetchColumn();

// ── Dropdowns ─────────────────────────────────────────────────────────────
$all_users    = $pdo->query("SELECT id, username FROM users ORDER BY username ASC")->fetchAll();
$all_projects = $pdo->query("SELECT id, name FROM projects WHERE playbook = 0 ORDER BY name ASC")->fetchAll();

render_header('Time Reports');
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Time Reports</h1>
    <span class="muted">Admin Dashboard</span>
  </div>
  <p class="muted">Analyze employee hours, filter by date range, user, or project, and export to CSV.</p>
</div>

<!-- ── Summary stat cards ─────────────────────────────────────────────── -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-value"><?= number_format($week_hours, 1) ?></div>
    <div class="stat-label">Hours This Week</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= number_format($month_hours, 1) ?></div>
    <div class="stat-label">Hours This Month</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $active_employees ?></div>
    <div class="stat-label">Employees Tracked</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $active_projects ?></div>
    <div class="stat-label">Projects Tracked</div>
  </div>
</div>

<!-- ── Filters ────────────────────────────────────────────────────────── -->
<div class="card">
  <h2 style="margin-top:0;">Filters</h2>
  <form method="get" style="max-width:720px;">
    <div class="form-grid">
      <div>
        <label>From Date</label>
        <input type="date" name="filter_from" value="<?= h($f_from) ?>" />
      </div>
      <div>
        <label>To Date</label>
        <input type="date" name="filter_to" value="<?= h($f_to) ?>" />
      </div>
      <div>
        <label>Employee</label>
        <select name="filter_user">
          <option value="">— All Employees —</option>
          <?php foreach ($all_users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $f_user === (int)$u['id'] ? 'selected' : '' ?>>
              <?= h($u['username']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Project</label>
        <select name="filter_project">
          <option value="">— All Projects —</option>
          <?php foreach ($all_projects as $pr): ?>
            <option value="<?= (int)$pr['id'] ?>" <?= $f_project === (int)$pr['id'] ? 'selected' : '' ?>>
              <?= h($pr['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="full">
        <div class="row" style="margin-top:6px; align-items:center;">
          <button type="submit" class="btn primary">Apply Filters</button>
          <a class="btn" href="time_report.php">Reset</a>
        </div>
      </div>
    </div>
  </form>
</div>

<!-- ── Per-employee summary ────────────────────────────────────────────── -->
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h2 style="margin:0;">Hours by Employee</h2>
    <span class="muted"><?= count($by_employee) ?> employee(s)</span>
  </div>
  <div class="table-wrap" style="margin-top:10px;">
    <table class="table-auto">
      <thead>
        <tr>
          <th>Employee</th>
          <th>Total Hours</th>
          <th>Entries</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$by_employee): ?>
          <tr><td colspan="3" class="muted">No data for this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($by_employee as $emp): ?>
          <tr>
            <td><strong><?= h($emp['username']) ?></strong></td>
            <td><?= number_format($emp['total_hours'], 2) ?>h</td>
            <td><?= (int)$emp['entries'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Per-project summary ────────────────────────────────────────────── -->
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h2 style="margin:0;">Hours by Project</h2>
    <span class="muted"><?= count($by_project) ?> project(s)</span>
  </div>
  <div class="table-wrap" style="margin-top:10px;">
    <table class="table-auto">
      <thead>
        <tr>
          <th>Project</th>
          <th>Total Hours</th>
          <th>Contributors</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$by_project): ?>
          <tr><td colspan="3" class="muted">No data for this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($by_project as $proj): ?>
          <tr>
            <td><strong><?= h($proj['project_name']) ?></strong></td>
            <td><?= number_format($proj['total_hours'], 2) ?>h</td>
            <td><?= h(implode(', ', array_keys($proj['employees']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Edit entry form ────────────────────────────────────────────────── -->
<?php if ($editing_entry): ?>
  <?php
    $e_ci_fmt = substr($editing_entry['clock_in'], 0, 16);                  // YYYY-MM-DDTHH:MM (drop seconds)
    $e_ci_fmt = str_replace(' ', 'T', $e_ci_fmt);
    $e_co_fmt = $editing_entry['clock_out']
      ? str_replace(' ', 'T', substr($editing_entry['clock_out'], 0, 16))
      : '';
  ?>
  <div class="card" style="border-color:#93c5fd;">
    <div class="row" style="justify-content:space-between; align-items:center; margin-bottom:10px;">
      <h2 style="margin:0;">Edit Time Entry #<?= (int)$editing_entry['id'] ?></h2>
      <a class="btn" href="time_report.php?<?= h(http_build_query(array_filter([
        'filter_from'    => $f_from,
        'filter_to'      => $f_to,
        'filter_user'    => (string)($f_user ?? ''),
        'filter_project' => (string)($f_project ?? ''),
      ], fn($v) => $v !== '' && $v !== '0'))) ?>">Cancel</a>
    </div>

    <?php if (!empty($save_errors)): ?>
      <div class="alert error" style="margin-bottom:10px;">
        <strong>Fix these:</strong>
        <ul><?php foreach ($save_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="save_entry"     value="1">
      <input type="hidden" name="entry_id"       value="<?= (int)$editing_entry['id'] ?>">
      <input type="hidden" name="filter_from"    value="<?= h($f_from) ?>">
      <input type="hidden" name="filter_to"      value="<?= h($f_to) ?>">
      <input type="hidden" name="filter_user"    value="<?= (int)($f_user ?? 0) ?>">
      <input type="hidden" name="filter_project" value="<?= (int)($f_project ?? 0) ?>">

      <div class="form-grid">
        <div>
          <label>Employee</label>
          <select name="user_id">
            <?php foreach ($all_users as $u): ?>
              <option value="<?= (int)$u['id'] ?>"
                <?= (int)$editing_entry['user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                <?= h($u['username']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Project</label>
          <select name="project_id_entry">
            <option value="">— No project —</option>
            <?php foreach ($all_projects as $pr): ?>
              <option value="<?= (int)$pr['id'] ?>"
                <?= (int)($editing_entry['project_id'] ?? 0) === (int)$pr['id'] ? 'selected' : '' ?>>
                <?= h($pr['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Clock In</label>
          <input type="datetime-local" name="clock_in" value="<?= h($e_ci_fmt) ?>" required>
        </div>

        <div>
          <label>Clock Out <span class="muted">(leave blank if still open)</span></label>
          <input type="datetime-local" name="clock_out" value="<?= h($e_co_fmt) ?>">
        </div>

        <div>
          <label>Hours Override <span class="muted">(leave blank to use clock times)</span></label>
          <input type="number" step="0.01" min="0" name="hours_override"
                 value="<?= $editing_entry['hours_override'] !== null ? h((string)$editing_entry['hours_override']) : '' ?>">
        </div>

        <div class="full">
          <label>Description</label>
          <textarea name="description" rows="3"><?= h($editing_entry['description'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="row" style="margin-top:12px;">
        <button class="btn primary" type="submit">Save Changes</button>
        <a class="btn" href="time_report.php?<?= h(http_build_query(array_filter([
          'filter_from'    => $f_from,
          'filter_to'      => $f_to,
          'filter_user'    => (string)($f_user ?? ''),
          'filter_project' => (string)($f_project ?? ''),
        ], fn($v) => $v !== '' && $v !== '0'))) ?>">Cancel</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<!-- ── Detailed drill-down ────────────────────────────────────────────── -->
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
    <h2 style="margin:0;">Detailed Entries</h2>

    <!-- CSV Export -->
    <form method="post">
      <input type="hidden" name="export_csv"     value="1">
      <input type="hidden" name="filter_from"    value="<?= h($f_from) ?>">
      <input type="hidden" name="filter_to"      value="<?= h($f_to) ?>">
      <input type="hidden" name="filter_user"    value="<?= (int)($f_user ?? 0) ?>">
      <input type="hidden" name="filter_project" value="<?= (int)($f_project ?? 0) ?>">
      <button type="submit" class="btn">⬇ Export CSV</button>
    </form>
  </div>

  <p class="muted" style="margin-top:6px;"><?= count($detail_rows) ?> entr<?= count($detail_rows) === 1 ? 'y' : 'ies' ?> found.</p>

  <div class="table-wrap">
    <table class="table-auto">
      <thead>
        <tr>
          <th>Employee</th>
          <th>Project</th>
          <th>Date</th>
          <th>Clock In</th>
          <th>Clock Out</th>
          <th>Hours</th>
          <th class="col-desc">Description</th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$detail_rows): ?>
          <tr><td colspan="8" class="muted">No entries match the current filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($detail_rows as $r): ?>
          <?php
            $ci = new DateTime($r['clock_in'], $tz);
            $co = $r['clock_out'] ? new DateTime($r['clock_out'], $tz) : null;
            $edit_href = 'time_report.php?' . http_build_query(array_filter([
              'action'         => 'edit',
              'id'             => $r['id'],
              'filter_from'    => $f_from,
              'filter_to'      => $f_to,
              'filter_user'    => (string)($f_user ?? ''),
              'filter_project' => (string)($f_project ?? ''),
            ], fn($v) => $v !== '' && $v !== '0'));
          ?>
          <tr>
            <td><strong><?= h($r['username']) ?></strong></td>
            <td><?= $r['project_name'] ? h($r['project_name']) : '<span class="muted">—</span>' ?></td>
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
              <?= $r['hours'] !== null
                ? number_format((float)$r['hours'], 2) . 'h'
                : '<span class="muted">—</span>' ?>
            </td>
            <td class="col-desc"><?= $r['description'] ? h($r['description']) : '<span class="muted">—</span>' ?></td>
            <td class="col-actions">
              <div class="actions">
                <a class="btn" href="<?= h($edit_href) ?>">Edit</a>
                <form method="post" style="margin:0;"
                      onsubmit="return confirm('Delete this time entry? This cannot be undone.');">
                  <input type="hidden" name="delete_entry"    value="1">
                  <input type="hidden" name="entry_id"        value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="filter_from"     value="<?= h($f_from) ?>">
                  <input type="hidden" name="filter_to"       value="<?= h($f_to) ?>">
                  <input type="hidden" name="filter_user"     value="<?= (int)($f_user ?? 0) ?>">
                  <input type="hidden" name="filter_project"  value="<?= (int)($f_project ?? 0) ?>">
                  <button class="btn danger" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
