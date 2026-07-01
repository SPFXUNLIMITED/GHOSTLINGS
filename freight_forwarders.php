<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$q = trim((string)($_GET['q'] ?? ''));

if (empty($_SESSION['freight_forwarder_delete_csrf'])) {
  $_SESSION['freight_forwarder_delete_csrf'] = bin2hex(random_bytes(24));
}

if ($q !== '') {
  $like = '%' . $q . '%';
  $stmt = $pdo->prepare("
    SELECT * FROM freight_forwarders
    WHERE company_name LIKE ?
       OR headquarters LIKE ?
       OR contact_person LIKE ?
       OR email LIKE ?
       OR phone LIKE ?
       OR primary_routes LIKE ?
      OR certifications LIKE ?
    ORDER BY company_name ASC
  ");
  $stmt->execute([$like, $like, $like, $like, $like, $like, $like]);
} else {
  $stmt = $pdo->query("SELECT * FROM freight_forwarders ORDER BY company_name ASC");
}
$forwarders = $stmt->fetchAll();

render_header('Freight Forwarders');
?>

<!-- Leaflet.js Pacific route map hero banner -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<style>
  #ff-map-hero {
    width: 100%;
    height: 320px;
    border-radius: 10px;
    z-index: 0;
  }
  .ff-map-card {
    padding: 0;
    overflow: hidden;
    position: relative;
    margin-bottom: 18px;
  }
  .ff-sea-label {
    pointer-events: none;
  }
  .ff-sea-label span {
    background: rgba(255,255,255,0.93);
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 5px 14px;
    font-size: 0.82em;
    font-weight: 600;
    color: #374151;
    white-space: nowrap;
    box-shadow: 0 2px 8px rgba(0,0,0,0.14);
    display: block;
    text-align: center;
  }
</style>

<div class="card ff-map-card">
  <div id="ff-map-hero"></div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV/XN2I/E=" crossorigin=""></script>
<script>
(function () {
  // Qingdao, China
  var qingdao   = [36.0671, 120.3826];
  // Long Beach, CA — use extended longitude (+360) so the polyline routes
  // eastward across the Pacific instead of westward through Eurasia.
  var longBeach = [33.7701, 241.8063];

  var midLat = (qingdao[0] + longBeach[0]) / 2;
  var midLng = (qingdao[1] + longBeach[1]) / 2; // ~181°, Pacific Ocean

  var map = L.map('ff-map-hero', {
    center: [midLat, midLng],
    zoom: 2,
    zoomControl: true,
    scrollWheelZoom: false,
    attributionControl: true
  });

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 18
  }).addTo(map);

  var cityIcon = L.divIcon({
    className: '',
    html: '<div style="width:14px;height:14px;border-radius:50%;background:#0ea5e9;border:2.5px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,0.4);"></div>',
    iconSize: [14, 14],
    iconAnchor: [7, 7]
  });

  L.marker(qingdao, { icon: cityIcon })
    .addTo(map)
    .bindTooltip('Qingdao, China', { permanent: true, direction: 'top', offset: [0, -10] });

  L.marker(longBeach, { icon: cityIcon })
    .addTo(map)
    .bindTooltip('Long Beach, CA', { permanent: true, direction: 'bottom', offset: [0, 10] });

  L.polyline([qingdao, longBeach], {
    color: '#0ea5e9',
    weight: 2.5,
    opacity: 0.8,
    dashArray: '8, 6'
  }).addTo(map);

  // Label placed at the geographic midpoint of the route (Pacific Ocean)
  L.marker([midLat, midLng], {
    icon: L.divIcon({
      className: 'ff-sea-label',
      html: '<span>⛵ Approximately 25 days by sea</span>',
      iconSize: [224, 28],
      iconAnchor: [112, 14]
    }),
    interactive: false
  }).addTo(map);
})();
</script>

<div class="card freight-hero page-header">
  <div class="freight-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body freight-hero-body">
    <span class="freight-hero-tag">Global Logistics Network</span>
    <h1>Freight Forwarders <span class="freight-hero-count">(<?= count($forwarders) ?>)</span></h1>
    <p class="muted">Build a premium logistics bench with trusted carriers, certified operators, and route experts ready for your next shipment.</p>
    <ul class="freight-hero-stats" aria-label="Freight forwarder summary">
      <li class="freight-hero-pill"><span class="freight-hero-emoji" aria-hidden="true">🌍</span> Multi-region coverage</li>
      <li class="freight-hero-pill"><span class="freight-hero-emoji" aria-hidden="true">✅</span> Compliance-ready partners</li>
      <li class="freight-hero-pill"><span class="freight-hero-emoji" aria-hidden="true">⚡</span> Faster quote turnarounds</li>
    </ul>
  </div>
  <div class="freight-hero-actions">
    <a class="btn primary" href="freight_forwarder_form.php">+ Add Freight Forwarder</a>
    <button type="button" class="btn" id="focus-forwarder-search">Find a Partner</button>
  </div>
</div>

<div id="forwarder-search" class="card">
  <form method="get" action="freight_forwarders.php" class="row" style="margin-bottom:4px;">
    <input id="forwarder-search-input" type="text" name="q" value="<?= h($q) ?>" aria-label="Search freight forwarders" placeholder="Search by company, route, certification, contact, email, or phone…" style="max-width:360px;" />
    <button type="submit" class="btn">Search</button>
    <?php if ($q !== ''): ?>
      <a class="btn" href="freight_forwarders.php">Clear</a>
    <?php endif; ?>
  </form>
</div>

<?php if (empty($forwarders)): ?>
  <div class="card">
    <p class="muted"><?= $q !== '' ? 'No freight forwarders matched your search.' : 'No freight forwarders yet. Click <strong>+ Add Freight Forwarder</strong> to get started.' ?></p>
  </div>
<?php else: ?>
<div class="card" style="padding:0; overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>Company</th>
        <th>Headquarters</th>
        <th>Certifications / Strengths</th>
        <th>Primary Routes</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($forwarders as $f): ?>
      <?php
        $logo_thumb = (string)($f['logo_thumb'] ?? '');
        $logo_path  = (string)($f['logo_path'] ?? '');
        if ($logo_thumb !== '') {
          $logo_thumb_url = 'uploads/' . rawurlencode($logo_thumb);
        } elseif ($logo_path !== '') {
          $logo_thumb_url = 'uploads/' . rawurlencode($logo_path);
        } else {
          $logo_thumb_url = '';
        }
        if ($logo_path !== '') {
          $logo_full_url = 'uploads/' . rawurlencode($logo_path);
        } elseif ($logo_thumb !== '') {
          $logo_full_url = 'uploads/' . rawurlencode($logo_thumb);
        } else {
          $logo_full_url = '';
        }
      ?>
      <tr>
        <td>
          <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
            <?php if ($logo_thumb_url !== ''): ?>
              <a href="<?= h($logo_full_url) ?>" target="_blank" rel="noopener noreferrer" title="View logo">
                <img src="<?= h($logo_thumb_url) ?>"
                     alt="<?= h($f['company_name']) ?> logo"
                     loading="lazy"
                     decoding="async"
                     style="max-width:60px; max-height:40px; object-fit:contain; display:block;" />
              </a>
            <?php else: ?>
              <span class="muted" style="font-size:0.85em;">—</span>
            <?php endif; ?>
            <strong><?= h($f['company_name']) ?></strong>
          </div>
        </td>
        <td><?= $f['headquarters'] !== '' ? h($f['headquarters']) : '<span class="muted">—</span>' ?></td>
        <td><?= $f['certifications'] !== '' ? h($f['certifications']) : '<span class="muted">—</span>' ?></td>
        <td><?= $f['primary_routes'] !== '' ? h($f['primary_routes']) : '<span class="muted">—</span>' ?></td>
        <td class="actions">
          <a class="btn" href="freight_forwarder_details.php?id=<?= (int)$f['id'] ?>">View</a>
          <a class="btn" href="freight_forwarder_form.php?id=<?= (int)$f['id'] ?>">Edit</a>
          <?php if (is_admin()): ?>
          <form method="post" action="freight_forwarder_delete.php" style="display:inline;"
                onsubmit="return confirm('Delete this freight forwarder?');">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['freight_forwarder_delete_csrf']) ?>" />
            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>" />
            <button type="submit" class="btn danger">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var jumpButton = document.getElementById('focus-forwarder-search');
  var searchInput = document.getElementById('forwarder-search-input');
  if (!jumpButton || !searchInput) return;

  jumpButton.addEventListener('click', function () {
    searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
    requestAnimationFrame(function () {
      searchInput.focus();
    });
  });
});
</script>

<?php render_footer(); ?>
