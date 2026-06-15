<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['vendor_form_csrf'])) {
  $_SESSION['vendor_form_csrf'] = bin2hex(random_bytes(24));
}

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;

$errors  = [];
$success = '';

$fields = [
  'company_name'  => '',
  'contact_name'  => '',
  'email'         => '',
  'phone'         => '',
  'website'       => '',
  'alibaba_store' => '',
  'address'       => '',
  'notes'         => '',
  'rating'        => '',
  'review'        => '',
  'logo_path'     => '',
  'logo_thumb'    => '',
];

// Load existing record for edits
if ($is_edit) {
  $row = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
  $row->execute([$id]);
  $vendor = $row->fetch();
  if (!$vendor) {
    http_response_code(404);
    render_header('Vendor Not Found');
    echo '<div class="card"><p class="muted">Vendor not found.</p><a class="btn" href="vendors.php">← Back to Vendors</a></div>';
    render_footer();
    exit;
  }
  foreach ($fields as $k => $_) {
    $fields[$k] = (string)($vendor[$k] ?? '');
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['vendor_form_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    foreach ($fields as $k => $_) {
      if ($k === 'logo_path' || $k === 'logo_thumb') continue;
      $fields[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if ($fields['company_name'] === '') {
      $errors[] = 'Company name is required.';
    } elseif (mb_strlen($fields['company_name']) > 255) {
      $errors[] = 'Company name must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['contact_name']) > 255) {
      $errors[] = 'Contact name must be 255 characters or fewer.';
    }
    if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Email address is not valid.';
    }
    if (mb_strlen($fields['email']) > 255) {
      $errors[] = 'Email must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['phone']) > 100) {
      $errors[] = 'Phone must be 100 characters or fewer.';
    }
    if (mb_strlen($fields['website']) > 255) {
      $errors[] = 'Website must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['alibaba_store']) > 255) {
      $errors[] = 'Alibaba Store link must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['address']) > 500) {
      $errors[] = 'Address must be 500 characters or fewer.';
    }
    if ($fields['rating'] !== '' && (!ctype_digit($fields['rating']) || (int)$fields['rating'] < 1 || (int)$fields['rating'] > 5)) {
      $errors[] = 'Rating must be a number between 1 and 5.';
    }
    if (mb_strlen($fields['review']) > 5000) {
      $errors[] = 'Internal review must be 5000 characters or fewer.';
    }

    // Handle logo upload
    $new_logo_path  = null;
    $new_logo_thumb = null;
    if (isset($_FILES['logo_image']) && $_FILES['logo_image']['error'] !== UPLOAD_ERR_NO_FILE) {
      $fup = $_FILES['logo_image'];
      if ($fup['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Logo upload failed (code ' . (int)$fup['error'] . ').';
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
          $errors[] = 'Logo must be a JPG, PNG, or GIF file.';
        } else {
          $uploadsDir = __DIR__ . '/uploads';
          if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0775, true);
          if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
            $errors[] = 'Uploads directory is not writable.';
          } else {
            $ext = $allowed_mimes[$detected_mime];
            $base_name    = 'vendor_logo_' . bin2hex(random_bytes(12));
            $stored_full  = $base_name . '.' . $ext;
            $stored_thumb = $base_name . '_thumb.' . $ext;
            if (!move_uploaded_file($tmp_path, $uploadsDir . '/' . $stored_full)) {
              $errors[] = 'Failed to save uploaded logo.';
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
              $new_logo_path  = $stored_full;
              $new_logo_thumb = $stored_thumb;
            }
          }
        }
      }
    }

    if (!$errors) {
      if ($is_edit) {
        $pdo->prepare("
          UPDATE vendors SET
            company_name = ?, contact_name = ?, email = ?, phone = ?,
            website = ?, alibaba_store = ?, address = ?, notes = ?,
            rating = ?, review = ?,
            logo_path  = COALESCE(?, logo_path),
            logo_thumb = COALESCE(?, logo_thumb)
          WHERE id = ?
        ")->execute([
          $fields['company_name'], $fields['contact_name'], $fields['email'],
          $fields['phone'], $fields['website'], $fields['alibaba_store'],
          $fields['address'],
          $fields['notes'] !== '' ? $fields['notes'] : null,
          $fields['rating'] !== '' ? (int)$fields['rating'] : null,
          $fields['review'] !== '' ? $fields['review'] : null,
          $new_logo_path,
          $new_logo_thumb,
          $id,
        ]);
        header("Location: vendors.php");
        exit;
      } else {
        $pdo->prepare("
          INSERT INTO vendors (company_name, contact_name, email, phone, website, alibaba_store, address, notes, rating, review, logo_path, logo_thumb)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
          $fields['company_name'], $fields['contact_name'], $fields['email'],
          $fields['phone'], $fields['website'], $fields['alibaba_store'],
          $fields['address'],
          $fields['notes'] !== '' ? $fields['notes'] : null,
          $fields['rating'] !== '' ? (int)$fields['rating'] : null,
          $fields['review'] !== '' ? $fields['review'] : null,
          $new_logo_path,
          $new_logo_thumb,
        ]);
        header("Location: vendors.php");
        exit;
      }
    }
  }
}

$page_title = $is_edit ? 'Edit Vendor' : 'Add Vendor';
render_header($page_title);
?>

<div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
  <h1 style="margin:0;"><?= h($page_title) ?></h1>
  <a class="btn" href="vendors.php">← Back to Vendors</a>
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

  <form method="post" enctype="multipart/form-data" action="vendor_form.php<?= $is_edit ? '?id=' . $id : '' ?>" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['vendor_form_csrf']) ?>" />

    <div class="form-grid">
      <div>
        <label>Company Name <span style="color:var(--d);">*</span></label>
        <input type="text" name="company_name" maxlength="255" required
               value="<?= h($fields['company_name']) ?>" placeholder="e.g. Acme Corp" />
      </div>
      <div>
        <label>Contact Name</label>
        <input type="text" name="contact_name" maxlength="255"
               value="<?= h($fields['contact_name']) ?>" placeholder="e.g. Jane Smith" />
      </div>
      <div>
        <label>Email</label>
        <input type="email" name="email" maxlength="255"
               value="<?= h($fields['email']) ?>" placeholder="e.g. jane@acmecorp.com" />
      </div>
      <div>
        <label>Phone</label>
        <input type="text" name="phone" maxlength="100"
               value="<?= h($fields['phone']) ?>" placeholder="e.g. +1 (555) 123-4567" />
      </div>
      <div>
        <label>Website</label>
        <input type="text" name="website" maxlength="255"
               value="<?= h($fields['website']) ?>" placeholder="e.g. https://acmecorp.com" />
      </div>
      <div>
        <label>Alibaba Store</label>
        <input type="text" name="alibaba_store" maxlength="255"
               value="<?= h($fields['alibaba_store']) ?>" placeholder="e.g. https://acmecorp.en.alibaba.com" />
      </div>
      <div>
        <label>Address</label>
        <input type="text" name="address" maxlength="500"
               value="<?= h($fields['address']) ?>" placeholder="e.g. 123 Main St, Springfield, CA 90210" />
      </div>
      <div class="full">
        <label>Notes</label>
        <textarea name="notes" rows="4" placeholder="Any additional notes about this vendor…"><?= h($fields['notes']) ?></textarea>
      </div>
      <div>
        <label>Internal Rating</label>
        <select name="rating">
          <option value="">— No rating —</option>
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <option value="<?= $i ?>"<?= (string)$fields['rating'] === (string)$i ? ' selected' : '' ?>>
              <?= str_repeat('★', $i) . str_repeat('☆', 5 - $i) ?> (<?= $i ?>)
            </option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="full">
        <label>Internal Review / Notes</label>
        <textarea name="review" rows="4" placeholder="Internal review or notes about this vendor's performance…"><?= h($fields['review']) ?></textarea>
      </div>
      <div class="full">
        <label>Company Logo (JPG, PNG, GIF)</label>
        <input type="file" name="logo_image" id="logo_image" accept="image/jpeg,image/png,image/gif" />
        <div class="muted" style="margin-top:4px;">Optional. Upload a company logo for this vendor.</div>
      </div>
      <?php if ($fields['logo_thumb'] !== '' && $is_edit): ?>
      <div class="full">
        <label>Current Logo</label>
        <?php
          $logo_thumb_url = 'uploads/' . rawurlencode($fields['logo_thumb']);
          $logo_full_url  = $fields['logo_path'] !== ''
            ? 'uploads/' . rawurlencode($fields['logo_path'])
            : 'vendor_logo.php?id=' . $id . '&type=full';
        ?>
        <a href="<?= h($logo_full_url) ?>" target="_blank" rel="noopener noreferrer" title="View full logo">
          <img src="<?= h($logo_thumb_url) ?>"
               alt="Vendor logo thumbnail"
               loading="lazy"
               decoding="async"
               style="max-width:200px; max-height:200px; border-radius:6px; border:1px solid rgba(0,0,0,.12); display:block;" />
        </a>
        <div class="muted" style="margin-top:4px;">Upload a new file above to replace it.</div>
      </div>
      <?php endif; ?>
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="btn primary"><?= $is_edit ? 'Save Changes' : 'Add Vendor' ?></button>
    </div>
  </form>
</div>

<?php render_footer(); ?>
