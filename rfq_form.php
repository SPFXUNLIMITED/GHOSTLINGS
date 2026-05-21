<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

const MAX_RFQ_QUANTITY = 1000;

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['rfq_form_csrf'])) {
  $_SESSION['rfq_form_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$success = '';
$fields = [
  'request_title' => '',
  'machine_size' => '',
  'laser_watts' => '',
  'tube_type' => '',
  'quantity' => '1',
  'required_features' => '',
  'additional_notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['rfq_form_csrf']) || !hash_equals((string)$_SESSION['rfq_form_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  }

  foreach ($fields as $k => $_) {
    $fields[$k] = trim((string)($_POST[$k] ?? ''));
  }

  if ($fields['request_title'] === '') $errors[] = 'Request title is required.';
  if ($fields['machine_size'] === '') $errors[] = 'Machine size is required.';
  if ($fields['laser_watts'] === '') $errors[] = 'Laser watts is required.';
  if ($fields['tube_type'] === '') $errors[] = 'Tube type is required.';
  if ($fields['required_features'] === '') $errors[] = 'Required features are required.';

  if (!ctype_digit($fields['quantity']) || (int)$fields['quantity'] < 1 || (int)$fields['quantity'] > MAX_RFQ_QUANTITY) {
    $errors[] = 'Quantity must be a whole number between 1 and ' . MAX_RFQ_QUANTITY . '.';
  }

  if (strlen($fields['required_features']) > 5000) {
    $errors[] = 'Required features must be 5000 characters or fewer.';
  }
  if (strlen($fields['additional_notes']) > 5000) {
    $errors[] = 'Additional notes must be 5000 characters or fewer.';
  }

  if (!$errors) {
    $stmt = $pdo->prepare(
      "INSERT INTO rfq_requests
        (requested_by, request_title, machine_size, laser_watts, tube_type, quantity, required_features, additional_notes)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
      (int)current_user_id(),
      $fields['request_title'],
      $fields['machine_size'],
      $fields['laser_watts'],
      $fields['tube_type'],
      (int)$fields['quantity'],
      $fields['required_features'],
      $fields['additional_notes'] === '' ? null : $fields['additional_notes'],
    ]);

    $_SESSION['rfq_form_csrf'] = bin2hex(random_bytes(24));
    $success = 'RFQ request submitted. You can now track quotes in RFQ Tracker.';
    $fields = [
      'request_title' => '',
      'machine_size' => '',
      'laser_watts' => '',
      'tube_type' => '',
      'quantity' => '1',
      'required_features' => '',
      'additional_notes' => '',
    ];
  }
}

render_header('RFQ Request Form');
?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">CO2 Laser Cutter RFQ Request</h1>
  <p class="muted" style="margin:0;">
    Submit machine specs for purchasing, including size, watts, tube type, and required features.
  </p>
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

<form method="post" class="card" novalidate>
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_form_csrf']) ?>" />

  <div class="form-grid">
    <div class="full">
      <label>RFQ Title <span style="color:var(--d)">*</span></label>
      <input type="text" name="request_title" maxlength="255" required
             value="<?= h($fields['request_title']) ?>"
             placeholder="e.g. 130W CO2 Laser Cutter for Acrylic Production" />
    </div>
    <div>
      <label>Machine Size <span style="color:var(--d)">*</span></label>
      <input type="text" name="machine_size" maxlength="100" required
             value="<?= h($fields['machine_size']) ?>"
             placeholder="e.g. 1300x900mm bed" />
    </div>
    <div>
      <label>Laser Watts <span style="color:var(--d)">*</span></label>
      <input type="text" name="laser_watts" maxlength="50" required
             value="<?= h($fields['laser_watts']) ?>"
             placeholder="e.g. 100W / 130W / 150W" />
    </div>
    <div>
      <label>Tube Type <span style="color:var(--d)">*</span></label>
      <input type="text" name="tube_type" maxlength="100" required
             value="<?= h($fields['tube_type']) ?>"
             placeholder="e.g. RECI W6, Yongli A8" />
    </div>
    <div>
      <label>Quantity <span style="color:var(--d)">*</span></label>
      <input type="number" name="quantity" min="1" max="<?= MAX_RFQ_QUANTITY ?>" required
             value="<?= h($fields['quantity']) ?>" />
    </div>
    <div class="full">
      <label>Required Features <span style="color:var(--d)">*</span></label>
      <textarea name="required_features" rows="5" maxlength="5000" required
                placeholder="List required machine features: chiller type, autofocus, rotary, software support, etc."><?= h($fields['required_features']) ?></textarea>
    </div>
    <div class="full">
      <label>Additional Notes</label>
      <textarea name="additional_notes" rows="4" maxlength="5000"
                placeholder="Any extra details about use case, preferred lead time, certification needs, etc."><?= h($fields['additional_notes']) ?></textarea>
    </div>
  </div>

  <div class="row" style="margin-top:18px;">
    <button type="submit" class="btn primary">Submit RFQ Request</button>
    <a class="btn" href="rfq_tracker.php">Go to RFQ Tracker</a>
  </div>
</form>

<?php render_footer(); ?>
