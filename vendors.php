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

<!-- Static China map hero banner (inline SVG, no external dependencies) -->
<style>
  .vendor-map-card {
    padding: 0;
    overflow: hidden;
    margin-bottom: 18px;
  }
  .vendor-map-svg {
    width: 100%;
    height: auto;
    display: block;
  }
</style>

<div class="card vendor-map-card">
  <svg class="vendor-map-svg" viewBox="0 0 800 320" xmlns="http://www.w3.org/2000/svg"
       role="img" aria-label="Map of China with route from Qingdao to Shenzhen, approximately 1,180 miles">
    <defs>
      <linearGradient id="vMapOcean" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0%" stop-color="#dbeafe"/>
        <stop offset="100%" stop-color="#bfdbfe"/>
      </linearGradient>
    </defs>
    <!-- Ocean background -->
    <rect width="800" height="320" fill="url(#vMapOcean)"/>
    <!-- China mainland (simplified outline, clockwise from NW Xinjiang) -->
    <polygon
      points="178,59 406,51 596,8 787,25 622,135 622,169 622,219 572,270 521,286 470,295 444,295 381,286 305,235 229,227 76,177 13,118 114,67"
      fill="#e8dcc8" stroke="#b8a88a" stroke-width="1.5" stroke-linejoin="round"/>
    <!-- Country label -->
    <text x="370" y="165"
          font-family="-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif"
          font-size="22" fill="#a09060" fill-opacity="0.45" font-weight="700"
          text-anchor="middle" letter-spacing="4">CHINA</text>
    <!-- Route line: Qingdao &#8594; Shenzhen -->
    <line x1="601" y1="168" x2="521" y2="282"
          stroke="#2563eb" stroke-width="2.5" stroke-dasharray="7,5" opacity="0.85"/>
    <!-- Distance badge centred on the line midpoint (~561, 225) -->
    <rect x="507" y="214" width="108" height="22" rx="11"
          fill="white" fill-opacity="0.93" stroke="#2563eb" stroke-width="1"/>
    <text x="561" y="229"
          font-family="-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif"
          font-size="12" fill="#1e40af" font-weight="600" text-anchor="middle">&#8776; 1,180 miles</text>
    <!-- Qingdao marker -->
    <circle cx="601" cy="168" r="7" fill="#2563eb" stroke="white" stroke-width="2.5"/>
    <text x="601" y="154"
          font-family="-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif"
          font-size="12" fill="#1e3a8a" font-weight="700" text-anchor="middle">Qingdao</text>
    <!-- Shenzhen marker -->
    <circle cx="521" cy="282" r="7" fill="#2563eb" stroke="white" stroke-width="2.5"/>
    <text x="521" y="307"
          font-family="-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif"
          font-size="12" fill="#1e3a8a" font-weight="700" text-anchor="middle">Shenzhen</text>
  </svg>
</div>

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
