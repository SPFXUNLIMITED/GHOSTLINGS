<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

// ── AJAX: Quick Stock Adjustment ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stock_adjust') {
  header('Content-Type: application/json; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');

  $token = (string)($_POST['stock_csrf'] ?? '');
  if (!hash_equals((string)($_SESSION['inventory_stock_csrf'] ?? ''), $token) || $token === '') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token.']);
    exit;
  }

  $item_id    = (int)($_POST['item_id'] ?? 0);
  $adjustment = (int)($_POST['adjustment'] ?? 0);

  if ($item_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid item.']);
    exit;
  }
  if ($adjustment === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Adjustment cannot be zero.']);
    exit;
  }

  $stmt = $pdo->prepare("
    UPDATE inventory_items
    SET current_stock = GREATEST(0, current_stock + ?) -- stock is never allowed to go below zero
    WHERE id = ?
  ");
  $stmt->execute([$adjustment, $item_id]);

  if ($stmt->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Item not found.']);
    exit;
  }

  $updated_stmt = $pdo->prepare("SELECT current_stock, low_stock_alert FROM inventory_items WHERE id = ?");
  $updated_stmt->execute([$item_id]);
  $updated = $updated_stmt->fetch();

  echo json_encode([
    'ok'        => true,
    'new_stock' => (int)$updated['current_stock'],
    'low_alert' => (int)$updated['low_stock_alert'],
  ]);
  exit;
}
// ────────────────────────────────────────────────────────────────────────────

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
} elseif ($success_param === 'cloned') {
  $success_message = 'Inventory item cloned.';
}

if (empty($_SESSION['inventory_clone_csrf'])) {
  $_SESSION['inventory_clone_csrf'] = bin2hex(random_bytes(24));
}
if (empty($_SESSION['inventory_stock_csrf'])) {
  $_SESSION['inventory_stock_csrf'] = bin2hex(random_bytes(24));
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
  /* ── Quick Stock Adjustment ── */
  .inventory-stock-btn {
    border: 0;
    background: none;
    padding: 0;
    cursor: pointer;
    display: inline-block;
  }
  .inventory-stock-btn:hover .inventory-low-stock,
  .inventory-stock-btn:hover .inventory-ok-stock {
    filter: brightness(0.93);
    outline: 2px solid currentColor;
    outline-offset: 1px;
  }
  .stock-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.72);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 2300;
  }
  .stock-modal-overlay.open { display: flex; }
  .stock-modal {
    width: min(420px, 96vw);
    background: #fff;
    border-radius: 12px;
    border: 1px solid var(--b);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 48px rgba(0,0,0,.25);
  }
  .stock-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--b);
    background: #f8fafc;
  }
  .stock-modal-title {
    font-weight: 700;
    font-size: 1rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .stock-modal-body {
    padding: 20px 16px;
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  .stock-current-display {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: #f1f5f9;
    border-radius: 8px;
    border: 1px solid var(--b);
  }
  .stock-current-label {
    font-size: 0.85rem;
    color: #64748b;
    font-weight: 500;
  }
  .stock-current-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #0f172a;
    margin-left: auto;
  }
  .stock-modal-field label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    margin-bottom: 5px;
    color: #334155;
  }
  .stock-modal-field input[type="number"],
  .stock-modal-field input[type="text"] {
    width: 100%;
    box-sizing: border-box;
  }
  .stock-modal-hint {
    font-size: 0.78rem;
    color: #64748b;
    margin-top: 4px;
  }
  .stock-modal-footer {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    padding: 12px 16px;
    border-top: 1px solid var(--b);
    background: #f8fafc;
  }
  .stock-modal-error {
    color: #991b1b;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 6px;
    padding: 8px 12px;
    font-size: 0.85rem;
    display: none;
  }
  .stock-modal-error.visible { display: block; }
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
            <button type="button"
                    class="inventory-stock-btn js-stock-adjust"
                    title="Click to adjust stock"
                    data-id="<?= (int)$item['id'] ?>"
                    data-name="<?= h((string)$item['item_name']) ?>"
                    data-stock="<?= $current_stock ?>"
                    data-alert="<?= $low_stock_alert ?>">
              <span class="<?= $is_low_stock ? 'inventory-low-stock' : 'inventory-ok-stock' ?>">
                <?= $current_stock ?> (alert: <?= $low_stock_alert ?>)
              </span>
            </button>
          </td>
          <td><?= $item['location'] !== '' ? h((string)$item['location']) : '<span class="muted">—</span>' ?></td>
          <td class="actions">
            <a class="btn" href="inventory_form.php?id=<?= (int)$item['id'] ?>&view=1">View</a>
            <a class="btn" href="inventory_form.php?id=<?= (int)$item['id'] ?>">Edit</a>
            <form method="post" action="inventory_clone.php" style="display:inline;">
              <input type="hidden" name="id" value="<?= (int)$item['id'] ?>" />
              <input type="hidden" name="clone_csrf_token" value="<?= h((string)$_SESSION['inventory_clone_csrf']) ?>" />
              <button type="submit" class="btn">Clone</button>
            </form>
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

<div id="stockAdjustModal" class="stock-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="stockAdjustModalTitle">
  <div class="stock-modal">
    <div class="stock-modal-head">
      <div class="stock-modal-title" id="stockAdjustModalTitle">Adjust Stock</div>
      <button type="button" class="btn" id="stockAdjustModalClose">✕</button>
    </div>
    <div class="stock-modal-body">
      <div class="stock-current-display">
        <span class="stock-current-label">Current Stock</span>
        <span class="stock-current-value" id="stockCurrentValue">—</span>
      </div>
      <div class="stock-modal-field">
        <label for="stockAdjustAmount">Adjustment <span class="muted" style="font-weight:400;">(use − for removals)</span></label>
        <input type="number" id="stockAdjustAmount" name="adjustment" placeholder="e.g. +5 or -2" step="1" />
        <p class="stock-modal-hint">Enter a positive number to add stock, or a negative number to remove stock.</p>
      </div>
      <div class="stock-modal-field">
        <label for="stockAdjustNote">Note <span class="muted" style="font-weight:400;">(optional)</span></label>
        <input type="text" id="stockAdjustNote" name="note" placeholder="e.g. Received shipment, Sold one unit…" maxlength="255" />
      </div>
      <div class="stock-modal-error" id="stockAdjustError"></div>
    </div>
    <div class="stock-modal-footer">
      <button type="button" class="btn" id="stockAdjustCancel">Cancel</button>
      <button type="button" class="btn primary" id="stockAdjustSave">Save</button>
    </div>
  </div>
</div>

<script>
(() => {
  const overlay   = document.getElementById('stockAdjustModal');
  const titleEl   = document.getElementById('stockAdjustModalTitle');
  const currentEl = document.getElementById('stockCurrentValue');
  const amountEl  = document.getElementById('stockAdjustAmount');
  const noteEl    = document.getElementById('stockAdjustNote');
  const errorEl   = document.getElementById('stockAdjustError');
  const saveBtn   = document.getElementById('stockAdjustSave');
  const cancelBtn = document.getElementById('stockAdjustCancel');
  const closeBtn  = document.getElementById('stockAdjustModalClose');

  if (!overlay) return;

  const csrfToken = <?= json_encode((string)$_SESSION['inventory_stock_csrf']) ?>;

  let activeItemId   = 0;
  let activeBtn      = null;
  let activeLowAlert = 0;

  const openModal = (btn) => {
    activeItemId   = parseInt(btn.dataset.id, 10) || 0;
    activeLowAlert = parseInt(btn.dataset.alert, 10) || 0;
    activeBtn      = btn;

    const name  = btn.dataset.name || 'Item';
    const stock = parseInt(btn.dataset.stock, 10);

    titleEl.textContent   = 'Adjust Stock — ' + name;
    currentEl.textContent = stock;
    amountEl.value        = '';
    noteEl.value          = '';
    errorEl.textContent   = '';
    errorEl.classList.remove('visible');

    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    setTimeout(() => amountEl.focus(), 60);
  };

  const closeModal = () => {
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    if (activeBtn) activeBtn.focus();
    activeBtn = null;
  };

  const showError = (msg) => {
    errorEl.textContent = msg;
    errorEl.classList.add('visible');
  };

  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && overlay.classList.contains('open')) closeModal();
  });

  saveBtn.addEventListener('click', async () => {
    const adjustment = parseInt(amountEl.value, 10);
    if (!amountEl.value.trim() || isNaN(adjustment)) {
      showError('Please enter a valid number (e.g. 5 or -3).');
      amountEl.focus();
      return;
    }
    if (adjustment === 0) {
      showError('Adjustment cannot be zero.');
      amountEl.focus();
      return;
    }

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';
    errorEl.classList.remove('visible');

    try {
      const body = new URLSearchParams({
        action:      'stock_adjust',
        stock_csrf:  csrfToken,
        item_id:     activeItemId,
        adjustment:  adjustment,
        note:        noteEl.value.trim(),
      });

      const res  = await fetch('inventory_list.php', { method: 'POST', body });
      const data = await res.json();

      if (!data.ok) {
        showError(data.error || 'An error occurred. Please try again.');
        return;
      }

      // Update the badge in the table row
      const newStock    = data.new_stock;
      const lowAlert    = data.low_alert;
      const isLow       = newStock <= lowAlert;
      const badge       = activeBtn.querySelector('span');
      const badgeClass  = isLow ? 'inventory-low-stock' : 'inventory-ok-stock';
      badge.className   = badgeClass;
      badge.textContent = newStock + ' (alert: ' + lowAlert + ')';
      activeBtn.dataset.stock = newStock;

      closeModal();
    } catch (err) {
      showError('Network error. Please check your connection and try again.');
    } finally {
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save';
    }
  });

  document.querySelectorAll('.js-stock-adjust').forEach((btn) => {
    btn.addEventListener('click', () => openModal(btn));
  });
})();
</script>

<?php render_footer(); ?>
