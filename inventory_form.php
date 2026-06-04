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
    supplier_1_name    VARCHAR(255) NOT NULL DEFAULT '',
    supplier_1_url     VARCHAR(1000) NULL,
    supplier_2_name    VARCHAR(255) NOT NULL DEFAULT '',
    supplier_2_url     VARCHAR(1000) NULL,
    supplier_3_name    VARCHAR(255) NOT NULL DEFAULT '',
    supplier_3_url     VARCHAR(1000) NULL,
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
    UNIQUE KEY uq_inventory_part_number (part_number),
    KEY idx_inventory_item_name (item_name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

foreach ([
  "ALTER TABLE inventory_items ADD COLUMN supplier_1_name VARCHAR(255) NOT NULL DEFAULT '' AFTER category",
  "ALTER TABLE inventory_items ADD COLUMN supplier_1_url VARCHAR(1000) NULL AFTER supplier_1_name",
  "ALTER TABLE inventory_items ADD COLUMN supplier_2_name VARCHAR(255) NOT NULL DEFAULT '' AFTER supplier_1_url",
  "ALTER TABLE inventory_items ADD COLUMN supplier_2_url VARCHAR(1000) NULL AFTER supplier_2_name",
  "ALTER TABLE inventory_items ADD COLUMN supplier_3_name VARCHAR(255) NOT NULL DEFAULT '' AFTER supplier_2_url",
  "ALTER TABLE inventory_items ADD COLUMN supplier_3_url VARCHAR(1000) NULL AFTER supplier_3_name",
] as $sql) {
  try {
    $pdo->exec($sql);
  } catch (PDOException $e) {
    if ($e->getCode() !== '42S21') {
      throw $e;
    }
  }
}

try {
  $pdo->exec("
    UPDATE inventory_items
    SET
      supplier_1_name = CASE
        WHEN supplier_1_name = '' THEN COALESCE(supplier, '')
        ELSE supplier_1_name
      END,
      supplier_1_url = CASE
        WHEN (supplier_1_url IS NULL OR supplier_1_url = '')
          THEN COALESCE(NULLIF(amazon_purchase_link, ''), NULLIF(alibaba_purchase_link, ''))
        ELSE supplier_1_url
      END
    WHERE
      supplier_1_name = '' OR supplier_1_url IS NULL OR supplier_1_url = ''
  ");
} catch (PDOException $e) {
  if ($e->getCode() !== '42S22') {
    throw $e;
  }
}

function next_inventory_part_number_from_seed(int $seed, array &$used): string {
  $suffix = max(1, $seed);
  do {
    $candidate = sprintf('INV-%05d', $suffix);
    $suffix++;
  } while (isset($used[strtolower($candidate)]));

  $used[strtolower($candidate)] = true;
  return $candidate;
}

function normalize_inventory_part_numbers(PDO $pdo): void {
  $rows = $pdo->query("SELECT id, part_number FROM inventory_items ORDER BY id ASC")->fetchAll();
  $used = [];
  $updates = [];

  foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    $part_number = trim((string)($row['part_number'] ?? ''));
    $key = strtolower($part_number);
    if ($part_number !== '' && !isset($used[$key])) {
      $used[$key] = true;
      continue;
    }

    $updates[$id] = next_inventory_part_number_from_seed($id, $used);
  }

  if ($updates) {
    $stmt = $pdo->prepare("UPDATE inventory_items SET part_number = ? WHERE id = ?");
    foreach ($updates as $id => $part_number) {
      $stmt->execute([$part_number, $id]);
    }
  }
}

function inventory_part_number_index_exists(PDO $pdo): bool {
  $stmt = $pdo->prepare("
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'inventory_items'
      AND index_name = 'uq_inventory_part_number'
    LIMIT 1
  ");
  $stmt->execute();
  return $stmt->fetchColumn() !== false;
}

function generate_inventory_part_number(PDO $pdo, int $inventory_id): string {
  $seed = max(1, $inventory_id);
  $stmt = $pdo->prepare("SELECT id FROM inventory_items WHERE part_number = ? AND id <> ? LIMIT 1");
  do {
    $candidate = sprintf('INV-%05d', $seed);
    $seed++;
    $stmt->execute([$candidate, $inventory_id]);
  } while ($stmt->fetchColumn() !== false);

  return $candidate;
}

if (!inventory_part_number_index_exists($pdo)) {
  normalize_inventory_part_numbers($pdo);

  try {
    $pdo->exec("ALTER TABLE inventory_items ADD UNIQUE INDEX uq_inventory_part_number (part_number)");
  } catch (PDOException $e) {
    if (!in_array((string)$e->getCode(), ['42000', '42S11'], true)) {
      throw $e;
    }
  }
}

if (empty($_SESSION['inventory_form_csrf'])) {
  $_SESSION['inventory_form_csrf'] = bin2hex(random_bytes(24));
}
if (empty($_SESSION['inventory_delete_csrf'])) {
  $_SESSION['inventory_delete_csrf'] = bin2hex(random_bytes(24));
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

function normalize_optional_url(string $raw, string $label, array &$errors): string {
  $raw = trim($raw);
  if ($raw === '') {
    return '';
  }
  if (mb_strlen($raw) > 1000) {
    $errors[] = $label . ' must be 1000 characters or fewer.';
    return '';
  }
  if (!filter_var($raw, FILTER_VALIDATE_URL)) {
    $errors[] = $label . ' must be a valid URL.';
    return '';
  }
  $parts = parse_url($raw);
  $scheme = strtolower((string)($parts['scheme'] ?? ''));
  if (!in_array($scheme, ['http', 'https'], true)) {
    $errors[] = $label . ' must start with http:// or https://.';
    return '';
  }
  return $raw;
}

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;
$is_view = $is_edit && (string)($_GET['view'] ?? '') === '1';
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

$part_number = '';
$fields = [
  'item_name' => '',
  'description' => '',
  'category' => 'Part',
  'supplier_1_name' => '',
  'supplier_1_url' => '',
  'supplier_2_name' => '',
  'supplier_2_url' => '',
  'supplier_3_name' => '',
  'supplier_3_url' => '',
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
      id, part_number, item_name, description, category,
      supplier_1_name, supplier_1_url, supplier_2_name, supplier_2_url, supplier_3_name, supplier_3_url,
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

  $part_number = (string)($existing['part_number'] ?? '');
  foreach ($fields as $key => $_) {
    $fields[$key] = (string)($existing[$key] ?? '');
  }
  $image_original_name = $existing['image_original_name'] ?? null;
  $image_stored_name = $existing['image_stored_name'] ?? null;
  $image_mime_type = $existing['image_mime_type'] ?? null;
}

if ($is_edit && (string)($_GET['delete_error'] ?? '') === 'image') {
  $errors[] = 'Unable to remove the stored image file, so the inventory item was not deleted.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_view) {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['inventory_form_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    foreach ($fields as $key => $_) {
      $fields[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if ($fields['item_name'] === '') {
      $errors[] = 'Name is required.';
    } elseif (mb_strlen($fields['item_name']) > 255) {
      $errors[] = 'Name must be 255 characters or fewer.';
    }

    if (!in_array($fields['category'], $categories, true)) {
      $errors[] = 'Category is invalid.';
    }
    $supplier_urls = [];
    for ($supplier_number = 1; $supplier_number <= 3; $supplier_number++) {
      $supplier_name_key = 'supplier_' . $supplier_number . '_name';
      $supplier_url_key = 'supplier_' . $supplier_number . '_url';
      if (mb_strlen($fields[$supplier_name_key]) > 255) {
        $errors[] = 'Supplier ' . $supplier_number . ' name must be 255 characters or fewer.';
      }
      $supplier_urls[$supplier_number] = normalize_optional_url($fields[$supplier_url_key], 'Supplier ' . $supplier_number . ' Link', $errors);
      if ($supplier_urls[$supplier_number] !== '' && $fields[$supplier_name_key] === '') {
        $errors[] = 'Supplier ' . $supplier_number . ' name is required when a link is provided.';
      }
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
            item_name = ?, description = ?, category = ?,
            supplier_1_name = ?, supplier_1_url = ?, supplier_2_name = ?, supplier_2_url = ?, supplier_3_name = ?, supplier_3_url = ?,
            cost_price = ?, retail_price = ?, wholesale_price = ?, minimum_price = ?,
            current_stock = ?, low_stock_alert = ?, location = ?,
            image_original_name = ?, image_stored_name = ?, image_mime_type = ?
          WHERE id = ?
        ");
        $upd->execute([
          $fields['item_name'],
          $fields['description'] !== '' ? $fields['description'] : null,
          $fields['category'],
          $fields['supplier_1_name'],
          $supplier_urls[1] !== '' ? $supplier_urls[1] : null,
          $fields['supplier_2_name'],
          $supplier_urls[2] !== '' ? $supplier_urls[2] : null,
          $fields['supplier_3_name'],
          $supplier_urls[3] !== '' ? $supplier_urls[3] : null,
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
        try {
          $actor_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
          if ($actor_id !== null && $actor_id <= 0) {
            $actor_id = null;
          }
          $actor_name = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : '';
          $detail = 'Inventory item #' . (int)$id . ' updated: ' . $fields['item_name'];
          if ($part_number !== '') {
            $detail .= ' [' . $part_number . ']';
          }
          log_admin_activity($pdo, $actor_id, 'Inventory Item Updated', $detail, $actor_name);
        } catch (Throwable $e) {
          // Non-blocking audit log write.
        }
        header('Location: inventory_list.php?success=updated');
        exit;
      } else {
        $pdo->beginTransaction();
        $new_id = 0;
        $created_part_number = '';
        try {
          $placeholder_part_number = 'TEMP-' . bin2hex(random_bytes(12));
          $ins = $pdo->prepare("
            INSERT INTO inventory_items (
              part_number, item_name, description, category,
              supplier_1_name, supplier_1_url, supplier_2_name, supplier_2_url, supplier_3_name, supplier_3_url,
              cost_price, retail_price, wholesale_price, minimum_price,
              current_stock, low_stock_alert, location,
              image_original_name, image_stored_name, image_mime_type
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
          ");
          $ins->execute([
            $placeholder_part_number,
            $fields['item_name'],
            $fields['description'] !== '' ? $fields['description'] : null,
            $fields['category'],
            $fields['supplier_1_name'],
            $supplier_urls[1] !== '' ? $supplier_urls[1] : null,
            $fields['supplier_2_name'],
            $supplier_urls[2] !== '' ? $supplier_urls[2] : null,
            $fields['supplier_3_name'],
            $supplier_urls[3] !== '' ? $supplier_urls[3] : null,
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

          $new_id = (int)$pdo->lastInsertId();
          $part_number = generate_inventory_part_number($pdo, $new_id);
          $part_upd = $pdo->prepare("UPDATE inventory_items SET part_number = ? WHERE id = ?");
          $part_upd->execute([$part_number, $new_id]);
          $created_part_number = $part_number;
          $pdo->commit();
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }
          throw $e;
        }
        try {
          $actor_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
          if ($actor_id !== null && $actor_id <= 0) {
            $actor_id = null;
          }
          $actor_name = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : '';
          $detail = 'Inventory item #' . (int)$new_id . ' created: ' . $fields['item_name'];
          if ($created_part_number !== '') {
            $detail .= ' [' . $created_part_number . ']';
          }
          log_admin_activity($pdo, $actor_id, 'Inventory Item Created', $detail, $actor_name);
        } catch (Throwable $e) {
          // Non-blocking audit log write.
        }
        header('Location: inventory_list.php?success=created');
        exit;
      }
    }
  }
}

$page_title = $is_view ? 'View Inventory Item' : ($is_edit ? 'Edit Inventory Item' : 'Add Inventory Item');
$image_url = $image_stored_name ? 'uploads/inventory/' . rawurlencode((string)$image_stored_name) : '';
render_header($page_title);
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1><?= h($page_title) ?></h1>
    <p class="muted">Manage inventory details, pricing tiers, stock levels, purchase links, and product image.</p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <?php if ($is_view): ?>
      <a class="btn" href="inventory_form.php?id=<?= (int)$id ?>">Edit Item</a>
    <?php endif; ?>
    <a class="btn" href="inventory_list.php">← Back to Inventory</a>
  </div>
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
  <?php if (!$is_view): ?>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['inventory_form_csrf']) ?>" />
    <?php if ($is_edit): ?>
      <input type="hidden" name="id" value="<?= (int)$id ?>" />
      <input type="hidden" name="delete_csrf_token" value="<?= h($_SESSION['inventory_delete_csrf']) ?>" />
    <?php endif; ?>
  <?php endif; ?>

    <div class="form-grid">
      <?php if ($is_edit): ?>
        <div>
          <label>Part Number</label>
          <div style="min-height:44px; padding:10px 12px; border:1px solid var(--b); border-radius:10px; background:#f8fafc; color:#0f172a; display:flex; align-items:center;"><?= h($part_number) ?></div>
        </div>
      <?php else: ?>
        <p class="full muted" role="status" aria-live="polite" style="margin:0;">Part Number will be generated automatically when this item is created.</p>
      <?php endif; ?>
      <div>
        <label>Name <span style="color:var(--d);">*</span></label>
        <input type="text" name="item_name" maxlength="255" <?= $is_view ? 'readonly' : 'required' ?> value="<?= h($fields['item_name']) ?>" />
      </div>
      <div class="full">
        <label>Description</label>
        <textarea name="description" rows="4" <?= $is_view ? 'readonly' : '' ?>><?= h($fields['description']) ?></textarea>
      </div>
      <div>
        <label>Category <span style="color:var(--d);">*</span></label>
        <select name="category" <?= $is_view ? 'disabled' : 'required' ?>>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= h($cat) ?>" <?= $fields['category'] === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="full">
        <label>Suppliers</label>
      </div>
      <div>
        <label>Supplier 1</label>
        <input type="text" name="supplier_1_name" maxlength="255" value="<?= h($fields['supplier_1_name']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Supplier 1 Link</label>
        <input type="url" name="supplier_1_url" maxlength="1000" placeholder="https://..." value="<?= h($fields['supplier_1_url']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Supplier 2</label>
        <input type="text" name="supplier_2_name" maxlength="255" value="<?= h($fields['supplier_2_name']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Supplier 2 Link</label>
        <input type="url" name="supplier_2_url" maxlength="1000" placeholder="https://..." value="<?= h($fields['supplier_2_url']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Supplier 3</label>
        <input type="text" name="supplier_3_name" maxlength="255" value="<?= h($fields['supplier_3_name']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Supplier 3 Link</label>
        <input type="url" name="supplier_3_url" maxlength="1000" placeholder="https://..." value="<?= h($fields['supplier_3_url']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Cost Price</label>
        <input type="text" name="cost_price" inputmode="decimal" placeholder="0.00" value="<?= h($fields['cost_price']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Retail Price</label>
        <input type="text" name="retail_price" inputmode="decimal" placeholder="0.00" value="<?= h($fields['retail_price']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Wholesale Price</label>
        <input type="text" name="wholesale_price" inputmode="decimal" placeholder="0.00" value="<?= h($fields['wholesale_price']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Minimum Price</label>
        <input type="text" name="minimum_price" inputmode="decimal" placeholder="0.00" value="<?= h($fields['minimum_price']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Current Stock</label>
        <input type="text" name="current_stock" inputmode="numeric" value="<?= h($fields['current_stock']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Low Stock Alert</label>
        <input type="text" name="low_stock_alert" inputmode="numeric" value="<?= h($fields['low_stock_alert']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Location</label>
        <input type="text" name="location" maxlength="255" value="<?= h($fields['location']) ?>" <?= $is_view ? 'readonly' : '' ?> />
      </div>
      <div>
        <label>Image Upload</label>
        <input type="file" name="image" accept=".jpg,.jpeg,.png,.gif,.webp,image/*" <?= $is_view ? 'disabled' : '' ?> />
        <div class="muted" style="margin-top:6px;">Accepted: JPG, PNG, GIF, WEBP (max 5 MB).</div>
      </div>
    </div>

    <?php if ($is_view): ?>
      <div style="margin-top:14px;">
        <div class="muted" style="margin-bottom:6px;">Supplier Links</div>
        <div style="display:grid; gap:6px;">
          <?php for ($supplier_number = 1; $supplier_number <= 3; $supplier_number++): ?>
            <?php
              $supplier_name = trim((string)($fields['supplier_' . $supplier_number . '_name'] ?? ''));
              $supplier_url = trim((string)($fields['supplier_' . $supplier_number . '_url'] ?? ''));
            ?>
            <div>
              <strong>Supplier <?= $supplier_number ?>:</strong>
              <?= $supplier_name !== '' ? h($supplier_name) : '<span class="muted">—</span>' ?>
              <?php if ($supplier_url !== ''): ?>
                &nbsp;<a class="btn" href="<?= h($supplier_url) ?>" target="_blank" rel="noopener noreferrer">Open Link</a>
              <?php else: ?>
                <span class="muted">(no link)</span>
              <?php endif; ?>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($image_url !== ''): ?>
      <div style="margin-top:14px;">
        <div class="muted" style="margin-bottom:6px;">Current Image</div>
        <img src="<?= h($image_url) ?>" alt="Current inventory image" style="width:120px; height:120px; object-fit:cover; border-radius:8px; border:1px solid var(--b);" />
      </div>
    <?php endif; ?>

    <?php if (!$is_view): ?>
      <div style="margin-top:16px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
        <button type="submit" class="btn primary"><?= $is_edit ? 'Save Changes' : 'Create Inventory Item' ?></button>
        <?php if ($is_edit): ?>
          <button type="submit"
                  class="btn"
                  formaction="inventory_delete.php"
                  formmethod="post"
                  formnovalidate
                  onclick="return confirm('Delete this inventory item permanently? This cannot be undone.');"
                  style="background:#b91c1c; border-color:#991b1b; color:#fff;">Delete Item</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </form>
</div>

<?php render_footer(); ?>
