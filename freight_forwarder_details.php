<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  header('Location: freight_forwarders.php');
  exit;
}

$stmt = $pdo->prepare("SELECT * FROM freight_forwarders WHERE id = ?");
$stmt->execute([$id]);
$forwarder = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$forwarder) {
  http_response_code(404);
  render_header('Freight Forwarder Not Found');
  echo '<div class="card"><p class="muted">Freight forwarder not found.</p><a class="btn" href="freight_forwarders.php">← Back to Freight Forwarders</a></div>';
  render_footer();
  exit;
}

render_header('Freight Forwarder Details');
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Freight Forwarder Details</h1>
    <div class="actions">
      <a class="btn" href="freight_forwarders.php">Back to Freight Forwarders</a>
      <a class="btn" href="freight_forwarder_form.php?id=<?= (int)$forwarder['id'] ?>">Edit</a>
    </div>
  </div>
</div>

<div class="card">
  <table>
    <tbody>
      <tr>
        <th style="width:220px;">Company</th>
        <td><strong><?= h($forwarder['company_name']) ?></strong> (ID <?= (int)$forwarder['id'] ?>)</td>
      </tr>
      <tr>
        <th>Headquarters</th>
        <td><?= $forwarder['headquarters'] !== '' ? h($forwarder['headquarters']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Contact Person</th>
        <td><?= $forwarder['contact_person'] !== '' ? h($forwarder['contact_person']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Email</th>
        <td>
          <?php if ($forwarder['email'] !== ''): ?>
            <a href="mailto:<?= h($forwarder['email']) ?>"><?= h($forwarder['email']) ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Phone Number</th>
        <td><?= $forwarder['phone'] !== '' ? h($forwarder['phone']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Website</th>
        <td>
          <?php
            $website = trim((string)($forwarder['website'] ?? ''));
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
        <th>Primary Routes</th>
        <td><?= $forwarder['primary_routes'] !== '' ? h($forwarder['primary_routes']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Shipping Modes</th>
        <td><?= $forwarder['shipping_modes'] !== '' ? h($forwarder['shipping_modes']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Certifications / Strengths</th>
        <td><?= $forwarder['certifications'] !== '' ? h($forwarder['certifications']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Notes</th>
        <td><?= !empty($forwarder['notes']) ? nl2br(h($forwarder['notes'])) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Created</th>
        <td><?= !empty($forwarder['created_at']) ? h($forwarder['created_at']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Last Updated</th>
        <td><?= !empty($forwarder['updated_at']) ? h($forwarder['updated_at']) : '<span class="muted">—</span>' ?></td>
      </tr>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
