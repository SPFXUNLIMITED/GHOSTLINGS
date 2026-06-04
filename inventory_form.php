<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$pdo->exec("
  CREATE TABLE IF NOT EXISTS inventory_items (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_number        VARCHAR(120) NOT NULL,
    item_name          VARCHAR(255) NOT NULL,
    description        TEXT NULL,
    category           ENUM('Machine','Part','Consumable') NOT NULL DEFAULT 'Part',
    supplier           VARCHAR(255) NOT NULL DEFAULT '',
    cost_price         DECIMAL(12,2) NULL,
    retail_price       DECIMAL(12,2) NULL,
    wholesale_price    DECIMAL(12,2) NULL,
    minimum_price      DECIMAL(12,2) NULL,
    current_stock      INT NOT NULL DEFAULT 0,
    low_stock_alert    INT NOT NULL DEFAULT 0,
    location           VARCHAR(255) NOT NULL DEFAULT '',
    image_original_name VARCHAR(255) NULL,
    image_stored_name  VARCHAR(255) NULL,
    image_mime_type    VARCHAR(191) NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_inventory_part_number (part_number),
    KEY idx_inventory_item_name (item_name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if (empty($_SESSION['inventory_form_csrf'])) {
  $_SESSION['inventory_form_csrf'] = bin2hex(random_bytes(24));
}

function parse_money_field(string $raw, string $label, array &$errors): ?string {
  $raw = trim($raw);
  if ($raw === '') {
    return null;
  }
  if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $raw)) {
    $errors[] = $label . ' must be a non-negative number with up to 2 decimals.';
    return null;
  }
  return number_format((float)$raw, 2, '.', '');
}

function parse_int_field(string $raw, string $label, array &$errors): int {
  $raw = trim($raw);
  if ($raw === '') {
    return 0;
  }
  if (!preg_match('/^\d+$/', $raw)) {
    $errors[] = $label . ' must be a non-negative whole number.';
    return 0;
  }
  return (int)$raw;
}

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;
$categories = ['Machine', 'Part', 'Consumable'];
$max_image_bytes = 5 * 1024 * 1024;
$allowed_image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$allowed_image_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$image_mime_to_ext = [
  'image/jpeg' => 'jpg',
  'image/png' => 'png',
  'image/gif' => 'gif',
  'image/webp' => 'webp',
];

$fields = [
  'part_number' => '',
  'item_name' => '',
  'description' => '',
  'category' => 'Part',
  'supplier' => '',
  'cost_price' => '',
  'retail_price' => '',
  'wholesale_price' => '',
  'minimum_price' => '',
  'current_stock' => '0',
  'low_stock_alert' => '0',
  'location' => '',
];

$errors = [];
$warnings = [];
$image_original_name = null;
$image_stored_name = null;
$image_mime_type = null;

if ($is_edit) {
  $stmt = $pdo->prepare("
    SELECT
      id, part_number, item_name, description, category, supplier,
      cost_price, retail_price, wholesale_price, minimum_price,
      current_stock, low_stock_alert, location,
      image_original_name, image_stored_name, image_mime_type
    FROM inventory_items
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$id]);
  $existing = $stmt->fetch();
  if (!$existing) {
    http_response_code(404);
    render_header('Inventory Item Not Found');
    echo '<div class="card"><p class="muted">Inventory item not found.</p><a class="btn" href="inventory_list.php">← Back to Inventory</a></div>';
    render_footer();
    exit;
  }

  foreach ($fields as $key => $_) {
    $fields[$key] = (string)($existing[$key] ?? '');
  }
  $image_original_name = $existing['image_original_name'] ?? null;
  $image_stored_name = $existing['image_stored_name'] ?? null;
  $image_mime_type = $existing['image_mime_type'] ?? null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['inventory_form_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    foreach ($fields as $key => $_) {
      $fields[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if ($fields['part_number'] === '') {
      $errors[] = 'Part Number is required.';
    } elseif (mb_strlen($fields['part_number']) > 120) {
      $errors[] = 'Part Number must be 120 characters or fewer.';
    }

    if ($fields['item_name'] === '') {
      $errors[] = 'Name is required.';
    } elseif (mb_strlen($fields['item_name']) > 255) {
      $errors[] = 'Name must be 255 characters or fewer.';
    }

    if (!in_array($fields['category'], $categories, true)) {
      $errors[] = 'Category is invalid.';
    }
    if (mb_strlen($fields['supplier']) > 255) {
      $errors[] = 'Supplier must be 255 characters or fewer.';
    }
    if (mb_strlen($fields['location']) > 255) {
      $errors[] = 'Location must be 255 characters or fewer.';
    }

    $cost_price = parse_money_field($fields['cost_price'], 'Cost Price', $errors);
    $retail_price = parse_money_field($fields['retail_price'], 'Retail Price', $errors);
    $wholesale_price = parse_money_field($fields['wholesale_price'], 'Wholesale Price', $errors);
    $minimum_price = parse_money_field($fields['minimum_price'], 'Minimum Price', $errors);
    $current_stock = parse_int_field($fields['current_stock'], 'Current Stock', $errors);
    $low_stock_alert = parse_int_field($fields['low_stock_alert'], 'Low Stock Alert', $errors);

    if (isset($_FILES['image']) && is_array($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $img = $_FILES['image'];
      if ((int)($img['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Image upload failed (code ' . (int)$img['error'] . ').';
      } else {
        $size = (int)($img['size'] ?? 0);
        $tmp_path = (string)($img['tmp_name'] ?? '');
        $orig_name = trim((string)($img['name'] ?? 'image'));
        if ($size > $max_image_bytes) {
          $errors[] = 'Image exceeds 5 MB maximum size.';
        }

        $uploaded_ext = strtolower((string)pathinfo($orig_name, PATHINFO_EXTENSION));
        if ($uploaded_ext === 'jpeg') {
          $uploaded_ext = 'jpg';
        }
        if (!in_array($uploaded_ext, $allowed_image_exts, true)) {
          $errors[] = 'Image type not allowed. Allowed: ' . implode(', ', $allowed_image_exts) . '.';
        }

        $mime = null;
        if ($tmp_path !== '' && is_file($tmp_path) && function_exists('finfo_open')) {
          $fi = finfo_open(FILEINFO_MIME_TYPE);
          if ($fi) {
            $mime = finfo_file($fi, $tmp_path) ?: null;
            finfo_close($fi);
          }
        }
        if ($mime === null || !in_array($mime, $allowed_image_mimes, true)) {
          $errors[] = 'Uploaded image content type is not allowed.';
        }

        if (!$errors) {
          $uploads_dir = __DIR__ . '/uploads/inventory';
          if (!is_dir($uploads_dir)) {
            if (!mkdir($uploads_dir, 0755, true) && !is_dir($uploads_dir)) {
              $errors[] = 'Unable to create uploads/inventory directory.';
            }
          }
          if (!is_dir($uploads_dir) || !is_writable($uploads_dir)) {
            $errors[] = 'uploads/inventory directory is missing or not writable.';
          } else {
            $stored_ext = $image_mime_to_ext[$mime] ?? '';
            if ($stored_ext === '') {
              $errors[] = 'Unable to resolve image extension.';
            } else {
              $stored = 'inv_' . bin2hex(random_bytes(16)) . '.' . $stored_ext;
              $dest = $uploads_dir . '/' . $stored;
              if (!move_uploaded_file($tmp_path, $dest)) {
                $errors[] = 'Failed to store uploaded image.';
              } else {
                if ($is_edit && $image_stored_name) {
                  $old = __DIR__ . '/uploads/inventory/' . basename((string)$image_stored_name);
                  if (is_file($old)) {
                    if (!unlink($old)) {
                      $warnings[] = 'New image uploaded, but the previous image file could not be removed.';
                    }
                  }
                }
                $image_original_name = $orig_name;
                $image_stored_name = $stored;
                $image_mime_type = $mime;
              }
            }
          }
        }
      }
    }

    if (!$errors) {
      $_SESSION['inventory_form_csrf'] = bin2hex(random_bytes(24));
      if ($is_edit) {
        $upd = $pdo->prepare("
          UPDATE inventory_items SET
            part_number = ?, item_name = ?, description = ?, category = ?, supplier = ?,
            cost_price = ?, retail_price = ?, wholesale_price = ?, minimum_price = ?,
            current_stock = ?, low_stock_alert = ?, location = ?,
            image_original_name = ?, image_stored_name = ?, image_mime_type = ?
          WHERE id = ?
        ");
        $upd->execute([
          $fields['part_number'],
          $fields['item_name'],
          $fields['description'] !== '' ? $fields['description'] : null,
          $fields['category'],
          $fields['supplier'],
          $cost_price,
          $retail_price,
          $wholesale_price,
          $minimum_price,
          $current_stock,
          $low_stock_alert,
          $fields['location'],
          $image_original_name,
          $image_stored_name,
          $image_mime_type,
          $id,
        ]);
        header('Location: inventory_list.php?success=updated');
        exit;
      } else {
        $ins = $pdo->prepare("
          INSERT INTO inventory_items (
            part_number, item_name, description, category, supplier,
            cost_price, retail_price, wholesale_price, minimum_price,
            current_stock, low_stock_alert, location,
            image_original_name, image_stored_name, image_mime_type
          ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
          $fields['part_number'],
          $fields['item_name'],
          $fields['description'] !== '' ? $fields['description'] : null,
          $fields['category'],
          $fields['supplier'],
          $cost_price,
          $retail_price,
          $wholesale_price,
          $minimum_price,
          $current_stock,
          $low_stock_alert,
          $fields['location'],
          $image_original_name,
          $image_stored_name,
          $image_mime_type,
        ]);
        header('Location: inventory_list.php?success=created');
        exit;
      }
    }
  }
}

$page_title = $is_edit ? 'Edit Inventory Item' : 'Add Inventory Item';
$image_url = $image_stored_name ? 'uploads/inventory/' . rawurlencode((string)$image_stored_name) : '';
render_header($page_title);
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1><?= h($page_title) ?></h1>
    <p class="muted">Manage inventory details, pricing tiers, stock levels, and product image.</p>
  </div>
  <a class="btn" href="inventory_list.php">← Back to Inventory</a>
</div>

<div class="card">
  <?php if ($errors): ?>
    <div class="alert error" style="margin-bottom:14px;">
      <ul style="margin:0; padding-left:18px;">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($warnings): ?>
    <div class="alert" style="margin-bottom:14px; border-color:#fde68a; background:#fffbeb; color:#92400e;">
      <ul style="margin:0; padding-left:18px;">
        <?php foreach ($warnings as $w): ?><li><?= h($w) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" action="inventory_form.php<?= $is_edit ? '?id=' . (int)$id : '' ?>" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['inventory_form_csrf']) ?>" />

    <div class="form-grid">
      <div>
        <label>Part Number <span style="color:var(--d);">*</span></label>
        <input type="text" name="part_number" maxlength="120" required value="<?= h($fields['part_number']) ?>" />
      </div>
      <div>
        <label>Name <span style="color:var(--d);">*</span></label>
        <input type="text" name="item_name" maxlength="255" required value="<?= h($fields['item_name']) ?>" />
      </div>
      <div class="full">
        <label>Description</label>
        <textarea name="description" rows="4"><?= h($fields['description']) ?></textarea>
      </div>
      <div>
        <label>Category <span style="color:var(--d);">*</span></label>
        <select name="category" required>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= h($cat) ?>" <?= $fields['category'] === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Supplier</label>
        <input type="text" name="supplier" maxlength="255" value="<?= h($fields['supplier']) ?>" />
      </div>
      <div>
        <label>Cost Price</label>
        <input type="text" name="cost_price" inputmode="decimal" placeholder="0.00" value="<?= h($fields['cost_price']) ?>" />
      </div>
      <div>
        <label>Retail Price</label>
        <input type="text" name="retail_price" inputmode="decimal" placeholder="0.00" value="<?= h($fields['retail_price']) ?>" />
      </div>
      <div>
        <label>Wholesale Price</label>
        <input type="text" name="wholesale_price" inputmode="decimal" placeholder="0.00" value="<?= h($fields['wholesale_price']) ?>" />
      </div>
      <div>
        <label>Minimum Price</label>
        <input type="text" name="minimum_price" inputmode="decimal" placeholder="0.00" value="<?= h($fields['minimum_price']) ?>" />
      </div>
      <div>
        <label>Current Stock</label>
        <input type="text" name="current_stock" inputmode="numeric" value="<?= h($fields['current_stock']) ?>" />
      </div>
      <div>
        <label>Low Stock Alert</label>
        <input type="text" name="low_stock_alert" inputmode="numeric" value="<?= h($fields['low_stock_alert']) ?>" />
      </div>
      <div>
        <label>Location</label>
        <input type="text" name="location" maxlength="255" value="<?= h($fields['location']) ?>" />
      </div>
      <div>
        <label>Image Upload</label>
        <input type="file" name="image" accept=".jpg,.jpeg,.png,.gif,.webp,image/*" />
        <div class="muted" style="margin-top:6px;">Accepted: JPG, PNG, GIF, WEBP (max 5 MB).</div>
      </div>
    </div>

    <?php if ($image_url !== ''): ?>
      <div style="margin-top:14px;">
        <div class="muted" style="margin-bottom:6px;">Current Image</div>
        <img src="<?= h($image_url) ?>" alt="Current inventory image" style="width:120px; height:120px; object-fit:cover; border-radius:8px; border:1px solid var(--b);" />
      </div>
    <?php endif; ?>

    <div style="margin-top:16px;">
      <button type="submit" class="btn primary"><?= $is_edit ? 'Save Changes' : 'Create Inventory Item' ?></button>
    </div>
  </form>
</div>

<?php render_footer(); ?>
