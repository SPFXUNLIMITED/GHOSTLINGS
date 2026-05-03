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

$next = $_GET['next'] ?? 'index.php';
$errors = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  $next = $_POST['next'] ?? 'index.php';

  if ($username === '' || $password === '') {
    $errors[] = 'Username and password are required.';
  } else {
    $stmt = $pdo->prepare("SELECT id, username, password_hash, is_admin FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
      $errors[] = 'Invalid credentials.';
    } else {
      session_regenerate_id(true);
      $_SESSION['user_id'] = (int)$user['id'];
      $_SESSION['username'] = $user['username'];
      $_SESSION['is_admin'] = (bool)$user['is_admin'];

      // Basic safety: allow only relative redirects
      if (!is_string($next) || $next === '' || str_starts_with($next, 'http')) {
        $next = 'index.php';
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

  <?php if ($errors): ?>
    <div class="alert error">
      <ul>
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="next" value="<?= h($next) ?>" />

    <label>Username</label>
    <input name="username" value="<?= h($username) ?>" autocomplete="username" />

    <label>Password</label>
    <input type="password" name="password" autocomplete="current-password" />

    <div class="row" style="margin-top:12px;">
      <button class="btn primary" type="submit">Sign in</button>
      <a class="btn" href="index.php">Cancel</a>
    </div>

    <p class="muted" style="margin-top:10px;">
      Single-user system: create exactly one row in <code>users</code>.
    </p>
  </form>
</div>
<?php render_footer(); ?>