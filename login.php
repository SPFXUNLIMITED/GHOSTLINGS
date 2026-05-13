<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (!empty($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}

$next = $_GET['next'] ?? '';
$info = $_GET['info'] ?? '';
$errors = [];
$login_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $login_input = trim($_POST['login_input'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  $next = $_POST['next'] ?? '';

  if ($login_input === '' || $password === '') {
    $errors[] = 'Email/username and password are required.';
  } else {
    // Try by email first, then by username
    $stmt = $pdo->prepare(
      "SELECT id, username, email, password_hash, is_admin, role, email_verified
       FROM users WHERE email = ? OR username = ? LIMIT 1"
    );
    $stmt->execute([$login_input, $login_input]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
      $errors[] = 'Invalid credentials.';
    } elseif (!empty($user['email']) && !(bool)$user['email_verified'] && $user['role'] === 'user') {
      // Form-registered users must verify email first
      $errors[] = 'Please verify your email address before logging in. Check your inbox for the verification link.';
    } else {
      session_regenerate_id(true);
      $_SESSION['user_id']      = (int)$user['id'];
      $_SESSION['username']     = $user['username'];
      $role                     = $user['role'] ?? ($user['is_admin'] ? 'admin' : 'user');
      $_SESSION['is_admin']     = ($role === 'admin');
      $_SESSION['is_moderator'] = ($role === 'moderator');
      $_SESSION['role']         = $role;

      // Determine redirect destination
      if (!is_string($next) || $next === '' || str_starts_with($next, 'http')) {
        // Form/regular users go to their user page; admins/moderators go to index
        if ($user['role'] === 'user' && !empty($user['email'])) {
          $next = 'user_page.php';
        } else {
          $next = 'index.php';
        }
      }
      header('Location: ' . $next);
      exit;
    }
  }
}

render_header('Login');
?>
<div class="card" style="max-width:520px; margin: 0 auto;">
  <h1>Login</h1>

  <?php if ($info !== ''): ?>
    <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; margin-bottom:10px;">
      <?= h($info) ?>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert error">
      <ul>
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="next" value="<?= h($next) ?>" />

    <label>Email or Username</label>
    <input name="login_input" value="<?= h($login_input) ?>" autocomplete="username" />

    <label>Password</label>
    <input type="password" name="password" autocomplete="current-password" />

    <div class="row" style="margin-top:12px;">
      <button class="btn primary" type="submit">Sign in</button>
      <a class="btn" href="form.php">Register via Form</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>