<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

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
  "ALTER TABLE inventory_items ADD COLUMN purchased_from VARCHAR(50) NOT NULL DEFAULT '' AFTER supplier_3_url",
  "ALTER TABLE inventory_items ADD COLUMN purchase_link VARCHAR(1000) NULL AFTER purchased_from",
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

// Migrate old vendor values to new names.
$pdo->exec("
  UPDATE inventory_items
  SET purchased_from = CASE
    WHEN purchased_from = 'Alibaba / Wholesale' THEN 'Alibaba'
    WHEN purchased_from = 'Other' THEN 'Other Supplier'
    ELSE purchased_from
  END
  WHERE purchased_from IN ('Alibaba / Wholesale', 'Other')
");

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

function fmt_money($value): string {
  if ($value === null || $value === '') {
    return '—';
  }
  return '$' . number_format((float)$value, 2);
}

$q = trim((string)($_GET['q'] ?? ''));
$success_param = (string)($_GET['success'] ?? '');
$success_message = '';
if ($success_param === 'created') {
  $success_message = 'Inventory item created.';
} elseif ($success_param === 'updated') {
  $success_message = 'Inventory item updated.';
} elseif ($success_param === 'deleted') {
  $success_message = 'Inventory item deleted.';
}

if ($q !== '') {
  $like = '%' . $q . '%';
  $stmt = $pdo->prepare("
    SELECT
      id, part_number, item_name, description, category,
      purchased_from, purchase_link,
      cost_price, retail_price,
      current_stock, low_stock_alert, location,
      image_original_name, image_stored_name, image_mime_type
    FROM inventory_items
    WHERE part_number LIKE ?
       OR item_name LIKE ?
       OR category LIKE ?
       OR purchased_from LIKE ?
       OR location LIKE ?
    ORDER BY item_name ASC, id DESC
  ");
  $stmt->execute([$like, $like, $like, $like, $like]);
} else {
  $stmt = $pdo->query("
    SELECT
      id, part_number, item_name, description, category,
      purchased_from, purchase_link,
      cost_price, retail_price,
      current_stock, low_stock_alert, location,
      image_original_name, image_stored_name, image_mime_type
    FROM inventory_items
    ORDER BY item_name ASC, id DESC
  ");
}
$items = $stmt->fetchAll();

render_header('Inventory List');
?>

<style>
  .inventory-thumb-btn {
    border: 0;
    background: none;
    padding: 0;
    cursor: pointer;
  }
  .inventory-thumb {
    width: 52px;
    height: 52px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--b);
    display: block;
  }
  .inventory-price {
    font-weight: 600;
    white-space: nowrap;
  }
  .inventory-low-stock {
    display: inline-block;
    border-radius: 999px;
    padding: 3px 8px;
    font-size: 12px;
    font-weight: 600;
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
    white-space: nowrap;
  }
  .inventory-ok-stock {
    display: inline-block;
    border-radius: 999px;
    padding: 3px 8px;
    font-size: 12px;
    font-weight: 600;
    background: #ecfdf5;
    color: #166534;
    border: 1px solid #a7f3d0;
    white-space: nowrap;
  }
  .inventory-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.72);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 2200;
  }
  .inventory-modal-overlay.open { display: flex; }
  .inventory-modal {
    width: min(960px, 96vw);
    max-height: 90vh;
    background: #fff;
    border-radius: 12px;
    border: 1px solid var(--b);
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }
  .inventory-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 14px;
    border-bottom: 1px solid var(--b);
  }
  .inventory-modal-title {
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .inventory-modal-body {
    padding: 14px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 280px;
    overflow: auto;
  }
  .inventory-modal-body img {
    max-width: 100%;
    max-height: 70vh;
    border-radius: 8px;
    border: 1px solid var(--b);
    background: #fff;
  }
  .inventory-add-btn {
    padding: 12px 18px;
    font-size: 1rem;
    font-weight: 700;
  }
</style>

<div class="card page-header">
  <div class="page-header-body">
    <h1>Inventory <span class="muted" style="font-size:0.7em; font-weight:400;">(<?= count($items) ?>)</span></h1>
    <p class="muted">Track inventory details, pricing, stock alerts, location, and images.</p>
  </div>
  <a class="btn primary inventory-add-btn" href="inventory_form.php">Add New Item</a>
</div>

<?php if ($success_message !== ''): ?>
  <div class="card">
    <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
      <?= h($success_message) ?>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <form method="get" action="inventory_list.php" class="row" style="margin-bottom:4px;">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by part #, name, category, supplier, or location…" style="max-width:420px;" />
    <button type="submit" class="btn">Search</button>
    <?php if ($q !== ''): ?><a class="btn" href="inventory_list.php">Clear</a><?php endif; ?>
  </form>
</div>

<?php if (empty($items)): ?>
  <div class="card">
    <p class="muted"><?= $q !== '' ? 'No inventory items matched your search.' : 'No inventory items yet. Click + Add Item to get started.' ?></p>
  </div>
<?php else: ?>
  <div class="card" style="padding:0; overflow-x:auto;">
    <table>
      <thead>
      <tr>
        <th>Image</th>
        <th>Part # / Name</th>
        <th>Category</th>
        <th>Purchased From</th>
        <th>Our Cost</th>
        <th>Selling Price</th>
        <th>Stock</th>
        <th>Location</th>
        <th>Actions</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <?php
          $image_url = '';
          if (!empty($item['image_stored_name'])) {
            $image_url = 'uploads/inventory/' . rawurlencode((string)$item['image_stored_name']);
          }
          $current_stock = (int)($item['current_stock'] ?? 0);
          $low_stock_alert = (int)($item['low_stock_alert'] ?? 0);
          $is_low_stock = $current_stock <= $low_stock_alert;
        ?>
        <tr>
          <td>
            <?php if ($image_url !== ''): ?>
              <button type="button" class="inventory-thumb-btn js-inventory-image"
                      data-full="<?= h($image_url) ?>"
                      data-name="<?= h((string)($item['item_name'] ?? 'Inventory Image')) ?>">
                <img class="inventory-thumb" src="<?= h($image_url) ?>" alt="<?= h((string)($item['item_name'] ?? 'Item')) ?>" />
              </button>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <strong><?= h((string)$item['part_number']) ?></strong><br />
            <?= h((string)$item['item_name']) ?>
            <?php if (!empty($item['description'])): ?>
              <div class="muted" style="margin-top:4px; max-width:280px; white-space:normal;"><?= nl2br(h((string)$item['description'])) ?></div>
            <?php endif; ?>
          </td>
          <td><?= h((string)$item['category']) ?></td>
          <td>
            <?php
              $pf = trim((string)($item['purchased_from'] ?? ''));
              $pl = trim((string)($item['purchase_link'] ?? ''));
            ?>
            <?php if ($pf !== ''): ?>
              <?php if ($pl !== ''): ?>
                <a href="<?= h($pl) ?>" target="_blank" rel="noopener noreferrer"><?= h($pf) ?></a>
              <?php else: ?>
                <?= h($pf) ?>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td class="inventory-price"><?= h(fmt_money($item['cost_price'])) ?></td>
          <td class="inventory-price"><?= h(fmt_money($item['retail_price'])) ?></td>
          <td>
            <span class="<?= $is_low_stock ? 'inventory-low-stock' : 'inventory-ok-stock' ?>">
              <?= $current_stock ?> (alert: <?= $low_stock_alert ?>)
            </span>
          </td>
          <td><?= $item['location'] !== '' ? h((string)$item['location']) : '<span class="muted">—</span>' ?></td>
          <td class="actions">
            <a class="btn" href="inventory_form.php?id=<?= (int)$item['id'] ?>&view=1">View</a>
            <a class="btn" href="inventory_form.php?id=<?= (int)$item['id'] ?>">Edit</a>
            <a class="btn" href="inventory_print_qr.php?id=<?= (int)$item['id'] ?>" target="_blank">Print QR Label</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div id="inventoryImageModal" class="inventory-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="inventoryImageModalTitle">
  <div class="inventory-modal">
    <div class="inventory-modal-head">
      <div class="inventory-modal-title" id="inventoryImageModalTitle">Image</div>
      <button type="button" class="btn" id="inventoryImageModalClose">Close</button>
    </div>
    <div class="inventory-modal-body" id="inventoryImageModalBody"></div>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('inventoryImageModal');
  const body = document.getElementById('inventoryImageModalBody');
  const title = document.getElementById('inventoryImageModalTitle');
  const closeBtn = document.getElementById('inventoryImageModalClose');
  if (!modal || !body || !title || !closeBtn) return;

  const closeModal = () => {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    body.innerHTML = '';
  };

  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (event) => {
    if (event.target === modal) closeModal();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('open')) closeModal();
  });

  document.querySelectorAll('.js-inventory-image').forEach((btn) => {
    btn.addEventListener('click', () => {
      const full = btn.getAttribute('data-full') || '';
      const name = btn.getAttribute('data-name') || 'Inventory Image';
      if (!full) return;
      title.textContent = name;
      body.innerHTML = '';
      const img = document.createElement('img');
      img.src = full;
      img.alt = name;
      body.appendChild(img);
      modal.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
    });
  });
})();
</script>

<?php render_footer(); ?>
