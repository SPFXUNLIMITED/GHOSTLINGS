<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require __DIR__ . '/auth.php';
require_login();

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if (!$project_id) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("SELECT id, name, owner_id FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); exit('Project not found'); }

$uid = current_user_id();

if (is_admin()) {
  $stmt = $pdo->prepare("
    SELECT t.*, COUNT(u.id) AS upload_count, usr.username AS assigned_username
    FROM tasks t
    LEFT JOIN task_uploads u ON u.task_id = t.id
    LEFT JOIN users usr ON usr.id = t.assigned_to
    WHERE t.project_id = ?
    GROUP BY t.id
    ORDER BY t.id DESC
  ");
  $stmt->execute([$project_id]);
} else {
  $stmt = $pdo->prepare("
    SELECT t.*, COUNT(u.id) AS upload_count, usr.username AS assigned_username
    FROM tasks t
    LEFT JOIN task_uploads u ON u.task_id = t.id
    LEFT JOIN users usr ON usr.id = t.assigned_to
    JOIN projects p ON p.id = t.project_id
    WHERE t.project_id = ?
      AND (t.assigned_to = ? OR p.owner_id = ?)
    GROUP BY t.id
    ORDER BY t.id DESC
  ");
  $stmt->execute([$project_id, $uid, $uid]);
}
$tasks = $stmt->fetchAll();

render_header('Tasks');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div>
      <h1 style="margin:0;">Tasks</h1>
      <div class="muted">Project: <strong><?= h($project['name']) ?></strong> (ID <?= (int)$project_id ?>)</div>
    </div>
    <div class="actions">
      <a class="btn" href="index.php">Back to Projects</a>
      <a class="btn primary" href="task_form.php?project_id=<?= (int)$project_id ?>">+ New Task</a>
    </div>
  </div>
</div>

<div class="card">
  <table>
    <thead>
      <tr>
		<th>Title</th>
		<th>Status</th>
		<th>Due</th>
		<th>Assigned To</th>
		<th>Details</th>
		<th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$tasks): ?>
        <tr><td colspan="6" class="muted">No tasks yet.</td></tr>
      <?php endif; ?>

      <?php foreach ($tasks as $t): ?>
        <tr>
          <td>
            <strong><?= h($t['title']) ?></strong><br>
            <?php $count = (int)($t['upload_count'] ?? 0); ?>
			<a class="muted" href="task_uploads.php?task_id=<?= (int)$t['id'] ?>">Files (<?= $count ?>)</a>
          </td>
          <td><span class="badge <?= h($t['status']) ?>"><?= h($t['status']) ?></span></td>

			<td>
			  <?php if (!empty($t['due_date'])): ?>
				<?= h(fmt_date_mdY($t['due_date'])) ?>
			  <?php else: ?>
				<span class="muted">—</span>
			  <?php endif; ?>
			</td>

			<td>
			  <?php if (!empty($t['assigned_username'])): ?>
				<?= h($t['assigned_username']) ?>
			  <?php else: ?>
				<span class="muted">—</span>
			  <?php endif; ?>
			</td>

          <td><?= $t['details'] ?? '' ?></td>
          <td>
            <div class="actions">
              <a class="btn" href="task_form.php?project_id=<?= (int)$project_id ?>&id=<?= (int)$t['id'] ?>">Edit</a>
              <a class="btn danger"
                 href="task_delete.php?project_id=<?= (int)$project_id ?>&id=<?= (int)$t['id'] ?>"
                 onclick="return confirm('Delete this task?');">
                Delete
              </a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
