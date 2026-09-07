<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (empty($_SESSION['machine_form_csrf'])) {
  $_SESSION['machine_form_csrf'] = bin2hex(random_bytes(24));
}

$id      = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;

$errors  = [];
$success = '';

$fields = [
  'name'             => '',
  'model'            => '',
  // Machine Dimensions
  'machine_length'     => '',
  'machine_width'      => '',
  'machine_length_mm'  => '',
  'machine_width_mm'   => '',
  'machine_weight_kg'  => '',
  // Cutting Area
  'cut_length'       => '',
  'cut_width'        => '',
  'cut_length_mm'    => '',
  'cut_width_mm'     => '',
  // Crate Dimensions
  'crate_length'     => '',
  'crate_width'      => '',
  'crate_length_mm'  => '',
  'crate_width_mm'   => '',
  'crate_height'     => '',
  'crate_height_mm'  => '',
  'crate_weight_kg'  => '',
  // Photos
  'primary_photo'    => '',
  'secondary_photo'  => '',
  'tertiary_photo'   => '',
  // Content
  'description'      => '',
  'price'            => '',
  // Toggles
  'is_active'        => '1',
  'is_visible'       => '1',
  'is_catalog'       => '1',
];

// Imperial decomposition variables (ft + remaining in) for display
$mach_width_ft    = ''; $mach_width_in    = '';
$mach_length_ft   = ''; $mach_length_in   = '';
$cut_length_ft    = ''; $cut_length_in    = '';
$cut_width_ft     = ''; $cut_width_in     = '';
$crate_length_ft  = ''; $crate_length_in  = '';
$crate_width_ft   = ''; $crate_width_in   = '';
$crate_height_ft  = ''; $crate_height_in  = '';
$weight_lbs = '';
$crate_weight_lbs = '';

if ($is_edit) {
  $stmt = $pdo->prepare("SELECT * FROM machines WHERE id = ?");
  $stmt->execute([$id]);
  $machine = $stmt->fetch();
  if (!$machine) {
    http_response_code(404);
    render_header('Machine Not Found');
    echo '<div class="card"><p class="muted">Machine not found.</p><a class="btn" href="machines.php">← Back to Machines</a></div>';
    render_footer();
    exit;
  }
  foreach ($fields as $k => $_) {
    $fields[$k] = (string)($machine[$k] ?? '');
  }
  // Decompose stored inches into feet + remaining inches for the form fields
  $decompose = function(?string $total_in_str, string &$ft_var, string &$in_var): void {
    if ($total_in_str === null || $total_in_str === '' || (float)$total_in_str <= 0) return;
    $total = (float)$total_in_str;
    $ft_var = (string)(int)floor($total / 12);
    $in_var = (string)round($total - ((int)floor($total / 12)) * 12, 4);
  };
  $decompose($fields['machine_width'],  $mach_width_ft,   $mach_width_in);
  $decompose($fields['machine_length'], $mach_length_ft,  $mach_length_in);
  $decompose($fields['cut_length'],   $cut_length_ft,   $cut_length_in);
  $decompose($fields['cut_width'],    $cut_width_ft,    $cut_width_in);
  $decompose($fields['crate_length'], $crate_length_ft, $crate_length_in);
  $decompose($fields['crate_width'],  $crate_width_ft,  $crate_width_in);
  $decompose($fields['crate_height'], $crate_height_ft, $crate_height_in);
  if ($fields['machine_weight_kg'] !== '' && (float)$fields['machine_weight_kg'] > 0) {
    $weight_lbs = (string)round((float)$fields['machine_weight_kg'] * 2.20462, 4);
  }
  if ($fields['crate_weight_kg'] !== '' && (float)$fields['crate_weight_kg'] > 0) {
    $crate_weight_lbs = (string)round((float)$fields['crate_weight_kg'] * 2.20462, 4);
  }
}

// ── Image upload helper ─────────────────────────────────────────────────────
$processPhotoUpload = function (string $fileKey, string $label, string $prefix) use (&$errors): ?string {
  if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] === UPLOAD_ERR_NO_FILE) {
    return null;
  }
  $fup = $_FILES[$fileKey];
  if ($fup['error'] !== UPLOAD_ERR_OK) {
    $errors[] = $label . ' upload failed (code ' . (int)$fup['error'] . ').';
    return null;
  }
  $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
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
    $errors[] = $label . ' must be a JPG, PNG, GIF, or WebP image.';
    return null;
  }
  $uploadsDir = __DIR__ . '/uploads';
  if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0775, true);
  }
  if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
    $errors[] = 'Uploads directory is not writable.';
    return null;
  }
  $ext      = $allowed_mimes[$detected_mime];
  $filename = $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
  if (!move_uploaded_file($tmp_path, $uploadsDir . '/' . $filename)) {
    $errors[] = 'Failed to save ' . strtolower($label) . '.';
    return null;
  }
  return $filename;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['machine_form_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    // Collect text fields
    $fields['name']        = trim((string)($_POST['name'] ?? ''));
    $fields['model']       = trim((string)($_POST['model'] ?? ''));
    $fields['description'] = trim((string)($_POST['description'] ?? ''));
    $fields['price']       = trim((string)($_POST['price'] ?? ''));
    $fields['is_active']   = isset($_POST['is_active'])  ? '1' : '0';
    $fields['is_visible']  = isset($_POST['is_visible'])  ? '1' : '0';
    $fields['is_catalog']  = isset($_POST['is_catalog'])  ? '1' : '0';

    // ── Dimension parsing helper ─────────────────────────────────────────────
    // Returns [inches_decimal|null, mm_decimal|null] from POST ft/in/mm fields.
    $parseDim = function (string $ft_key, string $in_key, string $mm_key): array {
      $ft  = trim((string)($_POST[$ft_key]  ?? ''));
      $in  = trim((string)($_POST[$in_key]  ?? ''));
      $mm  = trim((string)($_POST[$mm_key]  ?? ''));
      if ($mm !== '') {
        $mm_v = round((float)$mm, 2);
        return [round($mm_v / 25.4, 4), $mm_v];
      }
      if ($ft !== '' || $in !== '') {
        $in_v = round(((float)$ft * 12) + (float)$in, 4);
        return [$in_v, round($in_v * 25.4, 2)];
      }
      return [null, null];
    };

    // Machine Dimensions
    [$mach_width_db,  $mach_width_mm_db]  = $parseDim('mach_width_ft',  'mach_width_in',  'mach_width_mm');
    [$mach_length_db, $mach_length_mm_db] = $parseDim('mach_length_ft', 'mach_length_in', 'mach_length_mm');
    // Cutting Area
    [$cut_width_db,   $cut_width_mm_db]   = $parseDim('cut_width_ft',   'cut_width_in',   'cut_width_mm');
    [$cut_length_db,  $cut_length_mm_db]  = $parseDim('cut_length_ft',  'cut_length_in',  'cut_length_mm');
    // Crate Dimensions
    [$crate_width_db,  $crate_width_mm_db]  = $parseDim('crate_width_ft',  'crate_width_in',  'crate_width_mm');
    [$crate_length_db, $crate_length_mm_db] = $parseDim('crate_length_ft', 'crate_length_in', 'crate_length_mm');
    [$crate_height_db, $crate_height_mm_db] = $parseDim('crate_height_ft', 'crate_height_in', 'crate_height_mm');
    // Machine Weight
    $post_weight_lbs = trim((string)($_POST['weight_lbs'] ?? ''));
    $post_weight_kg  = trim((string)($_POST['weight_kg']  ?? ''));
    $weight_kg_db = null;
    if ($post_weight_kg !== '') {
      $weight_kg_db = round((float)$post_weight_kg, 2);
    } elseif ($post_weight_lbs !== '') {
      $weight_kg_db = round((float)$post_weight_lbs / 2.20462, 2);
    }
    // Crate Weight
    $post_crate_weight_lbs = trim((string)($_POST['crate_weight_lbs'] ?? ''));
    $post_crate_weight_kg  = trim((string)($_POST['crate_weight_kg']  ?? ''));
    $crate_weight_kg_db = null;
    if ($post_crate_weight_kg !== '') {
      $crate_weight_kg_db = round((float)$post_crate_weight_kg, 2);
    } elseif ($post_crate_weight_lbs !== '') {
      $crate_weight_kg_db = round((float)$post_crate_weight_lbs / 2.20462, 2);
    }

    // Repopulate for re-display if there are errors
    $fields['machine_length']    = $mach_length_db    !== null ? (string)$mach_length_db    : '';
    $fields['machine_width']     = $mach_width_db     !== null ? (string)$mach_width_db     : '';
    $fields['machine_length_mm'] = $mach_length_mm_db !== null ? (string)$mach_length_mm_db : '';
    $fields['machine_width_mm']  = $mach_width_mm_db  !== null ? (string)$mach_width_mm_db  : '';
    $fields['cut_length']   = $cut_length_db    !== null ? (string)$cut_length_db    : '';
    $fields['cut_width']    = $cut_width_db     !== null ? (string)$cut_width_db     : '';
    $fields['cut_length_mm'] = $cut_length_mm_db !== null ? (string)$cut_length_mm_db : '';
    $fields['cut_width_mm'] = $cut_width_mm_db  !== null ? (string)$cut_width_mm_db  : '';
    $fields['crate_length']    = $crate_length_db    !== null ? (string)$crate_length_db    : '';
    $fields['crate_width']     = $crate_width_db     !== null ? (string)$crate_width_db     : '';
    $fields['crate_length_mm'] = $crate_length_mm_db !== null ? (string)$crate_length_mm_db : '';
    $fields['crate_width_mm']  = $crate_width_mm_db  !== null ? (string)$crate_width_mm_db  : '';
    $fields['crate_height']    = $crate_height_db    !== null ? (string)$crate_height_db    : '';
    $fields['crate_height_mm'] = $crate_height_mm_db !== null ? (string)$crate_height_mm_db : '';
    $fields['crate_weight_kg'] = $crate_weight_kg_db !== null ? (string)$crate_weight_kg_db : '';
    $fields['machine_weight_kg'] = $weight_kg_db !== null ? (string)$weight_kg_db : '';
    $mach_width_ft  = trim((string)($_POST['mach_width_ft']  ?? ''));
    $mach_width_in  = trim((string)($_POST['mach_width_in']  ?? ''));
    $mach_length_ft = trim((string)($_POST['mach_length_ft'] ?? ''));
    $mach_length_in = trim((string)($_POST['mach_length_in'] ?? ''));
    $cut_length_ft  = trim((string)($_POST['cut_length_ft']  ?? ''));
    $cut_length_in  = trim((string)($_POST['cut_length_in']  ?? ''));
    $cut_width_ft   = trim((string)($_POST['cut_width_ft']   ?? ''));
    $cut_width_in   = trim((string)($_POST['cut_width_in']   ?? ''));
    $crate_length_ft = trim((string)($_POST['crate_length_ft'] ?? ''));
    $crate_length_in = trim((string)($_POST['crate_length_in'] ?? ''));
    $crate_width_ft  = trim((string)($_POST['crate_width_ft']  ?? ''));
    $crate_width_in  = trim((string)($_POST['crate_width_in']  ?? ''));
    $crate_height_ft = trim((string)($_POST['crate_height_ft'] ?? ''));
    $crate_height_in = trim((string)($_POST['crate_height_in'] ?? ''));
    $weight_lbs = $post_weight_lbs;
    $crate_weight_lbs = $post_crate_weight_lbs;

    // ── Validation ───────────────────────────────────────────────────────────
    if ($fields['name'] === '') {
      $errors[] = 'Machine name is required.';
    } elseif (mb_strlen($fields['name']) > 255) {
      $errors[] = 'Machine name must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['model']) > 255) {
      $errors[] = 'Model must be 255 characters or fewer.';
    }
    foreach ([
      'Cutting Area Width'   => $cut_width_db,
      'Cutting Area Length'  => $cut_length_db,
      'Machine Width'        => $mach_width_db,
      'Machine Length'       => $mach_length_db,
      'Crate Width'          => $crate_width_db,
      'Crate Length'         => $crate_length_db,
      'Crate Height'         => $crate_height_db,
    ] as $_dim_label => $_dim_val) {
      if ($_dim_val !== null && $_dim_val < 0) {
        $errors[] = $_dim_label . ' must be a positive number.';
      }
    }
    if ($weight_kg_db !== null && $weight_kg_db < 0) {
      $errors[] = 'Machine Weight must be a positive number.';
    }
    if ($crate_weight_kg_db !== null && $crate_weight_kg_db < 0) {
      $errors[] = 'Crate Weight must be a positive number.';
    }
    $price_db = null;
    if ($fields['price'] !== '') {
      if (!is_numeric($fields['price'])) {
        $errors[] = 'Price must be a number.';
      } else {
        $price_db = round((float)$fields['price'], 2);
        if ($price_db < 0) {
          $errors[] = 'Price must be a positive number.';
        }
      }
    }

    // ── Photo uploads ────────────────────────────────────────────────────────
    $new_primary   = $processPhotoUpload('primary_photo_upload',   'Primary photo',   'machine_primary');
    $new_secondary = $processPhotoUpload('secondary_photo_upload', 'Secondary photo', 'machine_secondary');
    $new_tertiary  = $processPhotoUpload('tertiary_photo_upload',  'Tertiary photo',  'machine_tertiary');

    // ── Save ─────────────────────────────────────────────────────────────────
    if (!$errors) {
      $primary_final   = $new_primary   ?? ($is_edit ? $fields['primary_photo']   : null) ?: null;
      $secondary_final = $new_secondary ?? ($is_edit ? $fields['secondary_photo'] : null) ?: null;
      $tertiary_final  = $new_tertiary  ?? ($is_edit ? $fields['tertiary_photo']  : null) ?: null;

      $common_params = [
        $fields['name'],
        $fields['model'] !== '' ? $fields['model'] : null,
        // Machine Dimensions
      $mach_length_db, $mach_width_db, $mach_length_mm_db, $mach_width_mm_db,
        $weight_kg_db,
        // Cutting Area
        $cut_length_db, $cut_width_db, $cut_length_mm_db, $cut_width_mm_db,
        // Crate Dimensions
        $crate_length_db, $crate_width_db, $crate_length_mm_db, $crate_width_mm_db,
        $crate_height_db, $crate_height_mm_db, $crate_weight_kg_db,
        // Photos
        $primary_final, $secondary_final, $tertiary_final,
        // Content & toggles
        $fields['description'] !== '' ? $fields['description'] : null,
        $price_db,
        (int)$fields['is_active'],
        (int)$fields['is_visible'],
        (int)$fields['is_catalog'],
      ];

      if ($is_edit) {
        $pdo->prepare("
          UPDATE machines SET
            name = ?, model = ?,
            machine_length = ?, machine_width = ?, machine_length_mm = ?, machine_width_mm = ?, machine_weight_kg = ?,
            cut_length = ?, cut_width = ?, cut_length_mm = ?, cut_width_mm = ?,
            crate_length = ?, crate_width = ?, crate_length_mm = ?, crate_width_mm = ?,
            crate_height = ?, crate_height_mm = ?, crate_weight_kg = ?,
            primary_photo = ?, secondary_photo = ?, tertiary_photo = ?,
            description = ?, price = ?, is_active = ?, is_visible = ?, is_catalog = ?
          WHERE id = ?
        ")->execute([...$common_params, $id]);
        $success = 'Machine updated.';
      } else {
        $pdo->prepare("
          INSERT INTO machines
            (name, model,
             machine_length, machine_width, machine_length_mm, machine_width_mm, machine_weight_kg,
             cut_length, cut_width, cut_length_mm, cut_width_mm,
             crate_length, crate_width, crate_length_mm, crate_width_mm,
             crate_height, crate_height_mm, crate_weight_kg,
             primary_photo, secondary_photo, tertiary_photo,
             description, price, is_active, is_visible, is_catalog)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute($common_params);
        $id      = (int)$pdo->lastInsertId();
        $is_edit = true;
        $success = 'Machine added.';
      }
      $_SESSION['machine_form_csrf'] = bin2hex(random_bytes(24));
      header('Location: machines.php');
      exit;
    }
  }
}

$page_title = $is_edit ? 'Edit Machine' : 'Add Machine';
render_header($page_title);
?>

<style>
  /* ── Dimension section cards ───────────────────────────────────────── */
  .dim-sections {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 14px;
    margin-top: 4px;
  }
  .dim-card {
    background: #f8fafc;
    border: 1px solid var(--b);
    border-radius: 10px;
    padding: 16px 18px 18px;
  }
  .dim-card-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--b);
  }
  .dim-unit-label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #94a3b8;
    margin-bottom: 8px;
  }
  .dim-row {
    display: flex;
    gap: 10px;
    align-items: flex-end;
    flex-wrap: wrap;
    margin-bottom: 4px;
  }
  .dim-row > div {
    display: flex;
    flex-direction: column;
    gap: 3px;
  }
  .dim-row > div > label {
    font-size: 0.78rem;
    color: #64748b;
    margin: 0;
    white-space: nowrap;
  }
  .dim-row input[type="number"] {
    width: 90px;
  }
  .dim-unit-sep {
    height: 1px;
    background: var(--b);
    margin: 14px 0;
  }
  /* ── Photo previews ────────────────────────────────────────────────── */
  .photo-preview {
    max-width: 280px;
    max-height: 200px;
    border-radius: 8px;
    border: 1px solid var(--b);
    object-fit: contain;
    display: block;
    background: #f8fafc;
    margin-top: 8px;
  }
  /* ── Toggle row ────────────────────────────────────────────────────── */
  .toggle-row {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
    align-items: center;
  }
  .toggle-row label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-weight: 500;
    margin: 0;
  }
</style>

<div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
  <h1 style="margin:0;"><?= h($page_title) ?></h1>
  <a class="btn" href="machines.php">← Back to Machines</a>
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

  <form method="post" enctype="multipart/form-data"
        action="machine_form.php<?= $is_edit ? '?id=' . $id : '' ?>"
        novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['machine_form_csrf']) ?>" />

    <div class="form-grid">

      <div>
        <label>Machine Name <span style="color:var(--d);">*</span></label>
        <input type="text" name="name" maxlength="255" required
               value="<?= h($fields['name']) ?>"
               placeholder="e.g. Fiber Laser Cutter" />
      </div>

      <div>
        <label>Model</label>
        <input type="text" name="model" maxlength="255"
               value="<?= h($fields['model']) ?>"
               placeholder="e.g. Thunder Laser Nova 63" />
      </div>

      <!-- ── Cutting Area ───────────────────────────────────────────────── -->
      <div class="full">
        <label>Cutting Area</label>
        <div class="dim-sections">

          <div class="dim-card">
            <div class="dim-card-title">Length</div>

            <div class="dim-unit-label">Imperial</div>
            <div class="dim-row">
              <div>
                <label for="cut_length_ft">Feet</label>
                <input type="number" id="cut_length_ft" name="cut_length_ft" min="0" step="1"
                       value="<?= h($cut_length_ft) ?>" placeholder="0"
                       oninput="syncImperialToMm('cut_length_ft','cut_length_in','cut_length_mm')" />
              </div>
              <div>
                <label for="cut_length_in">Inches</label>
                <input type="number" id="cut_length_in" name="cut_length_in" min="0" max="11.9999" step="0.01"
                       value="<?= h($cut_length_in) ?>" placeholder="0"
                       oninput="syncImperialToMm('cut_length_ft','cut_length_in','cut_length_mm')" />
              </div>
            </div>

            <div class="dim-unit-sep"></div>

            <div class="dim-unit-label">Metric</div>
            <div class="dim-row">
              <div>
                <label for="cut_length_mm">Millimeters</label>
                <input type="number" id="cut_length_mm" name="cut_length_mm" min="0" step="0.1"
                       value="<?= h($fields['cut_length_mm']) ?>" placeholder="0"
                       oninput="syncMmToImperial('cut_length_mm','cut_length_ft','cut_length_in')" />
              </div>
            </div>
          </div>

          <div class="dim-card">
            <div class="dim-card-title">Width</div>

            <div class="dim-unit-label">Imperial</div>
            <div class="dim-row">
              <div>
                <label for="cut_width_ft">Feet</label>
                <input type="number" id="cut_width_ft" name="cut_width_ft" min="0" step="1"
                       value="<?= h($cut_width_ft) ?>" placeholder="0"
                       oninput="syncImperialToMm('cut_width_ft','cut_width_in','cut_width_mm')" />
              </div>
              <div>
                <label for="cut_width_in">Inches</label>
                <input type="number" id="cut_width_in" name="cut_width_in" min="0" max="11.9999" step="0.01"
                       value="<?= h($cut_width_in) ?>" placeholder="0"
                       oninput="syncImperialToMm('cut_width_ft','cut_width_in','cut_width_mm')" />
              </div>
            </div>

            <div class="dim-unit-sep"></div>

            <div class="dim-unit-label">Metric</div>
            <div class="dim-row">
              <div>
                <label for="cut_width_mm">Millimeters</label>
                <input type="number" id="cut_width_mm" name="cut_width_mm" min="0" step="0.1"
                       value="<?= h($fields['cut_width_mm']) ?>" placeholder="0"
                       oninput="syncMmToImperial('cut_width_mm','cut_width_ft','cut_width_in')" />
              </div>
            </div>
          </div>

        </div><!-- .dim-sections cutting area -->
      </div>

      <!-- ── Machine Dimensions ────────────────────────────────────────── -->
      <div class="full">
        <label>Machine Dimensions</label>
        <div class="dim-sections">

          <div class="dim-card">
            <div class="dim-card-title">Length</div>

            <div class="dim-unit-label">Imperial</div>
            <div class="dim-row">
              <div>
                <label for="mach_length_ft">Feet</label>
                <input type="number" id="mach_length_ft" name="mach_length_ft" min="0" step="1"
                       value="<?= h($mach_length_ft) ?>" placeholder="0"
                       oninput="syncImperialToMm('mach_length_ft','mach_length_in','mach_length_mm')" />
              </div>
              <div>
                <label for="mach_length_in">Inches</label>
                <input type="number" id="mach_length_in" name="mach_length_in" min="0" max="11.9999" step="0.01"
                       value="<?= h($mach_length_in) ?>" placeholder="0"
                       oninput="syncImperialToMm('mach_length_ft','mach_length_in','mach_length_mm')" />
              </div>
            </div>

            <div class="dim-unit-sep"></div>

            <div class="dim-unit-label">Metric</div>
            <div class="dim-row">
              <div>
                <label for="mach_length_mm">Millimeters</label>
                <input type="number" id="mach_length_mm" name="mach_length_mm" min="0" step="0.1"
                       value="<?= h($fields['machine_length_mm']) ?>" placeholder="0"
                       oninput="syncMmToImperial('mach_length_mm','mach_length_ft','mach_length_in')" />
              </div>
            </div>
          </div>

          <div class="dim-card">
            <div class="dim-card-title">Width</div>

            <div class="dim-unit-label">Imperial</div>
            <div class="dim-row">
              <div>
                <label for="mach_width_ft">Feet</label>
                <input type="number" id="mach_width_ft" name="mach_width_ft" min="0" step="1"
                       value="<?= h($mach_width_ft) ?>" placeholder="0"
                       oninput="syncImperialToMm('mach_width_ft','mach_width_in','mach_width_mm')" />
              </div>
              <div>
                <label for="mach_width_in">Inches</label>
                <input type="number" id="mach_width_in" name="mach_width_in" min="0" max="11.9999" step="0.01"
                       value="<?= h($mach_width_in) ?>" placeholder="0"
                       oninput="syncImperialToMm('mach_width_ft','mach_width_in','mach_width_mm')" />
              </div>
            </div>

            <div class="dim-unit-sep"></div>

            <div class="dim-unit-label">Metric</div>
            <div class="dim-row">
              <div>
                <label for="mach_width_mm">Millimeters</label>
                <input type="number" id="mach_width_mm" name="mach_width_mm" min="0" step="0.1"
                       value="<?= h($fields['machine_width_mm']) ?>" placeholder="0"
                       oninput="syncMmToImperial('mach_width_mm','mach_width_ft','mach_width_in')" />
              </div>
            </div>
          </div>

          <div class="dim-card">
            <div class="dim-card-title">Weight</div>

            <div class="dim-unit-label">Imperial</div>
            <div class="dim-row">
              <div>
                <label for="weight_lbs">Pounds (lbs)</label>
                <input type="number" id="weight_lbs" name="weight_lbs" min="0" step="0.01"
                       value="<?= h($weight_lbs) ?>" placeholder="0"
                       oninput="syncLbsToKg('weight_lbs','weight_kg')" />
              </div>
            </div>

            <div class="dim-unit-sep"></div>

            <div class="dim-unit-label">Metric</div>
            <div class="dim-row">
              <div>
                <label for="weight_kg">Kilograms (kg)</label>
                <input type="number" id="weight_kg" name="weight_kg" min="0" step="0.01"
                       value="<?= h($fields['machine_weight_kg']) ?>" placeholder="0"
                       oninput="syncKgToLbs('weight_kg','weight_lbs')" />
              </div>
            </div>
          </div>

        </div><!-- .dim-sections machine -->
      </div>

      <!-- ── Crate Dimensions ──────────────────────────────────────────── -->
      <div class="full">
        <label>Crate Dimensions</label>
        <div class="dim-sections">

          <div class="dim-card">
            <div class="dim-card-title">Length</div>

            <div class="dim-unit-label">Imperial</div>
            <div class="dim-row">
              <div>
                <label for="crate_length_ft">Feet</label>
                <input type="number" id="crate_length_ft" name="crate_length_ft" min="0" step="1"
                       value="<?= h($crate_length_ft) ?>" placeholder="0"
                       oninput="syncImperialToMm('crate_length_ft','crate_length_in','crate_length_mm')" />
              </div>
              <div>
                <label for="crate_length_in">Inches</label>
                <input type="number" id="crate_length_in" name="crate_length_in" min="0" max="11.9999" step="0.01"
                       value="<?= h($crate_length_in) ?>" placeholder="0"
                       oninput="syncImperialToMm('crate_length_ft','crate_length_in','crate_length_mm')" />
              </div>
            </div>

            <div class="dim-unit-sep"></div>

            <div class="dim-unit-label">Metric</div>
            <div class="dim-row">
              <div>
                <label for="crate_length_mm">Millimeters</label>
                <input type="number" id="crate_length_mm" name="crate_length_mm" min="0" step="0.1"
                       value="<?= h($fields['crate_length_mm']) ?>" placeholder="0"
                       oninput="syncMmToImperial('crate_length_mm','crate_length_ft','crate_length_in')" />
              </div>
            </div>
          </div>

          <div class="dim-card">
            <div class="dim-card-title">Width</div>

            <div class="dim-unit-label">Imperial</div>
            <div class="dim-row">
              <div>
                <label for="crate_width_ft">Feet</label>
                <input type="number" id="crate_width_ft" name="crate_width_ft" min="0" step="1"
                       value="<?= h($crate_width_ft) ?>" placeholder="0"
                       oninput="syncImperialToMm('crate_width_ft','crate_width_in','crate_width_mm')" />
              </div>
              <div>
                <label for="crate_width_in">Inches</label>
                <input type="number" id="crate_width_in" name="crate_width_in" min="0" max="11.9999" step="0.01"
                       value="<?= h($crate_width_in) ?>" placeholder="0"
                       oninput="syncImperialToMm('crate_width_ft','crate_width_in','crate_width_mm')" />
              </div>
            </div>

            <div class="dim-unit-sep"></div>

            <div class="dim-unit-label">Metric</div>
            <div class="dim-row">
              <div>
                <label for="crate_width_mm">Millimeters</label>
                <input type="number" id="crate_width_mm" name="crate_width_mm" min="0" step="0.1"
                       value="<?= h($fields['crate_width_mm']) ?>" placeholder="0"
                       oninput="syncMmToImperial('crate_width_mm','crate_width_ft','crate_width_in')" />
              </div>
            </div>
          </div>

          <div class="dim-card">
            <div class="dim-card-title">Height</div>

            <div class="dim-unit-label">Imperial</div>
            <div class="dim-row">
              <div>
                <label for="crate_height_ft">Feet</label>
                <input type="number" id="crate_height_ft" name="crate_height_ft" min="0" step="1"
                       value="<?= h($crate_height_ft) ?>" placeholder="0"
                       oninput="syncImperialToMm('crate_height_ft','crate_height_in','crate_height_mm')" />
              </div>
              <div>
                <label for="crate_height_in">Inches</label>
                <input type="number" id="crate_height_in" name="crate_height_in" min="0" max="11.9999" step="0.01"
                       value="<?= h($crate_height_in) ?>" placeholder="0"
                       oninput="syncImperialToMm('crate_height_ft','crate_height_in','crate_height_mm')" />
              </div>
            </div>

            <div class="dim-unit-sep"></div>

            <div class="dim-unit-label">Metric</div>
            <div class="dim-row">
              <div>
                <label for="crate_height_mm">Millimeters</label>
                <input type="number" id="crate_height_mm" name="crate_height_mm" min="0" step="0.1"
                       value="<?= h($fields['crate_height_mm']) ?>" placeholder="0"
                       oninput="syncMmToImperial('crate_height_mm','crate_height_ft','crate_height_in')" />
              </div>
            </div>
          </div>

          <div class="dim-card">
            <div class="dim-card-title">Weight</div>

            <div class="dim-unit-label">Imperial</div>
            <div class="dim-row">
              <div>
                <label for="crate_weight_lbs">Pounds (lbs)</label>
                <input type="number" id="crate_weight_lbs" name="crate_weight_lbs" min="0" step="0.01"
                       value="<?= h($crate_weight_lbs) ?>" placeholder="0"
                       oninput="syncLbsToKg('crate_weight_lbs','crate_weight_kg')" />
              </div>
            </div>

            <div class="dim-unit-sep"></div>

            <div class="dim-unit-label">Metric</div>
            <div class="dim-row">
              <div>
                <label for="crate_weight_kg">Kilograms (kg)</label>
                <input type="number" id="crate_weight_kg" name="crate_weight_kg" min="0" step="0.01"
                       value="<?= h($fields['crate_weight_kg']) ?>" placeholder="0"
                       oninput="syncKgToLbs('crate_weight_kg','crate_weight_lbs')" />
              </div>
            </div>
          </div>

        </div><!-- .dim-sections crate -->
      </div>

      <!-- ── Photos ───────────────────────────────────────────────────────── -->
      <div>
        <label>Photo 1 (JPG, PNG, GIF, WebP)</label>
        <input type="file" name="primary_photo_upload" id="primary_photo_upload"
               accept="image/jpeg,image/png,image/gif,image/webp"
               onchange="previewPhoto(this, 'primary_preview')" />
        <?php if ($is_edit && $fields['primary_photo'] !== ''): ?>
          <div class="muted" style="margin-top:4px;">Upload a new file to replace the current photo.</div>
          <a href="uploads/<?= h(rawurlencode($fields['primary_photo'])) ?>" target="_blank" rel="noopener noreferrer">
            <img class="photo-preview" id="primary_preview"
                 src="uploads/<?= h(rawurlencode($fields['primary_photo'])) ?>"
                 alt="Current photo 1" />
          </a>
        <?php else: ?>
          <img class="photo-preview" id="primary_preview" src="" alt="" style="display:none;" />
        <?php endif; ?>
      </div>

      <div>
        <label>Photo 2 (JPG, PNG, GIF, WebP)</label>
        <input type="file" name="secondary_photo_upload" id="secondary_photo_upload"
               accept="image/jpeg,image/png,image/gif,image/webp"
               onchange="previewPhoto(this, 'secondary_preview')" />
        <?php if ($is_edit && $fields['secondary_photo'] !== ''): ?>
          <div class="muted" style="margin-top:4px;">Upload a new file to replace the current photo.</div>
          <a href="uploads/<?= h(rawurlencode($fields['secondary_photo'])) ?>" target="_blank" rel="noopener noreferrer">
            <img class="photo-preview" id="secondary_preview"
                 src="uploads/<?= h(rawurlencode($fields['secondary_photo'])) ?>"
                 alt="Current photo 2" />
          </a>
        <?php else: ?>
          <img class="photo-preview" id="secondary_preview" src="" alt="" style="display:none;" />
        <?php endif; ?>
      </div>

      <div>
        <label>Photo 3 (JPG, PNG, GIF, WebP)</label>
        <input type="file" name="tertiary_photo_upload" id="tertiary_photo_upload"
               accept="image/jpeg,image/png,image/gif,image/webp"
               onchange="previewPhoto(this, 'tertiary_preview')" />
        <?php if ($is_edit && $fields['tertiary_photo'] !== ''): ?>
          <div class="muted" style="margin-top:4px;">Upload a new file to replace the current photo.</div>
          <a href="uploads/<?= h(rawurlencode($fields['tertiary_photo'])) ?>" target="_blank" rel="noopener noreferrer">
            <img class="photo-preview" id="tertiary_preview"
                 src="uploads/<?= h(rawurlencode($fields['tertiary_photo'])) ?>"
                 alt="Current photo 3" />
          </a>
        <?php else: ?>
          <img class="photo-preview" id="tertiary_preview" src="" alt="" style="display:none;" />
        <?php endif; ?>
      </div>

      <!-- ── Description ──────────────────────────────────────────────────── -->
      <div class="full">
        <label>Description</label>
        <textarea name="description" rows="4"
                  placeholder="e.g. High-powered CO₂ laser cutter for large-format sheet work…"><?= h($fields['description']) ?></textarea>
      </div>

      <!-- ── Price ────────────────────────────────────────────────────────── -->
      <div>
        <label for="price">Price (USD)</label>
        <input type="number" id="price" name="price" min="0" step="0.01"
               value="<?= h($fields['price']) ?>" placeholder="0.00" />
        <div class="muted" style="margin-top:6px; font-size:0.82rem;">
          Amount charged at checkout. Stripe receives this value from our app — nothing is stored in Stripe.
        </div>
      </div>

      <!-- ── Toggles ──────────────────────────────────────────────────────── -->
      <div class="full">
        <div class="toggle-row">
          <label title="Machine is operational and available for use">
            <input type="checkbox" name="is_active" value="1"
                   <?= $fields['is_active']  === '1' ? 'checked' : '' ?> />
            Active
          </label>
          <label title="Machine is displayed on the public-facing site">
            <input type="checkbox" name="is_visible" value="1"
                   <?= $fields['is_visible'] === '1' ? 'checked' : '' ?> />
            Visible
          </label>
          <label title="Machine appears in the equipment catalog">
            <input type="checkbox" name="is_catalog" value="1"
                   <?= $fields['is_catalog'] === '1' ? 'checked' : '' ?> />
            Catalog
          </label>
        </div>
        <div class="muted" style="margin-top:6px; font-size:0.82rem;">
          <strong>Active</strong> — machine is operational &nbsp;·&nbsp;
          <strong>Visible</strong> — shown on the public site &nbsp;·&nbsp;
          <strong>Catalog</strong> — listed in the equipment catalog
        </div>
      </div>

    </div><!-- .form-grid -->

    <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
      <button type="submit" class="btn primary"><?= $is_edit ? 'Save Changes' : 'Add Machine' ?></button>
      <a class="btn" href="machines.php">Cancel</a>
    </div>
  </form>
</div>

<script>
// ── Unit conversion helpers ─────────────────────────────────────────────────
function round2(n) { return Math.round(n * 100) / 100; }

function syncImperialToMm(ftId, inId, mmId) {
  var ft       = parseFloat(document.getElementById(ftId).value) || 0;
  var inches   = parseFloat(document.getElementById(inId).value) || 0;
  var total_in = ft * 12 + inches;
  document.getElementById(mmId).value = total_in > 0 ? round2(total_in * 25.4) : '';
}

function syncMmToImperial(mmId, ftId, inId) {
  var mm = parseFloat(document.getElementById(mmId).value) || 0;
  if (mm > 0) {
    var total_in = mm / 25.4;
    var ft       = Math.floor(total_in / 12);
    var inches   = round2(total_in - ft * 12);
    document.getElementById(ftId).value = ft   || '';
    document.getElementById(inId).value = inches || '';
  } else {
    document.getElementById(ftId).value = '';
    document.getElementById(inId).value = '';
  }
}

function syncLbsToKg(lbsId, kgId) {
  var lbs = parseFloat(document.getElementById(lbsId).value) || 0;
  document.getElementById(kgId).value = lbs > 0 ? round2(lbs / 2.20462) : '';
}

function syncKgToLbs(kgId, lbsId) {
  var kg = parseFloat(document.getElementById(kgId).value) || 0;
  document.getElementById(lbsId).value = kg > 0 ? round2(kg * 2.20462) : '';
}

// ── Photo preview ───────────────────────────────────────────────────────────
function previewPhoto(input, previewId) {
  var preview = document.getElementById(previewId);
  if (!preview) return;
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function (e) {
      preview.src = e.target.result;
      preview.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
  }
}
</script>

<?php render_footer(); ?>
