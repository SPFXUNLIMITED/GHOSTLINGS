<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin();

const CR_LABEL_MAX = 100;
const CR_BODY_MAX  = 2000;

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['admin_backend_csrf'])) {
  $_SESSION['admin_backend_csrf'] = bin2hex(random_bytes(24));
}

$section = (string)($_GET['section'] ?? 'overview');
$cr_success = '';
$cr_errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'canned_responses') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['admin_backend_csrf'], $csrf)) {
    $cr_errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    for ($i = 1; $i <= 6; $i++) {
      $lbl  = trim((string)($_POST["cr_label_{$i}"] ?? ''));
      $body = trim((string)($_POST["cr_body_{$i}"] ?? ''));
      if (strlen($lbl) > CR_LABEL_MAX) {
        $cr_errors[] = "Response {$i} label must be " . CR_LABEL_MAX . " characters or fewer.";
      }
      if (strlen($body) > CR_BODY_MAX) {
        $cr_errors[] = "Response {$i} body must be " . CR_BODY_MAX . " characters or fewer.";
      }
    }
    if (!$cr_errors) {
      for ($i = 1; $i <= 6; $i++) {
        $lbl  = trim((string)($_POST["cr_label_{$i}"] ?? ''));
        $body = trim((string)($_POST["cr_body_{$i}"] ?? ''));
        $pdo->prepare(
          "INSERT INTO rfq_canned_responses (slot, label, body) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE label = VALUES(label), body = VALUES(body)"
        )->execute([$i, $lbl, $body]);
      }
      $_SESSION['admin_backend_csrf'] = bin2hex(random_bytes(24));
      $cr_success = 'Canned responses saved.';
    }
  }
}

// Load canned responses when that section is active
$canned = [];
if ($section === 'canned_responses') {
  $rows = $pdo->query(
    "SELECT slot, label, body FROM rfq_canned_responses WHERE slot IN (1,2,3,4,5,6) ORDER BY slot"
  )->fetchAll();
  foreach ($rows as $r) {
    $canned[(int)$r['slot']] = $r;
  }
  for ($i = 1; $i <= 6; $i++) {
    if (!isset($canned[$i])) {
      $canned[$i] = ['slot' => $i, 'label' => '', 'body' => ''];
    }
  }
}

render_header('Admin Backend');
?>

<div class="card">
  <h1 style="margin-top:0;">Admin Backend</h1>
  <p class="muted">Admin-only tools and management pages.</p>
</div>

<div class="admin-layout">

  <div class="admin-left card">
    <a class="menu-link <?= $section === 'overview' ? 'active' : '' ?>" href="admin_backend.php">Overview</a>
    <a class="menu-link" href="time_report.php">Time Reports</a>
    <a class="menu-link" href="users.php">Users</a>
    <a class="menu-link" href="user_profiles.php">User Profiles</a>
    <a class="menu-link <?= $section === 'canned_responses' ? 'active' : '' ?>" href="admin_backend.php?section=canned_responses">Canned Responses</a>
  </div>

  <div class="admin-right">

    <?php if ($section === 'canned_responses'): ?>

      <div class="card">
        <h2 style="margin-top:0;">Canned Responses</h2>
        <p class="muted" style="margin-bottom:16px;">
          These responses appear as quick-fill buttons on the RFQ Form's Additional Notes field.
          Set a button label and the text it will insert.
        </p>

        <?php if ($cr_errors): ?>
          <div class="alert error" style="margin-bottom:14px;">
            <ul style="margin:0; padding-left:18px;">
              <?php foreach ($cr_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($cr_success): ?>
          <div class="alert" style="margin-bottom:14px; border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            <?= h($cr_success) ?>
          </div>
        <?php endif; ?>

        <form method="post" action="admin_backend.php?section=canned_responses" novalidate>
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['admin_backend_csrf']) ?>" />

          <?php for ($i = 1; $i <= 6; $i++): ?>
            <h3 style="margin-top:<?= $i > 1 ? '20px' : '0' ?>; margin-bottom:10px; font-size:.9rem; text-transform:uppercase; letter-spacing:.04em; color:var(--m);">
              Response <?= $i ?>
            </h3>
            <div>
              <label>Button Label</label>
              <input type="text" name="cr_label_<?= $i ?>" maxlength="100"
                     value="<?= h($canned[$i]['label']) ?>"
                     placeholder="e.g. Standard Request" />
            </div>
            <div>
              <label>Response Text</label>
              <textarea name="cr_body_<?= $i ?>" rows="4" maxlength="2000"
                        placeholder="Text to insert into Additional Notes..."><?= h($canned[$i]['body']) ?></textarea>
            </div>
          <?php endfor; ?>

          <div style="margin-top:18px;">
            <button type="submit" class="btn primary">Save Canned Responses</button>
          </div>
        </form>
      </div>

    <?php else: ?>

      <div class="card">
        <h2 style="margin-top:0;">Quick Links</h2>
        <div class="row" style="gap:10px; flex-wrap:wrap;">
          <a class="btn" href="time_report.php">Time Reports</a>
          <a class="btn" href="users.php">Users</a>
          <a class="btn" href="user_profiles.php">User Profiles</a>
          <a class="btn" href="admin_backend.php?section=canned_responses">Canned Responses</a>
        </div>
      </div>

    <?php endif; ?>

  </div>

</div>

<?php render_footer(); ?>
