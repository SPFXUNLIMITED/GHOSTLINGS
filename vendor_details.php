<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  header('Location: vendors.php');
  exit;
}

$stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->execute([$id]);
$vendor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vendor) {
  http_response_code(404);
  render_header('Vendor Not Found');
  echo '<div class="card"><p class="muted">Vendor not found.</p><a class="btn" href="vendors.php">← Back to Vendors</a></div>';
  render_footer();
  exit;
}

$logo_thumb = (string)($vendor['logo_thumb'] ?? '');
$logo_path  = (string)($vendor['logo_path'] ?? '');
$alibaba_profile_thumb = (string)($vendor['alibaba_profile_photo_thumb'] ?? '');
$alibaba_profile_path  = (string)($vendor['alibaba_profile_photo_path'] ?? '');

$logo_image_url = $logo_thumb !== ''
  ? 'uploads/' . rawurlencode($logo_thumb)
  : ($logo_path !== '' ? 'vendor_logo.php?id=' . (int)$vendor['id'] . '&type=thumb' : '');
$logo_full_url = $logo_path !== ''
  ? 'uploads/' . rawurlencode($logo_path)
  : ($logo_thumb !== '' ? 'vendor_logo.php?id=' . (int)$vendor['id'] . '&type=full' : '');
$alibaba_profile_image_url = $alibaba_profile_thumb !== ''
  ? 'uploads/' . rawurlencode($alibaba_profile_thumb)
  : ($alibaba_profile_path !== '' ? 'uploads/' . rawurlencode($alibaba_profile_path) : '');
$alibaba_profile_full_url = $alibaba_profile_path !== ''
  ? 'uploads/' . rawurlencode($alibaba_profile_path)
  : ($alibaba_profile_thumb !== '' ? 'uploads/' . rawurlencode($alibaba_profile_thumb) : '');

render_header('Vendor Details');
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Vendor Details</h1>
    <div class="actions">
      <a class="btn" href="vendors.php">Back to Vendors</a>
      <a class="btn" href="vendor_form.php?id=<?= (int)$vendor['id'] ?>">Edit</a>
    </div>
  </div>
</div>

<div class="card">
  <table>
    <tbody>
      <tr>
        <th style="width:220px;">Company</th>
        <td><strong><?= h($vendor['company_name']) ?></strong> (ID <?= (int)$vendor['id'] ?>)</td>
      </tr>
      <tr>
        <th>Contact</th>
        <td><?= $vendor['contact_name'] !== '' ? h($vendor['contact_name']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Email</th>
        <td>
          <?php if ($vendor['email'] !== ''): ?>
            <a href="mailto:<?= h($vendor['email']) ?>"><?= h($vendor['email']) ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Phone</th>
        <td><?= $vendor['phone'] !== '' ? h($vendor['phone']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Website</th>
        <td>
          <?php
            $website = trim((string)($vendor['website'] ?? ''));
            $scheme = strtolower((string)parse_url($website, PHP_URL_SCHEME));
            $is_safe_website = $website !== '' && in_array($scheme, ['http', 'https'], true);
          ?>
          <?php if ($is_safe_website): ?>
            <a href="<?= h($website) ?>" target="_blank" rel="noopener noreferrer"><?= h($website) ?></a>
          <?php elseif ($website !== ''): ?>
            <?= h($website) ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Address</th>
        <td><?= $vendor['address'] !== '' ? h($vendor['address']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Images</th>
        <td>
          <div style="display:flex; flex-wrap:wrap; gap:18px;">
            <div>
              <div class="muted" style="margin-bottom:6px;">Alibaba Profile Photo</div>
              <?php if ($alibaba_profile_image_url !== ''): ?>
                <a href="<?= h($alibaba_profile_full_url) ?>" target="_blank" rel="noopener noreferrer" title="View Alibaba profile photo">
                  <img src="<?= h($alibaba_profile_image_url) ?>"
                       alt="<?= h($vendor['company_name']) ?> Alibaba profile photo"
                       loading="lazy"
                       decoding="async"
                       style="max-width:200px; max-height:200px; object-fit:contain; border-radius:6px; border:1px solid rgba(0,0,0,.12); display:block;" />
                </a>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </div>
            <div>
              <div class="muted" style="margin-bottom:6px;">Company Logo</div>
              <?php if ($logo_image_url !== ''): ?>
                <a href="<?= h($logo_full_url) ?>" target="_blank" rel="noopener noreferrer" title="View company logo">
                  <img src="<?= h($logo_image_url) ?>"
                       alt="<?= h($vendor['company_name']) ?> logo"
                       loading="lazy"
                       decoding="async"
                       style="max-width:200px; max-height:200px; object-fit:contain; border-radius:6px; border:1px solid rgba(0,0,0,.12); display:block;" />
                </a>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </div>
          </div>
        </td>
      </tr>
      <tr>
        <th>Notes</th>
        <td><?= !empty($vendor['notes']) ? nl2br(h($vendor['notes'])) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Internal Rating</th>
        <td>
          <?php if (!empty($vendor['rating'])): ?>
            <span title="<?= (int)$vendor['rating'] ?> out of 5" style="color:#f59e0b; font-size:1.2em; letter-spacing:1px;">
              <?= str_repeat('★', (int)$vendor['rating']) ?><span style="color:#d1d5db;"><?= str_repeat('★', 5 - (int)$vendor['rating']) ?></span>
            </span>
            <span class="muted" style="font-size:0.85em;"> (<?= (int)$vendor['rating'] ?>/5)</span>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Internal Review</th>
        <td><?= !empty($vendor['review']) ? nl2br(h($vendor['review'])) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Created</th>
        <td><?= !empty($vendor['created_at']) ? h($vendor['created_at']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Last Updated</th>
        <td><?= !empty($vendor['updated_at']) ? h($vendor['updated_at']) : '<span class="muted">—</span>' ?></td>
      </tr>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
