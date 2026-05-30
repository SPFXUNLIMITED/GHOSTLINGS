<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

if (empty($_SESSION['app_request_form_csrf'])) {
  $_SESSION['app_request_form_csrf'] = bin2hex(random_bytes(24));
}

$type_labels = [
  'bug' => 'Bug Report',
  'software_change' => 'Software Change Request',
  'feature_request' => 'Feature Request',
];

$priority_labels = [
  'low' => 'Low',
  'medium' => 'Medium',
  'high' => 'High',
];

$errors = [];
$success = '';
$fields = [
  'request_type' => 'bug',
  'request_title' => '',
  'request_details' => '',
  'priority' => 'medium',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['app_request_form_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    foreach (array_keys($fields) as $key) {
      $fields[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if (!isset($type_labels[$fields['request_type']])) {
      $errors[] = 'Please select a valid request type.';
    }
    if ($fields['request_title'] === '') {
      $errors[] = 'Title is required.';
    } elseif (strlen($fields['request_title']) > 255) {
      $errors[] = 'Title must be 255 characters or fewer.';
    }
    if ($fields['request_details'] === '') {
      $errors[] = 'Details are required.';
    } elseif (strlen($fields['request_details']) > 8000) {
      $errors[] = 'Details must be 8000 characters or fewer.';
    }
    if (!isset($priority_labels[$fields['priority']])) {
      $errors[] = 'Please select a valid priority.';
    }

    if (!$errors) {
      $ins = $pdo->prepare(
        "INSERT INTO app_requests
           (requested_by, request_type, request_title, request_details, priority)
         VALUES (?, ?, ?, ?, ?)"
      );
      $ins->execute([
        (int)$_SESSION['user_id'],
        $fields['request_type'],
        $fields['request_title'],
        $fields['request_details'],
        $fields['priority'],
      ]);

      $_SESSION['app_request_form_csrf'] = bin2hex(random_bytes(24));
      $fields['request_title'] = '';
      $fields['request_details'] = '';
      $fields['priority'] = 'medium';
      $fields['request_type'] = 'bug';
      $success = 'Your request has been submitted.';
    }
  }
}

render_header('Submit App Request');
?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">Bug / Change / Feature Request</h1>
  <p class="muted" style="margin:0;">
    Use this form to report bugs or request software changes and new features.
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

<form method="post" class="card" style="max-width:900px;">
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['app_request_form_csrf']) ?>" />

  <div class="form-grid">
    <div>
      <label>Request Type <span style="color:var(--d)">*</span></label>
      <select name="request_type" required>
        <?php foreach ($type_labels as $type => $label): ?>
          <option value="<?= h($type) ?>" <?= $fields['request_type'] === $type ? 'selected' : '' ?>>
            <?= h($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label>Priority <span style="color:var(--d)">*</span></label>
      <select name="priority" required>
        <?php foreach ($priority_labels as $priority => $label): ?>
          <option value="<?= h($priority) ?>" <?= $fields['priority'] === $priority ? 'selected' : '' ?>>
            <?= h($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="full">
      <label>Title <span style="color:var(--d)">*</span></label>
      <input type="text" name="request_title" maxlength="255" required
             value="<?= h($fields['request_title']) ?>"
             placeholder="Short summary of the bug or request" />
    </div>

    <div class="full">
      <label>Details <span style="color:var(--d)">*</span></label>
      <textarea name="request_details" rows="8" maxlength="8000" required
                placeholder="Include steps to reproduce (for bugs), current behavior, and expected outcome."><?= h($fields['request_details']) ?></textarea>
      <p class="muted" style="margin:4px 0 0;">Max 8000 characters.</p>
    </div>
  </div>

  <div class="row" style="margin-top:14px;">
    <button type="submit" class="btn primary">Submit Request</button>
  </div>
</form>

<?php render_footer(); ?>
