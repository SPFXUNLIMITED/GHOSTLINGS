<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require __DIR__ . '/auth.php';
require_login();

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if (!$project_id) { header('Location: playbooks.php'); exit; }

$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); exit('Project not found'); }

$stmt = $pdo->prepare("SELECT * FROM tasks WHERE project_id = ? ORDER BY id DESC");
$stmt->execute([$project_id]);
$tasks = $stmt->fetchAll();

render_header('Playbook Tasks');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div>
      <h1 style="margin:0;">Playbook Tasks</h1>
      <div class="muted">Playbook: <strong><?= h($project['name']) ?></strong> (ID <?= (int)$project_id ?>)</div>
    </div>
    <div class="actions">
      <a class="btn" href="playbooks.php">Back to Playbooks</a>
      <a class="btn primary" href="playbook_task_form.php?project_id=<?= (int)$project_id ?>">+ New Task</a>
    </div>
  </div>
</div>

<div class="card">
  <table>
    <thead>
      <tr>
        <th style="width:30%;">
          <button type="button" class="linklike" data-sort-col="title" data-sort-type="text" aria-label="Sort by title">Title</button>
        </th>
        <th style="width:14%;">
          <button type="button" class="linklike" data-sort-col="status" data-sort-type="status" aria-label="Sort by status">Status</button>
        </th>
        <th>Details</th>
        <th style="width:220px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$tasks): ?>
        <tr><td colspan="4" class="muted">No tasks yet.</td></tr>
      <?php endif; ?>

      <?php foreach ($tasks as $t): ?>
        <tr data-title="<?= h(strtolower($t['title'])) ?>"
            data-status="<?= h($t['status']) ?>"
            data-created-at="<?= h($t['created_at'] ?? '') ?>">
          <td><strong><?= h($t['title']) ?></strong></td>
          <td><span class="badge <?= h($t['status']) ?>"><?= h($t['status']) ?></span></td>
          <td><?= $t['details'] ?? '' ?></td>
          <td>
            <div class="actions">
              <a class="btn" href="playbook_task_form.php?project_id=<?= (int)$project_id ?>&id=<?= (int)$t['id'] ?>">Edit</a>
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