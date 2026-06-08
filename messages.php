<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

const MAX_MESSAGE_LENGTH = 5 * 1024 * 1024;

$current_user_id = (int)$_SESSION['user_id'];

// Find the other user (the only other user in the system)
$other_user_stmt = $pdo->prepare("SELECT id, username FROM users WHERE id != ? ORDER BY id LIMIT 1");
$other_user_stmt->execute([$current_user_id]);
$other_user = $other_user_stmt->fetch();

if (!$other_user) {
  render_header('Messages');
  echo '<div class="card"><p class="muted">No other users found.</p></div>';
  render_footer();
  exit;
}

$other_user_id = (int)$other_user['id'];

// CSRF
if (empty($_SESSION['messages_csrf'])) {
  $_SESSION['messages_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$sent = isset($_GET['sent']) && $_GET['sent'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $sent = false;
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['messages_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $body = trim((string)($_POST['body'] ?? ''));
    if (trim(strip_tags($body)) === '') {
      $errors[] = 'Message body cannot be empty.';
    } elseif (strlen($body) > MAX_MESSAGE_LENGTH) {
      $errors[] = 'Message is too long.';
    } else {
      $ins = $pdo->prepare(
        "INSERT INTO messages (sender_id, recipient_id, body) VALUES (?, ?, ?)"
      );
      $ins->execute([$current_user_id, $other_user_id, $body]);
      $_SESSION['messages_csrf'] = bin2hex(random_bytes(24));
      header('Location: messages.php?sent=1');
      exit;
    }
  }
}

// Mark all unread messages sent to the current user as read
$pdo->prepare("UPDATE messages SET is_read = 1 WHERE recipient_id = ? AND is_read = 0")
    ->execute([$current_user_id]);

// Load conversation history (all messages between both users), newest first then reverse for display
$history_stmt = $pdo->prepare("
  SELECT m.id, m.sender_id, m.body, m.is_read, m.created_at,
         u.username AS sender_username
  FROM messages m
  JOIN users u ON u.id = m.sender_id
  WHERE (m.sender_id = ? AND m.recipient_id = ?)
     OR (m.sender_id = ? AND m.recipient_id = ?)
  ORDER BY m.created_at ASC, m.id ASC
");
$history_stmt->execute([$current_user_id, $other_user_id, $other_user_id, $current_user_id]);
$messages = $history_stmt->fetchAll();

render_header('Messages');
?>

<div class="card">
  <h1 style="margin:0;">Messages</h1>
  <p class="muted" style="margin:6px 0 0;">Conversation with <strong><?= h($other_user['username']) ?></strong></p>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($sent): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
    Message sent successfully.
  </div>
<?php endif; ?>

<!-- Conversation History -->
<div class="card" id="message-history" style="padding:0; overflow:hidden;">
  <?php if (!$messages): ?>
    <p class="muted" style="padding:20px; text-align:center;">No messages yet. Send the first one below!</p>
  <?php else: ?>
    <div style="max-height:520px; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:12px;" id="msg-scroll">
      <?php foreach ($messages as $msg): ?>
        <?php
          $is_mine = (int)$msg['sender_id'] === $current_user_id;
          $align   = $is_mine ? 'flex-end' : 'flex-start';
          $bg      = $is_mine ? '#dbeafe' : '#f1f5f9';
          $color   = $is_mine ? '#1e40af' : '#111827';
          $label   = $is_mine ? 'You' : h($msg['sender_username']);
          $dt      = new DateTime($msg['created_at'], new DateTimeZone(APP_TZ));
          $fmt_dt  = $dt->format('m/d/Y g:i A');
        ?>
        <div style="display:flex; flex-direction:column; align-items:<?= $align ?>; max-width:100%;">
          <div style="font-size:11px; color:#6b7280; margin-bottom:3px;">
            <?= $label ?> &middot; <?= h($fmt_dt) ?>
          </div>
          <div style="background:<?= $bg ?>; color:<?= $color ?>; border-radius:10px; padding:10px 14px; max-width:80%; word-break:break-word; line-height:1.6; font-size:14px;">
            <?= $msg['body'] ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Compose New Message -->
<div class="card" style="margin-top:0; border-top:none; border-top-left-radius:0; border-top-right-radius:0;">
  <h2 style="margin:0 0 12px;">Send a Message</h2>
  <form method="post" id="msg-form">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['messages_csrf']) ?>" />
    <div style="margin-bottom:12px;">
      <textarea id="msg-body" name="body" rows="8" style="width:100%;"><?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
          echo h(trim((string)($_POST['body'] ?? '')));
        }
      ?></textarea>
    </div>
    <button type="submit" class="btn primary" style="font-size:16px; padding:10px 20px;">Send Message</button>
  </form>
</div>

<script src="/project/tinymce/js/tinymce/tinymce.min.js"></script>
<script>
tinymce.init({
  selector: '#msg-body',
  base_url: '/project/tinymce/js/tinymce',
  suffix: '.min',
  license_key: 'gpl',
  content_css: '/project/tinymce/js/tinymce/skins/content/default/content.min.css',
  menubar: false,
  plugins: 'lists link',
  toolbar: 'bold italic underline | bullist numlist | link | removeformat',
  height: 220,
  branding: false,
  promotion: false,
  statusbar: false,
  setup: function (editor) {
    editor.on('submit', function () {
      editor.save();
    });
  }
});

document.getElementById('msg-form').addEventListener('submit', function () {
  tinymce.triggerSave();
});

// Scroll message history to bottom on load
(function () {
  var el = document.getElementById('msg-scroll');
  if (el) el.scrollTop = el.scrollHeight;
})();
</script>

<?php render_footer(); ?>
