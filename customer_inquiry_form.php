<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['customer_inquiry_csrf'])) {
  $_SESSION['customer_inquiry_csrf'] = bin2hex(random_bytes(24));
}

$today = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Y-m-d');
$errors = [];
$fields = [
  'customer_name' => '',
  'company_name' => '',
  'phone_number' => '',
  'email' => '',
  'inquiry_date' => $today,
  'notes' => '',
];

$show_all = (string)($_GET['view'] ?? '') === 'all';
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $show_all = false;
  $saved = false;
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['customer_inquiry_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    foreach (array_keys($fields) as $key) {
      $fields[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if ($fields['customer_name'] === '') {
      $errors[] = 'Customer Name is required.';
    } elseif (strlen($fields['customer_name']) > 255) {
      $errors[] = 'Customer Name must be 255 characters or fewer.';
    }

    if ($fields['company_name'] !== '' && strlen($fields['company_name']) > 255) {
      $errors[] = 'Company Name must be 255 characters or fewer.';
    }
    if ($fields['phone_number'] !== '' && strlen($fields['phone_number']) > 50) {
      $errors[] = 'Phone Number must be 50 characters or fewer.';
    }
    if ($fields['email'] !== '' && strlen($fields['email']) > 255) {
      $errors[] = 'Email must be 255 characters or fewer.';
    }
    if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Please enter a valid email address.';
    }
    if ($fields['notes'] !== '' && strlen($fields['notes']) > 10000) {
      $errors[] = 'Notes must be 10000 characters or fewer.';
    }

    $date = DateTime::createFromFormat('Y-m-d', $fields['inquiry_date']);
    if (!$date || $date->format('Y-m-d') !== $fields['inquiry_date']) {
      $errors[] = 'Please provide a valid inquiry date.';
    }

    if (!$errors) {
      $ins = $pdo->prepare(
        "INSERT INTO customer_phone_inquiries
           (customer_name, company_name, phone_number, email, inquiry_date, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
      );
      $ins->execute([
        $fields['customer_name'],
        $fields['company_name'] !== '' ? $fields['company_name'] : null,
        $fields['phone_number'] !== '' ? $fields['phone_number'] : null,
        $fields['email'] !== '' ? $fields['email'] : null,
        $fields['inquiry_date'],
        $fields['notes'] !== '' ? $fields['notes'] : null,
        (int)($_SESSION['user_id'] ?? 0) ?: null,
      ]);

      $_SESSION['customer_inquiry_csrf'] = bin2hex(random_bytes(24));
      header('Location: customer_inquiry_form.php?saved=1');
      exit;
    }
  }
}

$inquiries = [];
if ($show_all) {
  $stmt = $pdo->query(
    "SELECT cpi.*, u.username AS created_by_username
     FROM customer_phone_inquiries cpi
     LEFT JOIN users u ON u.id = cpi.created_by
     ORDER BY cpi.inquiry_date DESC, cpi.id DESC
     LIMIT 200"
  );
  $inquiries = $stmt->fetchAll();
}

render_header('Customer Inquiry Log');
?>

<div class="card">
  <h1 style="margin:0;">Customer Phone Inquiry Log</h1>
  <p class="muted" style="margin:6px 0 0;">Quickly log customers who call asking about machines.</p>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($saved): ?>
  <div class="card" style="max-width:760px; text-align:center;">
    <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; margin-bottom:14px;">
      Inquiry saved successfully.
    </div>
    <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
      <a class="btn primary" href="customer_inquiry_form.php?new=1" style="font-size:16px; padding:12px 18px;">New Inquiry</a>
      <a class="btn" href="customer_inquiry_form.php?view=all" style="font-size:16px; padding:12px 18px;">View All Inquiries</a>
    </div>
  </div>
<?php elseif ($show_all): ?>
  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
      <h2 style="margin:0;">All Customer Inquiries</h2>
      <a class="btn primary" href="customer_inquiry_form.php?new=1">New Inquiry</a>
    </div>
    <div style="overflow-x:auto;">
      <table style="min-width:840px;">
        <thead>
          <tr>
            <th>Date</th>
            <th>Customer Name</th>
            <th>Company Name</th>
            <th>Phone Number</th>
            <th>Email</th>
            <th>Notes / What they want</th>
            <th>Logged By</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$inquiries): ?>
            <tr><td colspan="7" class="muted">No inquiries logged yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($inquiries as $inquiry): ?>
            <tr>
              <td style="white-space:nowrap;"><?= h($inquiry['inquiry_date']) ?></td>
              <td><?= h($inquiry['customer_name']) ?></td>
              <td><?= h($inquiry['company_name'] ?: '—') ?></td>
              <td><?= h($inquiry['phone_number'] ?: '—') ?></td>
              <td><?= h($inquiry['email'] ?: '—') ?></td>
              <td style="white-space:pre-wrap; min-width:280px;"><?= h($inquiry['notes'] ?: '—') ?></td>
              <td><?= h($inquiry['created_by_username'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <form method="post" class="card" style="max-width:960px;">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['customer_inquiry_csrf']) ?>" />
    <div class="form-grid">
      <div>
        <label>Customer Name <span style="color:var(--d)">*</span></label>
        <input type="text" name="customer_name" maxlength="255" required value="<?= h($fields['customer_name']) ?>" />
      </div>
      <div>
        <label>Company Name</label>
        <input type="text" name="company_name" maxlength="255" value="<?= h($fields['company_name']) ?>" />
      </div>
      <div>
        <label>Phone Number</label>
        <input type="text" name="phone_number" maxlength="50" value="<?= h($fields['phone_number']) ?>" />
      </div>
      <div>
        <label>Email</label>
        <input type="email" name="email" maxlength="255" value="<?= h($fields['email']) ?>" />
      </div>
      <div>
        <label>Date of Inquiry</label>
        <input type="date" name="inquiry_date" value="<?= h($fields['inquiry_date']) ?>" />
      </div>
      <div class="full">
        <label>Notes / What they want</label>
        <textarea name="notes" rows="6" maxlength="10000"><?= h($fields['notes']) ?></textarea>
      </div>
    </div>
    <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
      <button type="submit" class="btn primary" style="font-size:18px; padding:14px 22px;">Save Inquiry</button>
      <a class="btn" href="customer_inquiry_form.php?view=all">View All Inquiries</a>
    </div>
  </form>
<?php endif; ?>

<?php render_footer(); ?>
