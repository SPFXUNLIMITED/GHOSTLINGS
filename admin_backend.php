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
    <a class="btn" href="time_report.php" aria-label="Open the admin time reports page">Time Reports</a>
    <a class="btn" href="users.php" aria-label="Open the admin user management page">Users</a>
  </div>
</div>

<?php render_footer(); ?>
