<?php
/**
 * form.php – Public laser-machine registration form.
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

render_header('Customer Service Request Form');
?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">Laser Machine Customer Service Request</h1>
  <p class="muted" style="margin:0;">
    Fill out the form below to submit a customer service request. You will receive a verification
    email with your login credentials.
  </p>
</div>

<?php if ($success): ?>
  <div class="card" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; text-align:center; padding:32px;">
    <h2 style="margin-top:0;">✅ Request Submitted!</h2>
    <p>
      Thank you! We have received your customer service request. An email has been sent to
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
      <div class="full">
        <div class="g-recaptcha" data-sitekey="<?= h($recaptcha_site_key) ?>"></div>
      </div>
    </div>

    <div class="row" style="margin-top:18px;">
      <button type="submit" class="btn primary">Submit Customer Service Request</button>
      <a class="btn" href="login.php">Already registered? Log in</a>
    </div>
  </form>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;">Customer Service Request Map</h2>
  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:10px; margin-bottom:12px;">
    <div style="border:1px solid var(--b); border-radius:8px; padding:10px 12px;">
      <p class="muted" style="margin:0 0 4px;">Total Waiting Users</p>
      <strong style="font-size:20px;"><?= (int)$waiting_total ?></strong>
    </div>
    <div style="border:1px solid var(--b); border-radius:8px; padding:10px 12px;">
      <p class="muted" style="margin:0 0 4px;">Verified Users</p>
      <strong style="font-size:20px;"><?= (int)$waiting_verified ?></strong>
    </div>
    <div style="border:1px solid var(--b); border-radius:8px; padding:10px 12px;">
      <p class="muted" style="margin:0 0 4px;">Pending Verification</p>
      <strong style="font-size:20px;"><?= (int)$waiting_unverified ?></strong>
    </div>
  </div>
  <p class="muted" id="serviceMapStats" style="margin:0 0 12px;">Loading map pins…</p>
  <div id="serviceRequestMap" style="height:400px; border-radius:8px; border:1px solid var(--b);"></div>
</div>

<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
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
