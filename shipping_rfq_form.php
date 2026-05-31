<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['shipping_rfq_form_csrf'])) {
  $_SESSION['shipping_rfq_form_csrf'] = bin2hex(random_bytes(24));
}

$errors  = [];
$success = '';

$edit_id     = max(0, (int)(($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['edit_id'] ?? 0) : ($_GET['edit_id'] ?? 0)));
$is_edit     = $edit_id > 0;

// China ports of loading (common ones)
$china_ports = [
  'Shanghai, China',
  'Shenzhen, China',
  'Guangzhou, China',
  'Ningbo, China',
  'Tianjin, China',
  'Qingdao, China',
  'Xiamen, China',
  'Dalian, China',
  'Other',
];

// Load user profile defaults
$profile_contact = [
  'contact_name'  => '',
  'company_name'  => '',
  'contact_email' => '',
  'contact_phone' => '',
];
if (current_user_id() !== null) {
  $ps = $pdo->prepare("SELECT username, email, contact_name, company_name, contact_phone FROM users WHERE id = ? LIMIT 1");
  $ps->execute([(int)current_user_id()]);
  $pr = $ps->fetch();
  if ($pr) {
    $cn = trim((string)($pr['contact_name'] ?? ''));
    if ($cn === '') $cn = trim((string)($pr['username'] ?? ''));
    $profile_contact['contact_name']  = $cn;
    $profile_contact['company_name']  = trim((string)($pr['company_name']  ?? ''));
    $profile_contact['contact_email'] = trim((string)($pr['email']         ?? ''));
    $profile_contact['contact_phone'] = trim((string)($pr['contact_phone'] ?? ''));
  }
}

$fields = array_merge([
  'request_title'       => '',
  'machine_model'       => '',
  'machine_weight_kg'   => '',
  'port_of_loading'     => '',
  'port_of_loading_other' => '',
  'destination_type'    => 'port_la',
  'destination_address' => '',
  'shipment_type'       => 'LCL',
  'additional_notes'    => '',
], $profile_contact);

// Default single blank crate row for the form
$crate_rows = [[
  'crate_label'    => '',
  'length_cm'      => '',
  'width_cm'       => '',
  'height_cm'      => '',
  'gross_weight_kg'=> '',
  'quantity'       => '1',
]];

// Load existing record for editing
if ($is_edit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  $es = $pdo->prepare("SELECT * FROM shipping_rfq_requests WHERE id = ? LIMIT 1");
  $es->execute([$edit_id]);
  $er = $es->fetch();
  if (!$er) {
    $errors[] = 'Shipping RFQ not found.';
    $is_edit = false;
    $edit_id = 0;
  } else {
    $pol = (string)($er['port_of_loading'] ?? '');
    $pol_other = '';
    if ($pol !== '' && !in_array($pol, $china_ports, true)) {
      $pol_other = $pol;
      $pol = 'Other';
    }
    $fields = array_merge($fields, [
      'request_title'       => (string)($er['request_title'] ?? ''),
      'machine_model'       => (string)($er['machine_model'] ?? ''),
      'machine_weight_kg'   => $er['machine_weight_kg'] !== null ? rtrim(rtrim((string)$er['machine_weight_kg'], '0'), '.') : '',
      'port_of_loading'     => $pol,
      'port_of_loading_other' => $pol_other,
      'destination_type'    => (string)($er['destination_type'] ?? 'port_la'),
      'destination_address' => (string)($er['destination_address'] ?? ''),
      'shipment_type'       => (string)($er['shipment_type'] ?? 'LCL'),
      'additional_notes'    => (string)($er['additional_notes'] ?? ''),
      'contact_name'        => (string)($er['contact_name'] ?? ''),
      'company_name'        => (string)($er['company_name'] ?? ''),
      'contact_email'       => (string)($er['contact_email'] ?? ''),
      'contact_phone'       => (string)($er['contact_phone'] ?? ''),
    ]);
    // Load existing crate rows
    $cs = $pdo->prepare("SELECT * FROM shipping_rfq_crates WHERE shipping_rfq_id = ? ORDER BY sort_order, id");
    $cs->execute([$edit_id]);
    $existing_crates = $cs->fetchAll();
    if ($existing_crates) {
      $crate_rows = [];
      foreach ($existing_crates as $cr) {
        $crate_rows[] = [
          'crate_label'     => (string)($cr['crate_label'] ?? ''),
          'length_cm'       => $cr['length_cm'] !== null ? rtrim(rtrim((string)$cr['length_cm'], '0'), '.') : '',
          'width_cm'        => $cr['width_cm'] !== null ? rtrim(rtrim((string)$cr['width_cm'], '0'), '.') : '',
          'height_cm'       => $cr['height_cm'] !== null ? rtrim(rtrim((string)$cr['height_cm'], '0'), '.') : '',
          'gross_weight_kg' => $cr['gross_weight_kg'] !== null ? rtrim(rtrim((string)$cr['gross_weight_kg'], '0'), '.') : '',
          'quantity'        => (string)($cr['quantity'] ?? '1'),
        ];
      }
    }
  }
}

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['shipping_rfq_form_csrf']) || !hash_equals((string)$_SESSION['shipping_rfq_form_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  }

  // Collect scalar fields (profile contact is read-only, taken from profile)
  $scalar_keys = ['request_title', 'machine_model', 'machine_weight_kg',
                  'port_of_loading', 'port_of_loading_other',
                  'destination_type', 'destination_address', 'shipment_type', 'additional_notes'];
  foreach ($scalar_keys as $k) {
    $fields[$k] = trim((string)($_POST[$k] ?? ''));
  }
  // Profile fields stay from session/profile
  $fields = array_merge($fields, $profile_contact);

  // Resolve port of loading
  $pol_resolved = $fields['port_of_loading'] === 'Other'
    ? $fields['port_of_loading_other']
    : $fields['port_of_loading'];

  // Parse crate rows from POST arrays
  $crate_labels      = $_POST['crate_label']      ?? [];
  $crate_lengths     = $_POST['length_cm']        ?? [];
  $crate_widths      = $_POST['width_cm']         ?? [];
  $crate_heights     = $_POST['height_cm']        ?? [];
  $crate_weights     = $_POST['gross_weight_kg']  ?? [];
  $crate_quantities  = $_POST['crate_quantity']   ?? [];

  $crate_rows = [];
  $num_crates = max(
    count($crate_labels), count($crate_lengths), count($crate_widths),
    count($crate_heights), count($crate_weights), count($crate_quantities)
  );
  for ($i = 0; $i < $num_crates; $i++) {
    $crate_rows[] = [
      'crate_label'     => trim((string)($crate_labels[$i]     ?? '')),
      'length_cm'       => trim((string)($crate_lengths[$i]    ?? '')),
      'width_cm'        => trim((string)($crate_widths[$i]     ?? '')),
      'height_cm'       => trim((string)($crate_heights[$i]    ?? '')),
      'gross_weight_kg' => trim((string)($crate_weights[$i]    ?? '')),
      'quantity'        => trim((string)($crate_quantities[$i] ?? '1')),
    ];
  }
  // Remove completely empty crate rows
  $crate_rows = array_values(array_filter($crate_rows, function ($c) {
    return $c['crate_label'] !== '' || $c['length_cm'] !== '' || $c['width_cm'] !== ''
        || $c['height_cm'] !== '' || $c['gross_weight_kg'] !== '';
  }));
  if (!$crate_rows) {
    $crate_rows = [['crate_label'=>'','length_cm'=>'','width_cm'=>'','height_cm'=>'','gross_weight_kg'=>'','quantity'=>'1']];
  }

  // Validation
  if ($fields['request_title'] === '') $errors[] = 'Request title is required.';
  if ($fields['machine_model'] === '') $errors[] = 'Machine model is required.';
  if ($fields['machine_weight_kg'] !== '' && (!is_numeric($fields['machine_weight_kg']) || (float)$fields['machine_weight_kg'] <= 0)) {
    $errors[] = 'Machine weight must be a positive number.';
  }
  if ($pol_resolved === '') $errors[] = 'Port of loading is required.';
  if (!in_array($fields['destination_type'], ['port_la', 'door_delivery'], true)) {
    $errors[] = 'Invalid destination type.';
  }
  if ($fields['destination_type'] === 'door_delivery' && $fields['destination_address'] === '') {
    $errors[] = 'Delivery address is required for door delivery.';
  }
  if (!in_array($fields['shipment_type'], ['FCL', 'LCL'], true)) {
    $errors[] = 'Shipment type must be FCL or LCL.';
  }
  if (strlen($fields['additional_notes']) > 5000) $errors[] = 'Additional notes must be 5000 characters or fewer.';

  foreach ($crate_rows as $idx => $cr) {
    $n = $idx + 1;
    if ($cr['length_cm'] !== '' && (!is_numeric($cr['length_cm']) || (float)$cr['length_cm'] <= 0))
      $errors[] = "Crate #$n: length must be a positive number.";
    if ($cr['width_cm'] !== '' && (!is_numeric($cr['width_cm']) || (float)$cr['width_cm'] <= 0))
      $errors[] = "Crate #$n: width must be a positive number.";
    if ($cr['height_cm'] !== '' && (!is_numeric($cr['height_cm']) || (float)$cr['height_cm'] <= 0))
      $errors[] = "Crate #$n: height must be a positive number.";
    if ($cr['gross_weight_kg'] !== '' && (!is_numeric($cr['gross_weight_kg']) || (float)$cr['gross_weight_kg'] <= 0))
      $errors[] = "Crate #$n: gross weight must be a positive number.";
    if (!ctype_digit($cr['quantity']) || (int)$cr['quantity'] < 1)
      $errors[] = "Crate #$n: quantity must be a positive whole number.";
  }

  if ($is_edit) {
    $ex = $pdo->prepare("SELECT id FROM shipping_rfq_requests WHERE id = ? LIMIT 1");
    $ex->execute([$edit_id]);
    if (!$ex->fetch()) $errors[] = 'Shipping RFQ not found.';
  }

  if (!$errors) {
    $machine_weight_val = $fields['machine_weight_kg'] !== '' ? (float)$fields['machine_weight_kg'] : null;
    $dest_addr = $fields['destination_type'] === 'door_delivery' ? $fields['destination_address'] : '';

    if ($is_edit) {
      $stmt = $pdo->prepare(
        "UPDATE shipping_rfq_requests SET
          request_title = ?, machine_model = ?, machine_weight_kg = ?,
          port_of_loading = ?, destination_type = ?, destination_address = ?,
          shipment_type = ?, additional_notes = ?
         WHERE id = ?"
      );
      $stmt->execute([
        $fields['request_title'],
        $fields['machine_model'],
        $machine_weight_val,
        $pol_resolved,
        $fields['destination_type'],
        $dest_addr,
        $fields['shipment_type'],
        $fields['additional_notes'] !== '' ? $fields['additional_notes'] : null,
        $edit_id,
      ]);
      // Rebuild crate rows
      $pdo->prepare("DELETE FROM shipping_rfq_crates WHERE shipping_rfq_id = ?")->execute([$edit_id]);
      $cstmt = $pdo->prepare(
        "INSERT INTO shipping_rfq_crates (shipping_rfq_id, crate_label, length_cm, width_cm, height_cm, gross_weight_kg, quantity, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
      );
      foreach ($crate_rows as $si => $cr) {
        $cstmt->execute([
          $edit_id,
          $cr['crate_label'],
          $cr['length_cm'] !== '' ? (float)$cr['length_cm'] : null,
          $cr['width_cm']  !== '' ? (float)$cr['width_cm']  : null,
          $cr['height_cm'] !== '' ? (float)$cr['height_cm'] : null,
          $cr['gross_weight_kg'] !== '' ? (float)$cr['gross_weight_kg'] : null,
          (int)$cr['quantity'],
          $si,
        ]);
      }
      header('Location: shipping_rfq_tracker.php?rfq_id=' . $edit_id);
      exit;
    } else {
      $stmt = $pdo->prepare(
        "INSERT INTO shipping_rfq_requests
          (requested_by, request_title, machine_model, machine_weight_kg,
           port_of_loading, destination_type, destination_address, shipment_type,
           additional_notes, contact_name, company_name, contact_email, contact_phone)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
      );
      $stmt->execute([
        (int)current_user_id(),
        $fields['request_title'],
        $fields['machine_model'],
        $machine_weight_val,
        $pol_resolved,
        $fields['destination_type'],
        $dest_addr,
        $fields['shipment_type'],
        $fields['additional_notes'] !== '' ? $fields['additional_notes'] : null,
        $fields['contact_name']  !== '' ? $fields['contact_name']  : null,
        $fields['company_name']  !== '' ? $fields['company_name']  : null,
        $fields['contact_email'] !== '' ? $fields['contact_email'] : null,
        $fields['contact_phone'] !== '' ? $fields['contact_phone'] : null,
      ]);
      $new_id = (int)$pdo->lastInsertId();

      // Insert crate rows
      $cstmt = $pdo->prepare(
        "INSERT INTO shipping_rfq_crates (shipping_rfq_id, crate_label, length_cm, width_cm, height_cm, gross_weight_kg, quantity, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
      );
      foreach ($crate_rows as $si => $cr) {
        $cstmt->execute([
          $new_id,
          $cr['crate_label'],
          $cr['length_cm'] !== '' ? (float)$cr['length_cm'] : null,
          $cr['width_cm']  !== '' ? (float)$cr['width_cm']  : null,
          $cr['height_cm'] !== '' ? (float)$cr['height_cm'] : null,
          $cr['gross_weight_kg'] !== '' ? (float)$cr['gross_weight_kg'] : null,
          (int)$cr['quantity'],
          $si,
        ]);
      }

      $_SESSION['shipping_rfq_form_csrf'] = bin2hex(random_bytes(24));
      $success = 'Shipping RFQ submitted. You can now track quotes in the Shipping RFQ Tracker.';
      // Reset form
      $fields = array_merge([
        'request_title' => '', 'machine_model' => '', 'machine_weight_kg' => '',
        'port_of_loading' => '', 'port_of_loading_other' => '',
        'destination_type' => 'port_la', 'destination_address' => '',
        'shipment_type' => 'LCL', 'additional_notes' => '',
      ], $profile_contact);
      $crate_rows = [['crate_label'=>'','length_cm'=>'','width_cm'=>'','height_cm'=>'','gross_weight_kg'=>'','quantity'=>'1']];
    }
  }
}

render_header($is_edit ? ('Edit Shipping RFQ #' . $edit_id) : 'Shipping RFQ Form');
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1><?= $is_edit ? ('Edit Shipping RFQ #' . (int)$edit_id) : 'Shipping RFQ Form' ?></h1>
    <p class="muted">
      <?= $is_edit
        ? 'Update the shipping RFQ request details below.'
        : 'Request freight shipping quotes for machines or cargo from China to Los Angeles (port or door delivery).' ?>
    </p>
  </div>
  <a class="btn" href="shipping_rfq_tracker.php">Shipping RFQ Tracker →</a>
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
    <a href="shipping_rfq_tracker.php" style="margin-left:8px;">Go to Tracker →</a>
  </div>
<?php endif; ?>

<form method="post" class="card" novalidate>
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['shipping_rfq_form_csrf']) ?>" />
  <?php if ($is_edit): ?>
    <input type="hidden" name="edit_id" value="<?= (int)$edit_id ?>" />
  <?php endif; ?>

  <div class="info-banner">
    ℹ️ Contact details are pre-filled from your <a href="user_page.php">profile</a>.
  </div>

  <div class="card" style="margin-bottom:14px; background:var(--surface-alt, #f8f9fa);">
    <h2 class="form-section-heading" style="margin-top:0;">Length Converter</h2>
    <p class="muted" style="margin-top:0;">Convert between meter, centimeter, and feet.</p>
    <div class="form-grid">
      <div>
        <label for="length_meter">Meter (m)</label>
        <input type="number" id="length_meter" min="0" step="any" placeholder="e.g. 2.2" />
      </div>
      <div>
        <label for="length_centimeter">Centimeter (cm)</label>
        <input type="number" id="length_centimeter" min="0" step="any" placeholder="e.g. 220" />
      </div>
      <div>
        <label for="length_feet">Feet (ft)</label>
        <input type="number" id="length_feet" min="0" step="any" placeholder="e.g. 7.22" />
      </div>
    </div>
  </div>

  <h2 class="form-section-heading">Cargo Crate Details</h2>
  <p class="muted" style="margin-top:0;">Enter dimensions and weight for each crate or pallet. Click <strong>+ Add Crate</strong> to add more.</p>

  <div id="crates-container">
    <?php foreach ($crate_rows as $ci => $cr): ?>
    <div class="crate-row card" style="background:var(--surface-alt, #f8f9fa); margin-bottom:10px; padding:12px 14px;">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
        <strong class="crate-num">Crate #<?= $ci + 1 ?></strong>
        <button type="button" class="btn danger remove-crate-btn" style="padding:2px 10px; font-size:0.82em;">✕ Remove</button>
      </div>
      <div class="form-grid">
        <div>
          <label>Crate Label / Description</label>
          <input type="text" name="crate_label[]" maxlength="100"
                 value="<?= h($cr['crate_label']) ?>" placeholder="e.g. Main machine body" />
        </div>
        <div>
          <label>Quantity</label>
          <input type="number" name="crate_quantity[]" min="1" max="999"
                 value="<?= h($cr['quantity'] !== '' ? $cr['quantity'] : '1') ?>" />
        </div>
        <div>
          <label>Length (cm)</label>
          <input type="number" name="length_cm[]" min="0.01" step="0.01"
                 value="<?= h($cr['length_cm']) ?>" placeholder="e.g. 220" />
        </div>
        <div>
          <label>Width (cm)</label>
          <input type="number" name="width_cm[]" min="0.01" step="0.01"
                 value="<?= h($cr['width_cm']) ?>" placeholder="e.g. 160" />
        </div>
        <div>
          <label>Height (cm)</label>
          <input type="number" name="height_cm[]" min="0.01" step="0.01"
                 value="<?= h($cr['height_cm']) ?>" placeholder="e.g. 150" />
        </div>
        <div>
          <label>Gross Weight (kg)</label>
          <input type="number" name="gross_weight_kg[]" min="0.01" step="0.01"
                 value="<?= h($cr['gross_weight_kg']) ?>" placeholder="e.g. 380" />
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="margin-bottom:18px;">
    <button type="button" class="btn" id="add-crate-btn">+ Add Crate</button>
  </div>

  <h2 class="form-section-heading">Shipping Details</h2>
  <div class="form-grid">
    <div>
      <label>Port of Loading <span style="color:var(--d)">*</span></label>
      <select name="port_of_loading" id="port_of_loading" required>
        <option value="">— Select port —</option>
        <?php foreach ($china_ports as $p): ?>
          <option value="<?= h($p) ?>" <?= $fields['port_of_loading'] === $p ? 'selected' : '' ?>><?= h($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div id="port_other_wrap" style="display:<?= $fields['port_of_loading'] === 'Other' ? 'block' : 'none' ?>;">
      <label>Other Port (specify)</label>
      <input type="text" name="port_of_loading_other" maxlength="255"
             value="<?= h($fields['port_of_loading_other']) ?>"
             placeholder="e.g. Foshan, China" />
    </div>
    <div>
      <label>Destination <span style="color:var(--d)">*</span></label>
      <select name="destination_type" id="destination_type" required>
        <option value="port_la"       <?= $fields['destination_type'] === 'port_la'       ? 'selected' : '' ?>>Los Angeles Port (Port of Los Angeles)</option>
        <option value="door_delivery" <?= $fields['destination_type'] === 'door_delivery' ? 'selected' : '' ?>>Door Delivery (specify address)</option>
      </select>
    </div>
    <div id="door_address_wrap" style="display:<?= $fields['destination_type'] === 'door_delivery' ? 'block' : 'none' ?>;">
      <label>Delivery Address</label>
      <input type="text" name="destination_address" maxlength="500"
             value="<?= h($fields['destination_address']) ?>"
             placeholder="Full delivery address including city, state, ZIP" />
    </div>
    <div>
      <label>Shipment Type <span style="color:var(--d)">*</span></label>
      <select name="shipment_type" required>
        <option value="LCL" <?= $fields['shipment_type'] === 'LCL' ? 'selected' : '' ?>>LCL (Less than Container Load)</option>
        <option value="FCL" <?= $fields['shipment_type'] === 'FCL' ? 'selected' : '' ?>>FCL (Full Container Load)</option>
      </select>
    </div>
    <div class="full">
      <label>Additional Notes</label>
      <textarea name="additional_notes" rows="4" maxlength="5000"
                placeholder="Hazmat status, insurance requirements, incoterms preference, packing notes, etc."><?= h($fields['additional_notes']) ?></textarea>
    </div>
  </div>

  <div class="row" style="margin-top:18px;">
    <button type="submit" class="btn primary"><?= $is_edit ? 'Save Changes' : 'Submit Shipping RFQ' ?></button>
    <a class="btn" href="shipping_rfq_tracker.php">Go to Shipping RFQ Tracker</a>
  </div>
</form>

<script>
(function () {
  // Port of loading: show/hide "other" field
  var polSel = document.getElementById('port_of_loading');
  var polOther = document.getElementById('port_other_wrap');
  if (polSel && polOther) {
    polSel.addEventListener('change', function () {
      polOther.style.display = polSel.value === 'Other' ? 'block' : 'none';
    });
  }

  // Destination: show/hide door address
  var destSel = document.getElementById('destination_type');
  var doorWrap = document.getElementById('door_address_wrap');
  if (destSel && doorWrap) {
    destSel.addEventListener('change', function () {
      doorWrap.style.display = destSel.value === 'door_delivery' ? 'block' : 'none';
    });
  }

  // Length converter (meter / centimeter / feet)
  var meterInput = document.getElementById('length_meter');
  var cmInput = document.getElementById('length_centimeter');
  var feetInput = document.getElementById('length_feet');
  var updatingConverter = false;

  function fmtLength(val) {
    return val.toFixed(6).replace(/\.?0+$/, '');
  }

  function setConverterValues(meters) {
    meterInput.value = fmtLength(meters);
    cmInput.value = fmtLength(meters * 100);
    feetInput.value = fmtLength(meters * 3.280839895);
  }

  function clearOtherConverterInputs(source) {
    if (source !== meterInput) meterInput.value = '';
    if (source !== cmInput) cmInput.value = '';
    if (source !== feetInput) feetInput.value = '';
  }

  function bindLengthConverter(input, toMeters) {
    if (!input) return;
    input.addEventListener('input', function () {
      if (updatingConverter) return;
      var raw = input.value.trim();
      if (raw === '') {
        updatingConverter = true;
        clearOtherConverterInputs(input);
        updatingConverter = false;
        return;
      }
      var num = Number(raw);
      if (!Number.isFinite(num)) {
        updatingConverter = true;
        clearOtherConverterInputs(input);
        updatingConverter = false;
        return;
      }

      updatingConverter = true;
      setConverterValues(toMeters(num));
      updatingConverter = false;
    });
  }

  if (meterInput && cmInput && feetInput) {
    bindLengthConverter(meterInput, function (v) { return v; });
    bindLengthConverter(cmInput, function (v) { return v / 100; });
    bindLengthConverter(feetInput, function (v) { return v / 3.280839895; });
  }

  // Dynamic crate row addition/removal
  var container = document.getElementById('crates-container');
  var addBtn    = document.getElementById('add-crate-btn');

  function renumberCrates() {
    var rows = container.querySelectorAll('.crate-row');
    rows.forEach(function (row, i) {
      var lbl = row.querySelector('.crate-num');
      if (lbl) lbl.textContent = 'Crate #' + (i + 1);
    });
  }

  function attachRemove(row) {
    var btn = row.querySelector('.remove-crate-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (container.querySelectorAll('.crate-row').length <= 1) {
        alert('At least one crate row is required.');
        return;
      }
      row.parentNode.removeChild(row);
      renumberCrates();
    });
  }

  // Attach remove to existing rows
  container.querySelectorAll('.crate-row').forEach(function (row) {
    attachRemove(row);
  });

  if (addBtn) {
    addBtn.addEventListener('click', function () {
      var existing = container.querySelectorAll('.crate-row');
      var newIdx = existing.length + 1;
      var div = document.createElement('div');
      div.className = 'crate-row card';
      div.style.cssText = 'background:var(--surface-alt, #f8f9fa); margin-bottom:10px; padding:12px 14px;';
      div.innerHTML =
        '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">' +
          '<strong class="crate-num">Crate #' + newIdx + '</strong>' +
          '<button type="button" class="btn danger remove-crate-btn" style="padding:2px 10px; font-size:0.82em;">\u2715 Remove</button>' +
        '</div>' +
        '<div class="form-grid">' +
          '<div><label>Crate Label / Description</label>' +
            '<input type="text" name="crate_label[]" maxlength="100" placeholder="e.g. Main machine body" /></div>' +
          '<div><label>Quantity</label>' +
            '<input type="number" name="crate_quantity[]" min="1" max="999" value="1" /></div>' +
          '<div><label>Length (cm)</label>' +
            '<input type="number" name="length_cm[]" min="0.01" step="0.01" placeholder="e.g. 220" /></div>' +
          '<div><label>Width (cm)</label>' +
            '<input type="number" name="width_cm[]" min="0.01" step="0.01" placeholder="e.g. 160" /></div>' +
          '<div><label>Height (cm)</label>' +
            '<input type="number" name="height_cm[]" min="0.01" step="0.01" placeholder="e.g. 150" /></div>' +
          '<div><label>Gross Weight (kg)</label>' +
            '<input type="number" name="gross_weight_kg[]" min="0.01" step="0.01" placeholder="e.g. 380" /></div>' +
        '</div>';
      container.appendChild(div);
      attachRemove(div);
    });
  }
})();
</script>

<?php render_footer(); ?>
