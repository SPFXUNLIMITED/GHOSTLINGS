<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin();

render_header('Admin Backend');
?>

<div class="card">
  <h1 style="margin-top:0;">Admin Backend</h1>
  <p class="muted">Admin-only tools and management pages.</p>
</div>

<div class="card">
  <div class="row" style="gap:10px; flex-wrap:wrap;">
    <a class="btn" href="time_report.php">Time Reports</a>
    <a class="btn" href="users.php">Users</a>
  </div>
</div>

<?php render_footer(); ?>
