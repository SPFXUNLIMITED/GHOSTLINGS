<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  header('Location: vendors.php');
  exit;
}

$stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->execute([$id]);
$vendor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vendor) {
  http_response_code(404);
  render_header('Vendor Not Found');
  echo '<div class="card"><p class="muted">Vendor not found.</p><a class="btn" href="vendors.php">← Back to Vendors</a></div>';
  render_footer();
  exit;
}

render_header('Vendor Details');
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Vendor Details</h1>
    <div class="actions">
      <a class="btn" href="vendors.php">Back to Vendors</a>
      <a class="btn" href="vendor_form.php?id=<?= (int)$vendor['id'] ?>">Edit</a>
    </div>
  </div>
</div>

<div class="card">
  <table>
    <tbody>
      <tr>
        <th style="width:220px;">Company</th>
        <td><strong><?= h($vendor['company_name']) ?></strong> (ID <?= (int)$vendor['id'] ?>)</td>
      </tr>
      <tr>
        <th>Contact</th>
        <td><?= $vendor['contact_name'] !== '' ? h($vendor['contact_name']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Email</th>
        <td>
          <?php if ($vendor['email'] !== ''): ?>
            <a href="mailto:<?= h($vendor['email']) ?>"><?= h($vendor['email']) ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Phone</th>
        <td><?= $vendor['phone'] !== '' ? h($vendor['phone']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Website</th>
        <td>
          <?php
            $website = trim((string)($vendor['website'] ?? ''));
            $scheme = strtolower((string)parse_url($website, PHP_URL_SCHEME));
            $is_safe_website = $website !== '' && in_array($scheme, ['http', 'https'], true);
          ?>
          <?php if ($is_safe_website): ?>
            <a href="<?= h($website) ?>" target="_blank" rel="noopener noreferrer"><?= h($website) ?></a>
          <?php elseif ($website !== ''): ?>
            <?= h($website) ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Address</th>
        <td><?= $vendor['address'] !== '' ? h($vendor['address']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Notes</th>
        <td><?= !empty($vendor['notes']) ? nl2br(h($vendor['notes'])) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Created</th>
        <td><?= !empty($vendor['created_at']) ? h($vendor['created_at']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Last Updated</th>
        <td><?= !empty($vendor['updated_at']) ? h($vendor['updated_at']) : '<span class="muted">—</span>' ?></td>
      </tr>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
