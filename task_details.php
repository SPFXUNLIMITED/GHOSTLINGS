<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  header('Location: projects.php');
  exit;
}

// Delete comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment'])) {
  $comment_id = (int)($_POST['comment_id'] ?? 0);
  if ($comment_id > 0) {
    $stmt = $pdo->prepare("DELETE FROM task_comments WHERE id = ? AND task_id = ?");
    $stmt->execute([$comment_id, $id]);
  }
  header("Location: task_details.php?id={$id}");
  exit;
}

// Add comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
  $comment = trim($_POST['comment_body'] ?? '');
  if ($comment !== '') {
    $stmt = $pdo->prepare("INSERT INTO task_comments (task_id, body) VALUES (?, ?)");
    $stmt->execute([$id, $comment]);
  }
  header("Location: task_details.php?id={$id}");
  exit;
}

if (is_admin()) {
  $stmt = $pdo->prepare("
    SELECT t.*, p.name AS project_name, u.username AS assigned_username
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.id = ?
  ");
  $stmt->execute([$id]);
} else {
  $uid = current_user_id();
  $stmt = $pdo->prepare("
    SELECT t.*, p.name AS project_name, u.username AS assigned_username
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.id = ? AND (p.owner_id = ? OR t.assigned_to = ?)
  ");
  $stmt->execute([$id, $uid, $uid]);
}

$task = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$task) {
  http_response_code(404);
  exit('Task not found');
}

$task_placeholder_values = [];
$uid = current_user_id();
if ($uid) {
  $profile_stmt = $pdo->prepare("
    SELECT username, contact_name, company_name, email, contact_phone
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $profile_stmt->execute([$uid]);
  $profile = $profile_stmt->fetch() ?: [];
  $task_placeholder_values = [
    'username' => trim((string)($profile['username'] ?? '')),
    'contact_name' => trim((string)($profile['contact_name'] ?? '')),
    'company_name' => trim((string)($profile['company_name'] ?? '')),
    'email' => trim((string)($profile['email'] ?? '')),
    'contact_phone' => trim((string)($profile['contact_phone'] ?? '')),
  ];
}

if (empty($_SESSION['task_mark_done_csrf'])) {
  $_SESSION['task_mark_done_csrf'] = bin2hex(random_bytes(24));
}

// Mark task done
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_done']) && (string)$task['status'] !== 'done') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['task_mark_done_csrf'], $submitted_csrf)) {
    http_response_code(400);
    exit('Invalid request token.');
  }
  $stmt = $pdo->prepare("UPDATE tasks SET status = 'done' WHERE id = ?");
  $stmt->execute([$id]);
  header('Location: task_details.php?' . http_build_query(['id' => (int)$id]));
  exit;
}

$stmt = $pdo->prepare("SELECT id, body, created_at FROM task_comments WHERE task_id = ? ORDER BY id DESC");
$stmt->execute([$id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$upload_stmt = $pdo->prepare("
  SELECT id, original_name, stored_name, mime_type, size_bytes, caption, created_at
  FROM task_uploads
  WHERE task_id = ?
  ORDER BY created_at DESC, id DESC
");
$upload_stmt->execute([$id]);
$task_uploads = $upload_stmt->fetchAll(PDO::FETCH_ASSOC);

render_header('Task Details');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Task Details</h1>
    <div class="actions">
      <a class="btn" href="project_details.php?id=<?= (int)$task['project_id'] ?>">Back to Project</a>
      <a class="btn" href="task_form.php?project_id=<?= (int)$task['project_id'] ?>&id=<?= (int)$task['id'] ?>">Edit</a>
      <?php if ((string)$task['status'] !== 'done'): ?>
      <form method="post" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['task_mark_done_csrf']) ?>">
        <button class="btn primary" type="submit" name="mark_done" value="1">Mark Done</button>
      </form>
      <?php endif; ?>
      <a class="btn danger" href="task_delete.php?project_id=<?= (int)$task['project_id'] ?>&id=<?= (int)$task['id'] ?>&return_to=project_details" onclick="return confirm('Delete this task?');">Delete</a>
      <a class="btn" href="#task-files">Upload Files</a>
    </div>
  </div>
</div>

<div class="card">
  <table>
    <tbody>
      <tr>
        <th style="width:220px;">Task</th>
        <td><strong><?= h($task['title']) ?></strong> (ID <?= (int)$task['id'] ?>)</td>
      </tr>
      <tr>
        <th>Project</th>
        <td><?= h($task['project_name']) ?></td>
      </tr>
      <tr>
        <th>Status</th>
        <td><span class="badge <?= h($task['status']) ?>"><?= h($task['status']) ?></span></td>
      </tr>
      <tr>
        <th>Priority</th>
        <td><span class="badge priority-<?= h($task['priority'] ?? 'medium') ?>"><?= h(ucfirst($task['priority'] ?? 'medium')) ?></span></td>
      </tr>
      <tr>
        <th>Due Date</th>
        <td>
          <?php if (!empty($task['due_date'])): ?>
            <?= h(fmt_date_mdY($task['due_date'])) ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Assigned To</th>
        <td><?= !empty($task['assigned_username']) ? h($task['assigned_username']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Details</th>
        <td><?= render_doc_details($task['details'] ?? '', $task_placeholder_values) ?></td>
      </tr>
    </tbody>
  </table>
</div>

<div class="card" id="task-files">
  <div class="row" style="justify-content:space-between; align-items:center; margin-bottom:12px;">
    <h2 style="margin:0;">Files</h2>
    <span class="muted"><?= count($task_uploads) ?> total</span>
  </div>

  <form action="task_upload_handler.php" method="post" enctype="multipart/form-data" class="row" style="gap:12px; align-items:flex-end; margin-bottom:12px;">
    <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
    <div style="min-width:220px;">
      <label class="muted" for="task_file">Choose file</label>
      <input id="task_file" type="file" name="file" required>
    </div>
    <div style="min-width:220px;">
      <label class="muted" for="task_caption">Caption (optional)</label>
      <input id="task_caption" type="text" name="caption" value="" placeholder="Screenshot, spec, etc." style="width:100%;">
    </div>
    <div>
      <button class="btn primary" type="submit">Upload</button>
    </div>
  </form>

  <?php if (empty($task_uploads)): ?>
    <p class="muted">No files uploaded yet.</p>
  <?php else: ?>
    <div class="thumb-grid">
      <?php foreach ($task_uploads as $u): ?>
        <?php
          $storedName = (string)($u['stored_name'] ?? '');
          if (
            $storedName === '' ||
            basename($storedName) !== $storedName ||
            strpos($storedName, '..') !== false ||
            !preg_match('/^[a-zA-Z0-9._-]+$/', $storedName)
          ) {
            continue;
          }
          $isImg = is_string($u['mime_type']) && preg_match('#^image/(png|jpe?g|gif|webp)$#i', $u['mime_type']);
          $fileUrl = 'uploads/' . rawurlencode($storedName);
        ?>
        <div class="thumb">
          <div class="preview">
            <?php if ($isImg): ?>
              <a href="<?= h($fileUrl) ?>" target="_blank" rel="noopener">
                <img src="<?= h($fileUrl) ?>" alt="<?= h($u['original_name']) ?>">
              </a>
            <?php else: ?>
              <div class="muted" style="text-align:center; padding:10px;">
                <div style="font-size:42px; line-height:1;">📄</div>
                <div style="margin-top:6px;">File</div>
              </div>
            <?php endif; ?>
          </div>
          <div class="meta">
            <div class="name" title="<?= h($u['original_name']) ?>"><?= h($u['original_name']) ?></div>
            <?php if (!empty($u['caption'])): ?>
              <div class="sub"><?= h($u['caption']) ?></div>
            <?php endif; ?>
            <div class="sub">
              <?= h($u['mime_type'] ?? 'application/octet-stream') ?> •
              <?= number_format(((int)$u['size_bytes']) / 1024, 1) ?> KB
            </div>
            <div class="sub">Uploaded: <?= h($u['created_at']) ?></div>
          </div>
          <div class="actions" style="padding:10px;">
            <a class="btn" href="<?= h($fileUrl) ?>" target="_blank" rel="noopener">Open</a>
            <a class="btn danger" href="task_upload_delete.php?id=<?= (int)$u['id'] ?>&task_id=<?= (int)$task['id'] ?>"
               onclick="return confirm('Delete this file?');">Delete</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<style>
  .inline-form { margin: 0; }
  .thumb-grid{display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:12px;}
  .thumb{border:1px solid rgba(0,0,0,.08); border-radius:12px; overflow:hidden; background:#fff;}
  .thumb .preview{height:150px; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.03);}
  .thumb img{width:100%; height:150px; object-fit:cover; display:block;}
  .thumb .meta{padding:10px;}
  .thumb .meta .name{font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .thumb .meta .sub{font-size:12px; color:rgba(0,0,0,.55); margin-top:4px;}
</style>

<?php if ($comments): ?>
<div class="card">
  <h2 style="margin-top:0;">Comments</h2>
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
          <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
          <button class="btn danger" type="submit" name="delete_comment" value="1"
                  onclick="return confirm('Delete this comment?');">
            Delete
          </button>
        </form>
      </div>
      <div><?= $c['body'] ?? '' ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card">
  <h2 style="margin-top:0;">Comments</h2>
  <div class="muted">No comments yet.</div>
</div>
<?php endif; ?>

<div class="card">
  <form method="post">
    <label>Add a comment</label>
    <textarea id="comment_editor" name="comment_body" rows="3" placeholder="Write a comment..."></textarea>
    <div class="row" style="margin-top:10px;">
      <button class="btn" type="submit" name="add_comment" value="1">Add Comment</button>
    </div>
  </form>
</div>

<!-- TinyMCE WYSIWYG -->
<script src="/project/tinymce/js/tinymce/tinymce.min.js"></script>
<script>
  tinymce.init({
    selector: '#comment_editor',
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
