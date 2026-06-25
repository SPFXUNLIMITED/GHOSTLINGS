<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

const AGENDA_MAX_TITLE_LENGTH = 180;
const AGENDA_MAX_NOTES_LENGTH = 5000;

function agenda_is_valid_date(string $value): bool {
  if ($value === '') return false;
  $dt = DateTime::createFromFormat('Y-m-d', $value);
  return $dt instanceof DateTime && $dt->format('Y-m-d') === $value;
}

function agenda_is_valid_time(string $value): bool {
  if (!preg_match('/^\d{2}:\d{2}$/', $value)) return false;
  $dt = DateTime::createFromFormat('H:i', $value);
  return $dt instanceof DateTime && $dt->format('H:i') === $value;
}

function agenda_time_display(string $value): string {
  $value = trim($value);
  if ($value === '') return '';
  $dt = DateTime::createFromFormat('H:i:s', $value) ?: DateTime::createFromFormat('H:i', $value);
  return $dt instanceof DateTime ? $dt->format('g:i A') : $value;
}

function agenda_is_valid_item_id(string $value): bool {
  return $value !== '' && ctype_digit($value) && (int)$value > 0;
}

$agenda_errors = [];
$agenda_success = '';

if (empty($_SESSION['agenda_csrf'])) {
  $_SESSION['agenda_csrf'] = bin2hex(random_bytes(24));
}

try {
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS agenda_items (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      agenda_date DATE NOT NULL,
      agenda_time TIME NOT NULL,
      title VARCHAR(180) NOT NULL,
      notes TEXT NULL,
      created_by INT UNSIGNED NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_agenda_items_date (agenda_date),
      KEY idx_agenda_items_datetime (agenda_date, agenda_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  );
} catch (Throwable $e) {
  error_log('agenda table init error: ' . $e->getMessage());
  $agenda_errors[] = 'Agenda storage is temporarily unavailable.';
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$selected_date = trim((string)($_GET['date'] ?? $today));
if (!agenda_is_valid_date($selected_date)) {
  $selected_date = $today;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['agenda_csrf'], $csrf)) {
    $agenda_errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $action = trim((string)($_POST['action'] ?? ''));
    $posted_selected_date = trim((string)($_POST['selected_date'] ?? ''));
    if (agenda_is_valid_date($posted_selected_date)) {
      $selected_date = $posted_selected_date;
    }

    if ($action === 'delete') {
      $item_id_raw = trim((string)($_POST['item_id'] ?? ''));
      if (!agenda_is_valid_item_id($item_id_raw)) {
        $agenda_errors[] = 'Invalid agenda item selected for deletion.';
      } else {
        try {
          $stmt = $pdo->prepare('DELETE FROM agenda_items WHERE id = ?');
          $stmt->execute([(int)$item_id_raw]);
          if ($stmt->rowCount() > 0) {
            $agenda_success = 'Agenda item deleted.';
          } else {
            $agenda_errors[] = 'Agenda item not found.';
          }
        } catch (Throwable $e) {
          error_log('agenda delete error: ' . $e->getMessage());
          $agenda_errors[] = 'Unable to delete agenda item right now.';
        }
      }
    } else {
      $item_id_raw = trim((string)($_POST['item_id'] ?? ''));
      $item_date = trim((string)($_POST['item_date'] ?? $selected_date));
      $item_time = trim((string)($_POST['item_time'] ?? ''));
      $title = trim((string)($_POST['title'] ?? ''));
      $notes = trim((string)($_POST['notes'] ?? ''));

      if (!agenda_is_valid_date($item_date)) {
        $agenda_errors[] = 'Select a valid agenda date.';
      }
      if (!agenda_is_valid_time($item_time)) {
        $agenda_errors[] = 'Select a valid time.';
      }
      if ($title === '') {
        $agenda_errors[] = 'Title is required.';
      } elseif (strlen($title) > AGENDA_MAX_TITLE_LENGTH) {
        $agenda_errors[] = 'Title must be ' . AGENDA_MAX_TITLE_LENGTH . ' characters or fewer.';
      }
      if (strlen($notes) > AGENDA_MAX_NOTES_LENGTH) {
        $agenda_errors[] = 'Notes must be ' . AGENDA_MAX_NOTES_LENGTH . ' characters or fewer.';
      }

      if (!$agenda_errors) {
        try {
          if ($action === 'edit') {
            if (!agenda_is_valid_item_id($item_id_raw)) {
              $agenda_errors[] = 'Invalid agenda item selected for edit.';
            } else {
              $stmt = $pdo->prepare(
                'UPDATE agenda_items
                 SET agenda_date = :agenda_date,
                     agenda_time = :agenda_time,
                     title = :title,
                     notes = :notes
                 WHERE id = :id'
              );
              $stmt->execute([
                ':agenda_date' => $item_date,
                ':agenda_time' => $item_time . ':00',
                ':title' => $title,
                ':notes' => $notes === '' ? null : $notes,
                ':id' => (int)$item_id_raw,
              ]);
              if ($stmt->rowCount() > 0) {
                $agenda_success = 'Agenda item updated.';
              } else {
                $agenda_success = 'No changes were made to this agenda item.';
              }
            }
          } else {
            $stmt = $pdo->prepare(
              'INSERT INTO agenda_items (agenda_date, agenda_time, title, notes, created_by)
               VALUES (:agenda_date, :agenda_time, :title, :notes, :created_by)'
            );
            $stmt->execute([
              ':agenda_date' => $item_date,
              ':agenda_time' => $item_time . ':00',
              ':title' => $title,
              ':notes' => $notes === '' ? null : $notes,
              ':created_by' => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            ]);
            $agenda_success = 'Agenda item added.';
          }
          $selected_date = $item_date;
        } catch (Throwable $e) {
          error_log('agenda save error: ' . $e->getMessage());
          $agenda_errors[] = 'Unable to save agenda item right now.';
        }
      }
    }
  }
  $_SESSION['agenda_csrf'] = bin2hex(random_bytes(24));
}

$selected_dt = DateTimeImmutable::createFromFormat('Y-m-d', $selected_date) ?: new DateTimeImmutable('today');
$month_start = $selected_dt->modify('first day of this month');
$month_end = $selected_dt->modify('last day of this month');
$month_label = $month_start->format('F Y');
$prev_month_date = $month_start->modify('-1 month')->format('Y-m-01');
$next_month_date = $month_start->modify('+1 month')->format('Y-m-01');

$month_counts = [];
$day_items = [];

try {
  $count_stmt = $pdo->prepare(
    'SELECT agenda_date, COUNT(*) AS item_count
     FROM agenda_items
     WHERE agenda_date BETWEEN ? AND ?
     GROUP BY agenda_date'
  );
  $count_stmt->execute([$month_start->format('Y-m-d'), $month_end->format('Y-m-d')]);
  foreach ($count_stmt->fetchAll() as $row) {
    $month_counts[(string)$row['agenda_date']] = (int)$row['item_count'];
  }

  $items_stmt = $pdo->prepare(
    'SELECT id, agenda_date, agenda_time, title, notes
     FROM agenda_items
     WHERE agenda_date = ?
     ORDER BY agenda_time ASC, id ASC'
  );
  $items_stmt->execute([$selected_date]);
  $day_items = $items_stmt->fetchAll();
} catch (Throwable $e) {
  error_log('agenda read error: ' . $e->getMessage());
  $agenda_errors[] = 'Unable to load agenda items right now.';
}

render_header('Agenda');
?>

<style>
  .agenda-layout { display:grid; grid-template-columns:minmax(320px,1fr) minmax(360px,1fr); gap:16px; }
  .agenda-calendar { padding:16px; }
  .agenda-calendar-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; gap:8px; }
  .agenda-calendar-grid { display:grid; grid-template-columns:repeat(7, minmax(0,1fr)); gap:8px; }
  .agenda-weekday { text-align:center; font-size:12px; font-weight:700; color:#64748b; padding:6px 0; }
  .agenda-day,
  .agenda-day-empty { min-height:72px; border-radius:10px; border:1px solid #e2e8f0; background:#fff; }
  .agenda-day-empty { background:#f8fafc; border-style:dashed; }
  .agenda-day { display:flex; flex-direction:column; gap:6px; text-decoration:none; padding:8px; color:#0f172a; }
  .agenda-day strong { font-size:14px; }
  .agenda-day .count { font-size:11px; color:#1d4ed8; font-weight:700; }
  .agenda-day:hover { border-color:#93c5fd; box-shadow:0 0 0 2px rgba(37,99,235,.12); }
  .agenda-day.active { border-color:#2563eb; background:#eff6ff; }
  .agenda-day.today strong { color:#2563eb; }
  .agenda-item { border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fff; display:grid; gap:8px; }
  .agenda-item-head { display:flex; justify-content:space-between; gap:8px; align-items:flex-start; }
  .agenda-item-time { font-weight:700; color:#0f172a; white-space:nowrap; }
  .agenda-item-title { margin:0; font-size:15px; }
  .agenda-actions { display:flex; gap:8px; flex-wrap:wrap; }
  .agenda-inline-form { display:grid; gap:8px; }
  .agenda-inline-form .row { display:grid; grid-template-columns:1fr 2fr; gap:8px; }
  @media (max-width: 980px) {
    .agenda-layout { grid-template-columns:1fr; }
    .agenda-inline-form .row { grid-template-columns:1fr; }
  }
</style>

<div class="card page-header">
  <div class="page-header-body">
    <h1 style="margin:0 0 4px;">Agenda</h1>
    <p class="muted" style="margin:0;">Quick daily agenda for technicians and office staff.</p>
  </div>
  <a class="btn" href="agenda.php?date=<?= h($today) ?>">Today</a>
</div>

<?php foreach ($agenda_errors as $agenda_error): ?>
  <div class="alert" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;"><?= h($agenda_error) ?></div>
<?php endforeach; ?>

<?php if ($agenda_success !== ''): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;"><?= h($agenda_success) ?></div>
<?php endif; ?>

<div class="agenda-layout">
  <div class="card agenda-calendar">
    <div class="agenda-calendar-header">
      <a class="btn" href="agenda.php?date=<?= h($prev_month_date) ?>">← Prev</a>
      <h2 style="margin:0; font-size:18px;"><?= h($month_label) ?></h2>
      <a class="btn" href="agenda.php?date=<?= h($next_month_date) ?>">Next →</a>
    </div>

    <div class="agenda-calendar-grid" style="margin-bottom:6px;">
      <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
        <div class="agenda-weekday"><?= h($weekday) ?></div>
      <?php endforeach; ?>
    </div>

    <div class="agenda-calendar-grid">
      <?php
      $leading_days = (int)$month_start->format('w');
      $month_year = (int)$month_start->format('Y');
      $month_number = (int)$month_start->format('m');
      for ($i = 0; $i < $leading_days; $i++):
      ?>
        <div class="agenda-day-empty" aria-hidden="true"></div>
      <?php endfor; ?>

      <?php
      $day_count = (int)$month_end->format('j');
      for ($day = 1; $day <= $day_count; $day++):
        $day_dt = $month_start->setDate($month_year, $month_number, $day);
        $day_ymd = $day_dt->format('Y-m-d');
        $is_active = $day_ymd === $selected_date;
        $is_today = $day_ymd === $today;
        $count = (int)($month_counts[$day_ymd] ?? 0);
      ?>
        <a
          href="agenda.php?date=<?= h($day_ymd) ?>"
          class="agenda-day<?= $is_active ? ' active' : '' ?><?= $is_today ? ' today' : '' ?>"
          aria-label="<?= h($day_ymd) ?>, <?= $count ?> agenda item<?= $count === 1 ? '' : 's' ?>"
        >
          <strong><?= (int)$day ?></strong>
          <?php if ($count > 0): ?>
            <span class="count"><?= (int)$count ?> item<?= $count === 1 ? '' : 's' ?></span>
          <?php endif; ?>
        </a>
      <?php endfor; ?>
    </div>
  </div>

  <div style="display:grid; gap:16px;">
    <div class="card">
      <h2 style="margin-top:0; margin-bottom:10px;">Agenda for <?= h($selected_dt->format('l, M j, Y')) ?></h2>

      <form method="post" style="display:grid; gap:10px;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['agenda_csrf']) ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="selected_date" value="<?= h($selected_date) ?>">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
          <label>
            Date
            <input type="date" name="item_date" value="<?= h($selected_date) ?>" required>
          </label>
          <label>
            Time
            <input type="time" name="item_time" required>
          </label>
        </div>

        <label>
          Title
          <input type="text" name="title" maxlength="<?= AGENDA_MAX_TITLE_LENGTH ?>" placeholder="Task title" required>
        </label>

        <label>
          Notes (optional)
          <textarea name="notes" rows="3" maxlength="<?= AGENDA_MAX_NOTES_LENGTH ?>" placeholder="Optional notes"></textarea>
        </label>

        <button type="submit" class="btn primary">+ Add Agenda Item</button>
      </form>
    </div>

    <div class="card" style="display:grid; gap:10px;">
      <?php if (!$day_items): ?>
        <p class="muted" style="margin:0;">No agenda items for this day yet.</p>
      <?php else: ?>
        <?php foreach ($day_items as $item): ?>
          <?php
            $row_id = (int)($item['id'] ?? 0);
            $row_date = (string)($item['agenda_date'] ?? '');
            $row_time_raw = (string)($item['agenda_time'] ?? '');
            $row_time_short = substr($row_time_raw, 0, 5);
            $row_title = (string)($item['title'] ?? '');
            $row_notes = (string)($item['notes'] ?? '');
          ?>
          <article class="agenda-item">
            <div class="agenda-item-head">
              <div>
                <div class="agenda-item-time"><?= h(agenda_time_display($row_time_raw)) ?></div>
                <h3 class="agenda-item-title"><?= h($row_title) ?></h3>
              </div>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['agenda_csrf']) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="item_id" value="<?= $row_id ?>">
                <input type="hidden" name="selected_date" value="<?= h($selected_date) ?>">
                <button type="submit" class="btn" style="color:#b91c1c;">Delete</button>
              </form>
            </div>

            <?php if ($row_notes !== ''): ?>
              <p style="margin:0; white-space:pre-wrap;"><?= h($row_notes) ?></p>
            <?php endif; ?>

            <details>
              <summary class="muted" style="cursor:pointer;">Edit</summary>
              <form method="post" class="agenda-inline-form" style="margin-top:8px;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['agenda_csrf']) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="item_id" value="<?= $row_id ?>">
                <input type="hidden" name="selected_date" value="<?= h($selected_date) ?>">

                <div class="row">
                  <label>
                    Date
                    <input type="date" name="item_date" value="<?= h($row_date) ?>" required>
                  </label>
                  <label>
                    Time
                    <input type="time" name="item_time" value="<?= h($row_time_short) ?>" required>
                  </label>
                </div>

                <label>
                  Title
                  <input type="text" name="title" maxlength="<?= AGENDA_MAX_TITLE_LENGTH ?>" value="<?= h($row_title) ?>" required>
                </label>

                <label>
                  Notes (optional)
                  <textarea name="notes" rows="3" maxlength="<?= AGENDA_MAX_NOTES_LENGTH ?>"><?= h($row_notes) ?></textarea>
                </label>

                <div class="agenda-actions">
                  <button type="submit" class="btn">Save Changes</button>
                </div>
              </form>
            </details>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php render_footer(); ?>
