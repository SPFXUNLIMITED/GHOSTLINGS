<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$version = $config['version'] ?? '1.0.0';

// Recent projects (last 5, non-archived, non-playbook, non-doc-category)
if (is_admin()) {
  $stmt = $pdo->query("
    SELECT id, name, priority, created_at
    FROM projects
    WHERE playbook = 0 AND archived = 0 AND is_doc_category = 0
    ORDER BY created_at DESC
    LIMIT 5
  ");
} else {
  $uid  = current_user_id();
  $stmt = $pdo->prepare("
    SELECT DISTINCT pr.id, pr.name, pr.priority, pr.created_at
    FROM projects pr
    LEFT JOIN tasks t ON t.project_id = pr.id
    WHERE pr.playbook = 0 AND pr.archived = 0 AND pr.is_doc_category = 0
      AND (pr.owner_id = ? OR (t.assigned_to = ? AND t.assigned_to IS NOT NULL))
    ORDER BY pr.created_at DESC
    LIMIT 5
  ");
  $stmt->execute([$uid, $uid]);
}
$recent_projects = $stmt->fetchAll();

// Recent tasks (last 5, from non-archived non-playbook projects)
if (is_admin()) {
  $stmt = $pdo->query("
    SELECT t.id, t.title, t.status, t.priority, t.created_at,
           p.id AS project_id, p.name AS project_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE p.playbook = 0 AND p.archived = 0
    ORDER BY t.created_at DESC
    LIMIT 5
  ");
} else {
  $uid  = current_user_id();
  $stmt = $pdo->prepare("
    SELECT t.id, t.title, t.status, t.priority, t.created_at,
           p.id AS project_id, p.name AS project_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE p.playbook = 0 AND p.archived = 0
      AND t.assigned_to = ?
    ORDER BY t.created_at DESC
    LIMIT 5
  ");
  $stmt->execute([$uid]);
}
$recent_tasks = $stmt->fetchAll();

render_header('Home');
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
    <div>
      <h1 style="margin-top:0; margin-bottom:4px;">Home</h1>
      <p class="muted" style="margin:0;">Welcome to Project Manager.</p>
    </div>
    <span class="badge" style="font-size:13px;">v<?= h($version) ?></span>
  </div>
</div>

<div class="card">
  <div class="row" style="gap:8px; flex-wrap:wrap;">
    <a class="btn" href="projects.php">Projects</a>
    <a class="btn" href="documents.php">Documents</a>
    <a class="btn" href="playbooks.php">Playbooks</a>
    <a class="btn" href="archives.php">Archives</a>
    <?php if (!empty($_SESSION['user_id'])): ?>
      <a class="btn" href="time_clock.php">Time Clock</a>
    <?php endif; ?>
    <?php if (!empty($_SESSION['is_admin'])): ?>
      <a class="btn" href="time_report.php">Time Reports</a>
      <a class="btn" href="users.php">Users</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">Recent Activity</h2>

  <h3 style="margin-bottom:6px;">Latest Projects</h3>
  <?php if ($recent_projects): ?>
  <table class="table-auto">
    <thead>
      <tr>
        <th>Name</th>
        <th class="col-status">Priority</th>
        <th>Added</th>
        <th class="col-actions">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recent_projects as $p): ?>
      <tr>
        <td><strong><?= h($p['name']) ?></strong></td>
        <td class="col-status"><span class="badge priority-<?= h($p['priority'] ?? 'medium') ?>"><?= h(ucfirst($p['priority'] ?? 'medium')) ?></span></td>
        <td class="muted"><?= h($p['created_at'] ?? '') ?></td>
        <td class="col-actions"><a class="btn" href="project_details.php?id=<?= (int)$p['id'] ?>">View</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted">No projects yet.</p>
  <?php endif; ?>

  <h3 style="margin-top:16px; margin-bottom:6px;">Latest Tasks</h3>
  <?php if ($recent_tasks): ?>
  <table class="table-auto">
    <thead>
      <tr>
        <th>Task</th>
        <th>Project</th>
        <th class="col-status">Status</th>
        <th class="col-status">Priority</th>
        <th>Added</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recent_tasks as $t): ?>
      <tr>
        <td><strong><?= h($t['title']) ?></strong></td>
        <td><a href="tasks.php?project_id=<?= (int)$t['project_id'] ?>"><?= h($t['project_name']) ?></a></td>
        <td class="col-status"><span class="badge <?= h($t['status']) ?>"><?= h($t['status']) ?></span></td>
        <td class="col-status"><span class="badge priority-<?= h($t['priority'] ?? 'medium') ?>"><?= h(ucfirst($t['priority'] ?? 'medium')) ?></span></td>
        <td class="muted"><?= h($t['created_at'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted">No tasks yet.</p>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
