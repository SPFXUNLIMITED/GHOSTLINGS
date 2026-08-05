<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';

require_login();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['standalone_tasks_csrf'])) {
  $_SESSION['standalone_tasks_csrf'] = bin2hex(random_bytes(24));
}
$standalone_tasks_csrf = (string)$_SESSION['standalone_tasks_csrf'];

$status_options = [
  'pending' => 'Pending',
  'in-progress' => 'In Progress',
  'completed' => 'Completed',
];

$priority_options = [
  'high' => 'High',
  'medium' => 'Medium',
  'low' => 'Low',
];

$today = date('Y-m-d');
$filter = (string)($_GET['filter'] ?? 'all');
$allowed_filters = ['all', 'today'];
if (!in_array($filter, $allowed_filters, true)) {
  $filter = 'all';
}

if ($filter === 'today') {
  $stmt = $pdo->prepare("SELECT * FROM standalone_tasks WHERE due_date = ? ORDER BY sort_order ASC, id ASC");
  $stmt->execute([$today]);
} else {
  $stmt = $pdo->query("SELECT * FROM standalone_tasks ORDER BY sort_order ASC, id ASC");
}
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

function standalone_tasks_format_datetime(?string $value): string {
  if (!$value) {
    return '—';
  }
  try {
    return (new DateTime($value))->format('m/d/Y g:i A');
  } catch (Exception $e) {
    return $value;
  }
}

function standalone_tasks_truncate(string $value, int $limit = 100): string {
  $normalized = trim(preg_replace('/\s+/', ' ', $value) ?? '');
  if ($normalized === '') {
    return '—';
  }
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    return mb_strlen($normalized) > $limit ? mb_substr($normalized, 0, $limit) . '…' : $normalized;
  }
  return strlen($normalized) > $limit ? substr($normalized, 0, $limit) . '…' : $normalized;
}

render_header('Tasks');
?>
<div class="card standalone-tasks-page">
  <div class="row standalone-tasks-toolbar" style="justify-content:space-between; align-items:center; gap:16px;">
    <div>
      <h1 style="margin:0;">Tasks</h1>
      <div class="muted">Standalone task manager with quick modal CRUD actions.</div>
    </div>
    <div class="actions standalone-tasks-actions">
      <a class="btn <?= $filter === 'all' ? 'primary' : '' ?>" href="standalone_tasks.php">All</a>
      <a class="btn <?= $filter === 'today' ? 'primary' : '' ?>" href="standalone_tasks.php?filter=today">Today</a>
      <button type="button" class="btn primary" id="standalone-task-add-btn">Add Task</button>
    </div>
  </div>
</div>

<div class="card standalone-tasks-card">
  <div class="standalone-tasks-table-wrap">
    <table class="standalone-tasks-table">
      <thead>
        <tr>
          <th style="width:44px;">Move</th>
          <th>Description</th>
          <th>Status</th>
          <th>Priority</th>
          <th>Due Date</th>
          <th>Timestamps</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="standalone-task-list">
        <?php if (!$tasks): ?>
          <tr><td colspan="7" class="muted">No tasks found.</td></tr>
        <?php endif; ?>
        <?php foreach ($tasks as $task): ?>
          <?php
            $description = (string)($task['description'] ?? '');
            $plain_description = trim(preg_replace('/\s+/', ' ', strip_tags($description)) ?? '');
            $due_date = (string)($task['due_date'] ?? '');
            $is_overdue = $due_date !== '' && $due_date < $today && (string)($task['status'] ?? '') !== 'completed';
          ?>
          <tr
            class="standalone-task-row<?= $is_overdue ? ' is-overdue' : '' ?>"
            data-task-id="<?= (int)$task['id'] ?>"
            data-description="<?= h($description) ?>"
            data-status="<?= h((string)$task['status']) ?>"
            data-priority="<?= h((string)$task['priority']) ?>"
            data-due-date="<?= h($due_date) ?>"
            data-created-at="<?= h((string)($task['created_at'] ?? '')) ?>"
            data-updated-at="<?= h((string)($task['updated_at'] ?? '')) ?>"
          >
            <td>
              <button type="button" class="standalone-drag-handle" aria-label="Drag to reorder">↕</button>
            </td>
            <td>
              <div class="standalone-task-description"><?= h(standalone_tasks_truncate($plain_description, 100)) ?></div>
              <?php if ($is_overdue): ?>
                <div class="standalone-task-overdue">Overdue</div>
              <?php endif; ?>
            </td>
            <td><span class="badge standalone-status-badge status-<?= h((string)$task['status']) ?>"><?= h($status_options[$task['status']] ?? ucfirst((string)$task['status'])) ?></span></td>
            <td><span class="badge standalone-priority-badge priority-<?= h((string)$task['priority']) ?>"><?= h($priority_options[$task['priority']] ?? ucfirst((string)$task['priority'])) ?></span></td>
            <td><?= $due_date !== '' ? h(fmt_date_mdY($due_date)) : '<span class="muted">—</span>' ?></td>
            <td>
              <div class="standalone-task-timestamp"><strong>Created:</strong> <?= h(standalone_tasks_format_datetime($task['created_at'] ?? null)) ?></div>
              <div class="standalone-task-timestamp"><strong>Updated:</strong> <?= h(standalone_tasks_format_datetime($task['updated_at'] ?? null)) ?></div>
            </td>
            <td>
              <div class="actions standalone-task-row-actions">
                <button type="button" class="btn standalone-task-view-btn">View</button>
                <button type="button" class="btn standalone-task-edit-btn">Edit</button>
                <form method="post" action="standalone_task_delete.php" class="standalone-task-inline-form" onsubmit="return confirm('Delete this task?');">
                  <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                  <input type="hidden" name="filter" value="<?= h($filter) ?>">
                  <input type="hidden" name="csrf_token" value="<?= h($standalone_tasks_csrf) ?>">
                  <button type="submit" class="btn danger">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="standalone-task-modal" class="standalone-task-modal" role="dialog" aria-modal="true" aria-labelledby="standalone-task-modal-title" aria-hidden="true">
  <div class="standalone-task-modal-backdrop" id="standalone-task-modal-backdrop"></div>
  <div class="standalone-task-modal-shell">
    <div class="standalone-task-modal-header">
      <h2 id="standalone-task-modal-title" class="standalone-task-modal-title">Add Task</h2>
      <button type="button" class="standalone-task-modal-close" id="standalone-task-modal-close" aria-label="Close">&times;</button>
    </div>
    <form method="post" action="standalone_task_save.php" id="standalone-task-form">
      <div class="standalone-task-modal-body">
        <input type="hidden" name="id" id="standalone-task-id" value="">
        <input type="hidden" name="filter" value="<?= h($filter) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($standalone_tasks_csrf) ?>">
        <div class="form-grid">
          <div class="full">
            <label for="standalone-task-description-input">Description</label>
            <textarea id="standalone-task-description-input" name="description" rows="8" required></textarea>
            <div class="muted" style="margin-top:6px;">Supports multi-paragraph notes for speech-to-text input.</div>
          </div>
          <div>
            <label for="standalone-task-status-input">Status</label>
            <select id="standalone-task-status-input" name="status" required>
              <?php foreach ($status_options as $value => $label): ?>
                <option value="<?= h($value) ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="standalone-task-priority-input">Priority</label>
            <select id="standalone-task-priority-input" name="priority" required>
              <?php foreach ($priority_options as $value => $label): ?>
                <option value="<?= h($value) ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="standalone-task-due-date-input">Due Date</label>
            <input id="standalone-task-due-date-input" type="date" name="due_date" value="">
          </div>
          <div class="full standalone-task-readonly" id="standalone-task-readonly-fields" hidden>
            <div><strong>Created:</strong> <span id="standalone-task-created-at">—</span></div>
            <div><strong>Updated:</strong> <span id="standalone-task-updated-at">—</span></div>
          </div>
        </div>
      </div>
      <div class="standalone-task-modal-footer">
        <button type="button" class="btn" id="standalone-task-modal-cancel">Cancel</button>
        <button type="submit" class="btn primary" id="standalone-task-modal-submit">Save Task</button>
      </div>
    </form>
  </div>
</div>

<style>
.standalone-tasks-toolbar .btn.primary { box-shadow:none; }
.standalone-tasks-card { overflow:hidden; }
.standalone-tasks-table-wrap { overflow-x:auto; }
.standalone-tasks-table { width:100%; border-collapse:separate; border-spacing:0; }
.standalone-tasks-table th,
.standalone-tasks-table td { vertical-align:top; }
.standalone-task-row.is-overdue { background:linear-gradient(90deg, rgba(254,242,242,.92), rgba(255,255,255,1)); }
.standalone-task-row.dragging { opacity:.55; }
.standalone-task-description { max-width:420px; white-space:normal; color:#0f172a; }
.standalone-task-overdue { margin-top:6px; color:#b91c1c; font-size:12px; font-weight:700; letter-spacing:.02em; text-transform:uppercase; }
.standalone-status-badge.status-pending { background:#e0f2fe; color:#075985; }
.standalone-status-badge.status-in-progress { background:#fef3c7; color:#92400e; }
.standalone-status-badge.status-completed { background:#dcfce7; color:#166534; }
.standalone-priority-badge.priority-high { background:#fee2e2; color:#991b1b; }
.standalone-priority-badge.priority-medium { background:#e0e7ff; color:#3730a3; }
.standalone-priority-badge.priority-low { background:#ecfccb; color:#3f6212; }
.standalone-task-row-actions { flex-wrap:wrap; gap:8px; }
.standalone-task-inline-form { margin:0; }
.standalone-drag-handle {
  border:1px solid #cbd5e1;
  background:#f8fafc;
  color:#475569;
  width:34px;
  height:34px;
  border-radius:10px;
  cursor:grab;
  font-size:16px;
}
.standalone-drag-handle:active { cursor:grabbing; }
.standalone-task-timestamp { font-size:12px; color:#64748b; margin-bottom:6px; }
.standalone-task-modal { position:fixed; inset:0; z-index:9500; display:none; }
.standalone-task-modal.open { display:block; }
.standalone-task-modal-backdrop {
  position:absolute; inset:0; background:rgba(15,23,42,.72);
  backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);
}
.standalone-task-modal-shell {
  position:absolute; top:50%; left:50%; transform:translate(-50%, -50%);
  width:min(760px, calc(100vw - 32px)); max-height:calc(100vh - 48px);
  display:flex; flex-direction:column; background:#fff; border-radius:18px;
  box-shadow:0 32px 80px rgba(0,0,0,.4), 0 0 0 1px rgba(15,23,42,.08); overflow:hidden;
}
.standalone-task-modal-header,
.standalone-task-modal-footer { padding:18px 24px; border-color:#e2e8f0; }
.standalone-task-modal-header { display:flex; align-items:center; gap:12px; border-bottom:1px solid #e2e8f0; }
.standalone-task-modal-title { margin:0; color:#0f172a; font-size:1.15em; font-weight:700; }
.standalone-task-modal-close {
  margin-left:auto; width:32px; height:32px; border:none; border-radius:999px;
  background:#f1f5f9; color:#64748b; font-size:20px; line-height:1; cursor:pointer;
}
.standalone-task-modal-body { padding:20px 24px; overflow-y:auto; }
.standalone-task-modal-footer { border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:8px; }
.standalone-task-readonly {
  background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px;
  color:#334155; display:grid; gap:8px;
}
@media (max-width: 720px) {
  .standalone-tasks-toolbar,
  .standalone-task-row-actions { flex-direction:column; align-items:stretch !important; }
  .standalone-task-modal-shell { width:min(100vw - 16px, 760px); }
}
</style>

<script>
(function () {
  'use strict';

  var modal = document.getElementById('standalone-task-modal');
  var backdrop = document.getElementById('standalone-task-modal-backdrop');
  var addBtn = document.getElementById('standalone-task-add-btn');
  var closeBtn = document.getElementById('standalone-task-modal-close');
  var cancelBtn = document.getElementById('standalone-task-modal-cancel');
  var form = document.getElementById('standalone-task-form');
  var title = document.getElementById('standalone-task-modal-title');
  var submitBtn = document.getElementById('standalone-task-modal-submit');
  var taskIdInput = document.getElementById('standalone-task-id');
  var descriptionInput = document.getElementById('standalone-task-description-input');
  var statusInput = document.getElementById('standalone-task-status-input');
  var priorityInput = document.getElementById('standalone-task-priority-input');
  var dueDateInput = document.getElementById('standalone-task-due-date-input');
  var readonlyFields = document.getElementById('standalone-task-readonly-fields');
  var createdAtValue = document.getElementById('standalone-task-created-at');
  var updatedAtValue = document.getElementById('standalone-task-updated-at');
  var list = document.getElementById('standalone-task-list');
  var dragSource = null;

  function openModal() {
    modal.classList.add('open');
    modal.removeAttribute('aria-hidden');
    descriptionInput.focus();
  }

  function closeModal() {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
  }

  function setReadonlyMode(isReadonly) {
    descriptionInput.readOnly = isReadonly;
    statusInput.disabled = isReadonly;
    priorityInput.disabled = isReadonly;
    dueDateInput.disabled = isReadonly;
    submitBtn.hidden = isReadonly;
    readonlyFields.hidden = false;
  }

  function populateFromRow(row) {
    taskIdInput.value = row.dataset.taskId || '';
    descriptionInput.value = row.dataset.description || '';
    statusInput.value = row.dataset.status || 'pending';
    priorityInput.value = row.dataset.priority || 'medium';
    dueDateInput.value = row.dataset.dueDate || '';
    createdAtValue.textContent = row.dataset.createdAt || '—';
    updatedAtValue.textContent = row.dataset.updatedAt || '—';
  }

  function resetForCreate() {
    form.reset();
    taskIdInput.value = '';
    title.textContent = 'Add Task';
    submitBtn.textContent = 'Save Task';
    createdAtValue.textContent = '—';
    updatedAtValue.textContent = '—';
    readonlyFields.hidden = true;
    descriptionInput.readOnly = false;
    statusInput.disabled = false;
    priorityInput.disabled = false;
    dueDateInput.disabled = false;
    submitBtn.hidden = false;
    statusInput.value = 'pending';
    priorityInput.value = 'medium';
  }

  addBtn.addEventListener('click', function () {
    resetForCreate();
    openModal();
  });

  document.addEventListener('click', function (event) {
    var row = event.target.closest('.standalone-task-row');
    if (!row) return;

    if (event.target.closest('.standalone-task-view-btn')) {
      populateFromRow(row);
      title.textContent = 'View Task';
      setReadonlyMode(true);
      openModal();
      return;
    }

    if (event.target.closest('.standalone-task-edit-btn')) {
      populateFromRow(row);
      title.textContent = 'Edit Task';
      submitBtn.textContent = 'Save Changes';
      readonlyFields.hidden = false;
      descriptionInput.readOnly = false;
      statusInput.disabled = false;
      priorityInput.disabled = false;
      dueDateInput.disabled = false;
      submitBtn.hidden = false;
      openModal();
    }
  });

  [closeBtn, cancelBtn, backdrop].forEach(function (element) {
    element.addEventListener('click', closeModal);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && modal.classList.contains('open')) {
      closeModal();
    }
  });

  if (!list) return;

  function syncOrder() {
    var ids = Array.from(list.querySelectorAll('.standalone-task-row')).map(function (row) {
      return row.dataset.taskId;
    }).filter(Boolean);

    if (!ids.length) return;

    var body = new URLSearchParams();
    ids.forEach(function (id) { body.append('task_ids[]', id); });
    body.append('filter', <?= json_encode($filter) ?>);
    body.append('csrf_token', <?= json_encode($standalone_tasks_csrf) ?>);

    fetch('standalone_task_reorder.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin'
    }).catch(function () {
      window.location.reload();
    });
  }

  Array.from(list.querySelectorAll('.standalone-task-row')).forEach(function (row) {
    row.draggable = true;

    row.addEventListener('dragstart', function (event) {
      dragSource = row;
      row.classList.add('dragging');
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.dataset.taskId || '');
      }
    });

    row.addEventListener('dragend', function () {
      row.classList.remove('dragging');
      dragSource = null;
    });

    row.addEventListener('dragover', function (event) {
      event.preventDefault();
      if (!dragSource || dragSource === row) return;
      var rect = row.getBoundingClientRect();
      var shouldInsertAfter = (event.clientY - rect.top) > (rect.height / 2);
      if (shouldInsertAfter) {
        row.parentNode.insertBefore(dragSource, row.nextSibling);
      } else {
        row.parentNode.insertBefore(dragSource, row);
      }
    });

    row.addEventListener('drop', function (event) {
      event.preventDefault();
      syncOrder();
    });
  });
})();
</script>
<?php render_footer(); ?>
