<?php
/**
 * machine_inquiry_edit.php – Admin edit form for a single Machine Inquiry submission.
 * Accessible to admins and moderators only.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (empty($_SESSION['mie_csrf'])) {
  $_SESSION['mie_csrf'] = bin2hex(random_bytes(24));
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(404);
  render_header('Inquiry Not Found');
  echo '<div class="card"><p class="muted">Inquiry not found.</p><a class="btn" href="machine_inquiry_admin.php?section=inquiries">← Back to Inquiries</a></div>';
  render_footer();
  exit;
}

// US states list
$us_states = [
  'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California',
  'CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','FL'=>'Florida','GA'=>'Georgia',
  'HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa',
  'KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland',
  'MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri',
  'MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey',
  'NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio',
  'OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina',
  'SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont',
  'VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming',
  'DC'=>'District of Columbia',
];

$laser_type_options = ['CO2', 'Diode', 'Fiber', 'Not Sure'];
$work_area_options  = [
  'Under 12"×12"', '12"×12"', '16"×24"', '20"×28"', '24"×35"',
  '35"×51"', '51"×98"', 'Over 51"×98"', 'Not Sure',
];
$budget_options = [
  'Under $1,000', '$1,000–$3,000', '$3,000–$6,000', '$6,000–$12,000',
  '$12,000–$25,000', '$25,000–$50,000', 'Over $50,000', 'Not Sure',
];
$timeline_options = [
  'Immediately', 'Within 1 month', '1–3 months', '3–6 months',
  '6–12 months', 'Over a year', 'Just researching',
];
$heard_options = [
  'Google Search', 'Social Media', 'YouTube', 'Referral / Word of Mouth',
  'Trade Show / Event', 'Email Newsletter', 'Online Ad', 'Other',
];
$feature_options = [
  'enclosed_cabinet'   => 'Enclosed Cabinet',
  'air_assist'         => 'Air Assist',
  'rotary_attachment'  => 'Rotary Attachment',
  'pass_through'       => 'Pass-Through Slot',
  'camera_vision'      => 'Camera / Vision System',
  'wifi_connectivity'  => 'Wi-Fi Connectivity',
  'autofocus'          => 'Autofocus',
  'exhaust_filtration' => 'Exhaust / Filtration',
  'red_dot'            => 'Red Dot Pointer',
  'dual_head'          => 'Dual Laser Head',
  'high_speed'         => 'High-Speed Mode',
  'large_format'       => 'Large Format',
];

// ── Load existing record ───────────────────────────────────────────────────────
$row_stmt = $pdo->prepare("SELECT * FROM machine_inquiries WHERE id = ?");
$row_stmt->execute([$id]);
$inq = $row_stmt->fetch(PDO::FETCH_ASSOC);
if (!$inq) {
  http_response_code(404);
  render_header('Inquiry Not Found');
  echo '<div class="card"><p class="muted">Inquiry not found.</p><a class="btn" href="machine_inquiry_admin.php?section=inquiries">← Back to Inquiries</a></div>';
  render_footer();
  exit;
}

$errors  = [];
$success = '';

// ── POST handler ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['mie_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $fields = [
      'first_name'           => trim((string)($_POST['first_name'] ?? '')),
      'last_name'            => trim((string)($_POST['last_name'] ?? '')),
      'cell_phone'           => trim((string)($_POST['cell_phone'] ?? '')),
      'email'                => trim((string)($_POST['email'] ?? '')),
      'city'                 => trim((string)($_POST['city'] ?? '')),
      'state'                => trim((string)($_POST['state'] ?? '')),
      'zip_code'             => trim((string)($_POST['zip_code'] ?? '')),
      'machine_condition'    => trim((string)($_POST['machine_condition'] ?? '')),
      'laser_type'           => trim((string)($_POST['laser_type'] ?? '')),
      'desired_watts'        => trim((string)($_POST['desired_watts'] ?? '')),
      'work_area'            => trim((string)($_POST['work_area'] ?? '')),
      'budget'               => trim((string)($_POST['budget'] ?? '')),
      'intended_use'         => trim((string)($_POST['intended_use'] ?? '')),
      'timeline'             => trim((string)($_POST['timeline'] ?? '')),
      'current_machine'      => isset($_POST['current_machine']) ? 1 : 0,
      'current_machine_brand'=> trim((string)($_POST['current_machine_brand'] ?? '')),
      'additional_notes'     => trim((string)($_POST['additional_notes'] ?? '')),
      'heard_about_us'       => trim((string)($_POST['heard_about_us'] ?? '')),
    ];

    // Features (checkboxes → JSON array)
    $chosen_features = [];
    foreach (array_keys($feature_options) as $fk) {
      if (!empty($_POST['feature_' . $fk])) {
        $chosen_features[] = $fk;
      }
    }
    $fields['features_wanted'] = $chosen_features ? json_encode($chosen_features) : null;

    // Validate required fields
    if ($fields['first_name'] === '') $errors[] = 'First name is required.';
    if ($fields['last_name'] === '')  $errors[] = 'Last name is required.';
    if ($fields['cell_phone'] === '') $errors[] = 'Phone number is required.';
    if ($fields['email'] === '' || !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'A valid email address is required.';
    }
    if ($fields['city'] === '')    $errors[] = 'City is required.';
    if (!array_key_exists($fields['state'], $us_states)) $errors[] = 'Please select a valid state.';
    if ($fields['zip_code'] === '') $errors[] = 'ZIP code is required.';
    if (!in_array($fields['machine_condition'], ['new','used','either'], true)) {
      $errors[] = 'Please select a machine condition.';
    }

    if (!$errors) {
      $pdo->prepare("
        UPDATE machine_inquiries SET
          first_name            = ?,
          last_name             = ?,
          cell_phone            = ?,
          email                 = ?,
          city                  = ?,
          state                 = ?,
          zip_code              = ?,
          machine_condition     = ?,
          laser_type            = ?,
          desired_watts         = ?,
          work_area             = ?,
          budget                = ?,
          intended_use          = ?,
          features_wanted       = ?,
          timeline              = ?,
          current_machine       = ?,
          current_machine_brand = ?,
          additional_notes      = ?,
          heard_about_us        = ?
        WHERE id = ?
      ")->execute([
        $fields['first_name'],
        $fields['last_name'],
        $fields['cell_phone'],
        $fields['email'],
        $fields['city'],
        $fields['state'],
        $fields['zip_code'],
        $fields['machine_condition'],
        $fields['laser_type'] ?: null,
        $fields['desired_watts'] ?: null,
        $fields['work_area'] ?: null,
        $fields['budget'] ?: null,
        $fields['intended_use'] ?: null,
        $fields['features_wanted'],
        $fields['timeline'] ?: null,
        $fields['current_machine'],
        $fields['current_machine_brand'] ?: null,
        $fields['additional_notes'] ?: null,
        $fields['heard_about_us'] ?: null,
        $id,
      ]);
      $_SESSION['mie_csrf'] = bin2hex(random_bytes(24));
      // Reload saved data
      $row_stmt->execute([$id]);
      $inq = $row_stmt->fetch(PDO::FETCH_ASSOC);
      $success = 'Inquiry updated successfully.';
    }
  }
}

// Populate form values from DB row (or from failed POST)
$fv = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
  // Keep POST values on validation failure
  $fv['first_name']            = trim((string)($_POST['first_name'] ?? ''));
  $fv['last_name']             = trim((string)($_POST['last_name'] ?? ''));
  $fv['cell_phone']            = trim((string)($_POST['cell_phone'] ?? ''));
  $fv['email']                 = trim((string)($_POST['email'] ?? ''));
  $fv['city']                  = trim((string)($_POST['city'] ?? ''));
  $fv['state']                 = trim((string)($_POST['state'] ?? ''));
  $fv['zip_code']              = trim((string)($_POST['zip_code'] ?? ''));
  $fv['machine_condition']     = trim((string)($_POST['machine_condition'] ?? ''));
  $fv['laser_type']            = trim((string)($_POST['laser_type'] ?? ''));
  $fv['desired_watts']         = trim((string)($_POST['desired_watts'] ?? ''));
  $fv['work_area']             = trim((string)($_POST['work_area'] ?? ''));
  $fv['budget']                = trim((string)($_POST['budget'] ?? ''));
  $fv['intended_use']          = trim((string)($_POST['intended_use'] ?? ''));
  $fv['timeline']              = trim((string)($_POST['timeline'] ?? ''));
  $fv['current_machine']       = isset($_POST['current_machine']) ? 1 : 0;
  $fv['current_machine_brand'] = trim((string)($_POST['current_machine_brand'] ?? ''));
  $fv['additional_notes']      = trim((string)($_POST['additional_notes'] ?? ''));
  $fv['heard_about_us']        = trim((string)($_POST['heard_about_us'] ?? ''));
  // Reconstruct features_wanted from individual checkbox POST values
  $post_features = [];
  foreach (array_keys($feature_options) as $fk) {
    if (!empty($_POST['feature_' . $fk])) {
      $post_features[] = $fk;
    }
  }
  $fv['features_wanted'] = $post_features ? json_encode($post_features) : '';
} else {
  foreach ($inq as $k => $v) {
    $fv[$k] = (string)($v ?? '');
  }
}

// Parse features_wanted JSON for checkboxes
$selected_features = [];
if (!empty($fv['features_wanted'])) {
  $decoded = json_decode($fv['features_wanted'], true);
  if (is_array($decoded)) {
    $selected_features = $decoded;
  }
}

render_header('Edit Inquiry #' . $id);
?>
<style>
.mie-wrap { max-width: 780px; margin: 0 auto; padding: 24px 16px; }
.mie-wrap h1 { margin-bottom: 4px; }
.mie-wrap .muted { color: var(--muted, #888); font-size: 13px; }
.mie-section { margin-bottom: 28px; }
.mie-section h3 { font-size: 15px; font-weight: 600; border-bottom: 1px solid var(--border, #ddd); padding-bottom: 6px; margin-bottom: 14px; }
.mie-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.mie-grid .full { grid-column: 1 / -1; }
.mie-field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
.mie-field input[type="text"],
.mie-field input[type="email"],
.mie-field select,
.mie-field textarea { width: 100%; box-sizing: border-box; }
.mie-field textarea { min-height: 80px; resize: vertical; }
.mie-feature-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; }
.mie-feature-item { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.mie-actions { display: flex; gap: 10px; align-items: center; margin-top: 24px; }
@media (max-width: 600px) { .mie-grid { grid-template-columns: 1fr; } }
</style>
<div class="mie-wrap">
  <p><a href="machine_inquiry_admin.php?section=inquiries">← Back to Inquiries</a></p>
  <h1>Edit Inquiry <span class="muted">#<?= $id ?></span></h1>
  <p class="muted">Submitted: <?= h($inq['created_at']) ?></p>

  <?php if ($errors): ?>
  <div class="alert error" style="margin-bottom:16px;">
    <?php foreach ($errors as $e): ?>
    <p style="margin:0 0 4px;"><?= h($e) ?></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($success): ?>
  <div class="alert success" style="margin-bottom:16px;"><?= h($success) ?></div>
  <?php endif; ?>

  <form method="post" action="machine_inquiry_edit.php?id=<?= $id ?>" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mie_csrf']) ?>" />

    <!-- Contact -->
    <div class="mie-section">
      <h3>Contact Information</h3>
      <div class="mie-grid">
        <div class="mie-field">
          <label>First Name *</label>
          <input type="text" name="first_name" value="<?= h($fv['first_name']) ?>" maxlength="100" required />
        </div>
        <div class="mie-field">
          <label>Last Name *</label>
          <input type="text" name="last_name" value="<?= h($fv['last_name']) ?>" maxlength="100" required />
        </div>
        <div class="mie-field">
          <label>Phone *</label>
          <input type="text" name="cell_phone" value="<?= h($fv['cell_phone']) ?>" maxlength="30" required />
        </div>
        <div class="mie-field">
          <label>Email *</label>
          <input type="email" name="email" value="<?= h($fv['email']) ?>" maxlength="255" required />
        </div>
        <div class="mie-field">
          <label>City *</label>
          <input type="text" name="city" value="<?= h($fv['city']) ?>" maxlength="100" required />
        </div>
        <div class="mie-field">
          <label>State *</label>
          <select name="state" required>
            <option value="">— Select State —</option>
            <?php foreach ($us_states as $abbr => $name): ?>
            <option value="<?= h($abbr) ?>"<?= $fv['state'] === $abbr ? ' selected' : '' ?>><?= h($name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mie-field">
          <label>ZIP Code *</label>
          <input type="text" name="zip_code" value="<?= h($fv['zip_code']) ?>" maxlength="20" required />
        </div>
      </div>
    </div>

    <!-- Machine Preferences -->
    <div class="mie-section">
      <h3>Machine Preferences</h3>
      <div class="mie-grid">
        <div class="mie-field">
          <label>Machine Condition *</label>
          <select name="machine_condition" required>
            <option value="new"   <?= $fv['machine_condition'] === 'new'    ? 'selected' : '' ?>>New</option>
            <option value="used"  <?= $fv['machine_condition'] === 'used'   ? 'selected' : '' ?>>Used</option>
            <option value="either" <?= $fv['machine_condition'] === 'either' ? 'selected' : '' ?>>Either</option>
          </select>
        </div>
        <div class="mie-field">
          <label>Laser Type</label>
          <select name="laser_type">
            <option value="">— Not specified —</option>
            <?php foreach ($laser_type_options as $opt): ?>
            <option value="<?= h($opt) ?>"<?= $fv['laser_type'] === $opt ? ' selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mie-field">
          <label>Desired Wattage</label>
          <input type="text" name="desired_watts" value="<?= h($fv['desired_watts']) ?>" maxlength="50" placeholder="e.g. 80W, 100W" />
        </div>
        <div class="mie-field">
          <label>Work Area</label>
          <select name="work_area">
            <option value="">— Not specified —</option>
            <?php foreach ($work_area_options as $opt): ?>
            <option value="<?= h($opt) ?>"<?= $fv['work_area'] === $opt ? ' selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mie-field">
          <label>Budget</label>
          <select name="budget">
            <option value="">— Not specified —</option>
            <?php foreach ($budget_options as $opt): ?>
            <option value="<?= h($opt) ?>"<?= $fv['budget'] === $opt ? ' selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mie-field">
          <label>Timeline</label>
          <select name="timeline">
            <option value="">— Not specified —</option>
            <?php foreach ($timeline_options as $opt): ?>
            <option value="<?= h($opt) ?>"<?= $fv['timeline'] === $opt ? ' selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mie-field full">
          <label>Intended Use</label>
          <textarea name="intended_use"><?= h($fv['intended_use']) ?></textarea>
        </div>
        <div class="mie-field full">
          <label>Desired Features</label>
          <div class="mie-feature-grid">
            <?php foreach ($feature_options as $fk => $flabel): ?>
            <label class="mie-feature-item">
              <input type="checkbox" name="feature_<?= h($fk) ?>" value="1"<?= in_array($fk, $selected_features, true) ? ' checked' : '' ?> />
              <?= h($flabel) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Background -->
    <div class="mie-section">
      <h3>Background</h3>
      <div class="mie-grid">
        <div class="mie-field">
          <label>Owns a Laser?</label>
          <label style="display:flex; align-items:center; gap:8px; font-weight:normal;">
            <input type="checkbox" name="current_machine" value="1"<?= !empty($fv['current_machine']) ? ' checked' : '' ?> />
            Yes, currently owns a laser machine
          </label>
        </div>
        <div class="mie-field">
          <label>Current Machine Brand</label>
          <input type="text" name="current_machine_brand" value="<?= h($fv['current_machine_brand']) ?>" maxlength="100" />
        </div>
        <div class="mie-field">
          <label>How Did They Hear About Us?</label>
          <select name="heard_about_us">
            <option value="">— Not specified —</option>
            <?php foreach ($heard_options as $opt): ?>
            <option value="<?= h($opt) ?>"<?= $fv['heard_about_us'] === $opt ? ' selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mie-field full">
          <label>Additional Notes</label>
          <textarea name="additional_notes"><?= h($fv['additional_notes']) ?></textarea>
        </div>
      </div>
    </div>

    <div class="mie-actions">
      <button type="submit" class="btn primary">💾 Save Changes</button>
      <a class="btn" href="machine_inquiry_admin.php?section=inquiries">Cancel</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>
