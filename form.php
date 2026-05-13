<?php
/**
 * form.php – Public laser-machine registration form.
 * Open to the public; no login required.
 * Security: CSRF token, per-IP rate limit, single entry per email.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

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

function new_form_captcha(): array {
  $a = random_int(1, 9);
  $b = random_int(1, 9);
  return [
    'a' => $a,
    'b' => $b,
    'answer' => (string)($a + $b),
  ];
}

if (
  empty($_SESSION['form_captcha']) ||
  !is_array($_SESSION['form_captcha']) ||
  !isset($_SESSION['form_captcha']['a'], $_SESSION['form_captcha']['b'], $_SESSION['form_captcha']['answer'])
) {
  $_SESSION['form_captcha'] = new_form_captcha();
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
      $captcha_answer = trim((string)($_POST['captcha_answer'] ?? ''));
      if (
        $captcha_answer === '' ||
        !hash_equals((string)($_SESSION['form_captcha']['answer'] ?? ''), $captcha_answer)
      ) {
        $errors[] = 'Captcha answer is incorrect. Please try again.';
      }

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
                email, laser_brand, laser_model, laser_watts, laser_age, laser_problem, submission_ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
          );
          $ins_entry->execute([
            $new_user_id,
            $fields['first_name'], $fields['last_name'], $fields['cell_phone'],
            $fields['city'], $fields['state'], $fields['zip_code'],
            $fields['email'],
            $fields['laser_brand'], $fields['laser_model'],
            $fields['laser_watts'], $fields['laser_age'], $fields['laser_problem'],
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
                . "Thank you for submitting your laser machine service request.\r\n\r\n"
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
          $_SESSION['form_captcha'] = new_form_captcha();
          $success = true;
        } catch (\Throwable $ex) {
          $pdo->rollBack();
          $errors[] = 'A database error occurred. Please try again.';
        }
      }

      if (!$success) {
        $_SESSION['form_captcha'] = new_form_captcha();
      }
    }
  }
}

render_header('Service Request Form');
?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">Laser Machine Service Request</h1>
  <p class="muted" style="margin:0;">
    Fill out the form below to submit a service request. You will receive a verification
    email with your login credentials.
  </p>
</div>

<?php if ($success): ?>
  <div class="card" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; text-align:center; padding:32px;">
    <h2 style="margin-top:0;">✅ Request Submitted!</h2>
    <p>
      Thank you! We have received your service request. An email has been sent to
      <strong><?= h($fields['email']) ?></strong> with your login credentials and a
      verification link.
    </p>
    <p style="margin-bottom:0;">
      Please check your inbox (and spam folder), verify your email, then
      <a href="login.php" style="color:#166534; font-weight:600;">log in here</a>.
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

  <form method="post" class="card" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['form_csrf']) ?>" />
    <!-- Honeypot field – hidden from real users, catches bots -->
    <div style="display:none;" aria-hidden="true">
      <label>Leave blank</label>
      <input type="text" name="website" tabindex="-1" autocomplete="off" />
    </div>

    <h2 style="margin-top:0;">Personal Information</h2>
    <div class="form-grid">
      <div>
        <label>First Name <span style="color:var(--d)">*</span></label>
        <input type="text" name="first_name" value="<?= h($fields['first_name']) ?>"
               maxlength="100" required autocomplete="given-name" />
      </div>
      <div>
        <label>Last Name <span style="color:var(--d)">*</span></label>
        <input type="text" name="last_name" value="<?= h($fields['last_name']) ?>"
               maxlength="100" required autocomplete="family-name" />
      </div>
      <div>
        <label>Cell Phone <span style="color:var(--d)">*</span></label>
        <input type="tel" name="cell_phone" value="<?= h($fields['cell_phone']) ?>"
               maxlength="30" required autocomplete="tel" placeholder="e.g. 555-867-5309" />
      </div>
      <div>
        <label>Email Address <span style="color:var(--d)">*</span></label>
        <input type="email" name="email" value="<?= h($fields['email']) ?>"
               maxlength="255" required autocomplete="email" />
      </div>
      <div>
        <label>City <span style="color:var(--d)">*</span></label>
        <input type="text" name="city" value="<?= h($fields['city']) ?>"
               maxlength="100" required autocomplete="address-level2" />
      </div>
      <div>
        <label>State <span style="color:var(--d)">*</span></label>
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
        <label>ZIP Code <span style="color:var(--d)">*</span></label>
        <input type="text" name="zip_code" value="<?= h($fields['zip_code']) ?>"
               maxlength="10" required autocomplete="postal-code" placeholder="e.g. 90210" />
      </div>
    </div>

    <h2 style="margin-top:20px;">Laser Machine Information</h2>
    <div class="form-grid">
      <div>
        <label>Brand of Laser Machine <span style="color:var(--d)">*</span></label>
        <input type="text" name="laser_brand" value="<?= h($fields['laser_brand']) ?>"
               maxlength="100" required placeholder="e.g. xTool, Sculpfun, OMTech" />
      </div>
      <div>
        <label>Model <span style="color:var(--d)">*</span></label>
        <input type="text" name="laser_model" value="<?= h($fields['laser_model']) ?>"
               maxlength="100" required placeholder="e.g. S30 Ultra" />
      </div>
      <div>
        <label>Wattage <span style="color:var(--d)">*</span></label>
        <input type="text" name="laser_watts" value="<?= h($fields['laser_watts']) ?>"
               maxlength="50" required placeholder="e.g. 40W" />
      </div>
      <div>
        <label>How Old is the Machine? <span style="color:var(--d)">*</span></label>
        <input type="text" name="laser_age" value="<?= h($fields['laser_age']) ?>"
               maxlength="50" required placeholder="e.g. 2 years" />
      </div>
      <div class="full">
        <label>What is the Problem with the Machine? <span style="color:var(--d)">*</span></label>
        <textarea name="laser_problem" rows="5" required
                  maxlength="5000"><?= h($fields['laser_problem']) ?></textarea>
        <p class="muted" style="margin:4px 0 0;">Max 5000 characters.</p>
      </div>
      <div>
        <label>Captcha: What is <?= (int)($_SESSION['form_captcha']['a'] ?? 0) ?> + <?= (int)($_SESSION['form_captcha']['b'] ?? 0) ?>? <span style="color:var(--d)">*</span></label>
        <input type="text" name="captcha_answer" inputmode="numeric" pattern="[0-9]*" required autocomplete="off" />
      </div>
    </div>

    <div class="row" style="margin-top:18px;">
      <button type="submit" class="btn primary">Submit Service Request</button>
      <a class="btn" href="login.php">Already registered? Log in</a>
    </div>
  </form>
<?php endif; ?>

<?php render_footer(); ?>
