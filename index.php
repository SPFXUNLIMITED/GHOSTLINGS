<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
$changeTracker = require __DIR__ . '/change_tracker.php';
require_login();

render_header('Home');
?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">Home</h1>
  <p class="muted" style="margin:0;">Welcome to Project Manager.</p>
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
  <h2 style="margin-top:0; margin-bottom:8px;">Version</h2>
  <p class="muted" style="margin-top:0;">
    v<?= htmlspecialchars((string)($changeTracker['version'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?>
  </p>
  <h3 style="margin-bottom:6px;">Changes</h3>
  <ul style="margin:0; padding-left:18px;">
    <?php foreach (($changeTracker['changes'] ?? []) as $change): ?>
      <li><?= htmlspecialchars((string)$change, ENT_QUOTES, 'UTF-8') ?></li>
    <?php endforeach; ?>
  </ul>
</div>

<?php render_footer(); ?>
