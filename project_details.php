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
      COUNT(t.id) AS total_tasks,
      SUM(CASE WHEN t.status = 'todo' THEN 1 ELSE 0 END) AS todo_tasks,
      SUM(CASE WHEN t.status = 'doing' THEN 1 ELSE 0 END) AS doing_tasks,
      SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END) AS done_tasks
    FROM projects p
    LEFT JOIN users u ON u.id = p.owner_id
    LEFT JOIN tasks t ON t.project_id = p.id AND (p.owner_id = ? OR t.assigned_to = ?)
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
  $stmt->execute([$uid, $uid, $id, $uid, $uid]);
}

$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) {
  http_response_code(404);
  exit('Project not found');
}

$created = new DateTime($project['created_at']);
$created->setTimezone(new DateTimeZone('America/Los_Angeles'));

render_header('Project Details');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Project Details</h1>
    <div class="actions">
      <a class="btn" href="projects.php">Back to Projects</a>
      <a class="btn" href="tasks.php?project_id=<?= (int)$project['id'] ?>">View Tasks</a>
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

<div class="card">
  <h2 style="margin-top:0;">Task Summary</h2>
  <div class="row" style="gap:12px; flex-wrap:wrap;">
    <span class="badge">Total: <?= (int)$project['total_tasks'] ?></span>
    <span class="badge todo">To Do: <?= (int)$project['todo_tasks'] ?></span>
    <span class="badge doing">Doing: <?= (int)$project['doing_tasks'] ?></span>
    <span class="badge done">Done: <?= (int)$project['done_tasks'] ?></span>
  </div>
</div>

<?php render_footer(); ?>
