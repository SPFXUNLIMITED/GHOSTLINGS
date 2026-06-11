<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$current_user_id = (int)$_SESSION['user_id'];
$is_admin = !empty($_SESSION['is_admin']);

// Fetch unread messages for the current user with sender info
$msg_stmt = $pdo->prepare("
  SELECT m.id, m.body, m.created_at,
         u.username AS sender_username
  FROM messages m
  JOIN users u ON u.id = m.sender_id
  WHERE m.recipient_id = ? AND m.is_read = 0
  ORDER BY m.created_at DESC
");
$msg_stmt->execute([$current_user_id]);
$unread_messages = $msg_stmt->fetchAll();

// Fetch new bug reports for admins only
$new_bugs = [];
if ($is_admin) {
  $bug_stmt = $pdo->prepare("
    SELECT r.id, r.request_title, r.priority, r.created_at,
           u.username AS requested_by_username
    FROM app_requests r
    JOIN users u ON u.id = r.requested_by
    WHERE r.request_type = 'bug' AND r.status = 'new'
    ORDER BY r.created_at DESC
  ");
  $bug_stmt->execute();
  $new_bugs = $bug_stmt->fetchAll();
}

$approval_alerts = [];
$approval_stmt = $pdo->prepare("
  SELECT id, entity_type, entity_id, message, link_url, created_at
  FROM approval_alerts
  WHERE recipient_id = ? AND is_read = 0
  ORDER BY created_at DESC, id DESC
");
$approval_stmt->execute([$current_user_id]);
$approval_alerts = $approval_stmt->fetchAll();
if ($approval_alerts) {
  $approval_ids = array_map(static fn(array $row): int => (int)$row['id'], $approval_alerts);
  $approval_ids = array_values(array_filter($approval_ids, static fn(int $id): bool => $id > 0));
  if ($approval_ids) {
    $placeholders = implode(',', array_fill(0, count($approval_ids), '?'));
    $mark_read_stmt = $pdo->prepare("UPDATE approval_alerts SET is_read = 1 WHERE recipient_id = ? AND id IN ($placeholders)");
    $mark_read_stmt->execute(array_merge([$current_user_id], $approval_ids));
  }
}

render_header('Notifications');
?>

<div class="card">
  <h1 style="margin:0;">Notifications</h1>
</div>

<?php if (empty($unread_messages) && empty($new_bugs) && empty($approval_alerts)): ?>
<div class="card">
  <p class="muted">You have no new notifications.</p>
</div>
<?php endif; ?>

<?php if (!empty($approval_alerts)): ?>
<div class="card">
  <h2 style="margin:0 0 12px;">Approval Requests</h2>
  <table class="table">
    <thead>
      <tr>
        <th>Type</th>
        <th>Alert</th>
        <th>Date</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($approval_alerts as $alert): ?>
      <?php
        $entity_type = strtolower(trim((string)($alert['entity_type'] ?? '')));
        $type_label = $entity_type === 'invoice' ? 'Invoice' : 'Quote';
      ?>
      <tr>
        <td><?= h($type_label) ?></td>
        <td><?= h((string)$alert['message']) ?></td>
        <td><?= h((string)$alert['created_at']) ?></td>
        <td><a class="btn btn-sm" href="<?= h((string)$alert['link_url']) ?>">Open</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if (!empty($unread_messages)): ?>
<div class="card">
  <h2 style="margin:0 0 12px;">Unread Messages</h2>
  <table class="table">
    <thead>
      <tr>
        <th>From</th>
        <th>Preview</th>
        <th>Received</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($unread_messages as $msg): ?>
      <tr>
        <td><?= h($msg['sender_username']) ?></td>
        <td><?= h(mb_strimwidth(strip_tags($msg['body']), 0, 80, '…')) ?></td>
        <td><?= h($msg['created_at']) ?></td>
        <td><a class="btn btn-sm" href="messages.php">View</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($is_admin && !empty($new_bugs)): ?>
<div class="card">
  <h2 style="margin:0 0 12px;">New Bug Reports</h2>
  <table class="table">
    <thead>
      <tr>
        <th>Title</th>
        <th>Submitted By</th>
        <th>Priority</th>
        <th>Date</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php
      $priority_labels = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];
      foreach ($new_bugs as $bug):
      ?>
      <tr>
        <td><?= h($bug['request_title']) ?></td>
        <td><?= h($bug['requested_by_username']) ?></td>
        <td><?= h($priority_labels[$bug['priority']] ?? ucfirst($bug['priority'])) ?></td>
        <td><?= h($bug['created_at']) ?></td>
        <td><a class="btn btn-sm" href="app_request_tracker.php">View</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php render_footer(); ?>
