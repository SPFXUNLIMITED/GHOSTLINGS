<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

$pdo->exec("
  CREATE TABLE IF NOT EXISTS labor_items (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_name    VARCHAR(255) NOT NULL,
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

function fmt_labor_rate($value): string {
  if ($value === null || $value === '') return '—';
  return '$' . number_format((float)$value, 2) . '/hr';
}

function fmt_labor_hours($value): string {
  if ($value === null || $value === '') return '—';
  $n = (float)$value;
  $formatted = $n == (int)$n ? (string)(int)$n : number_format($n, 1);
  return $formatted . ($n == 1 ? ' hr' : ' hrs');
}

$q = trim((string)($_GET['q'] ?? ''));
$success_param = (string)($_GET['success'] ?? '');
$success_message = '';
if ($success_param === 'created') {
  $success_message = 'Labor item created.';
} elseif ($success_param === 'updated') {
  $success_message = 'Labor item updated.';
} elseif ($success_param === 'deleted') {
  $success_message = 'Labor item deleted.';
}

if ($q !== '') {
  $like = '%' . $q . '%';
  $stmt = $pdo->prepare("
    SELECT id, service_name, hourly_rate, typical_hours, category, description
    FROM labor_items
    WHERE service_name LIKE ? OR category LIKE ? OR description LIKE ?
    ORDER BY service_name ASC
  ");
  $stmt->execute([$like, $like, $like]);
} else {
  $stmt = $pdo->query("
    SELECT id, service_name, hourly_rate, typical_hours, category, description
    FROM labor_items
    ORDER BY service_name ASC
  ");
}
$items = $stmt->fetchAll();

render_header('Labor / Services');
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1>Labor / Services <span class="muted" style="font-size:0.7em; font-weight:400;">(<?= count($items) ?>)</span></h1>
    <p class="muted">Manage service items used on invoices and quotes.</p>
  </div>
  <a class="btn primary" href="labor_form.php" style="padding:12px 18px; font-size:1rem; font-weight:700;">Add New Service</a>
</div>

<?php if ($success_message !== ''): ?>
  <div class="card">
    <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
      <?= h($success_message) ?>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <form method="get" action="labor_list.php" class="row" style="margin-bottom:4px;">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by name, category, or description…" style="max-width:400px;" />
    <button type="submit" class="btn">Search</button>
    <?php if ($q !== ''): ?><a class="btn" href="labor_list.php">Clear</a><?php endif; ?>
  </form>
</div>

<?php if (empty($items)): ?>
  <div class="card">
    <p class="muted"><?= $q !== '' ? 'No labor items matched your search.' : 'No labor items yet. Click Add New Service to get started.' ?></p>
  </div>
<?php else: ?>
  <div class="card" style="padding:0; overflow-x:auto;">
    <table>
      <thead>
        <tr>
          <th>Service Name</th>
          <th>Category</th>
          <th>Hourly Rate</th>
          <th>Typical Hours</th>
          <th>Description</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td><strong><?= h((string)$item['service_name']) ?></strong></td>
            <td><?= h((string)$item['category']) ?></td>
            <td style="white-space:nowrap; font-weight:600;"><?= h(fmt_labor_rate($item['hourly_rate'])) ?></td>
            <td style="white-space:nowrap;"><?= h(fmt_labor_hours($item['typical_hours'])) ?></td>
            <td>
              <?php if (!empty($item['description'])): ?>
                <span class="muted" style="max-width:320px; display:block; white-space:normal;"><?= nl2br(h((string)$item['description'])) ?></span>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td class="actions">
              <a class="btn" href="labor_form.php?id=<?= (int)$item['id'] ?>&view=1">View</a>
              <a class="btn" href="labor_form.php?id=<?= (int)$item['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
