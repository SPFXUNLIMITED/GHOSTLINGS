<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$q = trim((string)($_GET['q'] ?? ''));

if (empty($_SESSION['vendor_delete_csrf'])) {
  $_SESSION['vendor_delete_csrf'] = bin2hex(random_bytes(24));
}
if ($q !== '') {
  $like = '%' . $q . '%';
  $stmt = $pdo->prepare("
    SELECT * FROM vendors
    WHERE company_name LIKE ? OR contact_name LIKE ? OR email LIKE ? OR phone LIKE ? OR port LIKE ?
    ORDER BY company_name ASC
  ");
  $stmt->execute([$like, $like, $like, $like, $like]);
} else {
  $stmt = $pdo->query("SELECT * FROM vendors ORDER BY company_name ASC");
}
$vendors = $stmt->fetchAll();

render_header('Vendors');
?>

<!-- Leaflet.js China map hero banner -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<style>
  #vendor-map-hero {
    width: 100%;
    height: 320px;
    border-radius: 10px;
    z-index: 0;
  }
  .vendor-map-card {
    padding: 0;
    overflow: hidden;
    position: relative;
    margin-bottom: 18px;
  }
  .vendor-map-badge {
    position: absolute;
    bottom: 16px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(255,255,255,0.93);
    border: 1px solid #e5e7eb;
    border-radius: 20px;
    padding: 6px 18px;
    font-size: 0.88em;
    font-weight: 600;
    color: #374151;
    box-shadow: 0 2px 8px rgba(0,0,0,0.13);
    pointer-events: none;
    white-space: nowrap;
    z-index: 999;
  }
</style>

<div class="card vendor-map-card">
  <div id="vendor-map-hero"></div>
  <div class="vendor-map-badge">📍 Qingdao → Shenzhen &nbsp;|&nbsp; ~1,180 miles</div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV/XN2I/E=" crossorigin=""></script>
<script>
(function () {
  var qingdao   = [36.0671, 120.3826];
  var shenzhen  = [22.5431, 114.0579];
  var midLat    = (qingdao[0] + shenzhen[0]) / 2;
  var midLng    = (qingdao[1] + shenzhen[1]) / 2;

  var map = L.map('vendor-map-hero', {
    center: [midLat, midLng],
    zoom: 5,
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
    html: '<div style="width:14px;height:14px;border-radius:50%;background:#2563eb;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.4);"></div>',
    iconSize: [14, 14],
    iconAnchor: [7, 7]
  });

  L.marker(qingdao,  { icon: cityIcon })
    .addTo(map)
    .bindTooltip('Qingdao', { permanent: true, direction: 'top', offset: [0, -10], className: '' });

  L.marker(shenzhen, { icon: cityIcon })
    .addTo(map)
    .bindTooltip('Shenzhen', { permanent: true, direction: 'bottom', offset: [0, 10], className: '' });

  L.polyline([qingdao, shenzhen], {
    color: '#2563eb',
    weight: 2.5,
    opacity: 0.75,
    dashArray: '7, 7'
  }).addTo(map);
})();
</script>

<div class="card page-header">
  <div class="page-header-body">
    <h1>Vendors <span class="muted" style="font-size:0.7em; font-weight:400;">(<?= count($vendors) ?>)</span></h1>
    <p class="muted">Manage supplier contacts for RFQs and sourcing requests.</p>
  </div>
  <a class="btn primary" href="vendor_form.php">+ Add Vendor</a>
</div>

<div class="card">
  <form method="get" action="vendors.php" class="row" style="margin-bottom:4px;">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by company, contact, email, or phone…" style="max-width:360px;" />
    <button type="submit" class="btn">Search</button>
    <?php if ($q !== ''): ?>
      <a class="btn" href="vendors.php">Clear</a>
    <?php endif; ?>
  </form>
</div>

<?php if (empty($vendors)): ?>
  <div class="card">
    <p class="muted"><?= $q !== '' ? 'No vendors matched your search.' : 'No vendors yet. Click <strong>+ Add Vendor</strong> to get started.' ?></p>
  </div>
<?php else: ?>
<div class="card" style="padding:0; overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>Logo</th>
        <th>Company</th>
        <th>Phone</th>
        <th>Port</th>
        <th>Alibaba Store</th>
        <th>Website</th>
        <th>Rating</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($vendors as $v): ?>
      <?php
        $alibabaStore = trim((string)($v['alibaba_store'] ?? ''));
        $websiteUrl = trim((string)($v['website'] ?? ''));
        $isValidAlibabaStore = $alibabaStore !== '' && filter_var($alibabaStore, FILTER_VALIDATE_URL)
          && in_array(strtolower((string)parse_url($alibabaStore, PHP_URL_SCHEME)), ['http', 'https'], true);
        $isValidWebsite = $websiteUrl !== '' && filter_var($websiteUrl, FILTER_VALIDATE_URL)
          && in_array(strtolower((string)parse_url($websiteUrl, PHP_URL_SCHEME)), ['http', 'https'], true);
        $logo_thumb = (string)($v['logo_thumb'] ?? '');
        $logo_path  = (string)($v['logo_path']  ?? '');
        $alibaba_profile_thumb = (string)($v['alibaba_profile_photo_thumb'] ?? '');
        $alibaba_profile_path  = (string)($v['alibaba_profile_photo_path'] ?? '');
        if ($logo_thumb !== '') {
          $logo_thumb_url = 'uploads/' . rawurlencode($logo_thumb);
        } elseif ($logo_path !== '') {
          $logo_thumb_url = 'vendor_logo.php?id=' . (int)$v['id'] . '&type=thumb';
        } else {
          $logo_thumb_url = '';
        }
        if ($logo_path !== '') {
          $logo_full_url = 'uploads/' . rawurlencode($logo_path);
        } elseif ($logo_thumb !== '') {
          $logo_full_url = 'vendor_logo.php?id=' . (int)$v['id'] . '&type=full';
        } else {
          $logo_full_url = '';
        }
        if ($alibaba_profile_thumb !== '') {
          $alibaba_profile_thumb_url = 'uploads/' . rawurlencode($alibaba_profile_thumb);
        } elseif ($alibaba_profile_path !== '') {
          $alibaba_profile_thumb_url = 'uploads/' . rawurlencode($alibaba_profile_path);
        } else {
          $alibaba_profile_thumb_url = '';
        }
        if ($alibaba_profile_path !== '') {
          $alibaba_profile_full_url = 'uploads/' . rawurlencode($alibaba_profile_path);
        } elseif ($alibaba_profile_thumb !== '') {
          $alibaba_profile_full_url = 'uploads/' . rawurlencode($alibaba_profile_thumb);
        } else {
          $alibaba_profile_full_url = '';
        }
        $missing_image_style = 'font-size:0.85em;';
      ?>
      <tr>
        <td>
          <div style="display:flex; flex-direction:column; gap:6px; min-width:60px; align-items:center;">
            <?php if ($alibaba_profile_thumb_url !== ''): ?>
              <a href="<?= h($alibaba_profile_full_url) ?>" target="_blank" rel="noopener noreferrer" title="View Alibaba profile photo">
                <img src="<?= h($alibaba_profile_thumb_url) ?>"
                     alt="<?= h($v['company_name']) ?> Alibaba profile photo"
                     loading="lazy"
                     decoding="async"
                     style="max-width:60px; max-height:40px; object-fit:contain; display:block;" />
              </a>
            <?php else: ?>
              <span class="muted" style="<?= h($missing_image_style) ?>">—</span>
            <?php endif; ?>
            <?php if (trim((string)($v['contact_name'] ?? '')) !== ''): ?>
              <span style="font-size:0.85em; line-height:1.2; text-align:center;"><?= h($v['contact_name']) ?></span>
            <?php else: ?>
              <span class="muted" style="<?= h($missing_image_style) ?>">—</span>
            <?php endif; ?>
            <?php if ($logo_thumb_url !== ''): ?>
              <a href="<?= h($logo_full_url) ?>" target="_blank" rel="noopener noreferrer" title="View logo">
               <img src="<?= h($logo_thumb_url) ?>"
                    alt="<?= h($v['company_name']) ?> logo"
                     loading="lazy"
                     decoding="async"
                     style="max-width:60px; max-height:40px; object-fit:contain; display:block;" />
              </a>
            <?php else: ?>
              <span class="muted" style="<?= h($missing_image_style) ?>">—</span>
            <?php endif; ?>
          </div>
        </td>
        <td><strong><?= h($v['company_name']) ?></strong></td>
        <td>
          <?php if ($v['phone'] !== ''): ?>
            <?= h($v['phone']) ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (($v['port'] ?? '') !== ''): ?>
            <?= h($v['port']) ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($isValidAlibabaStore): ?>
            <?php $display_alibaba = strlen($alibabaStore) > 40 ? substr($alibabaStore, 0, 40) . '…' : $alibabaStore; ?>
            <a href="<?= h($alibabaStore) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($alibabaStore) ?>"><?= h($display_alibaba) ?></a>
          <?php elseif ($alibabaStore !== ''): ?>
            <?= h($alibabaStore) ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($isValidWebsite): ?>
            <?php $display_url = strlen($websiteUrl) > 40 ? substr($websiteUrl, 0, 40) . '…' : $websiteUrl; ?>
            <a href="<?= h($websiteUrl) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($websiteUrl) ?>"><?= h($display_url) ?></a>
          <?php elseif ($websiteUrl !== ''): ?>
            <?= h($websiteUrl) ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!empty($v['rating'])): ?>
            <span title="<?= (int)$v['rating'] ?> out of 5" style="color:#f59e0b; font-size:1.1em; letter-spacing:1px;">
              <?= str_repeat('★', (int)$v['rating']) ?><span style="color:#d1d5db;"><?= str_repeat('★', 5 - (int)$v['rating']) ?></span>
            </span>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td class="actions">
          <a class="btn" href="vendor_details.php?id=<?= (int)$v['id'] ?>">View</a>
          <a class="btn" href="vendor_form.php?id=<?= (int)$v['id'] ?>">Edit</a>
          <?php if (is_admin()): ?>
          <form method="post" action="vendor_delete.php" style="display:inline;"
                onsubmit="return confirm('Delete this vendor?');">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['vendor_delete_csrf']) ?>" />
            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>" />
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

<?php render_footer(); ?>
