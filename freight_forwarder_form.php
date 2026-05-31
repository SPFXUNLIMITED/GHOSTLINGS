<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['freight_forwarder_form_csrf'])) {
  $_SESSION['freight_forwarder_form_csrf'] = bin2hex(random_bytes(24));
}

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;

$errors  = [];
$success = '';

$fields = [
  'company_name'   => '',
  'headquarters'   => '',
  'contact_person' => '',
  'phone'          => '',
  'email'          => '',
  'website'        => '',
  'primary_routes' => '',
  'shipping_modes' => '',
  'certifications' => '',
  'notes'          => '',
];

if ($is_edit) {
  $row = $pdo->prepare("SELECT * FROM freight_forwarders WHERE id = ?");
  $row->execute([$id]);
  $forwarder = $row->fetch();
  if (!$forwarder) {
    http_response_code(404);
    render_header('Freight Forwarder Not Found');
    echo '<div class="card"><p class="muted">Freight forwarder not found.</p><a class="btn" href="freight_forwarders.php">← Back to Freight Forwarders</a></div>';
    render_footer();
    exit;
  }
  foreach ($fields as $k => $_) {
    $fields[$k] = (string)($forwarder[$k] ?? '');
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['freight_forwarder_form_csrf'], $csrf)) {
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
    if (mb_strlen($fields['headquarters']) > 255) {
      $errors[] = 'Headquarters must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['contact_person']) > 255) {
      $errors[] = 'Contact person must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['phone']) > 100) {
      $errors[] = 'Phone must be 100 characters or fewer.';
    }
    if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Email address is not valid.';
    }
    if (mb_strlen($fields['email']) > 255) {
      $errors[] = 'Email must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['website']) > 255) {
      $errors[] = 'Website must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['primary_routes']) > 500) {
      $errors[] = 'Primary routes must be 500 characters or fewer.';
    }
    if (mb_strlen($fields['shipping_modes']) > 255) {
      $errors[] = 'Shipping modes must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['certifications']) > 255) {
      $errors[] = 'Certifications must be 255 characters or fewer.';
    }

    if (!$errors) {
      if ($is_edit) {
        $pdo->prepare("
          UPDATE freight_forwarders SET
            company_name = ?, headquarters = ?, contact_person = ?, phone = ?,
            email = ?, website = ?, primary_routes = ?, shipping_modes = ?,
            certifications = ?, notes = ?
          WHERE id = ?
        ")->execute([
          $fields['company_name'], $fields['headquarters'], $fields['contact_person'],
          $fields['phone'], $fields['email'], $fields['website'],
          $fields['primary_routes'], $fields['shipping_modes'], $fields['certifications'],
          $fields['notes'] !== '' ? $fields['notes'] : null,
          $id,
        ]);
        $success = 'Freight forwarder updated.';
      } else {
        $pdo->prepare("
          INSERT INTO freight_forwarders (
            company_name, headquarters, contact_person, phone, email,
            website, primary_routes, shipping_modes, certifications, notes
          )
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
          $fields['company_name'], $fields['headquarters'], $fields['contact_person'],
          $fields['phone'], $fields['email'], $fields['website'],
          $fields['primary_routes'], $fields['shipping_modes'], $fields['certifications'],
          $fields['notes'] !== '' ? $fields['notes'] : null,
        ]);
        $id = (int)$pdo->lastInsertId();
        $is_edit = true;
        $success = 'Freight forwarder added.';
      }
      $_SESSION['freight_forwarder_form_csrf'] = bin2hex(random_bytes(24));
    }
  }
}

$page_title = $is_edit ? 'Edit Freight Forwarder' : 'Add Freight Forwarder';
render_header($page_title);
?>

<div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
  <h1 style="margin:0;"><?= h($page_title) ?></h1>
  <a class="btn" href="freight_forwarders.php">← Back to Freight Forwarders</a>
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

  <form method="post" action="freight_forwarder_form.php<?= $is_edit ? '?id=' . $id : '' ?>" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['freight_forwarder_form_csrf']) ?>" />

    <div class="form-grid">
      <div>
        <label>Company Name <span style="color:var(--d);">*</span></label>
        <input type="text" name="company_name" maxlength="255" required
               value="<?= h($fields['company_name']) ?>" placeholder="e.g. Pacific Global Logistics" />
      </div>
      <div>
        <label>Headquarters</label>
        <input type="text" name="headquarters" maxlength="255"
               value="<?= h($fields['headquarters']) ?>" placeholder="e.g. Los Angeles, CA" />
      </div>
      <div>
        <label>Contact Person</label>
        <input type="text" name="contact_person" maxlength="255"
               value="<?= h($fields['contact_person']) ?>" placeholder="e.g. Michael Chen" />
      </div>
      <div>
        <label>Phone Number</label>
        <input type="text" name="phone" maxlength="100"
               value="<?= h($fields['phone']) ?>" placeholder="e.g. +1 (555) 123-4567" />
      </div>
      <div>
        <label>Email</label>
        <input type="email" name="email" maxlength="255"
               value="<?= h($fields['email']) ?>" placeholder="e.g. ops@forwarder.com" />
      </div>
      <div>
        <label>Website</label>
        <input type="text" name="website" maxlength="255"
               value="<?= h($fields['website']) ?>" placeholder="e.g. https://forwarder.com" />
      </div>
      <div class="full">
        <label>Primary Routes</label>
        <input type="text" name="primary_routes" maxlength="500"
               value="<?= h($fields['primary_routes']) ?>" placeholder="e.g. China to LA, Taiwan to Long Beach" />
      </div>
      <div>
        <label>Shipping Modes</label>
        <input type="text" name="shipping_modes" maxlength="255"
               value="<?= h($fields['shipping_modes']) ?>" placeholder="e.g. Ocean freight, Air freight, Rail" />
      </div>
      <div>
        <label>Certifications / Strengths</label>
        <input type="text" name="certifications" maxlength="255"
               value="<?= h($fields['certifications']) ?>" placeholder="e.g. CTPAT, FMC licensed, machinery specialist" />
      </div>
      <div class="full">
        <label>Notes</label>
        <textarea name="notes" rows="4" placeholder="e.g. Good with machinery, fast customs clearance…"><?= h($fields['notes']) ?></textarea>
      </div>
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="btn primary"><?= $is_edit ? 'Save Changes' : 'Add Freight Forwarder' ?></button>
    </div>
  </form>
</div>

<?php render_footer(); ?>
