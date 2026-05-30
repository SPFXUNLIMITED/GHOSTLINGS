<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require __DIR__ . '/auth.php';
require_login();

$category_id = (int)($_GET['category_id'] ?? $_POST['category_id'] ?? 0);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$category_id) { header('Location: sops.php'); exit; }

$all_users = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll();

$errors = [];
$doc = ['project_id' => $category_id, 'title' => '', 'details' => '', 'status' => 'todo', 'due_date' => '', 'priority' => 'medium', 'assigned_to' => null];

if (!$id) {
  $doc['project_id'] = $category_id;

  $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ? AND is_sop_category = 1");
  $stmt->execute([$category_id]);
  $category = $stmt->fetch();
  if (!$category) { http_response_code(404); exit('SOP category not found'); }
}

if ($id) {
  $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
  $stmt->execute([$id]);
  $doc = $stmt->fetch();
  if (!$doc) { http_response_code(404); exit('SOP page not found'); }

  $id = (int)$doc['id'];
  $doc['project_id'] = (int)$doc['project_id'];
  $category_id = $doc['project_id'];

  $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ? AND is_sop_category = 1");
  $stmt->execute([$category_id]);
  $category = $stmt->fetch();

  if (!$category) { http_response_code(404); exit('SOP category not found'); }
}

// Delete comment (edit mode only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment'])) {
  if (!$id) {
    $errors[] = "Invalid SOP page.";
  } else {
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    if ($comment_id <= 0) {
      $errors[] = "Invalid comment.";
    } else {
      $stmt = $pdo->prepare("DELETE FROM task_comments WHERE id = ? AND task_id = ?");
      $stmt->execute([$comment_id, $id]);

      $cid = (int)($doc['project_id'] ?? $category_id);
      header("Location: sop_page_form.php?category_id={$cid}&id={$id}");
      exit;
    }
  }
}

// Handle add-comment (edit mode only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
  if (!$id) {
    $errors[] = "Save the SOP page before adding comments.";
  } else {
    $comment = trim($_POST['comment_body'] ?? '');
    if ($comment === '') {
      $errors[] = "Comment cannot be empty.";
    } else {
      $stmt = $pdo->prepare("INSERT INTO task_comments (task_id, body) VALUES (?, ?)");
      $stmt->execute([$id, $comment]);

      $cid = (int)($doc['project_id'] ?? $category_id);
      header("Location: sop_page_form.php?category_id={$cid}&id={$id}");
      exit;
    }
  }
}

// SOP page save: normal create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['add_comment']) && !isset($_POST['delete_comment'])) {

  $new_category_id = $category_id;

  $title      = trim($_POST['title'] ?? '');
  $details    = trim($_POST['details'] ?? '');
  $status     = $_POST['status'] ?? 'todo';
  $due_date   = trim($_POST['due_date'] ?? '');
  $priority   = $_POST['priority'] ?? 'medium';
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
      $stmt->execute([$new_category_id, $title, $details ?: null, $status, $due, $priority, $assigned_to, $id]);
    } else {
      $stmt = $pdo->prepare("INSERT INTO tasks (project_id, title, details, status, due_date, priority, assigned_to) VALUES (?, ?, ?, ?, ?, ?, ?)");
      $stmt->execute([$new_category_id, $title, $details ?: null, $status, $due, $priority, $assigned_to]);
    }
    header("Location: sop_pages.php?category_id={$new_category_id}");
    exit;
  }

  $doc['title']      = $title;
  $doc['details']    = $details;
  $doc['status']     = $status;
  $doc['due_date']   = $due_date;
  $doc['priority']   = $priority;
  $doc['project_id'] = $new_category_id;
  $doc['assigned_to'] = $assigned_to;

  $category_id = $new_category_id;
  $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ? AND is_sop_category = 1");
  $stmt->execute([$category_id]);
  $category = $stmt->fetch();
  if (!$category) { $errors[] = "Selected SOP category not found."; }
}

// Load comments for display (edit mode only)
$comments = [];
if ($id) {
  $stmt = $pdo->prepare("SELECT id, body, created_at FROM task_comments WHERE task_id = ? ORDER BY id DESC");
  $stmt->execute([$id]);
  $comments = $stmt->fetchAll();
}

render_header($id ? 'Edit SOP Page' : 'New SOP Page');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div>
      <h1 style="margin:0;"><?= $id ? 'Edit SOP Page' : 'New SOP Page' ?></h1>
      <div class="muted">SOP Category: <?= h($category['name']) ?></div>
    </div>
    <a class="btn" href="sop_pages.php?category_id=<?= (int)$category_id ?>">Back</a>
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
        <input name="title" value="<?= h($doc['title']) ?>" />
      </div>

      <div>
        <label>Status</label>
        <select name="status">
          <?php foreach (['todo','doing','done'] as $s): ?>
            <option value="<?= h($s) ?>" <?= ($doc['status']===$s?'selected':'') ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Priority</label>
        <select name="priority">
          <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $val => $label): ?>
            <option value="<?= h($val) ?>" <?= (($doc['priority'] ?? 'medium') === $val) ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Due date</label>
        <input
          type="date"
          name="due_date"
          value="<?= h($doc['due_date'] ?? '') ?>"
          min="2000-01-01"
          max="2100-12-31"
        />
        <div class="muted" style="margin-top:6px;">
          Display format: <?= h(fmt_date_mdY($doc['due_date'] ?? '')) ?>
        </div>
      </div>

      <div>
        <label>Assign to</label>
        <select name="assigned_to">
          <option value="">— Unassigned —</option>
          <?php foreach ($all_users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= ((int)($doc['assigned_to'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>>
              <?= h($u['username']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="full">
        <label>Details</label>
        <textarea id="details_editor" name="details" rows="6"><?= h($doc['details'] ?? '') ?></textarea>
        <div class="muted" style="margin-top:6px;">
          Use square brackets for placeholders, like <code>Full Name text input [contact_name] [company_name] [email] [contact_phone] [username]</code>.
        </div>
      </div>
    </div>

    <div class="row" style="margin-top:12px;">
      <button class="btn primary" type="submit"><?= $id ? 'Save Changes' : 'Create SOP Page' ?></button>
      <a class="btn" href="sop_pages.php?category_id=<?= (int)$category_id ?>">Cancel</a>
    </div>

    <input type="hidden" name="category_id" value="<?= (int)$category_id ?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
  </form>

  <!-- START COMMENTS -->
  <?php if ($id): ?>
    <hr style="border:none; border-top:1px solid #e5e7eb; margin:40px 0 0;" />
    <h2 style="margin:0 0 10px;">Comments</h2>

    <div class="card" style="padding:12px; border-radius:10px;">
      <?php if (!$comments): ?>
        <div class="muted">No comments yet.</div>
      <?php else: ?>
        <?php foreach ($comments as $c): ?>
          <div style="padding:10px 0; border-bottom:1px solid #e5e7eb;">
            <div class="row" style="justify-content:space-between; align-items:center;">
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

              <form method="post" style="margin:0;">
                <input type="hidden" name="category_id" value="<?= (int)$doc['project_id'] ?>">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                <button class="btn danger" type="submit" name="delete_comment" value="1"
                        onclick="return confirm('Delete this comment?');">
                  Delete
                </button>
              </form>
            </div>

            <div><?= nl2br(h($c['body'] ?? '')) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="category_id" value="<?= (int)$doc['project_id'] ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">

      <label>Add a comment</label>
      <textarea id="comment_editor" name="comment_body" rows="3" placeholder="Write a comment..."></textarea>

      <div class="row" style="margin-top:10px;">
        <button class="btn" type="submit" name="add_comment" value="1">Add Comment</button>
      </div>
    </form>
  <?php else: ?>
    <div class="muted" style="margin-top:10px;">Save the SOP page first to add comments.</div>
  <?php endif; ?>

</div>

<!-- TinyMCE WYSIWYG -->
<script src="/project/tinymce/js/tinymce/tinymce.min.js"></script>
<script>
  tinymce.init({
    selector: '#details_editor',
    base_url: '/project/tinymce/js/tinymce',
    suffix: '.min',
    license_key: 'gpl',
    content_css: '/project/tinymce/js/tinymce/skins/content/default/content.min.css',
    height: 280,
    menubar: false,
    plugins: 'lists link table code',
    toolbar: 'undo redo | bold italic underline | bullist numlist | link table | removeformat | code',
    branding: false
  });
</script>

<?php render_footer(); ?>
