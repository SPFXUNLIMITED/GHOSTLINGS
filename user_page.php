<?php
/**
 * user_page.php – Authenticated user's personal page.
 * Shows their service request entry in a card and places a pin on a US map.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

// Fetch the current user's full record
$stmt = $pdo->prepare(
  "SELECT u.id, u.username, u.email, u.role,
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

render_header('My Service Request');
?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">My Service Request</h1>
  <p class="muted" style="margin:0;">Logged in as <strong><?= h($data['username']) ?></strong></p>
</div>

<?php if (!$has_entry): ?>
  <div class="card" style="text-align:center; padding:32px;">
    <p class="muted">No service request found for your account.</p>
    <a class="btn primary" href="form.php">Submit a Service Request</a>
  </div>
<?php else: ?>

<!-- ── Entry card ─────────────────────────────────────────────────────────── -->
<div class="card">
  <h2 style="margin-top:0;">Your Service Request</h2>
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
  <h2 style="margin-top:0;">Your Location</h2>
  <p class="muted" style="margin:0 0 12px;">
    <?= h($data['city']) ?>, <?= h($data['state']) ?> <?= h($data['zip_code']) ?>
  </p>
  <div id="map" style="height:400px; border-radius:8px; border:1px solid var(--b);"></div>
</div>

<!-- Leaflet CSS & JS (open source, no API key needed) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV/XN/WLEg=" crossorigin=""></script>

<script>
(function () {
  var city    = <?= json_encode($data['city']) ?>;
  var state   = <?= json_encode($data['state']) ?>;
  var zip     = <?= json_encode($data['zip_code']) ?>;
  var country = 'United States';

  // Default center: contiguous USA
  var map = L.map('map').setView([39.5, -98.35], 4);

  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  }).addTo(map);

  // Geocode using Nominatim (free, no key)
  var query = encodeURIComponent(zip + ' ' + city + ', ' + state + ', ' + country);
  fetch('https://nominatim.openstreetmap.org/search?q=' + query + '&format=json&limit=1', {
    headers: { 'Accept-Language': 'en-US,en' }
  })
  .then(function (r) { return r.json(); })
  .then(function (results) {
    if (results && results.length > 0) {
      var lat = parseFloat(results[0].lat);
      var lon = parseFloat(results[0].lon);
      map.setView([lat, lon], 11);
      L.marker([lat, lon])
        .addTo(map)
        .bindPopup('<strong>' + city + ', ' + state + ' ' + zip + '</strong>')
        .openPopup();
    } else {
      // Fallback: show state-level view using US map center
      var el = document.getElementById('map');
      if (el) {
        el.insertAdjacentHTML('afterend',
          '<p class="muted" style="margin-top:6px;">Could not geocode location exactly; showing approximate US region.</p>');
      }
    }
  })
  .catch(function () { /* geocoding unavailable, map still shows */ });
})();
</script>

<?php endif; ?>

<?php render_footer(); ?>
