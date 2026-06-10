<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$pdo->exec("
  CREATE TABLE IF NOT EXISTS labor_items (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_name    VARCHAR(255) NOT NULL,
    pricing_type    ENUM('Hourly','Flat Rate') NOT NULL DEFAULT 'Hourly',
    hourly_rate     DECIMAL(12,2) NULL,
    typical_hours   DECIMAL(8,2) NULL,
    category        ENUM('Repair','Maintenance','Training','Travel','Other') NOT NULL DEFAULT 'Repair',
    description     TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_labor_service_name (service_name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
try {
  $pdo->exec("ALTER TABLE labor_items ADD COLUMN pricing_type ENUM('Hourly','Flat Rate') NOT NULL DEFAULT 'Hourly' AFTER service_name");
} catch (PDOException $e) {
  // Column already exists
}

if (empty($_SESSION['labor_form_csrf'])) {
  $_SESSION['labor_form_csrf'] = bin2hex(random_bytes(24));
}
if (empty($_SESSION['labor_delete_csrf'])) {
  $_SESSION['labor_delete_csrf'] = bin2hex(random_bytes(24));
}

$categories = ['Repair', 'Maintenance', 'Training', 'Travel', 'Other'];

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;
$is_view = $is_edit && (string)($_GET['view'] ?? '') === '1';

$fields = [
  'service_name'  => '',
  'pricing_type'  => 'Hourly',
  'hourly_rate'   => '',
  'typical_hours' => '',
  'category'      => 'Repair',
  'description'   => '',
];

$errors = [];

if ($is_edit) {
  $stmt = $pdo->prepare("SELECT id, service_name, pricing_type, hourly_rate, typical_hours, category, description FROM labor_items WHERE id = ? LIMIT 1");
  $stmt->execute([$id]);
  $existing = $stmt->fetch();
  if (!$existing) {
    http_response_code(404);
    render_header('Labor Item Not Found');
    echo '<div class="card"><p class="muted">Labor item not found.</p><a class="btn" href="labor_list.php">← Back to Labor</a></div>';
    render_footer();
    exit;
  }
  foreach ($fields as $key => $_) {
    $fields[$key] = (string)($existing[$key] ?? '');
  }
}

// Handle delete
if ($is_edit && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_delete'])) {
  $csrf = (string)($_POST['delete_csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['labor_delete_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $_SESSION['labor_delete_csrf'] = bin2hex(random_bytes(24));
    $pdo->prepare("DELETE FROM labor_items WHERE id = ?")->execute([$id]);
    header('Location: labor_list.php?success=deleted');
    exit;
  }
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['_delete']) && !$is_view) {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['labor_form_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $_SESSION['labor_form_csrf'] = bin2hex(random_bytes(24));

    foreach ($fields as $key => $_) {
      $fields[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if ($fields['service_name'] === '') {
      $errors[] = 'Service Name is required.';
    } elseif (mb_strlen($fields['service_name']) > 255) {
      $errors[] = 'Service Name must be 255 characters or fewer.';
    }

    if (!in_array($fields['pricing_type'], ['Hourly', 'Flat Rate'], true)) {
      $fields['pricing_type'] = 'Hourly';
    }

    if ($fields['hourly_rate'] !== '') {
      if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $fields['hourly_rate'])) {
        $errors[] = 'Rate must be a non-negative number with up to 2 decimals.';
      }
    }

    if ($fields['pricing_type'] === 'Hourly' && $fields['typical_hours'] !== '') {
      if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $fields['typical_hours'])) {
        $errors[] = 'Typical Hours must be a non-negative number with up to 2 decimals.';
      }
    }

    if (!in_array($fields['category'], $categories, true)) {
      $fields['category'] = 'Repair';
    }

    if (empty($errors)) {
      $hourly_rate   = $fields['hourly_rate']   !== '' ? $fields['hourly_rate']   : null;
      $typical_hours = ($fields['pricing_type'] === 'Hourly' && $fields['typical_hours'] !== '') ? $fields['typical_hours'] : null;
      $description   = $fields['description']   !== '' ? $fields['description']   : null;

      if ($is_edit) {
        $stmt = $pdo->prepare("
          UPDATE labor_items
          SET service_name = ?, pricing_type = ?, hourly_rate = ?, typical_hours = ?, category = ?, description = ?
          WHERE id = ?
        ");
        $stmt->execute([$fields['service_name'], $fields['pricing_type'], $hourly_rate, $typical_hours, $fields['category'], $description, $id]);
        header('Location: labor_list.php?success=updated');
        exit;
      } else {
        $stmt = $pdo->prepare("
          INSERT INTO labor_items (service_name, pricing_type, hourly_rate, typical_hours, category, description)
          VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$fields['service_name'], $fields['pricing_type'], $hourly_rate, $typical_hours, $fields['category'], $description]);
        header('Location: labor_list.php?success=created');
        exit;
      }
    }
  }
}

$page_title = $is_view ? 'View Service' : ($is_edit ? 'Edit Service' : 'New Service');
render_header($page_title);
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1><?= h($page_title) ?></h1>
    <p class="muted"><?= $is_view ? 'Read-only view of this labor / service item.' : ($is_edit ? 'Update this labor / service item.' : 'Add a new labor / service item.') ?></p>
  </div>
  <div class="actions">
    <?php if ($is_view && $is_edit): ?>
      <a class="btn primary" href="labor_form.php?id=<?= $id ?>">Edit</a>
    <?php endif; ?>
    <a class="btn" href="labor_list.php">← Back to Labor</a>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="card">
    <div class="alert" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">
      <?php foreach ($errors as $err): ?>
        <div><?= h($err) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if (!$is_view): ?>
<form method="post" action="">
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['labor_form_csrf']) ?>" />
<?php endif; ?>

<div class="card">
  <div style="display:grid; gap:14px; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));">
    <div style="grid-column:1 / -1;">
      <label for="service_name">Service Name <?php if (!$is_view): ?><span style="color:var(--d);">*</span><?php endif; ?></label>
      <?php if ($is_view): ?>
        <div style="padding:10px 0; font-size:1.05em; font-weight:600;"><?= h($fields['service_name']) ?: '<span class="muted">—</span>' ?></div>
      <?php else: ?>
        <input id="service_name" type="text" name="service_name" maxlength="255" value="<?= h($fields['service_name']) ?>" required autofocus />
      <?php endif; ?>
    </div>

    <div>
      <label for="category">Category</label>
      <?php if ($is_view): ?>
        <div style="padding:10px 0;"><?= h($fields['category']) ?></div>
      <?php else: ?>
        <select id="category" name="category">
          <?php foreach ($categories as $cat): ?>
            <option value="<?= h($cat) ?>"<?= $fields['category'] === $cat ? ' selected' : '' ?>><?= h($cat) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>

    <div>
      <label for="pricing_type">Pricing Type</label>
      <?php if ($is_view): ?>
        <div style="padding:10px 0;"><?= h($fields['pricing_type'] ?: 'Hourly') ?></div>
      <?php else: ?>
        <select id="pricing_type" name="pricing_type" onchange="toggleTypicalHours()">
          <option value="Hourly"<?= $fields['pricing_type'] !== 'Flat Rate' ? ' selected' : '' ?>>Hourly</option>
          <option value="Flat Rate"<?= $fields['pricing_type'] === 'Flat Rate' ? ' selected' : '' ?>>Flat Rate</option>
        </select>
      <?php endif; ?>
    </div>

    <div>
      <label for="hourly_rate">Rate ($)</label>
      <?php if ($is_view): ?>
        <div style="padding:10px 0;"><?= $fields['hourly_rate'] !== '' ? h('$' . number_format((float)$fields['hourly_rate'], 2)) : '<span class="muted">—</span>' ?></div>
      <?php else: ?>
        <input id="hourly_rate" type="number" name="hourly_rate" step="0.01" min="0" value="<?= h($fields['hourly_rate']) ?>" placeholder="0.00" />
      <?php endif; ?>
    </div>

    <div id="typical_hours_row"<?= (!$is_view && $fields['pricing_type'] === 'Flat Rate') ? ' style="display:none;"' : '' ?>>
      <label for="typical_hours">Typical Hours</label>
      <?php if ($is_view): ?>
        <?php if ($fields['pricing_type'] === 'Flat Rate'): ?>
          <div style="padding:10px 0;"><span class="muted">—</span></div>
        <?php else: ?>
          <div style="padding:10px 0;"><?= $fields['typical_hours'] !== '' ? h(number_format((float)$fields['typical_hours'], 2)) : '<span class="muted">—</span>' ?></div>
        <?php endif; ?>
      <?php else: ?>
        <input id="typical_hours" type="number" name="typical_hours" step="0.01" min="0" value="<?= h($fields['typical_hours']) ?>" placeholder="e.g. 2.00" />
      <?php endif; ?>
    </div>

    <div style="grid-column:1 / -1;">
      <label for="description">Description <span class="muted">(optional)</span></label>
      <?php if ($is_view): ?>
        <div style="padding:10px 0; white-space:pre-wrap;"><?= $fields['description'] !== '' ? h($fields['description']) : '<span class="muted">—</span>' ?></div>
      <?php else: ?>
        <textarea id="description" name="description" rows="4"><?= h($fields['description']) ?></textarea>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$is_view): ?>
  <div style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap;">
    <button type="submit" class="btn primary" style="font-size:1rem; padding:12px 20px;"><?= $is_edit ? 'Update Service' : 'Save Service' ?></button>
    <a class="btn" href="labor_list.php">Cancel</a>
  </div>
  <?php endif; ?>
</div>

<?php if (!$is_view): ?>
</form>
<script>
function toggleTypicalHours() {
  var type = document.getElementById('pricing_type').value;
  var row = document.getElementById('typical_hours_row');
  if (row) {
    row.style.display = type === 'Flat Rate' ? 'none' : '';
  }
}
</script>
<?php endif; ?>

<?php if ($is_edit && !$is_view): ?>
<div class="card" style="border-color:#fecaca;">
  <h3 style="margin:0 0 10px; color:#991b1b;">Delete Service</h3>
  <p class="muted" style="margin:0 0 14px;">This will permanently remove this service item. This action cannot be undone.</p>
  <form method="post" action="" onsubmit="return confirm('Delete this service item? This cannot be undone.');">
    <input type="hidden" name="_delete" value="1" />
    <input type="hidden" name="delete_csrf_token" value="<?= h($_SESSION['labor_delete_csrf']) ?>" />
    <button type="submit" class="btn" style="background:#fef2f2; color:#991b1b; border-color:#fecaca;">Delete Service</button>
  </form>
</div>
<?php endif; ?>

<?php render_footer(); ?>
