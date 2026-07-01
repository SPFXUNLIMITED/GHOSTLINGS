<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['freight_forwarder_form_csrf'])) {
  $_SESSION['freight_forwarder_form_csrf'] = bin2hex(random_bytes(24));
}

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;

$errors  = [];
$success = '';

$fields = [
  'company_name'   => '',
  'logo_path'      => '',
  'logo_thumb'     => '',
  'headquarters'   => '',
  'contact_person' => '',
  'phone'          => '',
  'email'          => '',
  'website'        => '',
  'primary_routes' => '',
  'shipping_modes' => '',
  'certifications' => '',
  'notes'          => '',
];

if ($is_edit) {
  $row = $pdo->prepare("SELECT * FROM freight_forwarders WHERE id = ?");
  $row->execute([$id]);
  $forwarder = $row->fetch();
  if (!$forwarder) {
    http_response_code(404);
    render_header('Freight Forwarder Not Found');
    echo '<div class="card"><p class="muted">Freight forwarder not found.</p><a class="btn" href="freight_forwarders.php">← Back to Freight Forwarders</a></div>';
    render_footer();
    exit;
  }
  foreach ($fields as $k => $_) {
    $fields[$k] = (string)($forwarder[$k] ?? '');
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['freight_forwarder_form_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    foreach ($fields as $k => $_) {
      if (in_array($k, ['logo_path', 'logo_thumb'], true)) continue;
      $fields[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if ($fields['company_name'] === '') {
      $errors[] = 'Company name is required.';
    } elseif (mb_strlen($fields['company_name']) > 255) {
      $errors[] = 'Company name must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['headquarters']) > 255) {
      $errors[] = 'Headquarters must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['contact_person']) > 255) {
      $errors[] = 'Contact person must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['phone']) > 100) {
      $errors[] = 'Phone must be 100 characters or fewer.';
    }
    if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Email address is not valid.';
    }
    if (mb_strlen($fields['email']) > 255) {
      $errors[] = 'Email must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['website']) > 255) {
      $errors[] = 'Website must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['primary_routes']) > 500) {
      $errors[] = 'Primary routes must be 500 characters or fewer.';
    }
    if (mb_strlen($fields['shipping_modes']) > 255) {
      $errors[] = 'Shipping modes must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['certifications']) > 255) {
      $errors[] = 'Certifications must be 255 characters or fewer.';
    }

    $processImageUpload = function (string $fileKey, string $label, string $basePrefix) use (&$errors): array {
      $new_image_path  = null;
      $new_image_thumb = null;
      if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] !== UPLOAD_ERR_NO_FILE) {
        $fup = $_FILES[$fileKey];
        if ($fup['error'] !== UPLOAD_ERR_OK) {
          $errors[] = $label . ' upload failed (code ' . (int)$fup['error'] . ').';
        } else {
          $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
          $tmp_path = (string)($fup['tmp_name'] ?? '');
          $detected_mime = '';
          if (is_file($tmp_path) && function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
              $detected_mime = (string)(finfo_file($fi, $tmp_path) ?: '');
              finfo_close($fi);
            }
          }
          if (!isset($allowed_mimes[$detected_mime])) {
            $errors[] = $label . ' must be a JPG, PNG, or GIF file.';
          } else {
            $uploadsDir = __DIR__ . '/uploads';
            if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0775, true);
            if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
              $errors[] = 'Uploads directory is not writable.';
            } else {
              $ext = $allowed_mimes[$detected_mime];
              $base_name    = $basePrefix . '_' . bin2hex(random_bytes(12));
              $stored_full  = $base_name . '.' . $ext;
              $stored_thumb = $base_name . '_thumb.' . $ext;
              if (!move_uploaded_file($tmp_path, $uploadsDir . '/' . $stored_full)) {
                $errors[] = 'Failed to save uploaded ' . strtolower($label) . '.';
              } else {
                // Create thumbnail (max 200×200)
                $thumb_ok = false;
                $src_path = $uploadsDir . '/' . $stored_full;
                if ($detected_mime === 'image/jpeg') {
                  $src_img = @imagecreatefromjpeg($src_path);
                } elseif ($detected_mime === 'image/png') {
                  $src_img = @imagecreatefrompng($src_path);
                } else {
                  $src_img = @imagecreatefromgif($src_path);
                }
                if ($src_img !== false) {
                  $src_w = imagesx($src_img);
                  $src_h = imagesy($src_img);
                  $max_side = 200;
                  if ($src_w > $max_side || $src_h > $max_side) {
                    $ratio = min($max_side / $src_w, $max_side / $src_h);
                    $dst_w = (int)round($src_w * $ratio);
                    $dst_h = (int)round($src_h * $ratio);
                  } else {
                    $dst_w = $src_w;
                    $dst_h = $src_h;
                  }
                  $thumb_img = imagecreatetruecolor($dst_w, $dst_h);
                  if ($thumb_img !== false) {
                    if ($detected_mime === 'image/png') {
                      imagealphablending($thumb_img, false);
                      imagesavealpha($thumb_img, true);
                      $transparent = imagecolorallocatealpha($thumb_img, 255, 255, 255, 127);
                      imagefill($thumb_img, 0, 0, $transparent);
                    } elseif ($detected_mime === 'image/gif') {
                      $trans_idx = imagecolortransparent($src_img);
                      if ($trans_idx >= 0 && $trans_idx < imagecolorstotal($src_img)) {
                        $trans_color = imagecolorsforindex($src_img, $trans_idx);
                        $new_trans = imagecolorallocate($thumb_img, $trans_color['red'], $trans_color['green'], $trans_color['blue']);
                        imagefill($thumb_img, 0, 0, $new_trans);
                        imagecolortransparent($thumb_img, $new_trans);
                      }
                    }
                    imagecopyresampled($thumb_img, $src_img, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);
                    $thumb_path = $uploadsDir . '/' . $stored_thumb;
                    if ($detected_mime === 'image/jpeg') {
                      $thumb_ok = imagejpeg($thumb_img, $thumb_path, 85);
                    } elseif ($detected_mime === 'image/png') {
                      $thumb_ok = imagepng($thumb_img, $thumb_path);
                    } else {
                      $thumb_ok = imagegif($thumb_img, $thumb_path);
                    }
                    imagedestroy($thumb_img);
                  }
                  imagedestroy($src_img);
                }
                // Fall back to full image as thumb if GD failed
                if (!$thumb_ok) {
                  $stored_thumb = $stored_full;
                }
                $new_image_path  = $stored_full;
                $new_image_thumb = $stored_thumb;
              }
            }
          }
        }
      }
      return [$new_image_path, $new_image_thumb];
    };

    [$new_logo_path, $new_logo_thumb] = $processImageUpload('logo_image', 'Logo', 'freight_forwarder_logo');

    if (!$errors) {
      if ($is_edit) {
        $pdo->prepare("
          UPDATE freight_forwarders SET
            company_name = ?, logo_path = COALESCE(?, logo_path), logo_thumb = COALESCE(?, logo_thumb), headquarters = ?, contact_person = ?, phone = ?,
            email = ?, website = ?, primary_routes = ?, shipping_modes = ?,
            certifications = ?, notes = ?
          WHERE id = ?
        ")->execute([
          $fields['company_name'], $new_logo_path, $new_logo_thumb, $fields['headquarters'], $fields['contact_person'],
          $fields['phone'], $fields['email'], $fields['website'],
          $fields['primary_routes'], $fields['shipping_modes'], $fields['certifications'],
          $fields['notes'] !== '' ? $fields['notes'] : null,
          $id,
        ]);
        $success = 'Freight forwarder updated.';
      } else {
        $pdo->prepare("
          INSERT INTO freight_forwarders (
            company_name, logo_path, logo_thumb, headquarters, contact_person, phone, email,
            website, primary_routes, shipping_modes, certifications, notes
          )
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
          $fields['company_name'], $new_logo_path, $new_logo_thumb, $fields['headquarters'], $fields['contact_person'],
          $fields['phone'], $fields['email'], $fields['website'],
          $fields['primary_routes'], $fields['shipping_modes'], $fields['certifications'],
          $fields['notes'] !== '' ? $fields['notes'] : null,
        ]);
        $id = (int)$pdo->lastInsertId();
        $is_edit = true;
        $success = 'Freight forwarder added.';
      }
      $_SESSION['freight_forwarder_form_csrf'] = bin2hex(random_bytes(24));
      header('Location: freight_forwarders.php');
      exit;
    }
  }
}

$page_title = $is_edit ? 'Edit Freight Forwarder' : 'Add Freight Forwarder';
render_header($page_title);
?>

<div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
  <h1 style="margin:0;"><?= h($page_title) ?></h1>
  <a class="btn" href="freight_forwarders.php">← Back to Freight Forwarders</a>
</div>

<div class="card">
  <?php if ($errors): ?>
    <div class="alert error" style="margin-bottom:14px;">
      <ul style="margin:0; padding-left:18px;">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert" style="margin-bottom:14px; border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
      <?= h($success) ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" action="freight_forwarder_form.php<?= $is_edit ? '?id=' . $id : '' ?>" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['freight_forwarder_form_csrf']) ?>" />

    <div class="form-grid">
      <div>
        <label>Company Name <span style="color:var(--d);">*</span></label>
        <input type="text" name="company_name" maxlength="255" required
               value="<?= h($fields['company_name']) ?>" placeholder="e.g. Pacific Global Logistics" />
      </div>
      <div>
        <label>Headquarters</label>
        <input type="text" name="headquarters" maxlength="255"
               value="<?= h($fields['headquarters']) ?>" placeholder="e.g. Los Angeles, CA" />
      </div>
      <div>
        <label>Contact Person</label>
        <input type="text" name="contact_person" maxlength="255"
               value="<?= h($fields['contact_person']) ?>" placeholder="e.g. Michael Chen" />
      </div>
      <div>
        <label>Phone Number</label>
        <input type="text" name="phone" maxlength="100"
               value="<?= h($fields['phone']) ?>" placeholder="e.g. +1 (555) 123-4567" />
      </div>
      <div>
        <label>Email</label>
        <input type="email" name="email" maxlength="255"
               value="<?= h($fields['email']) ?>" placeholder="e.g. ops@forwarder.com" />
      </div>
      <div>
        <label>Website</label>
        <input type="text" name="website" maxlength="255"
               value="<?= h($fields['website']) ?>" placeholder="e.g. https://forwarder.com" />
      </div>
      <div class="full">
        <label>Primary Routes</label>
        <input type="text" name="primary_routes" maxlength="500"
               value="<?= h($fields['primary_routes']) ?>" placeholder="e.g. China to LA, Taiwan to Long Beach" />
      </div>
      <div class="full">
        <label>Company Logo (JPG, PNG, GIF)</label>
        <input type="file" name="logo_image" id="logo_image" accept="image/jpeg,image/png,image/gif" />
        <div class="muted" style="margin-top:4px;">Optional. Upload a company logo for this freight forwarder.</div>
      </div>
      <?php if ($is_edit && ($fields['logo_thumb'] !== '' || $fields['logo_path'] !== '')): ?>
      <div class="full">
        <label>Current Logo</label>
        <?php
          $logo_thumb_url = $fields['logo_thumb'] !== ''
            ? 'uploads/' . rawurlencode($fields['logo_thumb'])
            : 'uploads/' . rawurlencode($fields['logo_path']);
          $logo_full_url  = $fields['logo_path'] !== ''
            ? 'uploads/' . rawurlencode($fields['logo_path'])
            : $logo_thumb_url;
        ?>
        <a href="<?= h($logo_full_url) ?>" target="_blank" rel="noopener noreferrer" title="View full logo">
          <img src="<?= h($logo_thumb_url) ?>"
               alt="Freight forwarder logo thumbnail"
               loading="lazy"
               decoding="async"
               style="max-width:200px; max-height:200px; border-radius:6px; border:1px solid rgba(0,0,0,.12); display:block;" />
        </a>
        <div class="muted" style="margin-top:4px;">Upload a new file above to replace it.</div>
      </div>
      <?php endif; ?>
      <div>
        <label>Shipping Modes</label>
        <input type="text" name="shipping_modes" maxlength="255"
               value="<?= h($fields['shipping_modes']) ?>" placeholder="e.g. Ocean freight, Air freight, Rail" />
      </div>
      <div>
        <label>Certifications / Strengths</label>
        <input type="text" name="certifications" maxlength="255"
               value="<?= h($fields['certifications']) ?>" placeholder="e.g. CTPAT, FMC licensed, machinery specialist" />
      </div>
      <div class="full">
        <label>Notes</label>
        <textarea name="notes" rows="4" placeholder="e.g. Good with machinery, fast customs clearance…"><?= h($fields['notes']) ?></textarea>
      </div>
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="btn primary"><?= $is_edit ? 'Save Changes' : 'Add Freight Forwarder' ?></button>
    </div>
  </form>
</div>

<?php render_footer(); ?>
