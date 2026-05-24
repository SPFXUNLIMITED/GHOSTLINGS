<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['user_profiles_csrf'])) {
  $_SESSION['user_profiles_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['user_profiles_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $uid = (int)($_POST['uid'] ?? 0);
    $contact_name  = trim((string)($_POST['contact_name'] ?? ''));
    $email         = trim((string)($_POST['email'] ?? ''));
    $company_name  = trim((string)($_POST['company_name'] ?? ''));
    $contact_phone = trim((string)($_POST['contact_phone'] ?? ''));

    if ($uid <= 0) {
      $errors[] = 'Invalid user.';
    }
    if (strlen($contact_name) > 255) {
      $errors[] = 'Contact name must be 255 characters or fewer.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Email must be a valid email address.';
    }
    if (strlen($email) > 255) {
      $errors[] = 'Email must be 255 characters or fewer.';
    }
    if (strlen($company_name) > 255) {
      $errors[] = 'Company name must be 255 characters or fewer.';
    }
    if (strlen($contact_phone) > 100) {
      $errors[] = 'Contact phone must be 100 characters or fewer.';
    }

    if (!$errors && $email !== '') {
      $email_check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
      $email_check->execute([$email, $uid]);
      if ($email_check->fetch()) {
        $errors[] = 'That email is already used by another user.';
      }
    }

    if (!$errors) {
      $upd = $pdo->prepare(
        "UPDATE users
         SET contact_name = ?, email = ?, company_name = ?, contact_phone = ?
         WHERE id = ?"
      );
      $upd->execute([
        $contact_name === '' ? null : $contact_name,
        $email === '' ? null : $email,
        $company_name === '' ? null : $company_name,
        $contact_phone === '' ? null : $contact_phone,
        $uid,
      ]);
      $_SESSION['user_profiles_csrf'] = bin2hex(random_bytes(24));
      $success = 'User profile updated.';
    }
  }
}

$users = $pdo->query(
  "SELECT id, username, role, contact_name, email, company_name, contact_phone
   FROM users
   ORDER BY username ASC"
)->fetchAll();

render_header('User Profiles');
?>

<div class="card">
  <h1 style="margin:0 0 4px;">User Profiles</h1>
  <p class="muted">Manage profile details used to auto-fill the RFQ form.</p>
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

<div class="card">
  <h2 style="margin-top:0;">All User Profiles</h2>
  <div class="table-wrap">
    <table class="table-auto">
      <thead>
        <tr>
          <th>User</th>
          <th>Role</th>
          <th>Contact Name</th>
          <th>Email</th>
          <th>Company Name</th>
          <th>Contact Phone</th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="7" class="muted">No users found.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
          <?php $role = (string)($u['role'] ?? 'user'); ?>
          <?php $form_id = 'profile-form-' . (int)$u['id']; ?>
          <tr>
            <td>
              <strong><?= h((string)$u['username']) ?></strong>
              <?php if ((int)$u['id'] === current_user_id()): ?>
                <span class="badge" style="margin-left:6px;">You</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($role === 'admin'): ?>
                <span class="badge priority-high">Admin</span>
              <?php elseif ($role === 'moderator'): ?>
                <span class="badge priority-medium">Moderator</span>
              <?php else: ?>
                <span class="badge">User</span>
              <?php endif; ?>
            </td>
            <td>
              <input type="text" name="contact_name" form="<?= h($form_id) ?>" maxlength="255"
                     value="<?= h((string)($u['contact_name'] ?? '')) ?>" />
            </td>
            <td>
              <input type="email" name="email" form="<?= h($form_id) ?>" maxlength="255"
                     value="<?= h((string)($u['email'] ?? '')) ?>" />
            </td>
            <td>
              <input type="text" name="company_name" form="<?= h($form_id) ?>" maxlength="255"
                     value="<?= h((string)($u['company_name'] ?? '')) ?>" />
            </td>
            <td>
              <input type="text" name="contact_phone" form="<?= h($form_id) ?>" maxlength="100"
                     value="<?= h((string)($u['contact_phone'] ?? '')) ?>" />
            </td>
            <td class="col-actions">
              <form id="<?= h($form_id) ?>" method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['user_profiles_csrf']) ?>" />
                <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>" />
                <button type="submit" class="btn">Save</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
