<?php
require __DIR__ . '/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (empty($_SESSION['sms_opt_in_csrf'])) {
  $_SESSION['sms_opt_in_csrf'] = bin2hex(random_bytes(24));
}

$consented = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  $action = (string)($_POST['action'] ?? '');

  if (!hash_equals((string)$_SESSION['sms_opt_in_csrf'], $csrf)) {
    $error = 'Session expired. Please refresh and try again.';
  } elseif ($action !== 'agree') {
    $error = 'Please confirm your consent to continue.';
  } else {
    $_SESSION['sms_opt_in_confirmed_at'] = time();
    $consented = true;
  }
}

render_header('SMS Opt-In');
?>
<div class="card" style="max-width:760px; margin:24px auto; padding:28px;">
  <h1 style="margin-top:0;">SMS Opt-In Consent</h1>
  <p style="font-size:16px; line-height:1.6; margin-bottom:10px;">
    We will send you appointment confirmations, updates about your laser repair, and occasional promotions via text message.
  </p>
  <p style="font-size:16px; line-height:1.6; margin:10px 0;">
    Message and data rates may apply.
  </p>
  <p style="font-size:16px; line-height:1.6; margin:10px 0 24px;">
    You can reply STOP to opt out at any time.
  </p>

  <?php if ($consented): ?>
    <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; margin-bottom:16px;">
      Thank you. Your SMS consent has been recorded for this session.
    </div>
  <?php elseif ($error !== ''): ?>
    <div class="alert error" style="margin-bottom:16px;"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['sms_opt_in_csrf']) ?>">
    <input type="hidden" name="action" value="agree">
    <button type="submit" class="btn primary" style="padding:10px 20px; font-size:16px;">I Agree</button>
  </form>
</div>
<?php render_footer(); ?>
