<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['customer_form_csrf'])) {
  $_SESSION['customer_form_csrf'] = bin2hex(random_bytes(24));
}

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;

$errors = [];
$fields = [
  'first_name' => '',
  'last_name' => '',
  'company' => '',
  'phone' => '',
  'email' => '',
  'address' => '',
  'city' => '',
  'state' => '',
  'zip' => '',
  'country' => '',
];

if ($is_edit) {
  $row = $pdo->prepare("SELECT id, first_name, last_name, company, phone, email, address, city, state, zip, country FROM customers WHERE id = ?");
  $row->execute([$id]);
  $customer = $row->fetch(PDO::FETCH_ASSOC);
  if (!$customer) {
    http_response_code(404);
    render_header('Customer Not Found');
    echo '<div class="card"><p class="muted">Customer not found.</p><a class="btn" href="customers.php">← Back to Customers</a></div>';
    render_footer();
    exit;
  }
  foreach ($fields as $k => $_) {
    $fields[$k] = trim((string)($customer[$k] ?? ''));
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['customer_form_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    foreach ($fields as $k => $_) {
      $fields[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if (mb_strlen($fields['first_name']) > 255) {
      $errors[] = 'First name must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['last_name']) > 255) {
      $errors[] = 'Last name must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['company']) > 255) {
      $errors[] = 'Company must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['phone']) > 100) {
      $errors[] = 'Phone must be 100 characters or fewer.';
    }
    if (mb_strlen($fields['email']) > 255) {
      $errors[] = 'Email must be 255 characters or fewer.';
    }
    if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Email address is not valid.';
    }
    if (mb_strlen($fields['address']) > 255) {
      $errors[] = 'Address must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['city']) > 100) {
      $errors[] = 'City must be 100 characters or fewer.';
    }
    if (mb_strlen($fields['state']) > 100) {
      $errors[] = 'State must be 100 characters or fewer.';
    }
    if (mb_strlen($fields['zip']) > 20) {
      $errors[] = 'ZIP must be 20 characters or fewer.';
    }
    if (mb_strlen($fields['country']) > 100) {
      $errors[] = 'Country must be 100 characters or fewer.';
    }

    if (!$errors) {
      if ($is_edit) {
        $pdo->prepare("
          UPDATE customers
          SET first_name = ?, last_name = ?, company = ?, phone = ?, email = ?,
              address = ?, city = ?, state = ?, zip = ?, country = ?
          WHERE id = ?
        ")->execute([
          $fields['first_name'],
          $fields['last_name'],
          $fields['company'],
          $fields['phone'],
          $fields['email'],
          $fields['address'],
          $fields['city'],
          $fields['state'],
          $fields['zip'],
          $fields['country'],
          $id,
        ]);
        $_SESSION['customer_form_csrf'] = bin2hex(random_bytes(24));
        header('Location: customers.php?updated=1');
        exit;
      }

      $hubspot_contact_id = 'manual_' . bin2hex(random_bytes(10));
      $pdo->prepare("
        INSERT INTO customers (hubspot_contact_id, first_name, last_name, company, phone, email, address, city, state, zip, country, last_updated)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
      ")->execute([
        $hubspot_contact_id,
        $fields['first_name'],
        $fields['last_name'],
        $fields['company'],
        $fields['phone'],
        $fields['email'],
        $fields['address'],
        $fields['city'],
        $fields['state'],
        $fields['zip'],
        $fields['country'],
      ]);
      $_SESSION['customer_form_csrf'] = bin2hex(random_bytes(24));
      header('Location: customers.php?created=1');
      exit;
    }
  }
}

$page_title = $is_edit ? 'Edit Customer' : 'Add New Customer';
render_header($page_title);
?>

<div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
  <h1 style="margin:0;"><?= h($page_title) ?></h1>
  <a class="btn" href="customers.php">← Back to Customers</a>
</div>

<div class="card">
  <?php if ($errors): ?>
    <div class="alert error" style="margin-bottom:14px;">
      <ul style="margin:0; padding-left:18px;">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="customer_form.php<?= $is_edit ? '?id=' . $id : '' ?>" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['customer_form_csrf']) ?>" />

    <div class="form-grid">
      <div>
        <label>First Name</label>
        <input type="text" name="first_name" maxlength="255" value="<?= h($fields['first_name']) ?>" />
      </div>
      <div>
        <label>Last Name</label>
        <input type="text" name="last_name" maxlength="255" value="<?= h($fields['last_name']) ?>" />
      </div>
      <div>
        <label>Company</label>
        <input type="text" name="company" maxlength="255" value="<?= h($fields['company']) ?>" />
      </div>
      <div>
        <label>Phone</label>
        <input type="text" name="phone" maxlength="100" value="<?= h($fields['phone']) ?>" />
      </div>
      <div class="full">
        <label>Email</label>
        <input type="email" name="email" maxlength="255" value="<?= h($fields['email']) ?>" />
      </div>
      <div class="full">
        <label>Street Address</label>
        <input type="text" name="address" maxlength="255" value="<?= h($fields['address']) ?>" />
      </div>
      <div>
        <label>City</label>
        <input type="text" name="city" maxlength="100" value="<?= h($fields['city']) ?>" />
      </div>
      <div>
        <label>State / Region</label>
        <input type="text" name="state" maxlength="100" value="<?= h($fields['state']) ?>" />
      </div>
      <div>
        <label>ZIP / Postal Code</label>
        <input type="text" name="zip" maxlength="20" value="<?= h($fields['zip']) ?>" />
      </div>
      <div>
        <label>Country</label>
        <input type="text" name="country" maxlength="100" value="<?= h($fields['country']) ?>" />
      </div>
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="btn primary"><?= $is_edit ? 'Save Changes' : 'Add Customer' ?></button>
    </div>
  </form>
</div>

<?php render_footer(); ?>
