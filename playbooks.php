<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require __DIR__ . '/auth.php';
require_login();

$projects = $pdo->query("
  SELECT id, name, description, created_at
  FROM projects
  WHERE playbook = 1 AND archived = 0
  ORDER BY id DESC
")->fetchAll();

render_header('Playbooks');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Playbooks</h1>
    <a class="btn primary" href="project_form.php">+ New Playbook</a>
  </div>
  <p class="muted">Create playbooks, then manage tasks inside each playbook.</p>
</div>

<div class="card">
  <table>
    <thead>
      <tr>
        <th style="width:35%;">
          <button type="button" class="linklike" data-sort-col="name" data-sort-type="text" aria-label="Sort by name">Name</button>
        </th>
        <th>Description</th>
        <th style="width:220px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$projects): ?>
        <tr><td colspan="3" class="muted">No projects yet.</td></tr>
      <?php endif; ?>

      <?php foreach ($projects as $p): ?>
        <tr data-name="<?= h(strtolower($p['name'])) ?>"
            data-created-at="<?= h($p['created_at']) ?>">
          <td>
            <strong><?= h($p['name']) ?></strong><br />
            <span class="muted">
                Playbook #<?= (int)$p['id'] ?> <br>
				<?php
				  $dt = new DateTime($p['created_at']); // parsed from DB (often UTC)
				  $dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
				  echo nl2br(h($dt->format("m-d-Y\ng:i A")));
				?>
            </span>
          </td>
          <td class="col-desc"><?= nl2br(h($p['description'] ?? '')) ?></td>
          <td>
            <div class="actions">
              <a class="btn" href="playbook_tasks.php?project_id=<?= (int)$p['id'] ?>">Tasks</a>
              <a class="btn" href="project_form.php?id=<?= (int)$p['id'] ?>">Edit</a>
              <a class="btn" href="project_archive.php?id=<?= (int)$p['id'] ?>&action=archive"
                 onclick="return confirm('Archive this playbook?');">
                Archive
              </a>
              <a class="btn danger" href="project_delete.php?id=<?= (int)$p['id'] ?>"
                 onclick="return confirm('Delete this project? This also deletes its tasks.');">
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