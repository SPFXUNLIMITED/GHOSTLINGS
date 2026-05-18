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
$files = [];

if ($search !== '') {
  $escaped_search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
  $like = '%' . $escaped_search . '%';

  if (is_admin()) {
    $stmt = $pdo->prepare("
      SELECT id, name, description, created_at, priority
      FROM projects
      WHERE playbook = 0
        AND archived = 0
        AND (name LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\')
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
        AND (pr.name LIKE ? ESCAPE '\\' OR pr.description LIKE ? ESCAPE '\\')
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
      AND (name LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\')
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
        AND (t.title LIKE ? ESCAPE '\\' OR COALESCE(t.details, '') LIKE ? ESCAPE '\\' OR p.name LIKE ? ESCAPE '\\')
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
        AND (t.title LIKE ? ESCAPE '\\' OR COALESCE(t.details, '') LIKE ? ESCAPE '\\' OR p.name LIKE ? ESCAPE '\\')
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

  if (is_admin()) {
    $stmt = $pdo->prepare("
      SELECT
        'project' AS file_scope,
        pu.id AS file_id,
        pu.original_name,
        pu.caption,
        pu.created_at,
        NULL AS task_id,
        NULL AS task_title,
        p.id AS project_id,
        p.name AS project_name
      FROM project_uploads pu
      JOIN projects p ON p.id = pu.project_id
      WHERE p.archived = 0
        AND (
          pu.original_name LIKE ? ESCAPE '\\'
          OR COALESCE(pu.caption, '') LIKE ? ESCAPE '\\'
          OR p.name LIKE ? ESCAPE '\\'
        )
      ORDER BY pu.created_at DESC, pu.id DESC
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
        'project' AS file_scope,
        pu.id AS file_id,
        pu.original_name,
        pu.caption,
        pu.created_at,
        NULL AS task_id,
        NULL AS task_title,
        p.id AS project_id,
        p.name AS project_name
      FROM project_uploads pu
      JOIN projects p ON p.id = pu.project_id
      WHERE p.archived = 0
        AND (
          p.owner_id = ?
          OR EXISTS (
            SELECT 1
            FROM tasks tx
            WHERE tx.project_id = p.id
              AND tx.assigned_to = ?
          )
        )
        AND (
          pu.original_name LIKE ? ESCAPE '\\'
          OR COALESCE(pu.caption, '') LIKE ? ESCAPE '\\'
          OR p.name LIKE ? ESCAPE '\\'
        )
      ORDER BY pu.created_at DESC, pu.id DESC
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
  $project_files = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (is_admin()) {
    $stmt = $pdo->prepare("
      SELECT
        'task' AS file_scope,
        tu.id AS file_id,
        tu.original_name,
        tu.caption,
        tu.created_at,
        t.id AS task_id,
        t.title AS task_title,
        p.id AS project_id,
        p.name AS project_name
      FROM task_uploads tu
      JOIN tasks t ON t.id = tu.task_id
      JOIN projects p ON p.id = t.project_id
      WHERE p.archived = 0
        AND (
          tu.original_name LIKE ? ESCAPE '\\'
          OR COALESCE(tu.caption, '') LIKE ? ESCAPE '\\'
          OR t.title LIKE ? ESCAPE '\\'
          OR p.name LIKE ? ESCAPE '\\'
        )
      ORDER BY tu.created_at DESC, tu.id DESC
      LIMIT ?
    ");
    $stmt->bindValue(1, $like, PDO::PARAM_STR);
    $stmt->bindValue(2, $like, PDO::PARAM_STR);
    $stmt->bindValue(3, $like, PDO::PARAM_STR);
    $stmt->bindValue(4, $like, PDO::PARAM_STR);
    $stmt->bindValue(5, $max_results, PDO::PARAM_INT);
    $stmt->execute();
  } else {
    $uid = (int)current_user_id();
    $stmt = $pdo->prepare("
      SELECT
        'task' AS file_scope,
        tu.id AS file_id,
        tu.original_name,
        tu.caption,
        tu.created_at,
        t.id AS task_id,
        t.title AS task_title,
        p.id AS project_id,
        p.name AS project_name
      FROM task_uploads tu
      JOIN tasks t ON t.id = tu.task_id
      JOIN projects p ON p.id = t.project_id
      WHERE p.archived = 0
        AND (t.assigned_to = ? OR p.owner_id = ?)
        AND (
          tu.original_name LIKE ? ESCAPE '\\'
          OR COALESCE(tu.caption, '') LIKE ? ESCAPE '\\'
          OR t.title LIKE ? ESCAPE '\\'
          OR p.name LIKE ? ESCAPE '\\'
        )
      ORDER BY tu.created_at DESC, tu.id DESC
      LIMIT ?
    ");
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $uid, PDO::PARAM_INT);
    $stmt->bindValue(3, $like, PDO::PARAM_STR);
    $stmt->bindValue(4, $like, PDO::PARAM_STR);
    $stmt->bindValue(5, $like, PDO::PARAM_STR);
    $stmt->bindValue(6, $like, PDO::PARAM_STR);
    $stmt->bindValue(7, $max_results, PDO::PARAM_INT);
    $stmt->execute();
  }
  $task_files = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $files = array_merge($project_files, $task_files);
  usort($files, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
  });
  if (count($files) > $max_results) {
    $files = array_slice($files, 0, $max_results);
  }
}

render_header('Search');
?>
<div class="card">
  <h1 style="margin:0 0 8px;">Search</h1>
  <p class="muted" style="margin:0;">Search projects, playbooks, tasks, and files.</p>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:center;">
    <input
      type="text"
      name="q"
      value="<?= h($search) ?>"
      placeholder="Search projects, playbooks, tasks, files..."
      aria-label="Search projects, playbooks, tasks, and files"
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

  <div class="card">
    <h2 style="margin-top:0;">Files</h2>
    <?php if (!$files): ?>
      <div class="muted">No matching files found.</div>
    <?php else: ?>
      <table class="table-auto">
        <thead>
          <tr>
            <th>File</th>
            <th class="col-status">Location</th>
            <th class="col-status">Uploaded</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($files as $file): ?>
            <?php
              $is_task_file = (string)($file['file_scope'] ?? '') === 'task';
              $open_href = $is_task_file
                ? 'task_details.php?id=' . (int)($file['task_id'] ?? 0) . '#task-files'
                : 'project_details.php?id=' . (int)($file['project_id'] ?? 0) . '#project-files';
            ?>
            <tr>
              <td>
                <strong><?= h($file['original_name'] ?? '') ?></strong><br>
                <?php if (!empty($file['caption'])): ?>
                  <span class="muted"><?= h($file['caption']) ?></span>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td class="col-status">
                <?= h($file['project_name'] ?? '') ?>
                <?php if ($is_task_file): ?>
                  <br><span class="muted">Task: <?= h($file['task_title'] ?? '') ?></span>
                <?php else: ?>
                  <br><span class="muted">Project file</span>
                <?php endif; ?>
              </td>
              <td class="col-status"><?= h($file['created_at'] ?? '') ?></td>
              <td class="col-actions">
                <a class="btn" href="<?= h($open_href) ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
