<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$q = trim((string)($_GET['q'] ?? ''));

if (empty($_SESSION['vendor_delete_csrf'])) {
  $_SESSION['vendor_delete_csrf'] = bin2hex(random_bytes(24));
}
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

<div class="card page-header">
  <div class="page-header-body">
    <h1>Vendors <span class="muted" style="font-size:0.7em; font-weight:400;">(<?= count($vendors) ?>)</span></h1>
    <p class="muted">Manage supplier contacts for RFQs and sourcing requests.</p>
  </div>
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
        <th>Phone</th>
        <th>Alibaba Store Link</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($vendors as $v): ?>
      <tr>
        <td><strong><?= h($v['company_name']) ?></strong></td>
        <td><?= $v['phone'] !== '' ? h($v['phone']) : '<span class="muted">—</span>' ?></td>
        <td>
          <?php
            $alibaba_url = trim((string)($v['website'] ?? ''));
            $display_url = mb_strlen($alibaba_url) > 40 ? mb_substr($alibaba_url, 0, 40) . '…' : $alibaba_url;
            $scheme = strtolower((string)parse_url($alibaba_url, PHP_URL_SCHEME));
            $is_safe_url = $alibaba_url !== '' && in_array($scheme, ['http', 'https'], true);
          ?>
          <?php if ($is_safe_url): ?>
            <a href="<?= h($alibaba_url) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($alibaba_url) ?>"><?= h($display_url) ?></a>
          <?php elseif ($alibaba_url !== ''): ?>
            <?= h($alibaba_url) ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td class="actions">
          <a class="btn" href="vendor_details.php?id=<?= (int)$v['id'] ?>">View</a>
          <a class="btn" href="vendor_form.php?id=<?= (int)$v['id'] ?>">Edit</a>
          <?php if (is_admin()): ?>
          <form method="post" action="vendor_delete.php" style="display:inline;"
                onsubmit="return confirm('Delete this vendor?');">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['vendor_delete_csrf']) ?>" />
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
