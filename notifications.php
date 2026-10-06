<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$current_user_id = (int)$_SESSION['user_id'];
$is_admin = !empty($_SESSION['is_admin']);

if (empty($_SESSION['notifications_csrf'])) {
  $_SESSION['notifications_csrf'] = bin2hex(random_bytes(24));
}

// Handle "Mark as Read" POST action for a single approval alert
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_dismissed_id'])) {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['notifications_csrf'], $csrf)) {
    http_response_code(403);
    exit('Security token mismatch. Please refresh and try again.');
  }
  $dismiss_id = (int)$_POST['mark_dismissed_id'];
  if ($dismiss_id > 0) {
    $dismiss_stmt = $pdo->prepare("UPDATE approval_alerts SET dismissed = 1 WHERE id = ? AND recipient_id = ?");
    $dismiss_stmt->execute([$dismiss_id, $current_user_id]);
  }
  header('Location: notifications.php');
  exit;
}

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
  WHERE recipient_id = ? AND dismissed = 0
  ORDER BY created_at DESC, id DESC
");
$approval_stmt->execute([$current_user_id]);
$approval_alerts = $approval_stmt->fetchAll();

// Clear the red badge by marking unread alerts as read (badge uses is_read = 0).
// This does NOT dismiss items from the list; dismissed = 1 is only set via the "Mark as Read" button.
$pdo->prepare("UPDATE approval_alerts SET is_read = 1 WHERE recipient_id = ? AND is_read = 0")
    ->execute([$current_user_id]);

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
        <td><?= (string)$alert['message'] ?></td>
        <td><?= h((string)$alert['created_at']) ?></td>
        <td style="white-space:nowrap;">
          <a class="btn btn-sm" href="<?= h((string)$alert['link_url']) ?>">Open</a>
          <form method="post" action="notifications.php" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['notifications_csrf']) ?>">
            <input type="hidden" name="mark_dismissed_id" value="<?= (int)$alert['id'] ?>">
            <button type="submit" class="btn btn-sm">Mark as Read</button>
          </form>
        </td>
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
		<td>
		  <a class="btn btn-sm" href="<?= ($msg === 'Eve' || $msg === 'eve') ? 'eve_messages.php' : 'messages.php' ?>">
			View
		  </a>
		</td>
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
