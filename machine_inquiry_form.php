<?php
/**
 * machine_inquiry_form.php – Public CO2 Laser Machine Inquiry Form.
 * Open to the public; no login required.
 * Customers can request info about new and used CO2 laser cutting machines.
 * Security: CSRF token, per-IP rate limit, honeypot, reCAPTCHA v2.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
$cfg = require __DIR__ . '/config.php';
$recaptcha_site_key   = $cfg['recaptcha']['site_key']   ?? '';
$recaptcha_secret_key = $cfg['recaptcha']['secret_key'] ?? '';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// ── CSRF token ────────────────────────────────────────────────────────────────
if (empty($_SESSION['mif_csrf'])) {
  $_SESSION['mif_csrf'] = bin2hex(random_bytes(24));
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function mif_client_ip(): string {
  foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) {
      $ip = trim(explode(',', $_SERVER[$k])[0]);
      if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
  }
  return '0.0.0.0';
}

// ── Load promotion text from admin settings ───────────────────────────────────
$promo_text = '';
try {
  $promo_stmt = $pdo->prepare("SELECT setting_val FROM machine_inquiry_settings WHERE setting_key = 'promo_text' LIMIT 1");
  $promo_stmt->execute();
  $promo_row = $promo_stmt->fetch();
  $promo_text = trim((string)($promo_row['setting_val'] ?? ''));
} catch (\Throwable $ex) {
  $promo_text = '';
}

// US states list
$us_states = [
  'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California',
  'CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','FL'=>'Florida','GA'=>'Georgia',
  'HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa',
  'KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland',
  'MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi',
  'MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire',
  'NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina',
  'ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania',
  'RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota','TN'=>'Tennessee',
  'TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington',
  'WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming','DC'=>'District of Columbia',
];

$errors  = [];
$success = false;
$fields  = [
  'first_name'           => '',
  'last_name'            => '',
  'cell_phone'           => '',
  'email'                => '',
  'city'                 => '',
  'state'                => '',
  'zip_code'             => '',
  'machine_condition'    => 'either',
  'laser_type'           => '',
  'desired_watts'        => '',
  'work_area'            => '',
  'budget'               => '',
  'intended_use'         => '',
  'timeline'             => '',
  'current_machine'      => '0',
  'current_machine_brand'=> '',
  'additional_notes'     => '',
  'heard_about_us'       => '',
];
$features_wanted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ── CSRF ──────────────────────────────────────────────────────────────────
  $submitted_csrf = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['mif_csrf'] ?? '', $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  }

  if (!$errors) {
    // ── Honeypot ──────────────────────────────────────────────────────────
    if (!empty($_POST['website'])) {
      $success = true; // silent bot fail
    } else {
      // ── reCAPTCHA ────────────────────────────────────────────────────────
      $recaptcha_response = trim((string)($_POST['g-recaptcha-response'] ?? ''));
      if ($recaptcha_response === '') {
        $errors[] = 'Please complete the reCAPTCHA verification.';
      } else {
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST           => true,
          CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => $recaptcha_secret_key,
            'response' => $recaptcha_response,
            'remoteip' => mif_client_ip(),
          ]),
          CURLOPT_TIMEOUT        => 10,
          CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $verify      = curl_exec($ch);
        $curl_error  = curl_errno($ch);
        curl_close($ch);
        if ($curl_error || $verify === false) {
          $errors[] = 'Could not reach the verification service. Please try again.';
        } else {
          $verify_data = json_decode($verify, true);
          if (empty($verify_data['success'])) {
            $errors[] = 'reCAPTCHA verification failed. Please try again.';
          }
        }
      }

      if (!$errors) {
        // ── Collect & sanitize ────────────────────────────────────────────
        foreach ($fields as $k => $_) {
          $fields[$k] = trim((string)($_POST[$k] ?? ''));
        }
        $valid_features = [
          'autofocus', 'camera', 'rotary', 'pass_through', 'air_assist',
          'wifi', 'enclosed', 'chiller', 'red_dot', 'lcd_panel',
        ];
        $features_wanted = [];
        foreach ($valid_features as $f) {
          if (!empty($_POST['feature_' . $f])) {
            $features_wanted[] = $f;
          }
        }

        // ── Validate ──────────────────────────────────────────────────────
        if ($fields['first_name'] === '')  $errors[] = 'First name is required.';
        if ($fields['last_name'] === '')   $errors[] = 'Last name is required.';
        if ($fields['cell_phone'] === '')  $errors[] = 'Cell phone is required.';
        if ($fields['email'] === '')       $errors[] = 'Email address is required.';
        if ($fields['city'] === '')        $errors[] = 'City is required.';
        if ($fields['state'] === '')       $errors[] = 'State is required.';
        if ($fields['zip_code'] === '')    $errors[] = 'ZIP code is required.';
        if ($fields['intended_use'] === '') $errors[] = 'Please tell us what you plan to use the machine for.';
        if ($fields['budget'] === '')      $errors[] = 'Please select a budget range.';

        if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
          $errors[] = 'Please enter a valid email address.';
        }
        if (!isset($us_states[$fields['state']])) {
          $errors[] = 'Please select a valid US state.';
        }
        if (!preg_match('/^\d{5}(-\d{4})?$/', $fields['zip_code'])) {
          $errors[] = 'ZIP code must be 5 digits (or 5+4 format).';
        }
        if (!in_array($fields['machine_condition'], ['new','used','either'], true)) {
          $fields['machine_condition'] = 'either';
        }
        if (strlen($fields['intended_use']) > 3000) {
          $errors[] = 'Intended use must be 3000 characters or fewer.';
        }
        if (strlen($fields['additional_notes']) > 3000) {
          $errors[] = 'Additional notes must be 3000 characters or fewer.';
        }

        // ── Rate limit: 5 per IP per hour ─────────────────────────────────
        if (!$errors) {
          $ip = mif_client_ip();
          $rl = $pdo->prepare(
            "SELECT COUNT(*) FROM form_rate_limit WHERE ip = ? AND submitted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
          );
          $rl->execute([$ip]);
          if ((int)$rl->fetchColumn() >= 5) {
            $errors[] = 'Too many submissions from your location. Please try again later.';
          }
        }

        if (!$errors) {
          $ip = mif_client_ip();
          $pdo->beginTransaction();
          try {
            $ins = $pdo->prepare(
              "INSERT INTO machine_inquiries
                 (first_name, last_name, cell_phone, email, city, state, zip_code,
                  machine_condition, laser_type, desired_watts, work_area, budget,
                  intended_use, features_wanted, timeline, current_machine,
                  current_machine_brand, additional_notes, heard_about_us, submission_ip)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $ins->execute([
              $fields['first_name'], $fields['last_name'], $fields['cell_phone'],
              $fields['email'], $fields['city'], $fields['state'], $fields['zip_code'],
              $fields['machine_condition'], $fields['laser_type'], $fields['desired_watts'],
              $fields['work_area'], $fields['budget'], $fields['intended_use'],
              implode(',', $features_wanted),
              $fields['timeline'], (int)($fields['current_machine'] === '1'),
              $fields['current_machine_brand'], $fields['additional_notes'],
              $fields['heard_about_us'], $ip,
            ]);

            $pdo->prepare("INSERT INTO form_rate_limit (ip) VALUES (?)")->execute([$ip]);
            $pdo->commit();

            // ── Notification email ─────────────────────────────────────
            $scheme      = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $to          = $fields['email'];
            $subject     = 'Your CO2 Laser Machine Inquiry – Ghostlings Laser';
            $body        = "Hello {$fields['first_name']},\r\n\r\n"
                         . "Thank you for your CO2 laser machine inquiry! We have received your request and a member of our team will be in touch shortly.\r\n\r\n"
                         . "Your inquiry summary:\r\n"
                         . "  Machine Type: " . ucfirst($fields['machine_condition']) . "\r\n"
                         . "  Desired Wattage: " . ($fields['desired_watts'] ?: 'Not specified') . "\r\n"
                         . "  Work Area: " . ($fields['work_area'] ?: 'Not specified') . "\r\n"
                         . "  Budget: " . ($fields['budget'] ?: 'Not specified') . "\r\n\r\n"
                         . "– Ghostlings Laser Team\r\n";
            $headers     = "From: no-reply@" . $host . "\r\n"
                         . "Reply-To: no-reply@" . $host . "\r\n"
                         . "X-Mailer: PHP/" . phpversion() . "\r\n"
                         . "Content-Type: text/plain; charset=UTF-8\r\n";
            @mail($to, $subject, $body, $headers);

            $_SESSION['mif_csrf'] = bin2hex(random_bytes(24));
            $success = true;
          } catch (\Throwable $ex) {
            $pdo->rollBack();
            $errors[] = 'A database error occurred. Please try again.';
          }
        }
      }
    }
  }
}

render_header('CO2 Laser Machine Inquiry');
?>

<!-- ── Hero Banner ──────────────────────────────────────────────────────────── -->
<div class="mif-hero">
  <div class="mif-hero-glow"></div>
  <div class="mif-hero-content">
    <div class="mif-hero-icon">⚡</div>
    <h1 class="mif-hero-title">CO2 Laser Machine Inquiry</h1>
    <p class="mif-hero-sub">
      New &amp; Used Machines &bull; Expert Guidance &bull; Competitive Pricing
    </p>
    <div class="mif-hero-badges">
      <span class="mif-badge">🔬 Glass &amp; RF Tube</span>
      <span class="mif-badge">📦 In-Stock &amp; Import</span>
      <span class="mif-badge">🛡️ Warranty Included</span>
      <span class="mif-badge">🚚 US Shipping</span>
    </div>
  </div>
</div>

<!-- ── Promotion Banner ─────────────────────────────────────────────────────── -->
<?php if ($promo_text !== ''): ?>
<div class="mif-promo">
  <div class="mif-promo-pulse"></div>
  <div class="mif-promo-inner">
    <?php // Admin-controlled HTML from TinyMCE — intentionally unescaped ?>
    <?= $promo_text ?>
  </div>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="card mif-success" role="status" aria-live="polite">
  <div class="mif-success-icon">🎯</div>
  <h2 style="margin:0 0 8px;">Inquiry Submitted!</h2>
  <p style="margin:0 0 6px;">
    Thank you, <strong><?= h($fields['first_name']) ?></strong>! We received your CO2 laser machine inquiry
    and a confirmation has been sent to <strong><?= h($fields['email']) ?></strong>.
  </p>
  <p style="margin:0; color:var(--m); font-size:14px;">
    A member of our team will reach out to you shortly with personalized recommendations.
  </p>
</div>
<?php else: ?>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<!-- ── Progress Steps ──────────────────────────────────────────────────────── -->
<div class="card mif-steps-card">
  <div class="mif-steps">
    <div class="mif-step active"><span class="mif-step-num">1</span><span class="mif-step-label">Your Info</span></div>
    <div class="mif-step-line"></div>
    <div class="mif-step active"><span class="mif-step-num">2</span><span class="mif-step-label">Machine Details</span></div>
    <div class="mif-step-line"></div>
    <div class="mif-step active"><span class="mif-step-num">3</span><span class="mif-step-label">Final Notes</span></div>
  </div>
</div>

<form method="post" class="mif-form" novalidate>
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mif_csrf']) ?>" />
  <div style="display:none;" aria-hidden="true">
    <label>Leave blank</label>
    <input type="text" name="website" tabindex="-1" autocomplete="off" />
  </div>

  <!-- ── Section 1: Personal Information ────────────────────────────────── -->
  <div class="card mif-section">
    <div class="mif-section-header">
      <span class="mif-section-num">1</span>
      <div>
        <h2 style="margin:0;">Your Contact Information</h2>
        <p class="muted" style="margin:2px 0 0;">How can we reach you?</p>
      </div>
    </div>

    <div class="form-grid">
      <div>
        <label>First Name <span class="req">*</span></label>
        <input type="text" name="first_name" value="<?= h($fields['first_name']) ?>"
               maxlength="100" required autocomplete="given-name" />
      </div>
      <div>
        <label>Last Name <span class="req">*</span></label>
        <input type="text" name="last_name" value="<?= h($fields['last_name']) ?>"
               maxlength="100" required autocomplete="family-name" />
      </div>
      <div>
        <label>Cell Phone <span class="req">*</span></label>
        <input type="tel" name="cell_phone" value="<?= h($fields['cell_phone']) ?>"
               maxlength="30" required autocomplete="tel" placeholder="e.g. 555-867-5309"
               pattern="[\d\s\-\+\(\)\.]{7,30}" />
      </div>
      <div>
        <label>Email Address <span class="req">*</span></label>
        <input type="email" name="email" value="<?= h($fields['email']) ?>"
               maxlength="255" required autocomplete="email" />
      </div>
      <div>
        <label>City <span class="req">*</span></label>
        <input type="text" name="city" value="<?= h($fields['city']) ?>"
               maxlength="100" required autocomplete="address-level2" />
      </div>
      <div>
        <label>State <span class="req">*</span></label>
        <select name="state" required>
          <option value="">— Select state —</option>
          <?php foreach ($us_states as $code => $label): ?>
            <option value="<?= h($code) ?>" <?= $fields['state'] === $code ? 'selected' : '' ?>>
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>ZIP Code <span class="req">*</span></label>
        <input type="text" name="zip_code" value="<?= h($fields['zip_code']) ?>"
               maxlength="10" required autocomplete="postal-code" placeholder="e.g. 90210" />
      </div>
    </div>
  </div>

  <!-- ── Section 2: Machine Details ─────────────────────────────────────── -->
  <div class="card mif-section">
    <div class="mif-section-header">
      <span class="mif-section-num">2</span>
      <div>
        <h2 style="margin:0;">Machine Details</h2>
        <p class="muted" style="margin:2px 0 0;">Tell us about the CO2 laser you are looking for.</p>
      </div>
    </div>

    <div class="form-grid">
      <!-- Condition -->
      <div class="full">
        <label>Are you interested in a New or Used machine? <span class="req">*</span></label>
        <div class="mif-radio-group">
          <?php foreach (['new'=>'🆕 New Machine','used'=>'♻️ Used / Refurbished','either'=>'🤷 Either — Show Me Both'] as $val => $lbl): ?>
            <label class="mif-radio-card <?= $fields['machine_condition'] === $val ? 'selected' : '' ?>">
              <input type="radio" name="machine_condition" value="<?= $val ?>"
                     <?= $fields['machine_condition'] === $val ? 'checked' : '' ?> />
              <?= $lbl ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Laser Type -->
      <div>
        <label>CO2 Laser Tube Type</label>
        <select name="laser_type">
          <option value="">— Not sure / Any —</option>
          <?php foreach ([
            'glass_dc'  => 'Glass Tube (DC Excited)',
            'rf_metal'  => 'Metal RF Tube (Radio Frequency)',
            'not_sure'  => 'Not Sure — Need Guidance',
          ] as $val => $lbl): ?>
            <option value="<?= h($val) ?>" <?= $fields['laser_type'] === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="muted" style="margin:4px 0 0; font-size:12px;">Glass tubes are more affordable; RF tubes offer precision &amp; longevity.</p>
      </div>

      <!-- Wattage -->
      <div>
        <label>Desired Wattage</label>
        <select name="desired_watts">
          <option value="">— Not sure —</option>
          <?php foreach (['40W','60W','80W','100W','130W','150W','200W+','Not Sure'] as $w): ?>
            <option value="<?= h($w) ?>" <?= $fields['desired_watts'] === $w ? 'selected' : '' ?>><?= h($w) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Work Area -->
      <div>
        <label>Desired Work Area / Bed Size</label>
        <select name="work_area">
          <option value="">— Not sure —</option>
          <?php foreach ([
            'small'       => 'Small  – up to 12″ × 8″',
            'medium'      => 'Medium – up to 24″ × 16″',
            'large'       => 'Large  – up to 36″ × 24″',
            'xlarge'      => 'X-Large – up to 48″ × 36″',
            'xxlarge'     => 'Industrial – 48″ × 36″ and above',
            'not_sure'    => 'Not Sure',
          ] as $val => $lbl): ?>
            <option value="<?= h($val) ?>" <?= $fields['work_area'] === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Budget -->
      <div>
        <label>Budget Range <span class="req">*</span></label>
        <select name="budget" required>
          <option value="">— Select a range —</option>
          <?php foreach ([
            'under_1k'    => 'Under $1,000',
            '1k_3k'       => '$1,000 – $3,000',
            '3k_5k'       => '$3,000 – $5,000',
            '5k_10k'      => '$5,000 – $10,000',
            '10k_20k'     => '$10,000 – $20,000',
            '20k_plus'    => '$20,000+',
            'flexible'    => 'Flexible — Show me options',
          ] as $val => $lbl): ?>
            <option value="<?= h($val) ?>" <?= $fields['budget'] === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Timeline -->
      <div>
        <label>Purchase Timeline</label>
        <select name="timeline">
          <option value="">— Select —</option>
          <?php foreach ([
            'immediately'   => '🚨 Immediately – Ready to buy',
            'within_1mo'    => '📅 Within 1 Month',
            '1_3mo'         => '📆 1 – 3 Months',
            '3_6mo'         => '🗓️ 3 – 6 Months',
            'researching'   => '🔍 Just Researching',
          ] as $val => $lbl): ?>
            <option value="<?= h($val) ?>" <?= $fields['timeline'] === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Intended Use -->
      <div class="full">
        <label>What Do You Plan to Cut / Engrave? <span class="req">*</span></label>
        <textarea name="intended_use" rows="4" maxlength="3000"
                  placeholder="e.g. Acrylic signs, wood gifts, leather engraving, fabric cutting… Include any specific materials or thicknesses if known."
                  required><?= h($fields['intended_use']) ?></textarea>
        <p class="muted" style="margin:4px 0 0;">Max 3,000 characters.</p>
      </div>

      <!-- Desired Features -->
      <div class="full">
        <label>Desired Features &amp; Accessories</label>
        <div class="mif-feature-grid">
          <?php
          $feature_opts = [
            'autofocus'   => ['icon'=>'🎯', 'label'=>'Auto-Focus',       'desc'=>'Automatically sets focal height'],
            'camera'      => ['icon'=>'📷', 'label'=>'Camera Preview',    'desc'=>'Overhead camera for placement'],
            'rotary'      => ['icon'=>'🔄', 'label'=>'Rotary Attachment', 'desc'=>'Engrave cylinders &amp; mugs'],
            'pass_through'=> ['icon'=>'↔️', 'label'=>'Pass-Through Slot', 'desc'=>'Engrave oversized materials'],
            'air_assist'  => ['icon'=>'💨', 'label'=>'Air Assist',        'desc'=>'Cleaner cuts, less char'],
            'wifi'        => ['icon'=>'📶', 'label'=>'Wi-Fi / Network',   'desc'=>'Wireless job sending'],
            'enclosed'    => ['icon'=>'🏠', 'label'=>'Enclosed Cabinet',  'desc'=>'Built-in safety enclosure'],
            'chiller'     => ['icon'=>'❄️', 'label'=>'Water Chiller',     'desc'=>'Extends tube life'],
            'red_dot'     => ['icon'=>'🔴', 'label'=>'Red Dot Pointer',   'desc'=>'Visual positioning aid'],
            'lcd_panel'   => ['icon'=>'🖥️', 'label'=>'LCD Control Panel', 'desc'=>'Stand-alone operation'],
          ];
          foreach ($feature_opts as $key => $opt):
            $checked = in_array($key, $features_wanted, true) || !empty($_POST['feature_' . $key]);
          ?>
          <label class="mif-feature-card <?= $checked ? 'selected' : '' ?>">
            <input type="checkbox" name="feature_<?= h($key) ?>" value="1" <?= $checked ? 'checked' : '' ?> />
            <span class="mif-feature-icon"><?= $opt['icon'] ?></span>
            <span class="mif-feature-name"><?= h($opt['label']) ?></span>
            <span class="mif-feature-desc"><?= $opt['desc'] ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Section 3: Background & Notes ───────────────────────────────────── -->
  <div class="card mif-section">
    <div class="mif-section-header">
      <span class="mif-section-num">3</span>
      <div>
        <h2 style="margin:0;">Background &amp; Additional Notes</h2>
        <p class="muted" style="margin:2px 0 0;">Help us understand your situation better.</p>
      </div>
    </div>

    <div class="form-grid">
      <!-- Current Machine -->
      <div class="full">
        <label>Do you currently own a laser machine?</label>
        <div class="mif-radio-group" style="flex-wrap:wrap; gap:8px;">
          <label class="mif-radio-card <?= $fields['current_machine'] === '1' ? 'selected' : '' ?>">
            <input type="radio" name="current_machine" value="1"
                   <?= $fields['current_machine'] === '1' ? 'checked' : '' ?> />
            ✅ Yes, I own one
          </label>
          <label class="mif-radio-card <?= $fields['current_machine'] !== '1' ? 'selected' : '' ?>">
            <input type="radio" name="current_machine" value="0"
                   <?= $fields['current_machine'] !== '1' ? 'checked' : '' ?> />
            ❌ No, this is my first
          </label>
        </div>
      </div>

      <div>
        <label>Current Machine Brand (if any)</label>
        <input type="text" name="current_machine_brand"
               value="<?= h($fields['current_machine_brand']) ?>"
               maxlength="100" placeholder="e.g. OMTech, xTool, Epilog…" />
      </div>

      <div>
        <label>How Did You Hear About Us?</label>
        <select name="heard_about_us">
          <option value="">— Select —</option>
          <?php foreach ([
            'google'        => '🔍 Google Search',
            'facebook'      => '📘 Facebook',
            'instagram'     => '📸 Instagram',
            'youtube'       => '▶️ YouTube',
            'referral'      => '🤝 Referred by a Friend',
            'trade_show'    => '🏛️ Trade Show / Expo',
            'returning'     => '🔄 Returning Customer',
            'other'         => '💬 Other',
          ] as $val => $lbl): ?>
            <option value="<?= h($val) ?>" <?= $fields['heard_about_us'] === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="full">
        <label>Additional Notes or Questions</label>
        <textarea name="additional_notes" rows="5" maxlength="3000"
                  placeholder="Anything else you'd like us to know? Specific brands you've researched, special requirements, software preferences (LightBurn, RDWorks, etc.)…"
                  ><?= h($fields['additional_notes']) ?></textarea>
        <p class="muted" style="margin:4px 0 0;">Max 3,000 characters.</p>
      </div>

      <!-- reCAPTCHA -->
      <div class="full">
        <div class="g-recaptcha" data-sitekey="<?= h($recaptcha_site_key) ?>"></div>
      </div>
    </div>
  </div>

  <!-- Submit -->
  <div class="card mif-submit-card">
    <button type="submit" class="btn primary mif-submit-btn">
      🚀 Submit Machine Inquiry
    </button>
    <p class="muted" style="margin:8px 0 0; font-size:13px;">
      We will contact you within 1 business day with personalized machine recommendations.
    </p>
  </div>

</form>
<?php endif; ?>

<!-- Inline styles for the Machine Inquiry Form -->
<style>
/* ── Hero ───────────────────────────────────────────────────────────────────── */
.mif-hero {
  position: relative;
  background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 40%, #0f2942 100%);
  border-radius: 14px;
  padding: 48px 24px;
  text-align: center;
  margin: 12px 0;
  overflow: hidden;
  box-shadow: 0 8px 32px rgba(15,23,42,.35);
}
.mif-hero-glow {
  position: absolute;
  top: -60px; left: 50%;
  transform: translateX(-50%);
  width: 500px; height: 260px;
  background: radial-gradient(ellipse, rgba(56,189,248,.22) 0%, transparent 70%);
  pointer-events: none;
}
.mif-hero-content { position: relative; z-index: 1; }
.mif-hero-icon { font-size: 52px; line-height: 1; margin-bottom: 12px; filter: drop-shadow(0 0 18px rgba(56,189,248,.7)); }
.mif-hero-title { color: #f0f9ff; font-size: 2rem; margin: 0 0 8px; text-shadow: 0 2px 12px rgba(0,0,0,.4); }
.mif-hero-sub { color: #93c5fd; margin: 0 0 18px; font-size: 1.05rem; }
.mif-hero-badges { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }
.mif-badge {
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.18);
  color: #e0f2fe;
  border-radius: 999px;
  padding: 5px 14px;
  font-size: 13px;
  backdrop-filter: blur(4px);
}

/* ── Promo Banner ───────────────────────────────────────────────────────────── */
.mif-promo {
  position: relative;
  margin: 12px 0;
  border-radius: 12px;
  padding: 2px;
  background: linear-gradient(90deg, #f59e0b, #ef4444, #a855f7, #3b82f6, #f59e0b);
  background-size: 300% 100%;
  animation: promoGradient 4s linear infinite;
  box-shadow: 0 4px 18px rgba(245,158,11,.25);
}
@keyframes promoGradient { to { background-position: 300% 0; } }
.mif-promo-pulse {
  position: absolute; inset: 0; border-radius: 12px;
  animation: promoPulse 2.5s ease-in-out infinite;
  pointer-events: none;
}
@keyframes promoPulse { 0%,100%{opacity:.5;} 50%{opacity:.9;} }
.mif-promo-inner {
  background: #fffbeb;
  border-radius: 10px;
  padding: 16px 20px;
  font-size: 15px;
  font-weight: 500;
  color: #78350f;
  position: relative; z-index: 1;
}

/* ── Success ─────────────────────────────────────────────────────────────────  */
.mif-success {
  text-align: center;
  padding: 48px 24px;
  border-color: #bbf7d0;
  background: linear-gradient(135deg, #f0fdf4, #dcfce7);
  color: #14532d;
}
.mif-success-icon { font-size: 56px; margin-bottom: 12px; }

/* ── Steps ───────────────────────────────────────────────────────────────────  */
.mif-steps-card { padding: 16px 20px; }
.mif-steps { display: flex; align-items: center; gap: 6px; }
.mif-step {
  display: flex; align-items: center; gap: 8px;
  color: var(--m); font-size: 14px; font-weight: 500;
}
.mif-step.active { color: var(--p); }
.mif-step-num {
  width: 28px; height: 28px; border-radius: 50%;
  background: var(--b); color: var(--m);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; flex-shrink: 0;
}
.mif-step.active .mif-step-num { background: var(--p); color: #fff; }
.mif-step-line { flex: 1; height: 2px; background: var(--b); min-width: 24px; }

/* ── Form Sections ──────────────────────────────────────────────────────────  */
.mif-form { display: flex; flex-direction: column; gap: 0; }
.mif-section { margin-top: 8px; }
.mif-section-header {
  display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;
  padding-bottom: 14px; border-bottom: 1px solid var(--b);
}
.mif-section-num {
  width: 36px; height: 36px; border-radius: 50%;
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  color: #fff; font-weight: 700; font-size: 16px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; box-shadow: 0 2px 8px rgba(37,99,235,.35);
}

/* ── Required star ──────────────────────────────────────────────────────────  */
.req { color: var(--d); }

/* ── Radio Cards ────────────────────────────────────────────────────────────  */
.mif-radio-group { display: flex; gap: 10px; flex-wrap: wrap; }
.mif-radio-card {
  display: flex; align-items: center; gap: 8px;
  border: 2px solid var(--b); border-radius: 8px;
  padding: 10px 16px; cursor: pointer; font-size: 14px;
  transition: border-color .15s, background .15s;
  user-select: none;
}
.mif-radio-card:hover { border-color: var(--p); background: #eff6ff; }
.mif-radio-card.selected,
.mif-radio-card:has(input:checked) { border-color: var(--p); background: #eff6ff; color: var(--p); font-weight: 600; }
.mif-radio-card input[type="radio"],
.mif-radio-card input[type="checkbox"] { position: absolute; opacity: 0; width: 0; height: 0; }

/* ── Feature Cards ──────────────────────────────────────────────────────────  */
.mif-feature-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 10px;
  margin-top: 6px;
}
.mif-feature-card {
  display: flex; flex-direction: column; align-items: center; gap: 5px;
  border: 2px solid var(--b); border-radius: 10px;
  padding: 14px 10px; cursor: pointer; text-align: center;
  transition: border-color .15s, background .15s, box-shadow .15s;
  user-select: none; position: relative;
}
.mif-feature-card:hover { border-color: var(--p); background: #eff6ff; box-shadow: 0 2px 10px rgba(37,99,235,.12); }
.mif-feature-card.selected,
.mif-feature-card:has(input:checked) {
  border-color: var(--p); background: #eff6ff;
  box-shadow: 0 2px 10px rgba(37,99,235,.2);
}
.mif-feature-card:has(input:checked)::before {
  content: '✓';
  position: absolute; top: 6px; right: 8px;
  font-size: 11px; font-weight: 700; color: var(--p);
}
.mif-feature-card input[type="checkbox"] { position: absolute; opacity: 0; width: 0; height: 0; }
.mif-feature-icon { font-size: 26px; line-height: 1; }
.mif-feature-name { font-weight: 600; font-size: 13px; color: var(--t); }
.mif-feature-desc { font-size: 11px; color: var(--m); line-height: 1.3; }

/* ── Submit Card ────────────────────────────────────────────────────────────  */
.mif-submit-card { text-align: center; padding: 24px; margin-top: 8px; }
.mif-submit-btn {
  font-size: 16px !important; padding: 14px 36px !important;
  border-radius: 999px !important;
  background: linear-gradient(135deg, #2563eb, #7c3aed) !important;
  border: none !important;
  box-shadow: 0 4px 16px rgba(37,99,235,.4) !important;
  transition: transform .15s, box-shadow .15s !important;
}
.mif-submit-btn:hover {
  transform: translateY(-2px) !important;
  box-shadow: 0 8px 24px rgba(37,99,235,.5) !important;
}

/* ── Responsive ─────────────────────────────────────────────────────────────  */
@media (max-width: 600px) {
  .mif-hero { padding: 32px 16px; }
  .mif-hero-title { font-size: 1.4rem; }
  .mif-steps { gap: 4px; }
  .mif-step-label { display: none; }
  .mif-feature-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<script>
// Highlight radio cards on change
document.querySelectorAll('.mif-radio-card input').forEach(function(inp) {
  inp.addEventListener('change', function() {
    var group = inp.closest('.mif-radio-group');
    if (group) {
      group.querySelectorAll('.mif-radio-card').forEach(function(c) { c.classList.remove('selected'); });
    }
    inp.closest('.mif-radio-card').classList.add('selected');
  });
});
// Highlight feature cards on change
document.querySelectorAll('.mif-feature-card input[type="checkbox"]').forEach(function(inp) {
  inp.addEventListener('change', function() {
    inp.closest('.mif-feature-card').classList.toggle('selected', inp.checked);
  });
});
</script>

<?php render_footer(); ?>
