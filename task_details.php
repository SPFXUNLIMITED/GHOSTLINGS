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

$stmt = $pdo->prepare("SELECT id, body, created_at FROM task_comments WHERE task_id = ? ORDER BY id DESC");
$stmt->execute([$id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

render_header('Task Details');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Task Details</h1>
    <div class="actions">
      <a class="btn" href="project_details.php?id=<?= (int)$task['project_id'] ?>">Back to Project</a>
      <a class="btn" href="task_form.php?project_id=<?= (int)$task['project_id'] ?>&id=<?= (int)$task['id'] ?>">Edit</a>
      <a class="btn danger" href="task_delete.php?project_id=<?= (int)$task['project_id'] ?>&id=<?= (int)$task['id'] ?>" onclick="return confirm('Delete this task?');">Delete</a>
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
        <td><?= $task['details'] ?? '' ?></td>
      </tr>
    </tbody>
  </table>
</div>

<?php if ($comments): ?>
<div class="card">
  <h2 style="margin-top:0;">Comments</h2>
  <?php foreach ($comments as $c): ?>
    <div style="padding:10px 0; border-bottom:1px solid #e5e7eb;">
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
      <div><?= $c['body'] ?? '' ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
