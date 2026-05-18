<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$search = trim((string)($_GET['q'] ?? ''));
$max_results = 25;
$projects = [];
$playbooks = [];
$tasks = [];

if ($search !== '') {
  $like = '%' . $search . '%';

  if (is_admin()) {
    $stmt = $pdo->prepare("
      SELECT id, name, description, created_at, priority
      FROM projects
      WHERE playbook = 0
        AND archived = 0
        AND (name LIKE ? OR description LIKE ?)
      ORDER BY created_at DESC, id DESC
      LIMIT ?
    ");
    $stmt->bindValue(1, $like, PDO::PARAM_STR);
    $stmt->bindValue(2, $like, PDO::PARAM_STR);
    $stmt->bindValue(3, $max_results, PDO::PARAM_INT);
    $stmt->execute();
  } else {
    $uid = (int)current_user_id();
    $stmt = $pdo->prepare("
      SELECT DISTINCT pr.id, pr.name, pr.description, pr.created_at, pr.priority
      FROM projects pr
      LEFT JOIN tasks t ON t.project_id = pr.id
      WHERE pr.playbook = 0
        AND pr.archived = 0
        AND (pr.owner_id = ? OR (t.assigned_to = ? AND t.assigned_to IS NOT NULL))
        AND (pr.name LIKE ? OR pr.description LIKE ?)
      ORDER BY pr.created_at DESC, pr.id DESC
      LIMIT ?
    ");
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $uid, PDO::PARAM_INT);
    $stmt->bindValue(3, $like, PDO::PARAM_STR);
    $stmt->bindValue(4, $like, PDO::PARAM_STR);
    $stmt->bindValue(5, $max_results, PDO::PARAM_INT);
    $stmt->execute();
  }
  $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $stmt = $pdo->prepare("
    SELECT id, name, description, created_at
    FROM projects
    WHERE playbook = 1
      AND archived = 0
      AND (name LIKE ? OR description LIKE ?)
    ORDER BY created_at DESC, id DESC
    LIMIT ?
  ");
  $stmt->bindValue(1, $like, PDO::PARAM_STR);
  $stmt->bindValue(2, $like, PDO::PARAM_STR);
  $stmt->bindValue(3, $max_results, PDO::PARAM_INT);
  $stmt->execute();
  $playbooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (is_admin()) {
    $stmt = $pdo->prepare("
      SELECT
        t.id, t.title, t.status, t.priority, t.created_at,
        p.id AS project_id, p.name AS project_name, p.playbook
      FROM tasks t
      JOIN projects p ON p.id = t.project_id
      WHERE p.archived = 0
        AND (t.title LIKE ? OR COALESCE(t.details, '') LIKE ? OR p.name LIKE ?)
      ORDER BY t.created_at DESC, t.id DESC
      LIMIT ?
    ");
    $stmt->bindValue(1, $like, PDO::PARAM_STR);
    $stmt->bindValue(2, $like, PDO::PARAM_STR);
    $stmt->bindValue(3, $like, PDO::PARAM_STR);
    $stmt->bindValue(4, $max_results, PDO::PARAM_INT);
    $stmt->execute();
  } else {
    $uid = (int)current_user_id();
    $stmt = $pdo->prepare("
      SELECT
        t.id, t.title, t.status, t.priority, t.created_at,
        p.id AS project_id, p.name AS project_name, p.playbook
      FROM tasks t
      JOIN projects p ON p.id = t.project_id
      WHERE p.archived = 0
        AND (t.assigned_to = ? OR p.owner_id = ?)
        AND (t.title LIKE ? OR COALESCE(t.details, '') LIKE ? OR p.name LIKE ?)
      ORDER BY t.created_at DESC, t.id DESC
      LIMIT ?
    ");
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $uid, PDO::PARAM_INT);
    $stmt->bindValue(3, $like, PDO::PARAM_STR);
    $stmt->bindValue(4, $like, PDO::PARAM_STR);
    $stmt->bindValue(5, $like, PDO::PARAM_STR);
    $stmt->bindValue(6, $max_results, PDO::PARAM_INT);
    $stmt->execute();
  }
  $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

render_header('Search');
?>
<div class="card">
  <h1 style="margin:0 0 8px;">Search</h1>
  <p class="muted" style="margin:0;">Search projects, playbooks, and tasks.</p>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:center;">
    <input
      type="text"
      name="q"
      value="<?= h($search) ?>"
      placeholder="Search projects, playbooks, tasks..."
      aria-label="Search projects, playbooks, and tasks"
    />
    <button type="submit" class="btn primary">Search</button>
    <?php if ($search !== ''): ?>
      <a class="btn" href="search.php">Clear</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($search === ''): ?>
  <div class="card">
    <div class="muted">Enter a search term to view results.</div>
  </div>
<?php else: ?>
  <div class="card">
    <h2 style="margin-top:0;">Projects</h2>
    <?php if (!$projects): ?>
      <div class="muted">No matching projects found.</div>
    <?php else: ?>
      <table class="table-auto">
        <thead>
          <tr>
            <th>Name</th>
            <th class="col-status">Priority</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $project): ?>
            <tr>
              <td>
                <strong><?= h($project['name']) ?></strong><br>
                <span class="muted"><?= h($project['description'] ?? '') ?></span>
              </td>
              <td class="col-status">
                <span class="badge priority-<?= h($project['priority'] ?? 'medium') ?>">
                  <?= h(ucfirst($project['priority'] ?? 'medium')) ?>
                </span>
              </td>
              <td class="col-actions">
                <a class="btn" href="project_details.php?id=<?= (int)$project['id'] ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 style="margin-top:0;">Playbooks</h2>
    <?php if (!$playbooks): ?>
      <div class="muted">No matching playbooks found.</div>
    <?php else: ?>
      <table class="table-auto">
        <thead>
          <tr>
            <th>Name</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($playbooks as $playbook): ?>
            <tr>
              <td>
                <strong><?= h($playbook['name']) ?></strong><br>
                <span class="muted"><?= h($playbook['description'] ?? '') ?></span>
              </td>
              <td class="col-actions">
                <a class="btn" href="playbook_tasks.php?project_id=<?= (int)$playbook['id'] ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 style="margin-top:0;">Tasks</h2>
    <?php if (!$tasks): ?>
      <div class="muted">No matching tasks found.</div>
    <?php else: ?>
      <table class="table-auto">
        <thead>
          <tr>
            <th>Task</th>
            <th class="col-status">Project</th>
            <th class="col-status">Status</th>
            <th class="col-status">Priority</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tasks as $task): ?>
            <tr>
              <td><strong><?= h($task['title']) ?></strong></td>
              <td class="col-status"><?= h($task['project_name']) ?></td>
              <td class="col-status"><span class="badge <?= h($task['status']) ?>"><?= h($task['status']) ?></span></td>
              <td class="col-status">
                <span class="badge priority-<?= h($task['priority'] ?? 'medium') ?>">
                  <?= h(ucfirst($task['priority'] ?? 'medium')) ?>
                </span>
              </td>
              <td class="col-actions">
                <div class="actions project-actions-inline">
                  <a class="btn" href="task_details.php?id=<?= (int)$task['id'] ?>">Task</a>
                  <?php if ((int)$task['playbook'] === 1): ?>
                    <a class="btn" href="playbook_tasks.php?project_id=<?= (int)$task['project_id'] ?>">Playbook</a>
                  <?php else: ?>
                    <a class="btn" href="project_details.php?id=<?= (int)$task['project_id'] ?>">Project</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
