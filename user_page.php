<?php
/**
 * user_page.php – Authenticated user's personal page.
 * Provides a profile details form for updating contact information.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

// ── Profile details CSRF ──────────────────────────────────────────────────────
if (empty($_SESSION['user_page_profile_csrf'])) {
  $_SESSION['user_page_profile_csrf'] = bin2hex(random_bytes(24));
}

$profile_errors  = [];
$profile_success = '';

// ── Profile details POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['user_page_profile_csrf'], $csrf)) {
    $profile_errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $p_contact_name = trim((string)($_POST['contact_name'] ?? ''));
    $p_email        = trim((string)($_POST['profile_email'] ?? ''));
    $p_phone        = trim((string)($_POST['contact_phone'] ?? ''));
    $p_company      = trim((string)($_POST['company_name'] ?? ''));
    $p_delivery_address = trim((string)($_POST['delivery_address'] ?? ''));
    $p_notes        = trim((string)($_POST['profile_notes'] ?? ''));

    if (strlen($p_contact_name) > 255) {
      $profile_errors[] = 'Name must be 255 characters or fewer.';
    }
    if ($p_email !== '' && !filter_var($p_email, FILTER_VALIDATE_EMAIL)) {
      $profile_errors[] = 'Email must be a valid email address.';
    }
    if (strlen($p_email) > 255) {
      $profile_errors[] = 'Email must be 255 characters or fewer.';
    }
    if (strlen($p_phone) > 100) {
      $profile_errors[] = 'Phone must be 100 characters or fewer.';
    }
    if (strlen($p_company) > 255) {
      $profile_errors[] = 'Company name must be 255 characters or fewer.';
    }
    if (strlen($p_delivery_address) > 500) {
      $profile_errors[] = 'Delivery address must be 500 characters or fewer.';
    }

    if (empty($profile_errors) && $p_email !== '') {
      $ck = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
      $ck->execute([$p_email, (int)$_SESSION['user_id']]);
      if ($ck->fetch()) {
        $profile_errors[] = 'That email is already used by another account.';
      }
    }

    if (empty($profile_errors)) {
      $upd = $pdo->prepare(
        "UPDATE users
         SET contact_name = ?, email = ?, contact_phone = ?, company_name = ?, delivery_address = ?, profile_notes = ?
         WHERE id = ?"
      );
      $upd->execute([
        $p_contact_name === '' ? null : $p_contact_name,
        $p_email        === '' ? null : $p_email,
        $p_phone        === '' ? null : $p_phone,
        $p_company      === '' ? null : $p_company,
        $p_delivery_address === '' ? null : $p_delivery_address,
        $p_notes        === '' ? null : $p_notes,
        (int)$_SESSION['user_id'],
      ]);
      $_SESSION['user_page_profile_csrf'] = bin2hex(random_bytes(24));
      $profile_success = 'Your profile details have been saved.';
    }
  }
}

// ── Fetch the current user's full record
$stmt = $pdo->prepare(
  "SELECT id, username, email, role,
          contact_name, contact_phone, company_name, delivery_address, profile_notes
   FROM users
   WHERE id = ? LIMIT 1"
);
$stmt->execute([(int)$_SESSION['user_id']]);
$data = $stmt->fetch();

if (!$data) {
  http_response_code(404);
  exit('User not found.');
}

render_header('My Profile');
?>

<!-- ── Profile details alerts ─────────────────────────────────────────────── -->
<?php if ($profile_errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($profile_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($profile_success): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
    <?= h($profile_success) ?>
  </div>
<?php endif; ?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">My Profile</h1>
  <p class="muted" style="margin:0;">Logged in as <strong><?= h($data['username']) ?></strong></p>
</div>

<!-- ── My Profile Details ────────────────────────────────────────────────── -->
<div class="card">
  <h2 style="margin-top:0;">My Profile Details</h2>
  <p class="muted">Update your contact information used across the site.</p>
  <form method="post" style="max-width:540px;">
    <input type="hidden" name="action" value="update_profile">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['user_page_profile_csrf']) ?>">

    <div class="form-grid">
      <div>
        <label>Full Name</label>
        <input type="text" name="contact_name" maxlength="255"
               value="<?= h((string)($data['contact_name'] ?? '')) ?>"
               placeholder="Your full name" />
      </div>

      <div>
        <label>Email Address</label>
        <input type="email" name="profile_email" maxlength="255"
               value="<?= h((string)($data['email'] ?? '')) ?>"
               placeholder="you@example.com" />
      </div>

      <div>
        <label>Phone Number</label>
        <input type="text" name="contact_phone" maxlength="100"
               value="<?= h((string)($data['contact_phone'] ?? '')) ?>"
               placeholder="e.g. (555) 123-4567" />
      </div>

      <div>
        <label>Company / Organization</label>
        <input type="text" name="company_name" maxlength="255"
               value="<?= h((string)($data['company_name'] ?? '')) ?>"
               placeholder="Your company name" />
      </div>

      <div class="full">
        <label>Delivery Address</label>
        <textarea name="delivery_address" rows="3" maxlength="500"
                  placeholder="Address to prefill for purchase orders"><?= h((string)($data['delivery_address'] ?? '')) ?></textarea>
      </div>

      <div class="full">
        <label>Additional Notes</label>
        <textarea name="profile_notes" rows="4"
                  placeholder="Any additional details about yourself or your business…"><?= h((string)($data['profile_notes'] ?? '')) ?></textarea>
        <div class="muted" style="margin-top:6px;">
          Available placeholders: <code>[contact_name]</code> <code>[company_name]</code> <code>[email]</code> <code>[contact_phone]</code> <code>[username]</code>.
        </div>
      </div>

      <div class="full">
        <div class="row" style="margin-top:6px;">
          <button type="submit" class="btn primary">Save Details</button>
        </div>
      </div>
    </div>
  </form>
</div>

<?php render_footer(); ?>
