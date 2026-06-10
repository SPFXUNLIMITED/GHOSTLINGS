<?php
require __DIR__ . '/layout.php';

$status = strtolower(trim((string)($_GET['status'] ?? '')));
$is_success = $status === 'success';
$page_title = $is_success ? 'Payment Received' : 'Payment Not Completed';

render_header($page_title);
?>

<div class="card" style="max-width:640px; margin:0 auto;">
  <h1 style="margin-top:0;"><?= h($page_title) ?></h1>

  <?php if ($is_success): ?>
    <p>Thank you. Your payment was submitted securely through Stripe.</p>
    <p class="muted">Stripe will provide the receipt and payment confirmation for this transaction.</p>
  <?php else: ?>
    <p>Your payment was canceled or not completed.</p>
    <p class="muted">You can return to the invoice email at any time and use the Stripe payment link again when you are ready.</p>
  <?php endif; ?>

  <div style="margin-top:16px;">
    <a class="btn" href="login.php">Return to Site</a>
  </div>
</div>

<?php render_footer(); ?>
