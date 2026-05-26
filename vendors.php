<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$q = trim((string)($_GET['q'] ?? ''));

if ($q !== '') {
  $like = '%' . $q . '%';
  $stmt = $pdo->prepare("
    SELECT * FROM vendors
    WHERE company_name LIKE ? OR contact_name LIKE ? OR email LIKE ? OR phone LIKE ?
    ORDER BY company_name ASC
  ");
  $stmt->execute([$like, $like, $like, $like]);
} else {
  $stmt = $pdo->query("SELECT * FROM vendors ORDER BY company_name ASC");
}
$vendors = $stmt->fetchAll();

render_header('Vendors');
?>

<div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
  <h1 style="margin:0;">Vendors</h1>
  <a class="btn primary" href="vendor_form.php">+ Add Vendor</a>
</div>

<div class="card">
  <form method="get" action="vendors.php" class="row" style="margin-bottom:4px;">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by company, contact, email, or phone…" style="max-width:360px;" />
    <button type="submit" class="btn">Search</button>
    <?php if ($q !== ''): ?>
      <a class="btn" href="vendors.php">Clear</a>
    <?php endif; ?>
  </form>
</div>

<?php if (empty($vendors)): ?>
  <div class="card">
    <p class="muted"><?= $q !== '' ? 'No vendors matched your search.' : 'No vendors yet. Click <strong>+ Add Vendor</strong> to get started.' ?></p>
  </div>
<?php else: ?>
<div class="card" style="padding:0; overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>Company</th>
        <th>Contact</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Website</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($vendors as $v): ?>
      <tr>
        <td><?= h($v['company_name']) ?></td>
        <td><?= h($v['contact_name']) ?></td>
        <td>
          <?php if ($v['email'] !== ''): ?>
            <a href="mailto:<?= h($v['email']) ?>"><?= h($v['email']) ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td><?= $v['phone'] !== '' ? h($v['phone']) : '<span class="muted">—</span>' ?></td>
        <td>
          <?php if ($v['website'] !== ''): ?>
            <a href="<?= h($v['website']) ?>" target="_blank" rel="noopener noreferrer"><?= h($v['website']) ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td class="actions">
          <a class="btn" href="vendor_form.php?id=<?= (int)$v['id'] ?>">Edit</a>
          <?php if (is_admin()): ?>
          <form method="post" action="vendor_delete.php" style="display:inline;"
                onsubmit="return confirm('Delete this vendor?');">
            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>" />
            <button type="submit" class="btn danger">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php render_footer(); ?>
