<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require __DIR__ . '/auth.php';
require_login();

$task_id = (int)($_GET['task_id'] ?? 0);
if ($task_id <= 0) {
  http_response_code(400);
  render_header('Task Files');
  echo '<div class="card"><p class="muted">Missing task_id.</p></div>';
  render_footer();
  exit;
}

// Fetch task + project for context
$stmt = $pdo->prepare("
  SELECT t.id, t.title, t.project_id, p.name AS project_name
  FROM tasks t
  JOIN projects p ON p.id = t.project_id
  WHERE t.id = ?
  LIMIT 1
");
$stmt->execute([$task_id]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
  http_response_code(404);
  render_header('Task Files');
  echo '<div class="card"><p class="muted">Task not found.</p></div>';
  render_footer();
  exit;
}

// Load uploads
$stmt = $pdo->prepare("
  SELECT id, original_name, stored_name, mime_type, size_bytes, caption, created_at
  FROM task_uploads
  WHERE task_id = ?
  ORDER BY created_at DESC, id DESC
");
$stmt->execute([$task_id]);
$uploads = $stmt->fetchAll(PDO::FETCH_ASSOC);

function is_image_mime($mime) {
  return is_string($mime) && preg_match('#^image/(png|jpe?g|gif|webp)$#i', $mime);
}

render_header('Task Files');
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div>
      <h1 style="margin:0;">Task Files</h1>
      <div class="muted" style="margin-top:4px;">
        Project: <strong><?= h($task['project_name']) ?></strong><br>
        Task #<?= (int)$task['id'] ?>: <strong><?= h($task['title']) ?></strong>
      </div>
    </div>
    <div class="actions">
      <a class="btn" href="task_details.php?id=<?= (int)$task_id ?>">Back to Task</a>
    </div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">Upload a file</h2>
  <form action="task_upload_handler.php" method="post" enctype="multipart/form-data" class="row" style="gap:12px; align-items:flex-end;">
    <input type="hidden" name="task_id" value="<?= (int)$task_id ?>">

    <div style="min-width:260px;">
      <label class="muted" for="file">Choose file</label><br>
      <input id="file" type="file" name="file" required>
    </div>

    <div style="min-width:260px;">
      <label class="muted" for="caption">Caption (optional)</label><br>
      <input id="caption" type="text" name="caption" value="" placeholder="Screenshot, spec, etc." style="width:100%;">
    </div>

    <div>
      <button class="btn primary" type="submit">Upload</button>
    </div>
  </form>

  <p class="muted" style="margin-bottom:0;">
    Images will show thumbnails. Other files show an icon + filename.
  </p>
</div>

<style>
  .thumb-grid{display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:12px;}
  .thumb{border:1px solid rgba(0,0,0,.08); border-radius:12px; overflow:hidden; background:#fff;}
  .thumb .preview{height:150px; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.03);}
  .thumb img{width:100%; height:150px; object-fit:cover; display:block;}
  .thumb .meta{padding:10px;}
  .thumb .meta .name{font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .thumb .meta .sub{font-size:12px; color:rgba(0,0,0,.55); margin-top:4px;}
  .thumb .actions{padding:10px; display:flex; gap:8px;}
</style>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h2 style="margin:0;">Files</h2>
    <span class="muted"><?= count($uploads) ?> total</span>
  </div>

  <?php if (!$uploads): ?>
    <p class="muted">No files uploaded yet.</p>
  <?php else: ?>
    <div class="thumb-grid">
      <?php foreach ($uploads as $u): ?>
        <?php
          $isImg = is_image_mime($u['mime_type']);
          $url = 'uploads/' . $u['stored_name'];
        ?>
        <div class="thumb">
          <div class="preview">
            <?php if ($isImg): ?>
              <a href="<?= h($url) ?>" target="_blank" rel="noopener">
                <img src="<?= h($url) ?>" alt="<?= h($u['original_name']) ?>">
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

          <div class="actions">
            <a class="btn" href="<?= h($url) ?>" target="_blank" rel="noopener">Open</a>
            <a class="btn danger" href="task_upload_delete.php?id=<?= (int)$u['id'] ?>&task_id=<?= (int)$task_id ?>"
               onclick="return confirm('Delete this file?');">Delete</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
