<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();
require_once __DIR__ . '/project/customer_interaction_module.php';

customerInteractionEnsureSchema($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  header('Location: customers.php');
  exit;
}

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
  http_response_code(404);
  render_header('Customer Not Found');
  echo '<div class="card"><p class="muted">Customer not found.</p><a class="btn" href="customers.php">← Back to Customers</a></div>';
  render_footer();
  exit;
}

render_header('Customer Details');
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">Customer Details</h1>
    <div class="actions">
      <a class="btn" href="customers.php">Back to Customers</a>
      <button type="button" class="btn" onclick="openCustomerDetailsModal(<?= (int)$customer['id'] ?>)">Notes</button>
      <a class="btn" href="customer_form.php?id=<?= (int)$customer['id'] ?>">Edit</a>
    </div>
  </div>
</div>

<div class="card">
  <table>
    <tbody>
      <tr>
        <th style="width:220px;">Customer</th>
        <td>
          <?php
            $full_name = trim((string)($customer['first_name'] ?? '') . ' ' . (string)($customer['last_name'] ?? ''));
            echo $full_name !== '' ? '<strong>' . h($full_name) . '</strong>' : '<span class="muted">—</span>';
          ?>
          (ID <?= (int)$customer['id'] ?>)
        </td>
      </tr>
      <tr>
        <th>Company</th>
        <td><?= trim((string)($customer['company'] ?? '')) !== '' ? h((string)$customer['company']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Email</th>
        <td>
          <?php if (trim((string)($customer['email'] ?? '')) !== ''): ?>
            <a href="mailto:<?= h((string)$customer['email']) ?>"><?= h((string)$customer['email']) ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Phone</th>
        <td><?= trim((string)($customer['phone'] ?? '')) !== '' ? h((string)$customer['phone']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Street Address</th>
        <td><?= trim((string)($customer['address'] ?? '')) !== '' ? h((string)$customer['address']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>City</th>
        <td><?= trim((string)($customer['city'] ?? '')) !== '' ? h((string)$customer['city']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>State / Region</th>
        <td><?= trim((string)($customer['state'] ?? '')) !== '' ? h((string)$customer['state']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>ZIP / Postal Code</th>
        <td><?= trim((string)($customer['zip'] ?? '')) !== '' ? h((string)$customer['zip']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Country</th>
        <td><?= trim((string)($customer['country'] ?? '')) !== '' ? h((string)$customer['country']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>HubSpot Contact ID</th>
        <td><?= trim((string)($customer['hubspot_contact_id'] ?? '')) !== '' ? h((string)$customer['hubspot_contact_id']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Last Updated</th>
        <td><?= trim((string)($customer['last_updated'] ?? '')) !== '' ? h((string)$customer['last_updated']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Created</th>
        <td><?= trim((string)($customer['created_at'] ?? '')) !== '' ? h((string)$customer['created_at']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <tr>
        <th>Updated</th>
        <td><?= trim((string)($customer['updated_at'] ?? '')) !== '' ? h((string)$customer['updated_at']) : '<span class="muted">—</span>' ?></td>
      </tr>
    </tbody>
  </table>
</div>

<?php customerInteractionRenderModal(); ?>

<?php render_footer(); ?>
