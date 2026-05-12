<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
$trackerData = require __DIR__ . '/change_tracker.php';
$changeTracker = is_array($trackerData) ? $trackerData : [];
$version = isset($changeTracker['version']) && is_scalar($changeTracker['version']) ? (string)$changeTracker['version'] : 'unknown';
$changes = [];
if (isset($changeTracker['changes']) && is_array($changeTracker['changes'])) {
    $changes = array_values(array_filter($changeTracker['changes'], 'is_scalar'));
}
require_login();

render_header('Home');
?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">Home</h1>
  <p class="muted" style="margin:0;">Welcome to Project Manager.</p>
</div>

<div class="card">
  <h2 style="margin-top:0; margin-bottom:8px;">Version</h2>
  <p class="muted" style="margin-top:0;">
    v<?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8') ?>
  </p>
  <h3 style="margin-bottom:6px;">Changes</h3>
  <ul style="margin:0; padding-left:18px;">
    <?php foreach ($changes as $change): ?>
      <li><?= htmlspecialchars((string)$change, ENT_QUOTES, 'UTF-8') ?></li>
    <?php endforeach; ?>
  </ul>
</div>

<?php render_footer(); ?>
