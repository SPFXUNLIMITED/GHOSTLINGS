<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['vendor_form_csrf'])) {
  $_SESSION['vendor_form_csrf'] = bin2hex(random_bytes(24));
}

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;

$errors  = [];
$success = '';

$fields = [
  'company_name'  => '',
  'contact_name'  => '',
  'email'         => '',
  'phone'         => '',
  'website'       => '',
  'alibaba_store' => '',
  'address'       => '',
  'notes'         => '',
  'rating'        => '',
  'review'        => '',
];

// Load existing record for edits
if ($is_edit) {
  $row = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
  $row->execute([$id]);
  $vendor = $row->fetch();
  if (!$vendor) {
    http_response_code(404);
    render_header('Vendor Not Found');
    echo '<div class="card"><p class="muted">Vendor not found.</p><a class="btn" href="vendors.php">← Back to Vendors</a></div>';
    render_footer();
    exit;
  }
  foreach ($fields as $k => $_) {
    $fields[$k] = (string)($vendor[$k] ?? '');
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['vendor_form_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    foreach ($fields as $k => $_) {
      $fields[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if ($fields['company_name'] === '') {
      $errors[] = 'Company name is required.';
    } elseif (mb_strlen($fields['company_name']) > 255) {
      $errors[] = 'Company name must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['contact_name']) > 255) {
      $errors[] = 'Contact name must be 255 characters or fewer.';
    }
    if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Email address is not valid.';
    }
    if (mb_strlen($fields['email']) > 255) {
      $errors[] = 'Email must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['phone']) > 100) {
      $errors[] = 'Phone must be 100 characters or fewer.';
    }
    if (mb_strlen($fields['website']) > 255) {
      $errors[] = 'Website must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['alibaba_store']) > 255) {
      $errors[] = 'Alibaba Store link must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['address']) > 500) {
      $errors[] = 'Address must be 500 characters or fewer.';
    }
    if ($fields['rating'] !== '' && (!ctype_digit($fields['rating']) || (int)$fields['rating'] < 1 || (int)$fields['rating'] > 5)) {
      $errors[] = 'Rating must be a number between 1 and 5.';
    }

    if (!$errors) {
      if ($is_edit) {
        $pdo->prepare("
          UPDATE vendors SET
            company_name = ?, contact_name = ?, email = ?, phone = ?,
            website = ?, alibaba_store = ?, address = ?, notes = ?,
            rating = ?, review = ?
          WHERE id = ?
        ")->execute([
          $fields['company_name'], $fields['contact_name'], $fields['email'],
          $fields['phone'], $fields['website'], $fields['alibaba_store'],
          $fields['address'],
          $fields['notes'] !== '' ? $fields['notes'] : null,
          $fields['rating'] !== '' ? (int)$fields['rating'] : null,
          $fields['review'] !== '' ? $fields['review'] : null,
          $id,
        ]);
        $success = 'Vendor updated.';
      } else {
        $pdo->prepare("
          INSERT INTO vendors (company_name, contact_name, email, phone, website, alibaba_store, address, notes, rating, review)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
          $fields['company_name'], $fields['contact_name'], $fields['email'],
          $fields['phone'], $fields['website'], $fields['alibaba_store'],
          $fields['address'],
          $fields['notes'] !== '' ? $fields['notes'] : null,
          $fields['rating'] !== '' ? (int)$fields['rating'] : null,
          $fields['review'] !== '' ? $fields['review'] : null,
        ]);
        $id = (int)$pdo->lastInsertId();
        $is_edit = true;
        $success = 'Vendor added.';
      }
      $_SESSION['vendor_form_csrf'] = bin2hex(random_bytes(24));
    }
  }
}

$page_title = $is_edit ? 'Edit Vendor' : 'Add Vendor';
render_header($page_title);
?>

<div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
  <h1 style="margin:0;"><?= h($page_title) ?></h1>
  <a class="btn" href="vendors.php">← Back to Vendors</a>
</div>

<div class="card">
  <?php if ($errors): ?>
    <div class="alert error" style="margin-bottom:14px;">
      <ul style="margin:0; padding-left:18px;">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert" style="margin-bottom:14px; border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
      <?= h($success) ?>
    </div>
  <?php endif; ?>

  <form method="post" action="vendor_form.php<?= $is_edit ? '?id=' . $id : '' ?>" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['vendor_form_csrf']) ?>" />

    <div class="form-grid">
      <div>
        <label>Company Name <span style="color:var(--d);">*</span></label>
        <input type="text" name="company_name" maxlength="255" required
               value="<?= h($fields['company_name']) ?>" placeholder="e.g. Acme Corp" />
      </div>
      <div>
        <label>Contact Name</label>
        <input type="text" name="contact_name" maxlength="255"
               value="<?= h($fields['contact_name']) ?>" placeholder="e.g. Jane Smith" />
      </div>
      <div>
        <label>Email</label>
        <input type="email" name="email" maxlength="255"
               value="<?= h($fields['email']) ?>" placeholder="e.g. jane@acmecorp.com" />
      </div>
      <div>
        <label>Phone</label>
        <input type="text" name="phone" maxlength="100"
               value="<?= h($fields['phone']) ?>" placeholder="e.g. +1 (555) 123-4567" />
      </div>
      <div>
        <label>Website</label>
        <input type="text" name="website" maxlength="255"
               value="<?= h($fields['website']) ?>" placeholder="e.g. https://acmecorp.com" />
      </div>
      <div>
        <label>Alibaba Store</label>
        <input type="text" name="alibaba_store" maxlength="255"
               value="<?= h($fields['alibaba_store']) ?>" placeholder="e.g. https://acmecorp.en.alibaba.com" />
      </div>
      <div>
        <label>Address</label>
        <input type="text" name="address" maxlength="500"
               value="<?= h($fields['address']) ?>" placeholder="e.g. 123 Main St, Springfield, CA 90210" />
      </div>
      <div class="full">
        <label>Notes</label>
        <textarea name="notes" rows="4" placeholder="Any additional notes about this vendor…"><?= h($fields['notes']) ?></textarea>
      </div>
      <div>
        <label>Internal Rating</label>
        <select name="rating">
          <option value="">— No rating —</option>
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <option value="<?= $i ?>"<?= (string)$fields['rating'] === (string)$i ? ' selected' : '' ?>>
              <?= str_repeat('★', $i) . str_repeat('☆', 5 - $i) ?> (<?= $i ?>)
            </option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="full">
        <label>Internal Review / Notes</label>
        <textarea name="review" rows="4" placeholder="Internal review or notes about this vendor's performance…"><?= h($fields['review']) ?></textarea>
      </div>
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="btn primary"><?= $is_edit ? 'Save Changes' : 'Add Vendor' ?></button>
    </div>
  </form>
</div>

<?php render_footer(); ?>
