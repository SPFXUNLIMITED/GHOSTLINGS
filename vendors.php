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

<style>
  .vendor-hero {
    background-image: url('map.jpeg');
    background-size: cover;
    background-position: center;
    border-radius: 10px;
    padding: 72px 40px;
    margin-bottom: 18px;
    text-align: center;
    position: relative;
    overflow: hidden;
  }
  .vendor-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, rgba(10,18,32,0.62) 0%, rgba(10,18,32,0.72) 100%);
    border-radius: inherit;
    pointer-events: none;
  }
  .vendor-hero-content {
    position: relative;
    z-index: 1;
  }
  .vendor-hero-title {
    font-size: 2.6rem;
    font-weight: 700;
    color: #ffffff;
    letter-spacing: 0.01em;
    margin: 0 0 14px;
    text-shadow: 0 2px 12px rgba(0,0,0,0.55);
  }
  .vendor-hero-route {
    font-size: 1.1rem;
    color: #cbd5e1;
    letter-spacing: 0.06em;
    margin: 0;
    text-shadow: 0 1px 6px rgba(0,0,0,0.5);
  }
</style>

<div class="vendor-hero">
  <div class="vendor-hero-content">
    <h2 class="vendor-hero-title">Our Vendor Network</h2>
    <p class="vendor-hero-route">Qingdao &#8596; Shenzhen &nbsp;&bull;&nbsp; &#8776;&nbsp;1,180 miles</p>
  </div>
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
