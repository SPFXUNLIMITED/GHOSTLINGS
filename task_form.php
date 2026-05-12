<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require __DIR__ . '/auth.php';
require_login();

$project_id = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$project_id) { header('Location: projects.php'); exit; }

$all_users = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll();

$errors = [];
$task = ['project_id' => $project_id, 'title' => '', 'details' => '', 'status' => 'todo', 'due_date' => '', 'priority' => 'medium', 'assigned_to' => null];

if (!$id) {
  // Set default selected project for new task
  $task['project_id'] = $project_id;

  // Load the project for the header/back link
  $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ?");
  $stmt->execute([$project_id]);
  $project = $stmt->fetch();
  if (!$project) { http_response_code(404); exit('Project not found'); }
}

if ($id) {
  $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
  $stmt->execute([$id]);
  $task = $stmt->fetch();
  if (!$task) { http_response_code(404); exit('Task not found'); }

  $id = (int)$task['id'];
  $task['project_id'] = (int)$task['project_id'];
  $project_id = $task['project_id'];

  $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ?");
  $stmt->execute([$project_id]);
  $project = $stmt->fetch();

  if (!$project) { http_response_code(404); exit('Project not found'); }
}

// TASK SAVE: normal create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['add_comment']) && !isset($_POST['delete_comment'])) {

  $new_project_id = $project_id;

  $title = trim($_POST['title'] ?? '');
  // WYSIWYG HTML will come through here
  $details = trim($_POST['details'] ?? '');
  $status = $_POST['status'] ?? 'todo';
  $due_date = trim($_POST['due_date'] ?? ''); // must be YYYY-MM-DD for <input type="date">
  $priority = $_POST['priority'] ?? 'medium';
  if (!in_array($priority, ['low','medium','high','critical'], true)) $priority = 'medium';
  $assigned_to = isset($_POST['assigned_to']) && (int)$_POST['assigned_to'] > 0 ? (int)$_POST['assigned_to'] : null;

  if ($title === '') $errors[] = "Title is required.";
  if (!in_array($status, ['todo','doing','done'], true)) $errors[] = "Invalid status.";
  if ($due_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
    $errors[] = "Due date must be YYYY-MM-DD.";
  }

  if (!$errors) {
    $due = ($due_date === '') ? null : $due_date;
    if ($id) {
      $stmt = $pdo->prepare("UPDATE tasks SET project_id=?, title=?, details=?, status=?, due_date=?, priority=?, assigned_to=? WHERE id=?");
      $stmt->execute([$new_project_id, $title, $details ?: null, $status, $due, $priority, $assigned_to, $id]);
    } else {
      $stmt = $pdo->prepare("INSERT INTO tasks (project_id, title, details, status, due_date, priority, assigned_to) VALUES (?, ?, ?, ?, ?, ?, ?)");
      $stmt->execute([$new_project_id, $title, $details ?: null, $status, $due, $priority, $assigned_to]);
    }
    header("Location: project_details.php?id={$new_project_id}");
    exit;
  }

  $task['title'] = $title;
  $task['details'] = $details;
  $task['status'] = $status;
  $task['due_date'] = $due_date;
  $task['priority'] = $priority;
  $task['project_id'] = $new_project_id;
  $task['assigned_to'] = $assigned_to;

  $project_id = $new_project_id;
  $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ?");
  $stmt->execute([$project_id]);
  $project = $stmt->fetch();
  if (!$project) { $errors[] = "Selected project not found."; }
}

render_header($id ? 'Edit Task' : 'New Task');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div>
      <h1 style="margin:0;"><?= $id ? 'Edit Task' : 'New Task' ?></h1>
      <div class="muted">Project: <?= h($project['name']) ?></div>
    </div>
    <a class="btn" href="tasks.php?project_id=<?= (int)$project_id ?>">Back</a>
  </div>

  <?php if ($errors): ?>
    <div class="alert error">
      <strong>Fix these:</strong>
      <ul>
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post">
    <div class="form-grid">
      <div class="full">
        <label>Title</label>
        <input name="title" value="<?= h($task['title']) ?>" />
      </div>

      <div>
        <label>Status</label>
        <select name="status">
          <?php foreach (['todo','doing','done'] as $s): ?>
            <option value="<?= h($s) ?>" <?= ($task['status']===$s?'selected':'') ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Priority</label>
        <select name="priority">
          <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $val => $label): ?>
            <option value="<?= h($val) ?>" <?= (($task['priority'] ?? 'medium') === $val) ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Due date</label>
        <input
          type="date"
          name="due_date"
          value="<?= h($task['due_date'] ?? '') ?>"
          min="2000-01-01"
          max="2100-12-31"
        />
        <div class="muted" style="margin-top:6px;">
          Display format: <?= h(fmt_date_mdY($task['due_date'] ?? '')) ?>
        </div>
      </div>

      <div>
        <label>Assign to</label>
        <select name="assigned_to">
          <option value="">— Unassigned —</option>
          <?php foreach ($all_users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= ((int)($task['assigned_to'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>>
              <?= h($u['username']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="full">
        <label>Details</label>
        <textarea id="details_editor" name="details" rows="6"><?= h($task['details'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="row" style="margin-top:12px;">
      <button class="btn primary" type="submit"><?= $id ? 'Save Changes' : 'Create Task' ?></button>
      <a class="btn" href="tasks.php?project_id=<?= (int)$project_id ?>">Cancel</a>
    </div>

    <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
  </form>

</div>

<!-- TinyMCE WYSIWYG -->
<script src="https://cdn.tiny.cloud/1/pifs5sjkqqgawy88jx7d10zx5sxezi2ig67u4ci0exbu6hag/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
  tinymce.init({
    selector: '#details_editor',
    height: 280,
    menubar: false,
    plugins: 'lists link table code',
    toolbar: 'undo redo | bold italic underline | bullist numlist | link table | removeformat | code',
    branding: false
  });
</script>

<?php render_footer(); ?>