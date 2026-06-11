<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

const MAX_MESSAGE_LENGTH = 200000;
const MESSAGES_PER_PAGE = 10;
const MESSAGES_MAX_SHOW = 500;

function message_body_to_reply_text(string $html): string {
  $text = preg_replace('~<br\b[^>]*>|</?(p|div|blockquote)\b[^>]*>|</li>~i', "\n", $html) ?? '';
  $text = preg_replace('~<li\b[^>]*>~i', '- ', $text) ?? '';
  $text = strip_tags($text);
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = preg_replace("/\r\n?/", "\n", $text) ?? '';
  $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
  return trim($text);
}

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
$deleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $sent = false;
  $deleted = false;
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['messages_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $action = (string)($_POST['action'] ?? 'send_message');

    if ($action === 'delete_message') {
      $message_id = (int)($_POST['delete_message_id'] ?? 0);
      if ($message_id <= 0) {
        $errors[] = 'Invalid message selected.';
      } else {
        $del = $pdo->prepare("DELETE FROM messages WHERE id = ? AND sender_id = ?");
        $del->execute([$message_id, $current_user_id]);
        if ($del->rowCount() < 1) {
          $errors[] = 'Message not found or you do not have permission to delete it.';
        } else {
          $_SESSION['messages_csrf'] = bin2hex(random_bytes(24));
          header('Location: messages.php?deleted=1');
          exit;
        }
      }
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
}

// Mark all unread messages sent to the current user as read
$pdo->prepare("UPDATE messages SET is_read = 1 WHERE recipient_id = ? AND is_read = 0")
    ->execute([$current_user_id]);

// How many messages to show (default MESSAGES_PER_PAGE, increments of MESSAGES_PER_PAGE)
$show = min(MESSAGES_MAX_SHOW, max(MESSAGES_PER_PAGE, (int)($_GET['show'] ?? MESSAGES_PER_PAGE)));

// Count total messages between both users
$count_stmt = $pdo->prepare("
  SELECT COUNT(*) FROM messages
  WHERE (sender_id = ? AND recipient_id = ?)
     OR (sender_id = ? AND recipient_id = ?)
");
$count_stmt->execute([$current_user_id, $other_user_id, $other_user_id, $current_user_id]);
$total_count = (int)$count_stmt->fetchColumn();
$has_more = $total_count > $show;

// Load the $show most recent messages, then reverse for chronological display
$history_stmt = $pdo->prepare("
  SELECT m.id, m.sender_id, m.body, m.is_read, m.created_at,
         u.username AS sender_username
  FROM messages m
  JOIN users u ON u.id = m.sender_id
  WHERE (m.sender_id = ? AND m.recipient_id = ?)
     OR (m.sender_id = ? AND m.recipient_id = ?)
  ORDER BY m.created_at DESC, m.id DESC
  LIMIT ?
");
$history_stmt->bindValue(1, $current_user_id, PDO::PARAM_INT);
$history_stmt->bindValue(2, $other_user_id,   PDO::PARAM_INT);
$history_stmt->bindValue(3, $other_user_id,   PDO::PARAM_INT);
$history_stmt->bindValue(4, $current_user_id, PDO::PARAM_INT);
$history_stmt->bindValue(5, $show,            PDO::PARAM_INT);
$history_stmt->execute();
$messages = array_reverse($history_stmt->fetchAll());

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

<?php if ($deleted): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
    Message deleted successfully.
  </div>
<?php endif; ?>

<!-- Conversation History -->
<div class="card" id="message-history" style="padding:0; overflow:hidden;">
  <?php if (!$messages): ?>
    <p class="muted" style="padding:20px; text-align:center;">No messages yet. Send the first one below!</p>
  <?php else: ?>
    <?php if ($has_more): ?>
      <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; text-align:center;">
        <a href="messages.php?show=<?= h($show + MESSAGES_PER_PAGE) ?>" class="btn" style="font-size:13px; padding:6px 14px;">Load more</a>
      </div>
    <?php endif; ?>
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
          $reply_text = message_body_to_reply_text((string)$msg['body']);
        ?>
        <div style="display:flex; flex-direction:column; align-items:<?= $align ?>; max-width:100%;">
          <div style="font-size:11px; color:#6b7280; margin-bottom:3px;">
            <?= $label ?> &middot; <?= h($fmt_dt) ?>
          </div>
          <div style="background:<?= $bg ?>; color:<?= $color ?>; border-radius:10px; padding:10px 14px; max-width:80%; word-break:break-word; line-height:1.6; font-size:14px;">
            <?= $msg['body'] ?>
          </div>
          <div style="display:flex; gap:8px; margin-top:6px;">
            <button
              type="button"
              class="btn js-reply-btn"
              data-reply-text="<?= h($reply_text) ?>"
              data-reply-label="<?= h($label) ?>"
              style="font-size:12px; padding:4px 10px;"
            >Reply</button>
            <?php if ($is_mine): ?>
              <button
                type="button"
                class="btn js-delete-btn"
                data-message-id="<?= (int)$msg['id'] ?>"
                style="font-size:12px; padding:4px 10px; border-color:#fecaca; color:#b91c1c;"
              >Delete</button>
            <?php endif; ?>
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
    <input type="hidden" name="action" value="send_message" />
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

<form method="post" id="delete-message-form" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['messages_csrf']) ?>" />
  <input type="hidden" name="action" value="delete_message" />
  <input type="hidden" name="delete_message_id" id="delete_message_id" value="" />
</form>

<script src="/project/tinymce/js/tinymce/tinymce.min.js"></script>
<script>
tinymce.init({
  selector: '#msg-body',
  base_url: '/project/tinymce/js/tinymce',
  suffix: '.min',
  license_key: 'gpl',
  content_css: '/project/tinymce/js/tinymce/skins/content/default/content.min.css',
  menubar: false,
  plugins: 'lists link image emoticons',
  toolbar: 'bold italic underline blockquote | bullist numlist | link | uploadimage | emoticons | removeformat',
  content_style: 'blockquote { border-left: 4px solid #4f46e5; background: #f5f3ff; margin: 8px 0; padding: 8px 12px; color: #374151; font-style: italic; }',
  height: 220,
  branding: false,
  promotion: false,
  statusbar: false,
  images_upload_url: '/project/message_image_upload.php?csrf=<?= h($_SESSION['messages_csrf']) ?>',
  images_file_types: 'jpeg,jpg,png,gif,webp',
  paste_data_images: false,
  automatic_uploads: true,
  setup: function (editor) {
    editor.on('submit', function () {
      editor.save();
    });

    editor.ui.registry.addButton('uploadimage', {
      icon: 'image',
      tooltip: 'Upload Image',
      onAction: function () {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/gif,image/webp';
        input.onchange = function () {
          var file = input.files[0];
          if (!file) return;
          var formData = new FormData();
          formData.append('file', file);
          fetch('/project/message_image_upload.php?csrf=<?= h($_SESSION['messages_csrf']) ?>', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
          })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.location && /^\/project\/message_image_serve\.php\?file=[\w%.-]+$/.test(data.location)) {
              editor.insertContent('<img src="' + data.location + '" alt="" />');
            } else {
              alert(data.error || 'Image upload failed.');
            }
          })
          .catch(function () {
            alert('Image upload failed.');
          });
        };
        input.click();
      }
    });
  }
});

document.getElementById('msg-form').addEventListener('submit', function () {
  tinymce.triggerSave();
});

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

document.addEventListener('click', function (e) {
  var replyBtn = e.target.closest('.js-reply-btn');
  if (replyBtn) {
    var originalText = (replyBtn.getAttribute('data-reply-text') || '').trim();
    var originalLabel = (replyBtn.getAttribute('data-reply-label') || 'Unknown sender').trim();
    var editor = tinymce.get('msg-body');
    if (!originalText || !editor) return;

    var srOnlyLabel = escapeHtml('Quoted message from ' + originalLabel);
    var quotedText = escapeHtml(originalText).replace(/\r?\n/g, '<br>');
    var srOnlyHtml = '<span style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">' + srOnlyLabel + '</span>';
    var quoteHtml = '<blockquote style="border-left:4px solid #4f46e5; background:#f5f3ff; margin:8px 0; padding:8px 12px; color:#374151; font-style:italic;">' + srOnlyHtml + quotedText + '</blockquote><p><br></p>';
    editor.setContent(quoteHtml + editor.getContent({ format: 'html' }));
    editor.focus();
    return;
  }

  var deleteBtn = e.target.closest('.js-delete-btn');
  if (deleteBtn) {
    var messageId = deleteBtn.getAttribute('data-message-id');
    var deleteInput = document.getElementById('delete_message_id');
    var deleteForm = document.getElementById('delete-message-form');
    if (!messageId || !deleteInput || !deleteForm) return;
    if (!confirm('Delete this message?')) return;
    deleteInput.value = messageId;
    deleteForm.submit();
  }
});

// Scroll message history to bottom on page load and after sending (post-redirect reload)
window.addEventListener('load', function () {
  var el = document.getElementById('msg-scroll');
  if (el) el.scrollTop = el.scrollHeight;
});
</script>

<?php render_footer(); ?>
