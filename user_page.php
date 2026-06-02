<?php
/**
 * user_page.php – Authenticated user's personal page.
 * Shows their service request entry in a card and places a pin on a US map.
 * Also provides a profile details form for updating contact information.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$current_role = (string)($_SESSION['role'] ?? '');
$is_standard_user = ($current_role !== 'admin' && $current_role !== 'moderator');

// ── Profile details CSRF ──────────────────────────────────────────────────────
if (empty($_SESSION['user_page_profile_csrf'])) {
  $_SESSION['user_page_profile_csrf'] = bin2hex(random_bytes(24));
}

$profile_errors  = [];
$profile_success = '';

// ── Profile details POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['user_page_profile_csrf'], $csrf)) {
    $profile_errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $p_contact_name = trim((string)($_POST['contact_name'] ?? ''));
    $p_email        = trim((string)($_POST['profile_email'] ?? ''));
    $p_phone        = trim((string)($_POST['contact_phone'] ?? ''));
    $p_company      = trim((string)($_POST['company_name'] ?? ''));
    $p_delivery_address = trim((string)($_POST['delivery_address'] ?? ''));
    $p_notes        = trim((string)($_POST['profile_notes'] ?? ''));

    if (strlen($p_contact_name) > 255) {
      $profile_errors[] = 'Name must be 255 characters or fewer.';
    }
    if ($p_email !== '' && !filter_var($p_email, FILTER_VALIDATE_EMAIL)) {
      $profile_errors[] = 'Email must be a valid email address.';
    }
    if (strlen($p_email) > 255) {
      $profile_errors[] = 'Email must be 255 characters or fewer.';
    }
    if (strlen($p_phone) > 100) {
      $profile_errors[] = 'Phone must be 100 characters or fewer.';
    }
    if (strlen($p_company) > 255) {
      $profile_errors[] = 'Company name must be 255 characters or fewer.';
    }
    if (strlen($p_delivery_address) > 500) {
      $profile_errors[] = 'Delivery address must be 500 characters or fewer.';
    }

    if (empty($profile_errors) && $p_email !== '') {
      $ck = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
      $ck->execute([$p_email, (int)$_SESSION['user_id']]);
      if ($ck->fetch()) {
        $profile_errors[] = 'That email is already used by another account.';
      }
    }

    if (empty($profile_errors)) {
      $upd = $pdo->prepare(
        "UPDATE users
         SET contact_name = ?, email = ?, contact_phone = ?, company_name = ?, delivery_address = ?, profile_notes = ?
         WHERE id = ?"
      );
      $upd->execute([
        $p_contact_name === '' ? null : $p_contact_name,
        $p_email        === '' ? null : $p_email,
        $p_phone        === '' ? null : $p_phone,
        $p_company      === '' ? null : $p_company,
        $p_delivery_address === '' ? null : $p_delivery_address,
        $p_notes        === '' ? null : $p_notes,
        (int)$_SESSION['user_id'],
      ]);
      $_SESSION['user_page_profile_csrf'] = bin2hex(random_bytes(24));
      $profile_success = 'Your profile details have been saved.';
    }
  }
}

// ── Fetch the current user's full record
$stmt = $pdo->prepare(
  "SELECT u.id, u.username, u.email, u.role,
          u.contact_name, u.contact_phone, u.company_name, u.delivery_address, u.profile_notes,
          le.first_name, le.last_name, le.cell_phone,
          le.city, le.state, le.zip_code, le.email AS entry_email,
          le.laser_brand, le.laser_model, le.laser_watts, le.laser_age,
          le.laser_problem, le.created_at
   FROM users u
   LEFT JOIN laser_entries le ON le.user_id = u.id
   WHERE u.id = ? LIMIT 1"
);
$stmt->execute([(int)$_SESSION['user_id']]);
$data = $stmt->fetch();

if (!$data) {
  http_response_code(404);
  exit('User not found.');
}

$has_entry = !empty($data['first_name']);

// Fetch all regular users who currently have service requests ("waiting for service")
$waiting_users = [];
$waiting_total = 0;
$waiting_verified = 0;
$waiting_unverified = 0;
if ($is_standard_user) {
  $waiting_stmt = $pdo->query(
    "SELECT u.id, u.email_verified,
            le.city, le.state, le.zip_code
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
}

render_header('My Profile');
?>

<!-- ── Profile details alerts ─────────────────────────────────────────────── -->
<?php if ($profile_errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($profile_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($profile_success): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
    <?= h($profile_success) ?>
  </div>
<?php endif; ?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">My Profile</h1>
  <p class="muted" style="margin:0;">Logged in as <strong><?= h($data['username']) ?></strong></p>
</div>

<!-- ── My Profile Details ────────────────────────────────────────────────── -->
<div class="card">
  <h2 style="margin-top:0;">My Profile Details</h2>
  <p class="muted">Update your contact information used across the site.</p>
  <p class="muted" style="margin-top:6px;">
    Available placeholders: <code>[contact_name]</code> <code>[company_name]</code> <code>[email]</code> <code>[contact_phone]</code> <code>[username]</code>.
  </p>
  <form method="post" style="max-width:540px;">
    <input type="hidden" name="action" value="update_profile">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['user_page_profile_csrf']) ?>">

    <div class="form-grid">
      <div>
        <label>Full Name</label>
        <input type="text" name="contact_name" maxlength="255"
               value="<?= h((string)($data['contact_name'] ?? '')) ?>"
               placeholder="Your full name" />
      </div>

      <div>
        <label>Email Address</label>
        <input type="email" name="profile_email" maxlength="255"
               value="<?= h((string)($data['email'] ?? '')) ?>"
               placeholder="you@example.com" />
      </div>

      <div>
        <label>Phone Number</label>
        <input type="text" name="contact_phone" maxlength="100"
               value="<?= h((string)($data['contact_phone'] ?? '')) ?>"
               placeholder="e.g. (555) 123-4567" />
      </div>

      <div>
        <label>Company / Organization</label>
        <input type="text" name="company_name" maxlength="255"
               value="<?= h((string)($data['company_name'] ?? '')) ?>"
               placeholder="Your company name" />
      </div>

      <div class="full">
        <label>Delivery Address</label>
        <textarea name="delivery_address" rows="3" maxlength="500"
                  placeholder="Address to prefill for purchase orders"><?= h((string)($data['delivery_address'] ?? '')) ?></textarea>
      </div>

      <div class="full">
        <label>Additional Notes</label>
        <textarea name="profile_notes" rows="4"
                  placeholder="Any additional details about yourself or your business…"><?= h((string)($data['profile_notes'] ?? '')) ?></textarea>
        <div class="muted" style="margin-top:6px;">
          Available placeholders: <code>[contact_name]</code> <code>[company_name]</code> <code>[email]</code> <code>[contact_phone]</code> <code>[username]</code>.
        </div>
      </div>

      <div class="full">
        <div class="row" style="margin-top:6px;">
          <button type="submit" class="btn primary">Save Details</button>
        </div>
      </div>
    </div>
  </form>
</div>

<?php if ($is_standard_user && !$has_entry): ?>
  <div class="card" style="text-align:center; padding:32px;">
    <p class="muted">No customer service request found for your account.</p>
    <a class="btn primary" href="service_request_form.php">Submit a Customer Service Request</a>
  </div>
<?php elseif ($is_standard_user): ?>

<!-- ── Entry card ─────────────────────────────────────────────────────────── -->
<div class="card">
  <h2 style="margin-top:0;">Your Customer Service Request</h2>
  <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap:16px; margin-bottom:16px;">

    <div>
      <p class="muted" style="margin:0 0 2px;">Name</p>
      <strong><?= h($data['first_name']) ?> <?= h($data['last_name']) ?></strong>
    </div>

    <div>
      <p class="muted" style="margin:0 0 2px;">Cell Phone</p>
      <strong><?= h($data['cell_phone']) ?></strong>
    </div>

    <div>
      <p class="muted" style="margin:0 0 2px;">Email</p>
      <strong><?= h($data['entry_email']) ?></strong>
    </div>

    <div>
      <p class="muted" style="margin:0 0 2px;">Location</p>
      <strong><?= h($data['city']) ?>, <?= h($data['state']) ?> <?= h($data['zip_code']) ?></strong>
    </div>

    <div>
      <p class="muted" style="margin:0 0 2px;">Laser Brand</p>
      <strong><?= h($data['laser_brand']) ?></strong>
    </div>

    <div>
      <p class="muted" style="margin:0 0 2px;">Model</p>
      <strong><?= h($data['laser_model']) ?></strong>
    </div>

    <div>
      <p class="muted" style="margin:0 0 2px;">Wattage</p>
      <strong><?= h($data['laser_watts']) ?></strong>
    </div>

    <div>
      <p class="muted" style="margin:0 0 2px;">Machine Age</p>
      <strong><?= h($data['laser_age']) ?></strong>
    </div>

    <div style="grid-column: 1 / -1;">
      <p class="muted" style="margin:0 0 2px;">Problem Description</p>
      <div style="background:#f9fafb; border:1px solid var(--b); border-radius:8px; padding:12px; line-height:1.6;">
        <?= nl2br(h($data['laser_problem'])) ?>
      </div>
    </div>

    <div>
      <p class="muted" style="margin:0 0 2px;">Submitted</p>
      <strong><?= h($data['created_at']) ?></strong>
    </div>
  </div>
</div>

<!-- ── US Map ──────────────────────────────────────────────────────────────── -->
<div class="card">
  <h2 style="margin-top:0;">Users Waiting for Service</h2>
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
  <p class="muted" id="mapStats" style="margin:0 0 12px;">Loading map pins…</p>
  <div id="map" style="height:400px; border-radius:8px; border:1px solid var(--b);"></div>
</div>

<!-- Leaflet CSS & JS (open source, no API key needed) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
(function () {
  var waitingUsers = <?= json_encode($waiting_users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
  var statsEl = document.getElementById('mapStats');
  var country = 'United States';

  // Default center: contiguous USA
  var map = L.map('map').setView([39.5, -98.35], 4);

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
      grouped[key] = {
        city: u.city || '',
        state: u.state || '',
        zip_code: u.zip_code || '',
        users: []
      };
    }
    grouped[key].users.push(u);
  });

  var locations = Object.keys(grouped).map(function (k) { return grouped[k]; });
  var bounds = L.latLngBounds();
  var mappedUsers = 0;
  // Respect Nominatim's ~1 request/second public guidance; 1100ms adds small overhead buffer.
  var NOMINATIM_RATE_LIMIT_MS = 1100;

  function geocodeLocation(loc) {
    var query = encodeURIComponent((loc.zip_code || '') + ' ' + (loc.city || '') + ', ' + (loc.state || '') + ', ' + country);
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
    .catch(function () { /* skip failed geocode */ });
  }

  function finishMap() {
    if (mappedUsers > 0) {
      map.fitBounds(bounds.pad(0.2), { maxZoom: 10 });
      if (statsEl) {
        statsEl.textContent = 'Showing ' + mappedUsers + ' of ' + waitingUsers.length + ' waiting users on the map.';
      }
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
      setTimeout(function () {
        processLocationAt(index + 1);
      }, NOMINATIM_RATE_LIMIT_MS);
    });
  }

  if (statsEl) {
    statsEl.textContent = 'Geocoding ' + locations.length + ' location(s) for ' + waitingUsers.length + ' waiting users...';
  }
  processLocationAt(0);
})();
</script>

<?php endif; ?>

<?php render_footer(); ?>
