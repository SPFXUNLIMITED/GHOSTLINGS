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
      OR certifications LIKE ?
    ORDER BY company_name ASC
  ");
  $stmt->execute([$like, $like, $like, $like, $like, $like, $like]);
} else {
  $stmt = $pdo->query("SELECT * FROM freight_forwarders ORDER BY company_name ASC");
}
$forwarders = $stmt->fetchAll();

render_header('Freight Forwarders');
?>

<div class="card freight-hero page-header">
  <div class="freight-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body freight-hero-body">
    <span class="freight-hero-tag">Global Logistics Network</span>
    <h1>Freight Forwarders <span class="freight-hero-count">(<?= count($forwarders) ?>)</span></h1>
    <p class="muted">Build a premium logistics bench with trusted carriers, certified operators, and route experts ready for your next shipment.</p>
    <ul class="freight-hero-stats" aria-label="Freight forwarder summary">
      <li class="freight-hero-pill"><span class="freight-hero-emoji" aria-hidden="true">🌍</span> Multi-region coverage</li>
      <li class="freight-hero-pill"><span class="freight-hero-emoji" aria-hidden="true">✅</span> Compliance-ready partners</li>
      <li class="freight-hero-pill"><span class="freight-hero-emoji" aria-hidden="true">⚡</span> Faster quote turnarounds</li>
    </ul>
  </div>
  <div class="freight-hero-actions">
    <a class="btn primary" href="freight_forwarder_form.php">+ Add Freight Forwarder</a>
    <button type="button" class="btn" id="focus-forwarder-search">Find a Partner</button>
  </div>
</div>

<div id="forwarder-search" class="card">
  <form method="get" action="freight_forwarders.php" class="row" style="margin-bottom:4px;">
    <input id="forwarder-search-input" type="text" name="q" value="<?= h($q) ?>" aria-label="Search freight forwarders" placeholder="Search by company, route, certification, contact, email, or phone…" style="max-width:360px;" />
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
        <th>Certifications / Strengths</th>
        <th>Primary Routes</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($forwarders as $f): ?>
      <tr>
        <td><strong><?= h($f['company_name']) ?></strong></td>
        <td><?= $f['headquarters'] !== '' ? h($f['headquarters']) : '<span class="muted">—</span>' ?></td>
        <td><?= $f['certifications'] !== '' ? h($f['certifications']) : '<span class="muted">—</span>' ?></td>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
  var jumpButton = document.getElementById('focus-forwarder-search');
  var searchInput = document.getElementById('forwarder-search-input');
  if (!jumpButton || !searchInput) return;

  jumpButton.addEventListener('click', function () {
    searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
    requestAnimationFrame(function () {
      searchInput.focus();
    });
  });
});
</script>

<?php render_footer(); ?>
