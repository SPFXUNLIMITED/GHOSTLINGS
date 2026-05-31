<?php
/**
 * service_request_form.php – Public laser-machine service request form.
 * Open to the public; no login required.
 * Security: CSRF token, per-IP rate limit, single entry per email.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
$cfg = require __DIR__ . '/config.php';
$recaptcha_site_key   = $cfg['recaptcha']['site_key']   ?? '';
$recaptcha_secret_key = $cfg['recaptcha']['secret_key'] ?? '';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// If the user is already logged in and is a 'user' role, redirect to their page
if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'user') {
  header('Location: user_page.php');
  exit;
}

// ── CSRF token management ─────────────────────────────────────────────────────
if (empty($_SESSION['form_csrf'])) {
  $_SESSION['form_csrf'] = bin2hex(random_bytes(24));
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function client_ip(): string {
  foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) {
      $ip = trim(explode(',', $_SERVER[$k])[0]);
      if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
  }
  return '0.0.0.0';
}

$errors  = [];
$success = false;
$fields  = [
  'first_name'    => '',
  'last_name'     => '',
  'cell_phone'    => '',
  'city'          => '',
  'state'         => '',
  'zip_code'      => '',
  'email'         => '',
  'laser_brand'   => '',
  'laser_model'   => '',
  'laser_watts'   => '',
  'laser_age'     => '',
  'laser_problem' => '',
  'service_type'  => 'standard',
];

$service_types = [
  'standard' => 'Standard Service',
  'vip'      => 'VIP Service',
];

// US states list for the dropdown
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

// Waiting-user map data (latest entry per user)
$waiting_users = [];
$waiting_total = 0;
$waiting_verified = 0;
$waiting_unverified = 0;
try {
  $waiting_stmt = $pdo->query(
    "SELECT u.id, u.email_verified, le.city, le.state, le.zip_code
     FROM users u
     JOIN laser_entries le ON le.user_id = u.id
     LEFT JOIN laser_entries le_newer
       ON le_newer.user_id = le.user_id
      AND (
        le_newer.created_at > le.created_at
        OR (le_newer.created_at = le.created_at AND le_newer.id > le.id)
      )
     WHERE u.role = 'user'
       AND le_newer.id IS NULL
     ORDER BY le.created_at DESC, le.id DESC"
  );
  $waiting_users = $waiting_stmt->fetchAll();
  $waiting_total = count($waiting_users);
  foreach ($waiting_users as $wu) {
    if (!empty($wu['email_verified'])) {
      $waiting_verified++;
    }
  }
  $waiting_unverified = $waiting_total - $waiting_verified;
} catch (\Throwable $ex) {
  $waiting_users = [];
  $waiting_total = 0;
  $waiting_verified = 0;
  $waiting_unverified = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ── CSRF check ────────────────────────────────────────────────────────────
  $submitted_csrf = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['form_csrf'] ?? '', $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  }

  if (!$errors) {
    // ── Honeypot (anti-bot) ───────────────────────────────────────────────
    if (!empty($_POST['website'])) {
      // Silent fail for bots
      $success = true;
    } else {
      // ── reCAPTCHA v2 verification ─────────────────────────────────────
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
            'remoteip' => client_ip(),
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
        // ── Collect & sanitize inputs ─────────────────────────────────────────
        foreach ($fields as $k => $_) {
          $fields[$k] = trim((string)($_POST[$k] ?? ''));
        }

        // ── Validate ──────────────────────────────────────────────────────────
        if ($fields['first_name'] === '')  $errors[] = 'First name is required.';
        if ($fields['last_name'] === '')   $errors[] = 'Last name is required.';
        if ($fields['cell_phone'] === '')  $errors[] = 'Cell phone is required.';
        if ($fields['city'] === '')        $errors[] = 'City is required.';
        if ($fields['state'] === '')       $errors[] = 'State is required.';
        if ($fields['zip_code'] === '')    $errors[] = 'ZIP code is required.';
        if ($fields['email'] === '')       $errors[] = 'Email address is required.';
        if ($fields['laser_brand'] === '') $errors[] = 'Laser machine brand is required.';
        if ($fields['laser_model'] === '') $errors[] = 'Laser machine model is required.';
        if ($fields['laser_watts'] === '') $errors[] = 'Wattage is required.';
        if ($fields['laser_age'] === '')   $errors[] = 'Machine age is required.';
        if ($fields['laser_problem'] === '') $errors[] = 'Problem description is required.';

        if (!in_array($fields['service_type'], ['standard', 'vip'], true)) {
          $errors[] = 'Please select a valid service type.';
        }

        if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
          $errors[] = 'Please enter a valid email address.';
        }

        if (!isset($us_states[$fields['state']])) {
          $errors[] = 'Please select a valid US state.';
        }

        if (!preg_match('/^\d{5}(-\d{4})?$/', $fields['zip_code'])) {
          $errors[] = 'ZIP code must be 5 digits (or 5+4 format).';
        }

        if (strlen($fields['laser_problem']) > 5000) {
          $errors[] = 'Problem description must be 5000 characters or fewer.';
        }

        // ── Rate limit: max 5 submissions per IP in 1 hour ───────────────────
        if (!$errors) {
          $ip = client_ip();
          $rl = $pdo->prepare(
            "SELECT COUNT(*) FROM form_rate_limit WHERE ip = ? AND submitted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
          );
          $rl->execute([$ip]);
          if ((int)$rl->fetchColumn() >= 5) {
            $errors[] = 'Too many submissions from your location. Please try again later.';
          }
        }

        // ── Single entry per email ────────────────────────────────────────────
        if (!$errors) {
          $ck = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
          $ck->execute([$fields['email']]);
          if ($ck->fetch()) {
            $errors[] = 'An account with that email address already exists. Please <a href="login.php">log in</a>.';
          }
        }

        if (!$errors) {
          // ── Generate credentials ─────────────────────────────────────────
          $plain_password   = substr(str_replace(['+','/','='], '', base64_encode(random_bytes(18))), 0, 12);
          $password_hash    = password_hash($plain_password, PASSWORD_DEFAULT);
          $verify_token     = bin2hex(random_bytes(32));
          $token_expires    = (new DateTime('now', new DateTimeZone(APP_TZ)))
                                ->modify('+48 hours')
                                ->format('Y-m-d H:i:s');

          // Build a safe username from email (before @)
          $base_username = preg_replace('/[^A-Za-z0-9_.]/', '_', explode('@', $fields['email'])[0]);
          $base_username = substr($base_username, 0, 40);
          // Ensure uniqueness
          $un_check = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
          $un_check->execute([$base_username]);
          $username = $base_username;
          if ($un_check->fetch()) {
            $username = $base_username . '_' . substr(uniqid('', false), -5);
          }

          $pdo->beginTransaction();
          try {
            // Insert user
            $ins_user = $pdo->prepare(
              "INSERT INTO users
                 (username, email, password_hash, is_admin, role, email_verified, verification_token, token_expires)
               VALUES (?, ?, ?, 0, 'user', 0, ?, ?)"
            );
            $ins_user->execute([$username, $fields['email'], $password_hash, $verify_token, $token_expires]);
            $new_user_id = (int)$pdo->lastInsertId();

            // Insert laser entry
            $ins_entry = $pdo->prepare(
              "INSERT INTO laser_entries
                 (user_id, first_name, last_name, cell_phone, city, state, zip_code,
                  email, laser_brand, laser_model, laser_watts, laser_age, laser_problem, service_type, submission_ip)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins_entry->execute([
              $new_user_id,
              $fields['first_name'], $fields['last_name'], $fields['cell_phone'],
              $fields['city'], $fields['state'], $fields['zip_code'],
              $fields['email'],
              $fields['laser_brand'], $fields['laser_model'],
              $fields['laser_watts'], $fields['laser_age'], $fields['laser_problem'],
              $fields['service_type'],
              client_ip(),
            ]);

            // Log rate-limit record
            $pdo->prepare("INSERT INTO form_rate_limit (ip) VALUES (?)")->execute([client_ip()]);

            $pdo->commit();

            // ── Send verification + credentials email ─────────────────────
            $scheme       = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host         = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $script_name  = $_SERVER['SCRIPT_NAME'] ?? '';
            $project_path = rtrim(dirname($script_name), '/');
            if ($project_path === '' || $project_path === '.') {
              $project_path = '';
            }
            $base_url     = $scheme . '://' . $host . $project_path;
            $verify_url   = $base_url . '/verify_email.php?token=' . urlencode($verify_token);
            $login_url    = $base_url . '/login.php';
            $to           = $fields['email'];
            $subject      = 'Verify your email – Ghostlings Laser Support';
            $name_display = h($fields['first_name']) . ' ' . h($fields['last_name']);
            $body = "Hello {$fields['first_name']},\r\n\r\n"
                  . "Thank you for submitting your laser machine customer service request.\r\n\r\n"
                  . "Your login credentials:\r\n"
                  . "  Email:    {$fields['email']}\r\n"
                  . "  Password: {$plain_password}\r\n\r\n"
                  . "Please verify your email address by clicking the link below:\r\n"
                  . "{$verify_url}\r\n\r\n"
                  . "This link expires in 48 hours.\r\n\r\n"
                  . "After verifying, log in at:\r\n"
                  . "{$login_url}\r\n\r\n"
                  . "– Ghostlings Laser Support Team\r\n";
            $headers = "From: no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n"
                     . "Reply-To: no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n"
                     . "X-Mailer: PHP/" . phpversion() . "\r\n"
                     . "Content-Type: text/plain; charset=UTF-8\r\n";
            @mail($to, $subject, $body, $headers);

            // Regenerate CSRF token after successful submit
            $_SESSION['form_csrf'] = bin2hex(random_bytes(24));
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

render_header('Service Request Form');
?>

<div class="srf-hero">
  <div class="srf-hero-glow"></div>
  <div class="srf-hero-content">
    <div class="srf-hero-icon">🛠️</div>
    <h1 class="srf-hero-title">CO2 Laser Service Request</h1>
    <p class="srf-hero-sub">
      Fast Diagnostics &bull; Expert Repairs &bull; Priority Turnaround Options
    </p>
    <div class="srf-hero-badges">
      <span class="srf-badge">⚡ CO2 Specialists</span>
      <span class="srf-badge">🔬 Tube + Optics Support</span>
      <span class="srf-badge">📞 Real Technician Follow-Up</span>
      <span class="srf-badge">🚚 Nationwide Service Help</span>
    </div>
  </div>
</div>

<div class="card srf-value-card">
  <strong>Need your machine back online quickly?</strong>
  Submit your request in minutes and our service team will review your issue, send your login credentials,
  and guide you through next steps for your CO2 laser repair.
</div>

<?php if ($success): ?>
  <div class="card srf-success">
    <div class="srf-success-icon">✅</div>
    <h2 style="margin-top:0;">Request Submitted!</h2>
    <p style="margin:0 0 8px;">
      Thank you! We have received your customer service request. An email has been sent to
      <strong><?= h($fields['email']) ?></strong> with your login credentials and a
      verification link.
    </p>
    <p style="margin:0;">
      Please check your inbox (and spam folder), verify your email, then
      <a href="login.php" style="color:#14532d; font-weight:700;">log in here</a>.
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

  <div class="card srf-steps-card">
    <div class="srf-steps">
      <div class="srf-step active"><span class="srf-step-num">1</span><span class="srf-step-label">Your Info</span></div>
      <div class="srf-step-line"></div>
      <div class="srf-step active"><span class="srf-step-num">2</span><span class="srf-step-label">Machine Problem</span></div>
      <div class="srf-step-line"></div>
      <div class="srf-step active"><span class="srf-step-num">3</span><span class="srf-step-label">Service Level</span></div>
    </div>
  </div>

  <form method="post" class="srf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['form_csrf']) ?>" />
    <!-- Honeypot field – hidden from real users, catches bots -->
    <div style="display:none;" aria-hidden="true">
      <label>Leave blank</label>
      <input type="text" name="website" tabindex="-1" autocomplete="off" />
    </div>

    <div class="card srf-section">
      <div class="srf-section-header">
        <span class="srf-section-num">1</span>
        <div>
          <h2 style="margin:0;">Customer Information</h2>
          <p class="muted" style="margin:2px 0 0;">How can our laser repair specialists reach you?</p>
        </div>
      </div>
      <div class="form-grid">
        <div>
          <label>First Name <span class="srf-req">*</span></label>
          <input type="text" name="first_name" value="<?= h($fields['first_name']) ?>"
                 maxlength="100" required autocomplete="given-name" />
        </div>
        <div>
          <label>Last Name <span class="srf-req">*</span></label>
          <input type="text" name="last_name" value="<?= h($fields['last_name']) ?>"
                 maxlength="100" required autocomplete="family-name" />
        </div>
        <div>
          <label>Cell Phone <span class="srf-req">*</span></label>
          <input type="tel" name="cell_phone" value="<?= h($fields['cell_phone']) ?>"
                 maxlength="30" required autocomplete="tel" placeholder="e.g. 555-867-5309" />
        </div>
        <div>
          <label>Email Address <span class="srf-req">*</span></label>
          <input type="email" name="email" value="<?= h($fields['email']) ?>"
                 maxlength="255" required autocomplete="email" />
        </div>
        <div>
          <label>City <span class="srf-req">*</span></label>
          <input type="text" name="city" value="<?= h($fields['city']) ?>"
                 maxlength="100" required autocomplete="address-level2" />
        </div>
        <div>
          <label>State <span class="srf-req">*</span></label>
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
          <label>ZIP Code <span class="srf-req">*</span></label>
          <input type="text" name="zip_code" value="<?= h($fields['zip_code']) ?>"
                 maxlength="10" required autocomplete="postal-code" placeholder="e.g. 90210" />
        </div>
      </div>
    </div>

    <div class="card srf-section">
      <div class="srf-section-header">
        <span class="srf-section-num">2</span>
        <div>
          <h2 style="margin:0;">Machine Details &amp; Issue</h2>
          <p class="muted" style="margin:2px 0 0;">Give us the key details so we can diagnose faster.</p>
        </div>
      </div>
      <div class="form-grid">
        <div>
          <label>Brand of Laser Machine <span class="srf-req">*</span></label>
          <input type="text" name="laser_brand" value="<?= h($fields['laser_brand']) ?>"
                 maxlength="100" required placeholder="e.g. xTool, Sculpfun, OMTech" />
        </div>
        <div>
          <label>Model <span class="srf-req">*</span></label>
          <input type="text" name="laser_model" value="<?= h($fields['laser_model']) ?>"
                 maxlength="100" required placeholder="e.g. S30 Ultra" />
        </div>
        <div>
          <label>Wattage <span class="srf-req">*</span></label>
          <input type="text" name="laser_watts" value="<?= h($fields['laser_watts']) ?>"
                 maxlength="50" required placeholder="e.g. 40W" />
        </div>
        <div>
          <label>How Old is the Machine? <span class="srf-req">*</span></label>
          <input type="text" name="laser_age" value="<?= h($fields['laser_age']) ?>"
                 maxlength="50" required placeholder="e.g. 2 years" />
        </div>
        <div class="full">
          <label>What is the Problem with the Machine? <span class="srf-req">*</span></label>
          <textarea name="laser_problem" rows="5" required
                    maxlength="5000"><?= h($fields['laser_problem']) ?></textarea>
          <p class="muted" style="margin:4px 0 0;">Max 5000 characters.</p>
        </div>
      </div>
    </div>

    <div class="card srf-section">
      <div class="srf-section-header">
        <span class="srf-section-num">3</span>
        <div>
          <h2 style="margin:0;">Choose Service Level <span class="srf-req">*</span></h2>
          <p class="muted" style="margin:2px 0 0;">Pick the turnaround speed that best matches your repair urgency.</p>
        </div>
      </div>

      <div class="srf-service-grid">
        <label class="srf-service-card <?= $fields['service_type'] === 'standard' ? 'selected' : '' ?>" for="svc_standard">
          <input type="radio" id="svc_standard" name="service_type" value="standard"
                 <?= $fields['service_type'] === 'standard' ? 'checked' : '' ?> required />
          <span class="srf-service-icon">🔧</span>
          <strong class="srf-service-title">Standard Service</strong>
          <span class="srf-service-desc">Normal turnaround &amp; support queue — ideal for non-urgent repairs.</span>
        </label>

        <label class="srf-service-card srf-service-card-vip <?= $fields['service_type'] === 'vip' ? 'selected' : '' ?>" for="svc_vip">
          <input type="radio" id="svc_vip" name="service_type" value="vip"
                 <?= $fields['service_type'] === 'vip' ? 'checked' : '' ?> />
          <span class="srf-service-icon">👑</span>
          <strong class="srf-service-title">VIP Service</strong>
          <span class="srf-service-desc">Priority handling, expedited turnaround, and dedicated support.</span>
          <span class="srf-priority-pill">⚡ PRIORITY</span>
        </label>
      </div>

      <div class="srf-captcha-wrap">
        <div class="g-recaptcha" data-sitekey="<?= h($recaptcha_site_key) ?>"></div>
      </div>
    </div>

    <div class="card srf-submit-card">
      <button type="submit" class="btn primary srf-submit-btn">🚀 Submit Customer Service Request</button>
      <a class="btn srf-login-btn" href="login.php">Already registered? Log in</a>
      <p class="muted srf-submit-note">We respond quickly so you can get back to cutting and engraving fast.</p>
    </div>
  </form>
<?php endif; ?>

<div class="card srf-map-card">
  <h2 style="margin-top:0;">Customer Service Request Map</h2>
  <div class="srf-map-stats">
    <div class="srf-map-stat">
      <p class="muted" style="margin:0 0 4px;">Total Waiting Users</p>
      <strong style="font-size:20px;"><?= (int)$waiting_total ?></strong>
    </div>
    <div class="srf-map-stat">
      <p class="muted" style="margin:0 0 4px;">Verified Users</p>
      <strong style="font-size:20px;"><?= (int)$waiting_verified ?></strong>
    </div>
    <div class="srf-map-stat">
      <p class="muted" style="margin:0 0 4px;">Pending Verification</p>
      <strong style="font-size:20px;"><?= (int)$waiting_unverified ?></strong>
    </div>
  </div>
  <p class="muted" id="serviceMapStats" style="margin:0 0 12px;">Loading map pins…</p>
  <div id="serviceRequestMap" style="height:400px; border-radius:8px; border:1px solid var(--b);"></div>
</div>

<style>
.srf-hero {
  position: relative;
  background: linear-gradient(135deg, #111827 0%, #0f3b63 45%, #07283f 100%);
  border-radius: 14px;
  padding: 48px 24px;
  text-align: center;
  margin: 12px 0;
  overflow: hidden;
  box-shadow: 0 10px 30px rgba(2, 6, 23, .45);
}
.srf-hero-glow {
  position: absolute;
  top: -70px;
  left: 50%;
  transform: translateX(-50%);
  width: 520px;
  height: 260px;
  background: radial-gradient(ellipse, rgba(59,130,246,.3) 0%, transparent 70%);
  pointer-events: none;
}
.srf-hero-content { position: relative; z-index: 1; }
.srf-hero-icon { font-size: 54px; line-height: 1; margin-bottom: 12px; filter: drop-shadow(0 0 20px rgba(59,130,246,.65)); }
.srf-hero-title { margin: 0 0 8px; color: #eff6ff; font-size: 2rem; text-shadow: 0 2px 10px rgba(0,0,0,.45); }
.srf-hero-sub { margin: 0 0 18px; color: #bfdbfe; font-size: 1.05rem; }
.srf-hero-badges { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
.srf-badge {
  background: rgba(255,255,255,.1);
  color: #dbeafe;
  border: 1px solid rgba(255,255,255,.2);
  border-radius: 999px;
  padding: 6px 14px;
  font-size: 13px;
  backdrop-filter: blur(4px);
}
.srf-value-card {
  margin-top: 10px;
  border-left: 4px solid #f59e0b;
  background: linear-gradient(90deg, #fffbeb, #ffffff 75%);
}
.srf-success {
  text-align: center;
  padding: 38px 24px;
  border-color: #bbf7d0;
  background: linear-gradient(135deg, #f0fdf4, #dcfce7);
  color: #166534;
}
.srf-success-icon { font-size: 52px; line-height: 1; margin-bottom: 10px; }
.srf-steps-card { padding: 16px 20px; }
.srf-steps { display: flex; align-items: center; gap: 6px; }
.srf-step { display: flex; align-items: center; gap: 8px; color: var(--m); font-size: 14px; font-weight: 500; }
.srf-step.active { color: var(--p); }
.srf-step-num {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--b);
  color: var(--m);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
}
.srf-step.active .srf-step-num { background: var(--p); color: #fff; }
.srf-step-line { flex: 1; min-width: 24px; height: 2px; background: var(--b); }
.srf-form { display: flex; flex-direction: column; gap: 8px; }
.srf-section { margin: 0; }
.srf-section-header {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  margin-bottom: 18px;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--b);
}
.srf-section-num {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: linear-gradient(135deg, #2563eb, #0ea5e9);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  font-weight: 700;
  box-shadow: 0 3px 12px rgba(37,99,235,.32);
}
.srf-req { color: var(--d); }
.srf-service-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 14px;
}
.srf-service-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  border: 2px solid var(--b);
  border-radius: 12px;
  padding: 24px 20px;
  cursor: pointer;
  transition: border-color .2s, box-shadow .2s, background .2s;
  background: var(--card, #fff);
  position: relative;
}
.srf-service-card:hover { border-color: #d97706; box-shadow: 0 2px 12px rgba(0,0,0,.12); }
.srf-service-card.selected,
.srf-service-card:has(input[type="radio"]:checked) {
  border-color: #f59e0b;
  background: #fffbeb;
  box-shadow: 0 0 0 3px rgba(245,158,11,.2);
}
.srf-service-card input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
.srf-service-icon { font-size: 2.4rem; line-height: 1; margin-bottom: 10px; }
.srf-service-title { font-size: 1.1rem; margin-bottom: 6px; }
.srf-service-desc { font-size: .85rem; color: var(--m); }
.srf-service-card-vip .srf-service-title { color: #b45309; }
.srf-priority-pill {
  margin-top: 10px;
  display: inline-block;
  font-size: .75rem;
  font-weight: 600;
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #f59e0b;
  border-radius: 999px;
  padding: 3px 12px;
  letter-spacing: .04em;
}
.srf-captcha-wrap { margin-top: 20px; }
.srf-submit-card { text-align: center; padding: 24px; }
.srf-submit-btn {
  font-size: 16px !important;
  padding: 14px 34px !important;
  border-radius: 999px !important;
  background: linear-gradient(135deg, #2563eb, #7c3aed) !important;
  border: none !important;
  box-shadow: 0 5px 16px rgba(37,99,235,.4) !important;
}
.srf-submit-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 8px 24px rgba(37,99,235,.46) !important;
}
.srf-login-btn { margin-left: 8px; }
.srf-submit-note { margin: 10px 0 0; font-size: 13px; }
.srf-map-card { margin-top: 10px; }
.srf-map-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px,1fr));
  gap: 10px;
  margin-bottom: 12px;
}
.srf-map-stat {
  border: 1px solid var(--b);
  border-radius: 8px;
  padding: 10px 12px;
  background: linear-gradient(180deg, #fff, #f8fafc);
}
@media (max-width: 640px) {
  .srf-hero { padding: 34px 16px; }
  .srf-hero-title { font-size: 1.4rem; }
  .srf-step-label { display: none; }
  .srf-login-btn { margin-left: 0; margin-top: 8px; }
}
</style>

<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
document.querySelectorAll('.srf-service-card input[type="radio"]').forEach(function (input) {
  input.addEventListener('change', function () {
    document.querySelectorAll('.srf-service-card').forEach(function (card) {
      card.classList.remove('selected');
    });
    var card = input.closest('.srf-service-card');
    if (card) card.classList.add('selected');
  });
});
</script>
<script>
(function () {
  var waitingUsers = <?= json_encode($waiting_users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
  var statsEl = document.getElementById('serviceMapStats');
  var mapEl = document.getElementById('serviceRequestMap');
  if (!mapEl) return;

  var map = L.map('serviceRequestMap').setView([39.5, -98.35], 4);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  }).addTo(map);

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  if (!waitingUsers.length) {
    if (statsEl) statsEl.textContent = 'No waiting users found.';
    return;
  }

  var grouped = {};
  waitingUsers.forEach(function (u) {
    var key = JSON.stringify([u.zip_code || '', u.city || '', u.state || '']);
    if (!grouped[key]) {
      grouped[key] = { city: u.city || '', state: u.state || '', zip_code: u.zip_code || '', users: [] };
    }
    grouped[key].users.push(u);
  });

  var locations = Object.keys(grouped).map(function (k) { return grouped[k]; });
  var bounds = L.latLngBounds();
  var mappedUsers = 0;
  var NOMINATIM_RATE_LIMIT_MS = 1100;

  function geocodeLocation(loc) {
    var query = encodeURIComponent((loc.zip_code || '') + ' ' + (loc.city || '') + ', ' + (loc.state || '') + ', United States');
    return fetch('https://nominatim.openstreetmap.org/search?q=' + query + '&format=json&limit=1', {
      headers: { 'Accept-Language': 'en-US,en' }
    })
      .then(function (r) { return r.json(); })
      .then(function (results) {
        if (!results || !results.length) return;
        var lat = parseFloat(results[0].lat);
        var lon = parseFloat(results[0].lon);
        if (!isFinite(lat) || !isFinite(lon)) return;

        mappedUsers += loc.users.length;
        bounds.extend([lat, lon]);
        L.marker([lat, lon]).addTo(map).bindPopup(
          '<strong>' + esc(loc.city) + ', ' + esc(loc.state) + ' ' + esc(loc.zip_code) + '</strong><br>' +
          'Users waiting here: <strong>' + loc.users.length + '</strong>'
        );
      })
      .catch(function () {});
  }

  function finishMap() {
    if (mappedUsers > 0) {
      map.fitBounds(bounds.pad(0.2), { maxZoom: 10 });
      if (statsEl) statsEl.textContent = 'Showing ' + mappedUsers + ' of ' + waitingUsers.length + ' waiting users on the map.';
    } else if (statsEl) {
      statsEl.textContent = 'Could not geocode waiting-user locations; showing US overview.';
    }
  }

  function processLocationAt(index) {
    if (index >= locations.length) {
      finishMap();
      return;
    }
    geocodeLocation(locations[index]).finally(function () {
      setTimeout(function () { processLocationAt(index + 1); }, NOMINATIM_RATE_LIMIT_MS);
    });
  }

  if (statsEl) statsEl.textContent = 'Geocoding ' + locations.length + ' location(s) for ' + waitingUsers.length + ' waiting users...';
  processLocationAt(0);
})();
</script>
<?php render_footer(); ?>
