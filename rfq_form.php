<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

const MAX_RFQ_QUANTITY = 1000;
const REQUEST_TYPES = ['RFQ', 'Sourcing'];
const REQUEST_CATEGORIES = ['machine', 'parts'];

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['rfq_form_csrf'])) {
  $_SESSION['rfq_form_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$success = '';
$forced_request_category = null;
if (isset($rfq_form_mode) && is_string($rfq_form_mode)) {
  $mode = strtolower(trim($rfq_form_mode));
  if (in_array($mode, REQUEST_CATEGORIES, true)) {
    $forced_request_category = $mode;
  }
}
$query_category = strtolower(trim((string)($_GET['request_category'] ?? '')));
if ($forced_request_category === null && in_array($query_category, REQUEST_CATEGORIES, true)) {
  $forced_request_category = $query_category;
}
$is_parts_entrypoint = $forced_request_category === 'parts';
$fields = [
  'request_category'=> $forced_request_category ?? 'machine',
  'request_type'    => 'RFQ',
  'acquisition_purpose' => 'customer',
  'contact_name'    => '',
  'company_name'    => '',
  'contact_email'   => '',
  'contact_phone'   => '',
  'buyer_name'      => '',
  'buyer_company'   => '',
  'buyer_email'     => '',
  'buyer_phone'     => '',
  'request_title'   => '',
  'machine_size'    => '',
  'laser_watts'     => '',
  'tube_type'       => '',
  'part_category'   => '',
  'part_specs'      => '',
  'quantity'        => '1',
  'required_features' => '',
  'additional_notes'  => '',
];
$profile_contact_fields = [
  'contact_name'  => '',
  'company_name'  => '',
  'contact_email' => '',
  'contact_phone' => '',
];

// Load canned responses for quick-fill buttons
$canned_responses = $pdo->query(
  "SELECT slot, label, body FROM rfq_canned_responses WHERE slot IN (1,2,3,4,5,6) AND label != '' AND body != '' ORDER BY slot"
)->fetchAll();

if (current_user_id() !== null) {
  $profile_stmt = $pdo->prepare(
    "SELECT username, email, contact_name, company_name, contact_phone
     FROM users
     WHERE id = ?
     LIMIT 1"
  );
  $profile_stmt->execute([(int)current_user_id()]);
  $profile = $profile_stmt->fetch();
  if ($profile) {
    $profile_contact_fields['contact_name'] = trim((string)($profile['contact_name'] ?? ''));
    if ($profile_contact_fields['contact_name'] === '') {
      $profile_contact_fields['contact_name'] = trim((string)($profile['username'] ?? ''));
    }
    $profile_contact_fields['company_name'] = trim((string)($profile['company_name'] ?? ''));
    $profile_contact_fields['contact_email'] = trim((string)($profile['email'] ?? ''));
    $profile_contact_fields['contact_phone'] = trim((string)($profile['contact_phone'] ?? ''));
  }
}
$fields = array_merge($fields, $profile_contact_fields);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['rfq_form_csrf']) || !hash_equals((string)$_SESSION['rfq_form_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  }

  foreach ($fields as $k => $_) {
    if (array_key_exists($k, $profile_contact_fields)) {
      continue;
    }
    $fields[$k] = trim((string)($_POST[$k] ?? ''));
  }
  $fields = array_merge($fields, $profile_contact_fields);
  if ($forced_request_category !== null) {
    $fields['request_category'] = $forced_request_category;
  }

  if (!in_array($fields['request_type'], REQUEST_TYPES, true)) {
    $errors[] = 'Request type must be RFQ or Sourcing.';
  }
  if (!in_array($fields['request_category'], REQUEST_CATEGORIES, true)) {
    $errors[] = 'Request category must be Machine or Parts.';
  }
  if (!in_array($fields['acquisition_purpose'], ['customer', 'internal'], true)) {
    $errors[] = 'Acquisition purpose must be Customer Request or Internal Use.';
  }
  if ($fields['request_title'] === '') $errors[] = 'Request title is required.';
  if ($fields['request_category'] === 'machine') {
    if ($fields['machine_size'] === '') $errors[] = 'Machine size is required for machine requests.';
    if ($fields['laser_watts'] === '') $errors[] = 'Laser watts is required for machine requests.';
    if ($fields['tube_type'] === '') $errors[] = 'Tube type is required for machine requests.';
    if ($fields['required_features'] === '') $errors[] = 'Required features are required for machine requests.';
  } else {
    if ($fields['part_category'] === '') $errors[] = 'Part category is required for parts requests.';
    if ($fields['part_specs'] === '') $errors[] = 'Part specs are required for parts requests.';
  }
  if ($fields['contact_email'] !== '' && !filter_var($fields['contact_email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Contact email must be a valid email address.';
  }
  if ($fields['buyer_email'] !== '' && !filter_var($fields['buyer_email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Customer email must be a valid email address.';
  }

  if (!ctype_digit($fields['quantity']) || (int)$fields['quantity'] < 1 || (int)$fields['quantity'] > MAX_RFQ_QUANTITY) {
    $errors[] = 'Quantity must be a whole number between 1 and ' . MAX_RFQ_QUANTITY . '.';
  }

  if (strlen($fields['required_features']) > 5000) {
    $errors[] = 'Required features must be 5000 characters or fewer.';
  }
  if (strlen($fields['part_specs']) > 5000) {
    $errors[] = 'Part specs must be 5000 characters or fewer.';
  }
  if (strlen($fields['part_category']) > 100) {
    $errors[] = 'Part category must be 100 characters or fewer.';
  }
  if (strlen($fields['additional_notes']) > 5000) {
    $errors[] = 'Additional notes must be 5000 characters or fewer.';
  }

  if (!$errors) {
    $full_request_title = $fields['request_type'] . ': ' . $fields['request_title'];

    $stmt = $pdo->prepare(
      "INSERT INTO rfq_requests
        (
          requested_by, request_category, acquisition_purpose, contact_name, company_name, contact_email, contact_phone,
          buyer_name, buyer_company, buyer_email, buyer_phone,
          request_title, machine_size, laser_watts, tube_type, part_category, part_specs,
          quantity, required_features, additional_notes
        )
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
      (int)current_user_id(),
      $fields['request_category'],
      $fields['acquisition_purpose'],
      $fields['contact_name']  === '' ? null : $fields['contact_name'],
      $fields['company_name']  === '' ? null : $fields['company_name'],
      $fields['contact_email'] === '' ? null : $fields['contact_email'],
      $fields['contact_phone'] === '' ? null : $fields['contact_phone'],
      $fields['buyer_name']    === '' ? null : $fields['buyer_name'],
      $fields['buyer_company'] === '' ? null : $fields['buyer_company'],
      $fields['buyer_email']   === '' ? null : $fields['buyer_email'],
      $fields['buyer_phone']   === '' ? null : $fields['buyer_phone'],
      $full_request_title,
      $fields['request_category'] === 'machine' ? $fields['machine_size'] : null,
      $fields['request_category'] === 'machine' ? $fields['laser_watts'] : null,
      $fields['request_category'] === 'machine' ? $fields['tube_type'] : null,
      $fields['request_category'] === 'parts' ? $fields['part_category'] : null,
      $fields['request_category'] === 'parts' ? $fields['part_specs'] : null,
      (int)$fields['quantity'],
      $fields['request_category'] === 'machine' ? $fields['required_features'] : null,
      $fields['additional_notes'] === '' ? null : $fields['additional_notes'],
    ]);

    $_SESSION['rfq_form_csrf'] = bin2hex(random_bytes(24));
    $success = $fields['request_type'] . ' request submitted. You can now track quotes in RFQ Tracker.';
    $fields = [
      'request_category'=> $forced_request_category ?? 'machine',
      'request_type'    => 'RFQ',
      'acquisition_purpose' => 'customer',
      'contact_name'    => '',
      'company_name'    => '',
      'contact_email'   => '',
      'contact_phone'   => '',
      'buyer_name'      => '',
      'buyer_company'   => '',
      'buyer_email'     => '',
      'buyer_phone'     => '',
      'request_title'   => '',
      'machine_size'    => '',
      'laser_watts'     => '',
      'tube_type'       => '',
      'part_category'   => '',
      'part_specs'      => '',
      'quantity'        => '1',
      'required_features' => '',
      'additional_notes'  => '',
    ];
    $fields = array_merge($fields, $profile_contact_fields);
  }
}

render_header($is_parts_entrypoint ? 'Parts RFQ / Sourcing Request Form' : 'RFQ / Sourcing Request Form');
?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;"><?= $is_parts_entrypoint ? 'CO2 Laser Parts RFQ / Sourcing Requests' : 'RFQ / Sourcing Requests' ?></h1>
  <p class="muted" style="margin:0;">
    Submit either machine RFQs or parts sourcing requests (chillers, blowers, laser tubes, and more) in one workflow.
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

  <p class="muted" style="margin-top:0; margin-bottom:16px;">
    Company and contact details are pulled from your <a href="user_page.php">profile</a>.
  </p>
  <h2 style="margin-top:0; margin-bottom:12px; font-size:1rem; text-transform:uppercase; letter-spacing:.04em; color:var(--muted, #6b7280);">Request Details</h2>

  <div class="form-grid">
    <div>
      <?php if ($forced_request_category !== null): ?>
        <label>Request Category</label>
        <input type="hidden" id="request_category" name="request_category" value="<?= h($fields['request_category']) ?>" />
        <input type="text" value="<?= $fields['request_category'] === 'parts' ? 'Parts' : 'Machine' ?>" disabled />
      <?php else: ?>
        <label>Request Category <span style="color:var(--d)">*</span></label>
        <select name="request_category" id="request_category" required>
          <option value="machine" <?= $fields['request_category'] === 'machine' ? 'selected' : '' ?>>Machine</option>
          <option value="parts" <?= $fields['request_category'] === 'parts' ? 'selected' : '' ?>>Parts</option>
        </select>
      <?php endif; ?>
    </div>
    <div>
      <label>Request Type <span style="color:var(--d)">*</span></label>
      <select name="request_type" required>
        <?php foreach (REQUEST_TYPES as $request_type): ?>
          <option value="<?= h($request_type) ?>" <?= $fields['request_type'] === $request_type ? 'selected' : '' ?>><?= h($request_type) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Acquisition Purpose <span style="color:var(--d)">*</span></label>
      <select name="acquisition_purpose" id="acquisition_purpose" required>
        <option value="customer" <?= $fields['acquisition_purpose'] === 'customer' ? 'selected' : '' ?>>Customer Request</option>
        <option value="internal" <?= $fields['acquisition_purpose'] === 'internal' ? 'selected' : '' ?>>Internal Use (Inventory / Repairs)</option>
      </select>
    </div>
  </div>

  <div id="customer_information_section" style="margin-top:12px; display:<?= $fields['acquisition_purpose'] === 'customer' ? 'block' : 'none' ?>;">
    <h2 style="margin-top:0; margin-bottom:12px; font-size:1rem; text-transform:uppercase; letter-spacing:.04em; color:var(--muted, #6b7280);">Customer Information</h2>
    <div class="form-grid" style="margin-bottom:12px;">
      <div>
        <label>Customer Name</label>
        <input type="text" name="buyer_name" maxlength="255"
               value="<?= h($fields['buyer_name']) ?>" <?= $fields['acquisition_purpose'] === 'customer' ? '' : 'disabled' ?> />
      </div>
      <div>
        <label>Customer Company</label>
        <input type="text" name="buyer_company" maxlength="255"
               value="<?= h($fields['buyer_company']) ?>" <?= $fields['acquisition_purpose'] === 'customer' ? '' : 'disabled' ?> />
      </div>
      <div>
        <label>Customer Email</label>
        <input type="email" name="buyer_email" maxlength="255"
               value="<?= h($fields['buyer_email']) ?>" <?= $fields['acquisition_purpose'] === 'customer' ? '' : 'disabled' ?> />
      </div>
      <div>
        <label>Customer Phone</label>
        <input type="text" name="buyer_phone" maxlength="100"
               value="<?= h($fields['buyer_phone']) ?>" <?= $fields['acquisition_purpose'] === 'customer' ? '' : 'disabled' ?> />
      </div>
    </div>
  </div>

  <div class="form-grid">
    <div class="full">
      <label>Request Title <span style="color:var(--d)">*</span></label>
      <input type="text" name="request_title" maxlength="255" required
             value="<?= h($fields['request_title']) ?>"
             placeholder="e.g. 130W CO2 Laser Cutter for Acrylic Production" />
    </div>
    <div class="machine-only">
      <label>Machine Size <span style="color:var(--d)">*</span></label>
      <input type="text" name="machine_size" maxlength="100" data-required-on="machine"
             value="<?= h($fields['machine_size']) ?>"
             placeholder="e.g. 1300x900mm bed" />
    </div>
    <div class="machine-only">
      <label>Laser Watts <span style="color:var(--d)">*</span></label>
      <input type="text" name="laser_watts" maxlength="50" data-required-on="machine"
             value="<?= h($fields['laser_watts']) ?>"
             placeholder="e.g. 100W / 130W / 150W" />
    </div>
    <div class="machine-only">
      <label>Tube Type <span style="color:var(--d)">*</span></label>
      <input type="text" name="tube_type" maxlength="100" data-required-on="machine"
             value="<?= h($fields['tube_type']) ?>"
             placeholder="e.g. RECI W6, Yongli A8" />
    </div>
    <div class="parts-only">
      <label>Part Category <span style="color:var(--d)">*</span></label>
      <input type="text" name="part_category" maxlength="100" data-required-on="parts"
             value="<?= h($fields['part_category']) ?>"
             placeholder="e.g. Chiller, Blower, Laser Tube, Lens Set" />
    </div>
    <div>
      <label>Quantity <span style="color:var(--d)">*</span></label>
      <input type="number" name="quantity" min="1" max="<?= MAX_RFQ_QUANTITY ?>" required
             value="<?= h($fields['quantity']) ?>" />
    </div>
    <div class="full machine-only">
      <label>Required Features <span style="color:var(--d)">*</span></label>
      <textarea name="required_features" rows="5" maxlength="5000" data-required-on="machine"
               placeholder="List required machine features: chiller type, autofocus, rotary, software support, etc."><?= h($fields['required_features']) ?></textarea>
    </div>
    <div class="full parts-only">
      <label>Part Specs <span style="color:var(--d)">*</span></label>
      <textarea name="part_specs" rows="5" maxlength="5000" data-required-on="parts"
               placeholder="List brand/model compatibility, voltage, dimensions, connector type, and any other required part specs."><?= h($fields['part_specs']) ?></textarea>
    </div>
    <div class="full">
      <label>Additional Notes</label>
      <?php if ($canned_responses): ?>
      <div style="margin-bottom:6px; display:flex; gap:8px; flex-wrap:wrap;">
        <?php foreach ($canned_responses as $cr): ?>
        <button type="button" class="btn"
                data-canned-body="<?= h($cr['body']) ?>"
        ><?= h($cr['label']) ?></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <textarea name="additional_notes" rows="4" maxlength="5000"
                placeholder="Any extra details about use case, preferred lead time, certification needs, etc."><?= h($fields['additional_notes']) ?></textarea>
      <?php if ($canned_responses): ?>
      <script>
        (function () {
          var notes = document.querySelector('[name=additional_notes]');
          document.querySelectorAll('[data-canned-body]').forEach(function (btn) {
            btn.addEventListener('click', function () {
              notes.value = btn.getAttribute('data-canned-body');
            });
          });
        })();
      </script>
      <?php endif; ?>
    </div>
  </div>

  <div class="row" style="margin-top:18px;">
    <button type="submit" class="btn primary">Submit Request</button>
    <a class="btn" href="<?= $fields['request_category'] === 'parts' ? 'rfq_form.php?request_category=machine' : 'rfq_form.php?request_category=parts' ?>">
      <?= $fields['request_category'] === 'parts' ? 'Switch to Machine RFQ Form' : 'Switch to Parts RFQ Form' ?>
    </a>
    <a class="btn" href="rfq_tracker.php">Go to RFQ Tracker</a>
  </div>
</form>

<script>
  (function () {
    var categoryField = document.getElementById('request_category');
    var acquisitionField = document.getElementById('acquisition_purpose');
    var customerInfoSection = document.getElementById('customer_information_section');
    var customerInfoInputs = customerInfoSection ? customerInfoSection.querySelectorAll('input') : [];
    if (acquisitionField && customerInfoSection) {
      function toggleCustomerInfo() {
        var showCustomerInfo = acquisitionField.value === 'customer';
        customerInfoSection.style.display = showCustomerInfo ? 'block' : 'none';
        customerInfoInputs.forEach(function (input) {
          input.disabled = !showCustomerInfo;
        });
      }
      acquisitionField.addEventListener('change', toggleCustomerInfo);
      toggleCustomerInfo();
    }
    if (!categoryField) return;
    var machineFields = document.querySelectorAll('.machine-only');
    var partsFields = document.querySelectorAll('.parts-only');
    function toggleSections() {
      var isParts = categoryField.value === 'parts';
      machineFields.forEach(function (el) { el.style.display = isParts ? 'none' : ''; });
      partsFields.forEach(function (el) { el.style.display = isParts ? '' : 'none'; });
      document.querySelectorAll('[data-required-on]').forEach(function (input) {
        input.required = input.getAttribute('data-required-on') === categoryField.value;
      });
    }
    categoryField.addEventListener('change', toggleSections);
    toggleSections();
  })();
</script>

<?php render_footer(); ?>
