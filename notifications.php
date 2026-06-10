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

render_header('Notifications');
?>

<div class="card">
  <h1 style="margin:0;">Notifications</h1>
</div>

<?php if (empty($unread_messages) && empty($new_bugs)): ?>
<div class="card">
  <p class="muted">You have no new notifications.</p>
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
