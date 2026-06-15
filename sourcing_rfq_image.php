<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_rfq_access();

$rfq_id = (int)($_GET['rfq_id'] ?? 0);
$type   = (string)($_GET['type'] ?? 'full');

if ($rfq_id <= 0) {
  http_response_code(400);
  exit('Missing rfq_id');
}

$stmt = $pdo->prepare("SELECT image_path, image_thumb FROM rfq_requests WHERE id = ? LIMIT 1");
$stmt->execute([$rfq_id]);
$row = $stmt->fetch();

if (!$row) {
  http_response_code(404);
  exit('Image not found');
}

if ($type === 'thumb') {
  $uploadsDir = __DIR__ . '/uploads';
  $stored_thumb = (string)($row['image_thumb'] ?? '');
  $stored_full  = (string)($row['image_path']  ?? '');
  $src_path = '';
  $detected_mime = '';
  $new_thumb_name = '';
  $thumb_path = '';
  $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];

  if ($stored_full !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $stored_full)) {
    $src_path = $uploadsDir . '/' . $stored_full;
    if (is_file($src_path)) {
      $fi = finfo_open(FILEINFO_MIME_TYPE);
      $detected_mime = ($fi !== false) ? (finfo_file($fi, $src_path) ?: '') : '';
      if ($fi !== false) finfo_close($fi);

      if (!isset($allowed_mimes[$detected_mime])) {
        http_response_code(415);
        exit('Unsupported image type');
      }

      $ext = $allowed_mimes[$detected_mime];
      $base_name = pathinfo(basename($stored_full), PATHINFO_FILENAME);
      $new_thumb_name = $base_name . '_thumb.' . $ext;
      $thumb_path = $uploadsDir . '/' . $new_thumb_name;
    }
  }

  if ($thumb_path !== '' && is_file($thumb_path)) {
    $stored = $new_thumb_name;
    if ($stored_thumb !== $new_thumb_name) {
      $upd = $pdo->prepare("UPDATE rfq_requests SET image_thumb = ? WHERE id = ?");
      $upd->execute([$new_thumb_name, $rfq_id]);
    }
  } elseif ($stored_thumb !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $stored_thumb) && is_file($uploadsDir . '/' . $stored_thumb)) {
    $stored = $stored_thumb;
  } else {
    if ($src_path === '' || !is_file($src_path)) {
      http_response_code(404);
      exit('File not found on disk');
    }
    if ($thumb_path === '' || $new_thumb_name === '') {
      http_response_code(415);
      exit('Unsupported image type');
    }

    $thumb_ok = false;
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
        if ($detected_mime === 'image/jpeg') {
          $thumb_ok = (bool)imagejpeg($thumb_img, $thumb_path, 85);
        } elseif ($detected_mime === 'image/png') {
          $thumb_ok = (bool)imagepng($thumb_img, $thumb_path);
        } else {
          $thumb_ok = (bool)imagegif($thumb_img, $thumb_path);
        }
        imagedestroy($thumb_img);
      }
      imagedestroy($src_img);
    }

    if ($thumb_ok && is_file($thumb_path)) {
      $upd = $pdo->prepare("UPDATE rfq_requests SET image_thumb = ? WHERE id = ?");
      $upd->execute([$new_thumb_name, $rfq_id]);
      $stored = $new_thumb_name;
    } else {
      error_log("sourcing_rfq_image: GD thumbnail generation failed for rfq_id={$rfq_id}, falling back to full image");
      if ($stored_thumb !== $stored_full) {
        $upd = $pdo->prepare("UPDATE rfq_requests SET image_thumb = ? WHERE id = ?");
        $upd->execute([$stored_full, $rfq_id]);
      }
      $stored = $stored_full;
    }
  }
} else {
  $stored = (string)($row['image_path'] ?? '');
  if ($stored === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $stored)) {
    http_response_code(404);
    exit('Image not found');
  }
}

$path = __DIR__ . '/uploads/' . $stored;
if (!is_file($path)) {
  http_response_code(404);
  exit('File not found on disk');
}

$fi = finfo_open(FILEINFO_MIME_TYPE);
$mime = ($fi !== false) ? (finfo_file($fi, $path) ?: 'image/jpeg') : 'image/jpeg';
if ($fi !== false) finfo_close($fi);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=86400');
if (isset($_GET['download'])) {
  $ext = pathinfo($stored, PATHINFO_EXTENSION);
  $download_name = 'rfq_' . $rfq_id . '_image.' . $ext;
  header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($download_name));
} else {
  header('Content-Disposition: inline');
}
readfile($path);
exit;
