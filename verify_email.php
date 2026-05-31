<?php
/**
 * verify_email.php – Email verification endpoint.
 * Validates the token from the verification email and marks the user's email as verified.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$token = trim($_GET['token'] ?? '');
$error = '';
$success = false;

if ($token === '') {
  $error = 'No verification token provided.';
} else {
  $stmt = $pdo->prepare(
    "SELECT id, email_verified, token_expires FROM users
     WHERE verification_token = ? LIMIT 1"
  );
  $stmt->execute([$token]);
  $user = $stmt->fetch();

  if (!$user) {
    $error = 'Invalid or already-used verification link.';
  } elseif ((int)$user['email_verified'] === 1) {
    // Already verified — just redirect to login
    header('Location: login.php?info=' . urlencode('Your email is already verified. Please log in.'));
    exit;
  } elseif ($user['token_expires'] !== null) {
    $tz = new DateTimeZone(APP_TZ);
    $expires = new DateTime($user['token_expires'], $tz);
    $now     = new DateTime('now', $tz);
    if ($now > $expires) {
      $error = 'Your verification link has expired. Please submit the form again or contact support.';
    }
  }

  if (!$error) {
    $upd = $pdo->prepare(
      "UPDATE users SET email_verified = 1, verification_token = NULL, token_expires = NULL
       WHERE id = ?"
    );
    $upd->execute([(int)$user['id']]);
    $success = true;
  }
}

render_header('Email Verification');
?>

<div class="card" style="max-width:560px; margin: 0 auto; text-align:center;">
  <?php if ($success): ?>
    <h1 style="margin-top:0; color:#166534;">✅ Email Verified!</h1>
    <p>Your email address has been successfully verified.</p>
    <p>You can now log in using your email address and the password that was emailed to you.</p>
    <div class="row" style="justify-content:center; margin-top:18px;">
      <a class="btn primary" href="login.php">Log In Now</a>
    </div>
  <?php else: ?>
    <h1 style="margin-top:0; color:#991b1b;">Verification Failed</h1>
    <p class="alert error"><?= h($error) ?></p>
    <div class="row" style="justify-content:center; margin-top:18px;">
      <a class="btn" href="service_request_form.php">Back to Form</a>
      <a class="btn" href="login.php">Log In</a>
    </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
