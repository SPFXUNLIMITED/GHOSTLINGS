<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require __DIR__ . '/auth.php';
require_login();

$per_page   = 15;
$proj_page  = max(1, (int)($_GET['proj_page']  ?? 1));
$task_page  = max(1, (int)($_GET['task_page']  ?? 1));
$proj_offset = ($proj_page - 1) * $per_page;
$task_offset = ($task_page - 1) * $per_page;

if (is_admin()) {
  $proj_total = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE playbook = 0 AND archived = 0")->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT id, name, description, created_at, priority
    FROM projects
    WHERE playbook = 0 AND archived = 0
    ORDER BY created_at DESC, id DESC
    LIMIT :limit OFFSET :offset
  ");
  $stmt->bindValue(':limit',  $per_page,    PDO::PARAM_INT);
  $stmt->bindValue(':offset', $proj_offset, PDO::PARAM_INT);
  $stmt->execute();
  $projects = $stmt->fetchAll();

  $task_total = (int)$pdo->query("
    SELECT COUNT(*)
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE p.playbook = 0 AND p.archived = 0
  ")->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT
      t.id, t.project_id, t.title, t.status, t.due_date, t.created_at, t.priority,
      p.name AS project_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE p.playbook = 0 AND p.archived = 0
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT :limit OFFSET :offset
  ");
  $stmt->bindValue(':limit',  $per_page,    PDO::PARAM_INT);
  $stmt->bindValue(':offset', $task_offset, PDO::PARAM_INT);
  $stmt->execute();
  $recent_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $uid = current_user_id();

  $stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT pr.id)
    FROM projects pr
    LEFT JOIN tasks t ON t.project_id = pr.id
    WHERE pr.playbook = 0 AND pr.archived = 0
      AND (pr.owner_id = ? OR (t.assigned_to = ? AND t.assigned_to IS NOT NULL))
  ");
  $stmt->execute([$uid, $uid]);
  $proj_total = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT DISTINCT pr.id, pr.name, pr.description, pr.created_at, pr.priority
    FROM projects pr
    LEFT JOIN tasks t ON t.project_id = pr.id
    WHERE pr.playbook = 0 AND pr.archived = 0
      AND (pr.owner_id = ? OR (t.assigned_to = ? AND t.assigned_to IS NOT NULL))
    ORDER BY pr.created_at DESC, pr.id DESC
    LIMIT ? OFFSET ?
  ");
  $stmt->bindValue(1, $uid,          PDO::PARAM_INT);
  $stmt->bindValue(2, $uid,          PDO::PARAM_INT);
  $stmt->bindValue(3, $per_page,     PDO::PARAM_INT);
  $stmt->bindValue(4, $proj_offset,  PDO::PARAM_INT);
  $stmt->execute();
  $projects = $stmt->fetchAll();

  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE p.playbook = 0 AND p.archived = 0
      AND t.assigned_to = ?
  ");
  $stmt->execute([$uid]);
  $task_total = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT
      t.id, t.project_id, t.title, t.status, t.due_date, t.created_at, t.priority,
      p.name AS project_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE p.playbook = 0 AND p.archived = 0
      AND t.assigned_to = ?
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT ? OFFSET ?
  ");
  $stmt->bindValue(1, $uid,          PDO::PARAM_INT);
  $stmt->bindValue(2, $per_page,     PDO::PARAM_INT);
  $stmt->bindValue(3, $task_offset,  PDO::PARAM_INT);
  $stmt->execute();
  $recent_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

render_header('Projects');
$project_desc_max_length = 50;
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Projects</h1>
    <a class="btn primary" href="project_form.php">+ New Project</a>
  </div>
  <p class="muted">Create projects, then manage tasks inside each project.</p>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table-auto" data-table-key="projects" data-default-sort-col="createdAt" data-default-sort-dir="desc">
      <thead>
        <tr>
          <th>
            <button type="button" class="linklike" data-sort-col="name" data-sort-type="text" aria-label="Sort projects by name">
              Name
            </button>
          </th>
          <th>Description</th>
          <th class="col-status">
            <button type="button" class="linklike" data-sort-col="createdAt" data-sort-type="date" aria-label="Sort projects by created date">
              Created
            </button>
          </th>
          <th class="col-status">
            <button type="button" class="linklike" data-sort-col="priority" data-sort-type="priority" aria-label="Sort projects by priority">
              Priority
            </button>
          </th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$projects): ?>
          <tr><td colspan="5" class="muted">No projects yet.</td></tr>
        <?php endif; ?>

        <?php foreach ($projects as $p): ?>
          <?php
            $project_description = preg_replace('/\s+/', ' ', (string)($p['description'] ?? ''));
            $project_description = trim($project_description);
            if (mb_strlen($project_description) > $project_desc_max_length) {
              $project_description = mb_substr($project_description, 0, $project_desc_max_length) . '...';
            }
            $project_created = '';
            if (!empty($p['created_at'])) {
              $project_created_dt = new DateTime($p['created_at']);
              $project_created_dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
              $project_created = $project_created_dt->format('m-d-Y g:i A');
            }
            if ($project_created === '') {
              $project_created = '—';
            }
          ?>
          <tr data-name="<?= h(strtolower($p['name'])) ?>"
              data-priority="<?= h($p['priority'] ?? 'medium') ?>"
              data-created-at="<?= h($p['created_at']) ?>">
            <td>
              <strong><?= h($p['name']) ?></strong>
            </td>
            <td class="col-desc"><?= h($project_description) ?></td>
            <td class="col-status"><?= h($project_created) ?></td>
            <td class="col-status"><span class="badge priority-<?= h($p['priority'] ?? 'medium') ?>"><?= h(ucfirst($p['priority'] ?? 'medium')) ?></span></td>
            <td class="col-actions">
              <div class="actions project-actions-inline">
                <a class="btn" href="project_details.php?id=<?= (int)$p['id'] ?>">View</a>
                <a class="btn" href="project_form.php?id=<?= (int)$p['id'] ?>">Edit</a>
                <a class="btn" href="project_archive.php?id=<?= (int)$p['id'] ?>&action=archive"
                   onclick="return confirm('Archive this project?');">
                  Archive
                </a>

              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php render_pagination($proj_page, $proj_total, $per_page, 'proj_page'); ?>
</div>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h2 style="margin:0;">Recent Tasks</h2>
    <span class="muted">Most recent 15</span>
  </div>

  <div class="table-wrap">
    <table class="table-auto" data-table-key="tasks" data-default-sort-col="createdAt" data-default-sort-dir="desc">
      <thead>
        <tr>
          <th>
            <button type="button" class="linklike" data-sort-col="title" data-sort-type="text" aria-label="Sort tasks by title">
              Task
            </button>
          </th>
          <th class="col-status">
            <button type="button" class="linklike" data-sort-col="project" data-sort-type="text" aria-label="Sort tasks by project">
              Project
            </button>
          </th>
          <th class="col-status">
            <button type="button" class="linklike" data-sort-col="createdAt" data-sort-type="date" aria-label="Sort tasks by created date">
              Created
            </button>
          </th>
          <th class="col-status">
            <button type="button" class="linklike" data-sort-col="priority" data-sort-type="priority" aria-label="Sort tasks by priority">
              Priority
            </button>
          </th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent_tasks as $t): ?>
          <?php
            $task_created = '';
            if (!empty($t['created_at'])) {
              $task_created_dt = new DateTime($t['created_at']);
              $task_created_dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
              $task_created = $task_created_dt->format('m-d-Y g:i A');
            }
            if ($task_created === '') {
              $task_created = '—';
            }
          ?>
          <tr data-title="<?= h(strtolower($t['title'])) ?>"
              data-project="<?= h(strtolower($t['project_name'])) ?>"
              data-status="<?= h($t['status']) ?>"
              data-priority="<?= h($t['priority'] ?? 'medium') ?>"
              data-due="<?= h($t['due_date'] ?? '') ?>"
              data-created-at="<?= h($t['created_at']) ?>">
            <td>
              <strong><?= h($t['title']) ?></strong>
            </td>
            <td class="col-desc"><?= h($t['project_name']) ?></td>
            <td class="col-status"><?= h($task_created) ?></td>
            <td class="col-status"><span class="badge priority-<?= h($t['priority'] ?? 'medium') ?>"><?= h(ucfirst($t['priority'] ?? 'medium')) ?></span></td>
            <td class="col-actions">
              <div class="actions project-actions-inline">
                <a class="btn" href="task_details.php?id=<?= (int)$t['id'] ?>">View</a>
                <a class="btn" href="tasks.php?project_id=<?= (int)$t['project_id'] ?>">Project Tasks</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($recent_tasks)): ?>
          <tr><td colspan="5" class="muted">No tasks yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php render_pagination($task_page, $task_total, $per_page, 'task_page'); ?>
</div>

<?php render_footer(); ?>
