<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$q = trim((string)($_GET['q'] ?? ''));

if (empty($_SESSION['freight_forwarder_delete_csrf'])) {
  $_SESSION['freight_forwarder_delete_csrf'] = bin2hex(random_bytes(24));
}

if ($q !== '') {
  $like = '%' . $q . '%';
  $stmt = $pdo->prepare("
    SELECT * FROM freight_forwarders
    WHERE company_name LIKE ?
       OR headquarters LIKE ?
       OR contact_person LIKE ?
       OR email LIKE ?
       OR phone LIKE ?
       OR primary_routes LIKE ?
    ORDER BY company_name ASC
  ");
  $stmt->execute([$like, $like, $like, $like, $like, $like]);
} else {
  $stmt = $pdo->query("SELECT * FROM freight_forwarders ORDER BY company_name ASC");
}
$forwarders = $stmt->fetchAll();

render_header('Freight Forwarders');
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1>Freight Forwarders <span class="muted" style="font-size:0.7em; font-weight:400;">(<?= count($forwarders) ?>)</span></h1>
    <p class="muted">Manage freight forwarding partners for import/export routes and logistics.</p>
  </div>
  <a class="btn primary" href="freight_forwarder_form.php">+ Add Freight Forwarder</a>
</div>

<div class="card">
  <form method="get" action="freight_forwarders.php" class="row" style="margin-bottom:4px;">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by company, route, contact, email, or phone…" style="max-width:360px;" />
    <button type="submit" class="btn">Search</button>
    <?php if ($q !== ''): ?>
      <a class="btn" href="freight_forwarders.php">Clear</a>
    <?php endif; ?>
  </form>
</div>

<?php if (empty($forwarders)): ?>
  <div class="card">
    <p class="muted"><?= $q !== '' ? 'No freight forwarders matched your search.' : 'No freight forwarders yet. Click <strong>+ Add Freight Forwarder</strong> to get started.' ?></p>
  </div>
<?php else: ?>
<div class="card" style="padding:0; overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>Company</th>
        <th>Headquarters</th>
        <th>Contact</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Primary Routes</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($forwarders as $f): ?>
      <tr>
        <td><strong><?= h($f['company_name']) ?></strong></td>
        <td><?= $f['headquarters'] !== '' ? h($f['headquarters']) : '<span class="muted">—</span>' ?></td>
        <td><?= $f['contact_person'] !== '' ? h($f['contact_person']) : '<span class="muted">—</span>' ?></td>
        <td>
          <?php if ($f['email'] !== ''): ?>
            <a href="mailto:<?= h($f['email']) ?>"><?= h($f['email']) ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td><?= $f['phone'] !== '' ? h($f['phone']) : '<span class="muted">—</span>' ?></td>
        <td><?= $f['primary_routes'] !== '' ? h($f['primary_routes']) : '<span class="muted">—</span>' ?></td>
        <td class="actions">
          <a class="btn" href="freight_forwarder_details.php?id=<?= (int)$f['id'] ?>">View</a>
          <a class="btn" href="freight_forwarder_form.php?id=<?= (int)$f['id'] ?>">Edit</a>
          <?php if (is_admin()): ?>
          <form method="post" action="freight_forwarder_delete.php" style="display:inline;"
                onsubmit="return confirm('Delete this freight forwarder?');">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['freight_forwarder_delete_csrf']) ?>" />
            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>" />
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
