<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$projects = $pdo->query("
  SELECT id, name, description, created_at, priority, playbook
  FROM projects
  WHERE archived = 1
  ORDER BY id DESC
")->fetchAll();

render_header('Archives');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Archives</h1>
    <a class="btn" href="index.php">← Back to Projects</a>
  </div>
  <p class="muted">Archived projects and playbooks. Use the Unarchive button to restore them.</p>
</div>

<div class="card">
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Type</th>
        <th>Description</th>
        <th class="col-actions">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$projects): ?>
        <tr><td colspan="4" class="muted">No archived projects.</td></tr>
      <?php endif; ?>

      <?php foreach ($projects as $p): ?>
        <?php
          $dt = new DateTime($p['created_at']);
          $dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
          $type = $p['playbook'] ? 'Playbook' : 'Project';
        ?>
        <tr>
          <td>
            <strong><?= h($p['name']) ?></strong><br />
            <span class="muted">
              <?= $type ?> #<?= (int)$p['id'] ?><br>
              <?= nl2br(h($dt->format("m-d-Y\ng:i A"))) ?>
            </span>
          </td>
          <td><span class="badge"><?= h($type) ?></span></td>
          <td class="col-desc"><?= nl2br(h($p['description'] ?? '')) ?></td>
          <td class="col-actions">
            <div class="actions">
              <a class="btn primary"
                 href="project_archive.php?id=<?= (int)$p['id'] ?>&action=unarchive"
                 onclick="return confirm('Restore this <?= h(strtolower($type)) ?> to active?');">
                Unarchive
              </a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
