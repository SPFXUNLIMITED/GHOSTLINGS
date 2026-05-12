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

$task_title_max_length = 50;

$created = new DateTime($project['created_at']);
$created->setTimezone(new DateTimeZone('America/Los_Angeles'));

render_header('Project Details');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Project Details</h1>
    <div class="actions">
      <a class="btn" href="projects.php">Back to Projects</a>
      <a class="btn" href="project_form.php?id=<?= (int)$project['id'] ?>">Edit</a>
      <a class="btn" href="project_archive.php?id=<?= (int)$project['id'] ?>&action=archive"
         onclick="return confirm('Archive this project?');">Archive</a>
      <a class="btn primary" href="task_form.php?project_id=<?= (int)$project['id'] ?>">+ New Task</a>
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

<?php render_footer(); ?>
