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
        <th style="width:18%;">
          <button type="button" class="linklike" data-sort-col="title" data-sort-type="text" aria-label="Sort by title">Title</button>
        </th>
        <th>Assigned To</th>
        <th>Details</th>
        <th style="width:160px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$tasks): ?>
        <tr><td colspan="4" class="muted">No tasks yet.</td></tr>
      <?php endif; ?>

      <?php foreach ($tasks as $t): ?>
        <tr data-title="<?= h(strtolower($t['title'])) ?>"
            data-created-at="<?= h($t['created_at'] ?? '') ?>">
          <td>
            <strong><?= h($t['title']) ?></strong><br>
            <?php $count = (int)($t['upload_count'] ?? 0); ?>
            <a class="muted" href="task_uploads.php?task_id=<?= (int)$t['id'] ?>">Files (<?= $count ?>)</a>
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
              <a class="btn" href="playbook_task_form.php?project_id=<?= (int)$project_id ?>&id=<?= (int)$t['id'] ?>">Edit</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>