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
    $stmt = $pdo->prepare("DELETE FROM project_comments WHERE id = ? AND project_id = ?");
    $stmt->execute([$comment_id, $id]);
  }
  header("Location: project_details.php?id={$id}");
  exit;
}

// Add comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
  $comment = trim($_POST['comment_body'] ?? '');
  if ($comment !== '') {
    $stmt = $pdo->prepare("INSERT INTO project_comments (project_id, body) VALUES (?, ?)");
    $stmt->execute([$id, $comment]);
  }
  header("Location: project_details.php?id={$id}");
  exit;
}

if (is_admin()) {
  $stmt = $pdo->prepare("
    SELECT
      p.id, p.name, p.description, p.created_at, p.priority, p.owner_id,
      u.username AS owner_username,
      COUNT(t.id) AS total_tasks,
      SUM(CASE WHEN t.status = 'todo' THEN 1 ELSE 0 END) AS todo_tasks,
      SUM(CASE WHEN t.status = 'doing' THEN 1 ELSE 0 END) AS doing_tasks,
      SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END) AS done_tasks
    FROM projects p
    LEFT JOIN users u ON u.id = p.owner_id
    LEFT JOIN tasks t ON t.project_id = p.id
    WHERE p.id = ? AND p.playbook = 0 AND p.archived = 0
    GROUP BY p.id
  ");
  $stmt->execute([$id]);
} else {
  $uid = current_user_id();
  $stmt = $pdo->prepare("
    SELECT
      p.id, p.name, p.description, p.created_at, p.priority, p.owner_id,
      u.username AS owner_username,
      SUM(CASE WHEN t.id IS NOT NULL AND (p.owner_id = ? OR t.assigned_to = ?) THEN 1 ELSE 0 END) AS total_tasks,
      SUM(CASE WHEN t.id IS NOT NULL AND t.status = 'todo' AND (p.owner_id = ? OR t.assigned_to = ?) THEN 1 ELSE 0 END) AS todo_tasks,
      SUM(CASE WHEN t.id IS NOT NULL AND t.status = 'doing' AND (p.owner_id = ? OR t.assigned_to = ?) THEN 1 ELSE 0 END) AS doing_tasks,
      SUM(CASE WHEN t.id IS NOT NULL AND t.status = 'done' AND (p.owner_id = ? OR t.assigned_to = ?) THEN 1 ELSE 0 END) AS done_tasks
    FROM projects p
    LEFT JOIN users u ON u.id = p.owner_id
    LEFT JOIN tasks t ON t.project_id = p.id
    WHERE p.id = ? AND p.playbook = 0 AND p.archived = 0
      AND (
        p.owner_id = ?
        OR EXISTS (
          SELECT 1
          FROM tasks tx
          WHERE tx.project_id = p.id
            AND tx.assigned_to = ?
        )
      )
    GROUP BY p.id
  ");
  $stmt->execute([$uid, $uid, $uid, $uid, $uid, $uid, $uid, $uid, $id, $uid, $uid]);
}

$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) {
  http_response_code(404);
  exit('Project not found');
}

if (is_admin()) {
  $task_stmt = $pdo->prepare("
    SELECT t.id, t.title, t.status, t.priority, t.details
    FROM tasks t
    WHERE t.project_id = ?
    ORDER BY FIELD(t.priority, 'critical', 'high', 'medium', 'low'), FIELD(t.status, 'todo', 'doing', 'done')
    LIMIT 5
  ");
  $task_stmt->execute([$id]);
} else {
  $task_stmt = $pdo->prepare("
    SELECT t.id, t.title, t.status, t.priority, t.details
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE t.project_id = ? AND (p.owner_id = ? OR t.assigned_to = ?)
    ORDER BY FIELD(t.priority, 'critical', 'high', 'medium', 'low'), FIELD(t.status, 'todo', 'doing', 'done')
    LIMIT 5
  ");
  $task_stmt->execute([$id, current_user_id(), current_user_id()]);
}
$preview_tasks = $task_stmt->fetchAll(PDO::FETCH_ASSOC);

$comment_stmt = $pdo->prepare("SELECT id, body, created_at FROM project_comments WHERE project_id = ? ORDER BY id DESC");
$comment_stmt->execute([$id]);
$comments = $comment_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load project uploads
$upload_stmt = $pdo->prepare("
  SELECT id, original_name, stored_name, mime_type, size_bytes, caption, created_at
  FROM project_uploads
  WHERE project_id = ?
  ORDER BY created_at DESC, id DESC
");
$upload_stmt->execute([$id]);
$project_uploads = $upload_stmt->fetchAll(PDO::FETCH_ASSOC);

$task_title_max_length = 50;

$created = new DateTime($project['created_at']);
$created->setTimezone(new DateTimeZone('America/Los_Angeles'));

render_header('Project Details');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Project Details</h1>
    <div class="actions">
      <a class="btn" href="project_form.php?id=<?= (int)$project['id'] ?>">Edit</a>
      <a class="btn" href="project_archive.php?id=<?= (int)$project['id'] ?>&action=archive"
         onclick="return confirm('Archive this project?');">Archive</a>
      <a class="btn primary" href="task_form.php?project_id=<?= (int)$project['id'] ?>">+ New Task</a>
      <a class="btn" href="#project-files">Upload Files</a>
    </div>
  </div>
</div>

<div class="card">
  <table>
    <tbody>
      <tr>
        <th style="width:220px;">Project</th>
        <td><strong><?= h($project['name']) ?></strong> (ID <?= (int)$project['id'] ?>)</td>
      </tr>
      <tr>
        <th>Priority</th>
        <td><span class="badge priority-<?= h($project['priority'] ?? 'medium') ?>"><?= h(ucfirst($project['priority'] ?? 'medium')) ?></span></td>
      </tr>
      <tr>
        <th>Owner</th>
        <td><?= h($project['owner_username'] ?? '—') ?></td>
      </tr>
      <tr>
        <th>Created</th>
        <td><?= h($created->format('m-d-Y g:i A')) ?> (Los Angeles)</td>
      </tr>
      <tr>
        <th>Description</th>
        <td><?= nl2br(h($project['description'] ?? '')) ?></td>
      </tr>
    </tbody>
  </table>
</div>

<div class="card" id="project-files">
  <div class="row" style="justify-content:space-between; align-items:center; margin-bottom:12px;">
    <h2 style="margin:0;">Files</h2>
    <span class="muted"><?= count($project_uploads) ?> total</span>
  </div>

  <form action="project_upload_handler.php" method="post" enctype="multipart/form-data" class="row" style="gap:12px; align-items:flex-end; margin-bottom:12px;">
    <input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>">
    <div style="min-width:220px;">
      <label class="muted" for="proj_file">Choose file</label>
      <input id="proj_file" type="file" name="file" required>
    </div>
    <div style="min-width:220px;">
      <label class="muted" for="proj_caption">Caption (optional)</label>
      <input id="proj_caption" type="text" name="caption" value="" placeholder="Screenshot, spec, etc." style="width:100%;">
    </div>
    <div>
      <button class="btn primary" type="submit">Upload</button>
    </div>
  </form>

  <?php if (!$project_uploads): ?>
    <p class="muted">No files uploaded yet.</p>
  <?php else: ?>
    <div class="thumb-grid">
      <?php foreach ($project_uploads as $u): ?>
        <?php
          $isImg = is_string($u['mime_type']) && preg_match('#^image/(png|jpe?g|gif|webp)$#i', $u['mime_type']);
          $fileUrl = 'uploads/' . $u['stored_name'];
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
            <a class="btn danger" href="project_upload_delete.php?id=<?= (int)$u['id'] ?>&project_id=<?= (int)$project['id'] ?>"
               onclick="return confirm('Delete this file?');">Delete</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<style>
  .thumb-grid{display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:12px;}
  .thumb{border:1px solid rgba(0,0,0,.08); border-radius:12px; overflow:hidden; background:#fff;}
  .thumb .preview{height:150px; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.03);}
  .thumb img{width:100%; height:150px; object-fit:cover; display:block;}
  .thumb .meta{padding:10px;}
  .thumb .meta .name{font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .thumb .meta .sub{font-size:12px; color:rgba(0,0,0,.55); margin-top:4px;}
</style>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h2 style="margin:0;">Tasks</h2>
  </div>
  <div class="table-wrap">
    <table class="table-auto">
      <thead>
        <tr>
          <th>Title</th>
          <th>Details</th>
          <th class="col-status">Status</th>
          <th class="col-status">Priority</th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($preview_tasks)): ?>
          <tr><td colspan="5" class="muted">No tasks yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($preview_tasks as $t): ?>
          <?php
            $task_title = (string)($t['title'] ?? '');
            if (mb_strlen($task_title) > $task_title_max_length) {
              $task_title = mb_substr($task_title, 0, $task_title_max_length) . '...';
            }
            $task_details = strip_tags((string)($t['details'] ?? ''));
            if (mb_strlen($task_details) > 50) {
              $task_details = mb_substr($task_details, 0, 50) . '...';
            }
          ?>
          <tr>
            <td><?= h($task_title) ?></td>
            <td><?php if ($task_details !== '') { echo h($task_details); } else { ?><span class="muted">—</span><?php } ?></td>
            <td class="col-status"><span class="badge <?= h($t['status']) ?>"><?= h($t['status']) ?></span></td>
            <td class="col-status"><span class="badge priority-<?= h($t['priority'] ?? 'medium') ?>"><?= h(ucfirst($t['priority'] ?? 'medium')) ?></span></td>
            <td class="col-actions">
              <div class="actions">
                <a class="btn" href="task_details.php?id=<?= (int)$t['id'] ?>">View</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

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
<script src="https://cdn.tiny.cloud/1/pifs5sjkqqgawy88jx7d10zx5sxezi2ig67u4ci0exbu6hag/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
  tinymce.init({
    selector: '#comment_editor',
    height: 280,
    menubar: false,
    plugins: 'lists link table code',
    toolbar: 'undo redo | bold italic underline | bullist numlist | link table | removeformat | code',
    branding: false
  });
</script>

<?php render_footer(); ?>
