<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require __DIR__ . '/auth.php';
require_login();

$project_id = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$project_id) { header('Location: playbooks.php'); exit; }

// Only allow playbook projects
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ? AND playbook = 1");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); exit('Playbook not found'); }

// Only list playbook projects in the selector
$stmt = $pdo->query("SELECT id, name FROM projects WHERE playbook = 1 ORDER BY name");
$all_projects = $stmt->fetchAll();
if (!$all_projects) { http_response_code(500); exit('No playbooks exist'); }

$all_users = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll();

$errors = [];
$task = ['project_id' => $project_id, 'title' => '', 'details' => '', 'status' => 'todo', 'due_date' => '', 'assigned_to' => null];

if ($id) {
  $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
  $stmt->execute([$id]);
  $task = $stmt->fetch();
  if (!$task) { http_response_code(404); exit('Task not found'); }

  $id = (int)$task['id'];
  $task['project_id'] = (int)$task['project_id'];
  $project_id = (int)$task['project_id'];

  // Ensure the task belongs to a playbook project
  $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ? AND playbook = 1");
  $stmt->execute([$project_id]);
  $project = $stmt->fetch();
  if (!$project) { http_response_code(404); exit('Playbook not found'); }
}

// Delete comment (edit mode only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment'])) {
  if (!$id) {
    $errors[] = "Invalid task.";
  } else {
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    if ($comment_id <= 0) {
      $errors[] = "Invalid comment.";
    } else {
      // Ensure the comment belongs to this task
      $stmt = $pdo->prepare("DELETE FROM task_comments WHERE id = ? AND task_id = ?");
      $stmt->execute([$comment_id, $id]);

      $pid = (int)($task['project_id'] ?? $project_id);
      header("Location: playbook_task_form.php?project_id={$pid}&id={$id}");
      exit;
    }
  }
}

// COMMENTS: handle add-comment first (edit mode only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
  if (!$id) {
    $errors[] = "Save the task before adding comments.";
  } else {
    $comment = trim($_POST['comment_body'] ?? '');
    if ($comment === '') {
      $errors[] = "Comment cannot be empty.";
    } else {
      $stmt = $pdo->prepare("INSERT INTO task_comments (task_id, body) VALUES (?, ?)");
      $stmt->execute([$id, $comment]);

      $pid = (int)($task['project_id'] ?? $project_id);
      header("Location: playbook_task_form.php?project_id={$pid}&id={$id}");
      exit;
    }
  }
}

// TASK SAVE: normal create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['add_comment']) && !isset($_POST['delete_comment'])) {

  $new_project_id = (int)($_POST['project_id'] ?? $project_id);
  if ($new_project_id <= 0) $errors[] = "Playbook is required.";

  // Ensure selected project is a playbook
  if (!$errors) {
    $chk = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND playbook = 1");
    $chk->execute([$new_project_id]);
    if (!$chk->fetch()) $errors[] = "Selected playbook not found.";
  }

  $title = trim($_POST['title'] ?? '');
  $details = trim($_POST['details'] ?? '');
  $status = $_POST['status'] ?? 'todo';
  $due_date = trim($_POST['due_date'] ?? ''); // must be YYYY-MM-DD for <input type="date">
  $assigned_to = isset($_POST['assigned_to']) && (int)$_POST['assigned_to'] > 0 ? (int)$_POST['assigned_to'] : null;

  if ($title === '') $errors[] = "Title is required.";
  if (!in_array($status, ['todo','doing','done'], true)) $errors[] = "Invalid status.";
  if ($due_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
    $errors[] = "Due date must be YYYY-MM-DD.";
  }

  if (!$errors) {
    $due = ($due_date === '') ? null : $due_date;

    if ($id) {
      $stmt = $pdo->prepare("UPDATE tasks SET project_id=?, title=?, details=?, status=?, due_date=?, assigned_to=? WHERE id=?");
      $stmt->execute([$new_project_id, $title, $details ?: null, $status, $due, $assigned_to, $id]);
    } else {
      $stmt = $pdo->prepare("INSERT INTO tasks (project_id, title, details, status, due_date, assigned_to) VALUES (?, ?, ?, ?, ?, ?)");
      $stmt->execute([$new_project_id, $title, $details ?: null, $status, $due, $assigned_to]);
    }

    header("Location: playbook_tasks.php?project_id={$new_project_id}");
    exit;
  }

  $task['title'] = $title;
  $task['details'] = $details;
  $task['status'] = $status;
  $task['due_date'] = $due_date;
  $task['project_id'] = $new_project_id;
  $task['assigned_to'] = $assigned_to;

  $project_id = $new_project_id;

  $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ? AND playbook = 1");
  $stmt->execute([$project_id]);
  $project = $stmt->fetch();
  if (!$project) { $errors[] = "Selected playbook not found."; }
}

// Load comments for display (edit mode only)
$comments = [];
if ($id) {
  $stmt = $pdo->prepare("SELECT id, body, created_at FROM task_comments WHERE task_id = ? ORDER BY id DESC");
  $stmt->execute([$id]);
  $comments = $stmt->fetchAll();
}

render_header($id ? 'Edit Task' : 'New Task');
?>

<!-- ========================= -->
<!-- PAGE: PLAYBOOK TASK FORM  -->
<!-- ========================= -->

<div class="card">

  <!-- Header row: title + playbook name + back link -->
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div>
      <!-- Page title -->
      <h1 style="margin:0;"><?= $id ? 'Edit Task' : 'New Task' ?></h1>

      <!-- Context: which playbook this task belongs to -->
      <div class="muted">Playbook: <?= h($project['name']) ?></div>
    </div>

    <!-- Back to playbook tasks list -->
    <a class="btn" href="playbook_tasks.php?project_id=<?= (int)$project_id ?>">Back</a>
  </div>

  <!-- Validation errors -->
  <?php if ($errors): ?>
    <!-- Error box -->
    <div class="alert error">
      <strong>Fix these:</strong>
      <ul>
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- ================= -->
  <!-- TASK CREATE/EDIT  -->
  <!-- ================= -->

  <form method="post">

    <!-- Form grid: title, playbook, status, due date, details -->
    <div class="form-grid">

      <!-- Task Title -->
      <div class="full">
        <label>Title</label>
        <input name="title" value="<?= h($task['title']) ?>" />
      </div>

      <!-- Playbook selector (playbook projects only) -->
      <div>
        <label>Playbook</label>
        <select name="project_id">
          <?php foreach ($all_projects as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ((int)$task['project_id'] === (int)$p['id']) ? 'selected' : '' ?>>
              <?= h($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Status selector -->
      <div>
        <label>Status</label>
        <select name="status">
          <?php foreach (['todo','doing','done'] as $s): ?>
            <option value="<?= h($s) ?>" <?= ($task['status']===$s?'selected':'') ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Due date selector -->
      <div>
        <label>Due date</label>

        <input
          type="date"
          name="due_date"
          value="<?= h($task['due_date'] ?? '') ?>"
          min="2000-01-01"
          max="2100-12-31"
        />

        <!-- Helper text showing how the due date is displayed elsewhere -->
        <div class="muted" style="margin-top:6px;">
          Display format: <?= h(fmt_date_mdY($task['due_date'] ?? '')) ?>
        </div>
      </div>

      <!-- Assign to user selector -->
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

      <!-- Details WYSIWYG editor -->
      <div class="full">
        <label>Details</label>
        <textarea id="details_editor" name="details" rows="6"><?= h($task['details'] ?? '') ?></textarea>
      </div>
    </div>

    <!-- Form actions -->
    <div class="row" style="margin-top:12px;">
      <button class="btn primary" type="submit"><?= $id ? 'Save Changes' : 'Create Task' ?></button>
      <a class="btn" href="playbook_tasks.php?project_id=<?= (int)$project_id ?>">Cancel</a>
    </div>

    <!-- Keep task id on postback (edit mode) -->
    <input type="hidden" name="id" value="<?= (int)$id ?>">
  </form>

  <!-- ================= -->
  <!-- COMMENTS SECTION  -->
  <!-- ================= -->

  <!-- START COMMENTS -->
  <?php if ($id): ?>

    <!-- Divider before comments -->
    <hr style="border:none; border-top:1px solid #e5e7eb; margin:40px 0 0;" />

    <!-- Comments title -->
    <h2 style="margin:0 0 10px;">Comments</h2>

    <!-- Existing comments list -->
    <div class="card" style="padding:12px; border-radius:10px;">
      <?php if (!$comments): ?>
        <!-- Empty state -->
        <div class="muted">No comments yet.</div>
      <?php else: ?>
        <?php foreach ($comments as $c): ?>
          <!-- Single comment -->
          <div style="padding:10px 0; border-bottom:1px solid #e5e7eb;">
            <div class="row" style="justify-content:space-between; align-items:center;">

              <!-- Comment timestamp -->
              <div class="muted" style="margin-bottom:6px;">
                <?php
                if (!empty($c['created_at'])) {
                  $dt = new DateTime($c['created_at']);
                  echo h($dt->format('m-d-Y H:i'));
                } else {
                  echo '<span class="muted">—</span>';
                }
                ?>
              </div>

              <!-- Delete comment button -->
              <form method="post" style="margin:0;">
                <input type="hidden" name="project_id" value="<?= (int)$task['project_id'] ?>">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                <button class="btn danger" type="submit" name="delete_comment" value="1"
                        onclick="return confirm('Delete this comment?');">
                  Delete
                </button>
              </form>
            </div>

            <!-- Comment body (trusted users: rendered as HTML) -->
            <div><?= $c['body'] ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Add comment form -->
    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="project_id" value="<?= (int)$task['project_id'] ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">

      <!-- Comment editor (WYSIWYG) -->
      <label>Add a comment</label>
      <textarea id="comment_editor" name="comment_body" rows="3" placeholder="Write a comment..."></textarea>

      <!-- Comment form actions -->
      <div class="row" style="margin-top:10px;">
        <button class="btn" type="submit" name="add_comment" value="1">Add Comment</button>
      </div>
    </form>

  <?php else: ?>
    <!-- Comments disabled until the task is saved (must have an id) -->
    <div class="muted" style="margin-top:10px;">Save the task first to add comments.</div>
  <?php endif; ?>
  <!-- END COMMENTS -->

</div>

<!-- ================= -->
<!-- WYSIWYG: TinyMCE  -->
<!-- ================= -->

<!-- TinyMCE WYSIWYG -->
<script src="https://ghostlaser.com/project/tinymce/js/tinymce/tinymce.min.js"></script>
<script>
  tinymce.init({
    selector: '#details_editor, #comment_editor',
    height: 280,
    menubar: false,
    plugins: 'lists link table code',
    toolbar: 'undo redo | bold italic underline | bullist numlist | link table | removeformat | code',
    branding: false
  });
</script>

<?php render_footer(); ?>
