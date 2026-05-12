<?php
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

render_header('Home');
?>

<div class="card">
  <h1 style="margin-top:0;">Home</h1>
  <p class="muted">Welcome to Project Manager.</p>
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

<?php render_footer(); ?>
