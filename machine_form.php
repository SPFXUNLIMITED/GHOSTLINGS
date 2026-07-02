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
  'name'            => '',
  'model'           => '',
  'width'           => '',
  'height'          => '',
  'width_mm'        => '',
  'height_mm'       => '',
  'primary_photo'   => '',
  'secondary_photo' => '',
  'description'     => '',
  'is_active'       => '1',
];

// For the form's imperial decomposition (display only; recombined on submit)
$width_ft  = '';
$width_in  = '';
$height_ft = '';
$height_in = '';

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
  if ($fields['width'] !== '' && (float)$fields['width'] > 0) {
    $total_in  = (float)$fields['width'];
    $width_ft  = (string)(int)floor($total_in / 12);
    $width_in  = (string)round($total_in - ((int)floor($total_in / 12)) * 12, 4);
  }
  if ($fields['height'] !== '' && (float)$fields['height'] > 0) {
    $total_in   = (float)$fields['height'];
    $height_ft  = (string)(int)floor($total_in / 12);
    $height_in  = (string)round($total_in - ((int)floor($total_in / 12)) * 12, 4);
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
    $fields['is_active']   = isset($_POST['is_active']) ? '1' : '0';

    // ── Dimension parsing ────────────────────────────────────────────────────
    // Accept imperial (ft + in) OR metric (mm); JS keeps both in sync.
    // If mm fields are provided, prefer them; otherwise calculate from ft+in.
    $post_width_ft  = trim((string)($_POST['width_ft']  ?? ''));
    $post_width_in  = trim((string)($_POST['width_in']  ?? ''));
    $post_width_mm  = trim((string)($_POST['width_mm']  ?? ''));
    $post_height_ft = trim((string)($_POST['height_ft'] ?? ''));
    $post_height_in = trim((string)($_POST['height_in'] ?? ''));
    $post_height_mm = trim((string)($_POST['height_mm'] ?? ''));

    $width_db     = null;
    $width_mm_db  = null;
    $height_db    = null;
    $height_mm_db = null;

    if ($post_width_mm !== '') {
      $width_mm_db = round((float)$post_width_mm, 2);
      $width_db    = round($width_mm_db / 25.4, 4);
    } elseif ($post_width_ft !== '' || $post_width_in !== '') {
      $width_db    = round(((float)$post_width_ft * 12) + (float)$post_width_in, 4);
      $width_mm_db = round($width_db * 25.4, 2);
    }

    if ($post_height_mm !== '') {
      $height_mm_db = round((float)$post_height_mm, 2);
      $height_db    = round($height_mm_db / 25.4, 4);
    } elseif ($post_height_ft !== '' || $post_height_in !== '') {
      $height_db    = round(((float)$post_height_ft * 12) + (float)$post_height_in, 4);
      $height_mm_db = round($height_db * 25.4, 2);
    }

    // Repopulate for re-display if there are errors
    $fields['width']     = $width_db    !== null ? (string)$width_db    : '';
    $fields['height']    = $height_db   !== null ? (string)$height_db   : '';
    $fields['width_mm']  = $width_mm_db  !== null ? (string)$width_mm_db  : '';
    $fields['height_mm'] = $height_mm_db !== null ? (string)$height_mm_db : '';
    $width_ft  = $post_width_ft;
    $width_in  = $post_width_in;
    $height_ft = $post_height_ft;
    $height_in = $post_height_in;

    // ── Validation ───────────────────────────────────────────────────────────
    if ($fields['name'] === '') {
      $errors[] = 'Machine name is required.';
    } elseif (mb_strlen($fields['name']) > 255) {
      $errors[] = 'Machine name must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['model']) > 255) {
      $errors[] = 'Model must be 255 characters or fewer.';
    }
    if ($width_db !== null && $width_db < 0) {
      $errors[] = 'Width must be a positive number.';
    }
    if ($height_db !== null && $height_db < 0) {
      $errors[] = 'Height must be a positive number.';
    }

    // ── Photo uploads ────────────────────────────────────────────────────────
    $new_primary   = $processPhotoUpload('primary_photo_upload',   'Primary photo',   'machine_primary');
    $new_secondary = $processPhotoUpload('secondary_photo_upload', 'Secondary photo', 'machine_secondary');

    // ── Save ─────────────────────────────────────────────────────────────────
    if (!$errors) {
      $primary_final   = $new_primary   ?? ($is_edit ? $fields['primary_photo']   : null) ?: null;
      $secondary_final = $new_secondary ?? ($is_edit ? $fields['secondary_photo'] : null) ?: null;

      if ($is_edit) {
        $pdo->prepare("
          UPDATE machines SET
            name = ?, model = ?,
            width = ?, height = ?, width_mm = ?, height_mm = ?,
            primary_photo = ?, secondary_photo = ?,
            description = ?, is_active = ?
          WHERE id = ?
        ")->execute([
          $fields['name'],
          $fields['model'] !== '' ? $fields['model'] : null,
          $width_db, $height_db, $width_mm_db, $height_mm_db,
          $primary_final, $secondary_final,
          $fields['description'] !== '' ? $fields['description'] : null,
          (int)$fields['is_active'],
          $id,
        ]);
        $success = 'Machine updated.';
      } else {
        $pdo->prepare("
          INSERT INTO machines (name, model, width, height, width_mm, height_mm, primary_photo, secondary_photo, description, is_active)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
          $fields['name'],
          $fields['model'] !== '' ? $fields['model'] : null,
          $width_db, $height_db, $width_mm_db, $height_mm_db,
          $primary_final, $secondary_final,
          $fields['description'] !== '' ? $fields['description'] : null,
          (int)$fields['is_active'],
        ]);
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
  .dim-group {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
  }
  .dim-group label { margin: 0; white-space: nowrap; font-size: 0.85rem; color: #64748b; }
  .dim-group input[type="number"] { width: 90px; }
  .dim-separator {
    font-size: 1.2rem;
    color: #94a3b8;
    font-weight: 700;
    padding: 0 4px;
  }
  .dim-section-label {
    font-weight: 600;
    font-size: 0.8rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 6px;
  }
  .dim-or-divider {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #94a3b8;
    font-size: 0.85rem;
    margin: 4px 0;
  }
  .dim-or-divider::before,
  .dim-or-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--b);
  }
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

      <!-- ── Width ────────────────────────────────────────────────────────── -->
      <div class="full">
        <label>Width</label>

        <div class="dim-section-label">Imperial</div>
        <div class="dim-group">
          <div>
            <label for="width_ft">Feet</label>
            <input type="number" id="width_ft" name="width_ft" min="0" step="1"
                   value="<?= h($width_ft) ?>" placeholder="0"
                   oninput="syncWidthImperialToMm()" />
          </div>
          <span class="dim-separator">'</span>
          <div>
            <label for="width_in">Inches</label>
            <input type="number" id="width_in" name="width_in" min="0" max="11.9999" step="0.01"
                   value="<?= h($width_in) ?>" placeholder="0"
                   oninput="syncWidthImperialToMm()" />
          </div>
          <span class="dim-separator">"</span>
        </div>

        <div class="dim-or-divider">or</div>

        <div class="dim-section-label">Metric</div>
        <div class="dim-group">
          <div>
            <label for="width_mm">Millimeters</label>
            <input type="number" id="width_mm" name="width_mm" min="0" step="0.1"
                   value="<?= h($fields['width_mm']) ?>" placeholder="0"
                   oninput="syncWidthMmToImperial()" />
          </div>
          <span style="color:#64748b; font-size:0.9rem;">mm</span>
        </div>
      </div>

      <!-- ── Height ───────────────────────────────────────────────────────── -->
      <div class="full">
        <label>Height (Depth)</label>

        <div class="dim-section-label">Imperial</div>
        <div class="dim-group">
          <div>
            <label for="height_ft">Feet</label>
            <input type="number" id="height_ft" name="height_ft" min="0" step="1"
                   value="<?= h($height_ft) ?>" placeholder="0"
                   oninput="syncHeightImperialToMm()" />
          </div>
          <span class="dim-separator">'</span>
          <div>
            <label for="height_in">Inches</label>
            <input type="number" id="height_in" name="height_in" min="0" max="11.9999" step="0.01"
                   value="<?= h($height_in) ?>" placeholder="0"
                   oninput="syncHeightImperialToMm()" />
          </div>
          <span class="dim-separator">"</span>
        </div>

        <div class="dim-or-divider">or</div>

        <div class="dim-section-label">Metric</div>
        <div class="dim-group">
          <div>
            <label for="height_mm">Millimeters</label>
            <input type="number" id="height_mm" name="height_mm" min="0" step="0.1"
                   value="<?= h($fields['height_mm']) ?>" placeholder="0"
                   oninput="syncHeightMmToImperial()" />
          </div>
          <span style="color:#64748b; font-size:0.9rem;">mm</span>
        </div>
      </div>

      <!-- ── Photos ───────────────────────────────────────────────────────── -->
      <div>
        <label>Primary Photo (JPG, PNG, GIF, WebP)</label>
        <input type="file" name="primary_photo_upload" id="primary_photo_upload"
               accept="image/jpeg,image/png,image/gif,image/webp"
               onchange="previewPhoto(this, 'primary_preview')" />
        <?php if ($is_edit && $fields['primary_photo'] !== ''): ?>
          <div class="muted" style="margin-top:4px;">Upload a new file to replace the current photo.</div>
          <a href="uploads/<?= h(rawurlencode($fields['primary_photo'])) ?>" target="_blank" rel="noopener noreferrer">
            <img class="photo-preview" id="primary_preview"
                 src="uploads/<?= h(rawurlencode($fields['primary_photo'])) ?>"
                 alt="Current primary photo" />
          </a>
        <?php else: ?>
          <img class="photo-preview" id="primary_preview" src="" alt="" style="display:none;" />
        <?php endif; ?>
      </div>

      <div>
        <label>Secondary Photo (JPG, PNG, GIF, WebP)</label>
        <input type="file" name="secondary_photo_upload" id="secondary_photo_upload"
               accept="image/jpeg,image/png,image/gif,image/webp"
               onchange="previewPhoto(this, 'secondary_preview')" />
        <?php if ($is_edit && $fields['secondary_photo'] !== ''): ?>
          <div class="muted" style="margin-top:4px;">Upload a new file to replace the current photo.</div>
          <a href="uploads/<?= h(rawurlencode($fields['secondary_photo'])) ?>" target="_blank" rel="noopener noreferrer">
            <img class="photo-preview" id="secondary_preview"
                 src="uploads/<?= h(rawurlencode($fields['secondary_photo'])) ?>"
                 alt="Current secondary photo" />
          </a>
        <?php else: ?>
          <img class="photo-preview" id="secondary_preview" src="" alt="" style="display:none;" />
        <?php endif; ?>
      </div>

      <div class="full">
        <label>Description</label>
        <textarea name="description" rows="4"
                  placeholder="e.g. High-powered CO₂ laser cutter for large-format sheet work…"><?= h($fields['description']) ?></textarea>
      </div>

      <div class="full">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" name="is_active" value="1"
                 <?= $fields['is_active'] === '1' ? 'checked' : '' ?> />
          Active (visible in catalog)
        </label>
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
function inchesToMm(inches) { return inches * 25.4; }
function mmToInches(mm)     { return mm / 25.4; }

function round2(n) { return Math.round(n * 100) / 100; }

function syncWidthImperialToMm() {
  var ft     = parseFloat(document.getElementById('width_ft').value) || 0;
  var inches = parseFloat(document.getElementById('width_in').value) || 0;
  var total_in = ft * 12 + inches;
  document.getElementById('width_mm').value = total_in > 0 ? round2(inchesToMm(total_in)) : '';
}

function syncWidthMmToImperial() {
  var mm = parseFloat(document.getElementById('width_mm').value) || 0;
  if (mm > 0) {
    var total_in = mmToInches(mm);
    var ft     = Math.floor(total_in / 12);
    var inches = round2(total_in - ft * 12);
    document.getElementById('width_ft').value = ft || '';
    document.getElementById('width_in').value = inches || '';
  } else {
    document.getElementById('width_ft').value = '';
    document.getElementById('width_in').value = '';
  }
}

function syncHeightImperialToMm() {
  var ft     = parseFloat(document.getElementById('height_ft').value) || 0;
  var inches = parseFloat(document.getElementById('height_in').value) || 0;
  var total_in = ft * 12 + inches;
  document.getElementById('height_mm').value = total_in > 0 ? round2(inchesToMm(total_in)) : '';
}

function syncHeightMmToImperial() {
  var mm = parseFloat(document.getElementById('height_mm').value) || 0;
  if (mm > 0) {
    var total_in = mmToInches(mm);
    var ft     = Math.floor(total_in / 12);
    var inches = round2(total_in - ft * 12);
    document.getElementById('height_ft').value = ft || '';
    document.getElementById('height_in').value = inches || '';
  } else {
    document.getElementById('height_ft').value = '';
    document.getElementById('height_in').value = '';
  }
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
