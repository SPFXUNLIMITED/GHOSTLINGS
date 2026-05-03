<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin();

$errors   = [];
$success  = '';
$action   = $_POST['action'] ?? '';

// ── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // ADD USER
  if ($action === 'add') {
    $new_username = trim($_POST['new_username'] ?? '');
    $new_password = (string)($_POST['new_password'] ?? '');
    $new_password2 = (string)($_POST['new_password2'] ?? '');
    $new_is_admin  = !empty($_POST['new_is_admin']) ? 1 : 0;

    if ($new_username === '') {
      $errors[] = 'Username is required.';
    } elseif (strlen($new_username) > 64) {
      $errors[] = 'Username must be 64 characters or fewer.';
    } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]*[A-Za-z0-9]$|^[A-Za-z0-9]$/', $new_username)
              || str_contains($new_username, '..')) {
      $errors[] = 'Username may only contain letters, numbers, underscores, hyphens, and dots; it must start and end with a letter or number and must not contain consecutive dots.';
    } elseif (strlen($new_password) < 6) {
      $errors[] = 'Password must be at least 6 characters.';
    } elseif ($new_password !== $new_password2) {
      $errors[] = 'Passwords do not match.';
    } else {
      // Check duplicate
      $ck = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
      $ck->execute([$new_username]);
      if ($ck->fetch()) {
        $errors[] = 'That username is already taken.';
      } else {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $ins  = $pdo->prepare("INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, ?)");
        $ins->execute([$new_username, $hash, $new_is_admin]);
        $success = 'User "' . htmlspecialchars($new_username, ENT_QUOTES, 'UTF-8') . '" created successfully.';
      }
    }
  }

  // CHANGE PASSWORD
  elseif ($action === 'change_password') {
    $uid  = (int)($_POST['uid'] ?? 0);
    $pw1  = (string)($_POST['password1'] ?? '');
    $pw2  = (string)($_POST['password2'] ?? '');

    if ($uid <= 0) {
      $errors[] = 'Invalid user.';
    } elseif (strlen($pw1) < 6) {
      $errors[] = 'Password must be at least 6 characters.';
    } elseif ($pw1 !== $pw2) {
      $errors[] = 'Passwords do not match.';
    } else {
      $hash = password_hash($pw1, PASSWORD_DEFAULT);
      $upd  = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
      $upd->execute([$hash, $uid]);
      $success = 'Password updated.';
    }
  }

  // TOGGLE ADMIN
  elseif ($action === 'toggle_admin') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid <= 0) {
      $errors[] = 'Invalid user.';
    } elseif ($uid === current_user_id()) {
      $errors[] = 'You cannot change your own admin status.';
    } else {
      $row = $pdo->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
      $row->execute([$uid]);
      $target = $row->fetch();
      if (!$target) {
        $errors[] = 'User not found.';
      } else {
        $new_admin = $target['is_admin'] ? 0 : 1;
        $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?")->execute([$new_admin, $uid]);
        $success = 'Admin status updated.';
      }
    }
  }

  // DELETE USER
  elseif ($action === 'delete') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid <= 0) {
      $errors[] = 'Invalid user.';
    } elseif ($uid === current_user_id()) {
      $errors[] = 'You cannot delete your own account.';
    } else {
      // Clear assigned_to references before deleting the user
      $pdo->prepare("UPDATE tasks SET assigned_to = NULL WHERE assigned_to = ?")->execute([$uid]);
      $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
      $success = 'User deleted.';
    }
  }
}

// ── Fetch all users ──────────────────────────────────────────────────────────
$users = $pdo->query("SELECT id, username, is_admin FROM users ORDER BY id ASC")->fetchAll();

render_header('User Management');
?>

<div class="card">
  <h1 style="margin:0 0 4px;">User Management</h1>
  <p class="muted">Add users, change passwords, and manage administrator access.</p>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
    <?= h($success) ?>
  </div>
<?php endif; ?>

<!-- ── User list ──────────────────────────────────────────────────────────── -->
<div class="card">
  <h2 style="margin-top:0;">All Users</h2>
  <div class="table-wrap">
    <table class="table-auto">
      <thead>
        <tr>
          <th>ID</th>
          <th>Username</th>
          <th>Role</th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="4" class="muted">No users found.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
          <tr>
            <td class="muted"><?= (int)$u['id'] ?></td>
            <td>
              <strong><?= h($u['username']) ?></strong>
              <?php if ((int)$u['id'] === current_user_id()): ?>
                <span class="badge" style="margin-left:6px;">You</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($u['is_admin']): ?>
                <span class="badge priority-high">Admin</span>
              <?php else: ?>
                <span class="badge">User</span>
              <?php endif; ?>
            </td>
            <td class="col-actions">
              <div class="actions">
                <!-- Change password button triggers inline form -->
                <button type="button" class="btn"
                  onclick="togglePasswordForm(<?= (int)$u['id'] ?>)">
                  Change Password
                </button>

                <!-- Toggle admin -->
                <?php if ((int)$u['id'] !== current_user_id()): ?>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_admin">
                    <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                    <button type="submit" class="btn">
                      <?= $u['is_admin'] ? 'Revoke Admin' : 'Make Admin' ?>
                    </button>
                  </form>

                  <!-- Delete -->
                  <form method="post" style="display:inline;"
                    onsubmit="return confirm('Delete user <?= h($u['username']) ?>? This cannot be undone.');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                    <button type="submit" class="btn danger">Delete</button>
                  </form>
                <?php endif; ?>
              </div>

              <!-- Inline change-password form (hidden by default) -->
              <div id="pwform-<?= (int)$u['id'] ?>" style="display:none; margin-top:10px;">
                <form method="post">
                  <input type="hidden" name="action" value="change_password">
                  <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                  <div class="form-grid" style="max-width:400px;">
                    <div>
                      <label>New Password</label>
                      <input type="password" name="password1" autocomplete="new-password" required minlength="6" />
                    </div>
                    <div>
                      <label>Confirm Password</label>
                      <input type="password" name="password2" autocomplete="new-password" required minlength="6" />
                    </div>
                    <div class="full">
                      <div class="row" style="margin-top:6px;">
                        <button type="submit" class="btn primary">Save Password</button>
                        <button type="button" class="btn"
                          onclick="togglePasswordForm(<?= (int)$u['id'] ?>)">Cancel</button>
                      </div>
                    </div>
                  </div>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Add new user ───────────────────────────────────────────────────────── -->
<div class="card">
  <h2 style="margin-top:0;">Add New User</h2>
  <form method="post" style="max-width:480px;">
    <input type="hidden" name="action" value="add">

    <label>Username</label>
    <input type="text" name="new_username" value="<?= h($_POST['new_username'] ?? '') ?>"
           autocomplete="off" required maxlength="64" />

    <label>Password</label>
    <input type="password" name="new_password" autocomplete="new-password" required minlength="6" />

    <label>Confirm Password</label>
    <input type="password" name="new_password2" autocomplete="new-password" required minlength="6" />

    <label style="display:flex; align-items:center; gap:8px; margin-top:12px; cursor:pointer;">
      <input type="checkbox" name="new_is_admin" value="1"
             style="width:auto; padding:0;"
             <?= !empty($_POST['new_is_admin']) ? 'checked' : '' ?> />
      Grant administrator access
    </label>

    <div class="row" style="margin-top:14px;">
      <button type="submit" class="btn primary">Create User</button>
    </div>
  </form>
</div>

<script>
function togglePasswordForm(uid) {
  var el = document.getElementById('pwform-' + uid);
  if (el) {
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
  }
}
</script>

<?php render_footer(); ?>
