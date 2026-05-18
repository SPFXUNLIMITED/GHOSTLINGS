<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
$trackerData = require __DIR__ . '/change_tracker.php';
$changeTracker = is_array($trackerData) ? $trackerData : [];
$version = isset($changeTracker['version']) && is_scalar($changeTracker['version']) ? (string)$changeTracker['version'] : 'unknown';
$changes = [];
if (isset($changeTracker['changes']) && is_array($changeTracker['changes'])) {
    $changes = array_values(array_filter($changeTracker['changes'], 'is_scalar'));
}
require_login();

$recent_comments = [];
$recent_comments_limit = 100;
$recent_comment_preview_max_chars = 140;
$new_badge_duration_seconds = 24 * 60 * 60;
$new_cutoff = time() - $new_badge_duration_seconds;
$uid = current_user_id();
if ($uid !== null) {
    $recent_comments_limit = max(1, (int)$recent_comments_limit);
    if (is_admin()) {
        $stmt = $pdo->prepare("
          SELECT
            x.comment_type,
            x.comment_id,
            x.item_id,
            x.item_title,
            x.project_name,
            x.body,
            x.created_at
          FROM (
            SELECT
              'task' AS comment_type,
              tc.id AS comment_id,
              t.id AS item_id,
              t.title AS item_title,
              p.name AS project_name,
              tc.body,
              tc.created_at
            FROM task_comments tc
            JOIN tasks t ON t.id = tc.task_id
            JOIN projects p ON p.id = t.project_id
            WHERE p.archived = 0

            UNION ALL

            SELECT
              'project' AS comment_type,
              pc.id AS comment_id,
              p.id AS item_id,
              p.name AS item_title,
              p.name AS project_name,
              pc.body,
              pc.created_at
            FROM project_comments pc
            JOIN projects p ON p.id = pc.project_id
            WHERE p.archived = 0
          ) x
          ORDER BY x.created_at DESC, x.comment_id DESC
          LIMIT ?
        ");
        $stmt->bindValue(1, $recent_comments_limit, PDO::PARAM_INT);
    } else {
        $stmt = $pdo->prepare("
          SELECT
            x.comment_type,
            x.comment_id,
            x.item_id,
            x.item_title,
            x.project_name,
            x.body,
            x.created_at
          FROM (
            SELECT
              'task' AS comment_type,
              tc.id AS comment_id,
              t.id AS item_id,
              t.title AS item_title,
              p.name AS project_name,
              tc.body,
              tc.created_at
            FROM task_comments tc
            JOIN tasks t ON t.id = tc.task_id
            JOIN projects p ON p.id = t.project_id
            WHERE t.assigned_to = ?
              AND p.archived = 0

            UNION ALL

            SELECT
              'project' AS comment_type,
              pc.id AS comment_id,
              p.id AS item_id,
              p.name AS item_title,
              p.name AS project_name,
              pc.body,
              pc.created_at
            FROM project_comments pc
            JOIN projects p ON p.id = pc.project_id
            WHERE p.archived = 0
              AND EXISTS (
                SELECT 1
                FROM tasks tx
                WHERE tx.project_id = p.id
                  AND tx.assigned_to = ?
              )
          ) x
          ORDER BY x.created_at DESC, x.comment_id DESC
          LIMIT ?
        ");
        $stmt->bindValue(1, (int)$uid, PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$uid, PDO::PARAM_INT);
        $stmt->bindValue(3, $recent_comments_limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    $recent_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

render_header('Home');
?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">Home</h1>
  <p class="muted" style="margin:0;">Welcome to Project Manager.</p>
</div>

<div class="card">
  <h2 style="margin-top:0; margin-bottom:8px;">Recent Comments</h2>
  <?php if ($recent_comments): ?>
    <?php foreach ($recent_comments as $comment): ?>
      <?php
        $created_ts = !empty($comment['created_at']) ? strtotime((string)$comment['created_at']) : false;
        $is_new = ($created_ts !== false) && ($created_ts >= $new_cutoff);
        $link = ((string)$comment['comment_type'] === 'task')
          ? ('task_details.php?id=' . (int)$comment['item_id'])
          : ('project_details.php?id=' . (int)$comment['item_id']);
        $comment_preview_raw = strip_tags((string)($comment['body'] ?? ''));
        $comment_preview_raw = html_entity_decode($comment_preview_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $comment_preview_raw = str_replace("\xC2\xA0", ' ', $comment_preview_raw);
        $comment_preview = trim(preg_replace('/\s+/u', ' ', $comment_preview_raw));
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
          if (mb_strlen($comment_preview, 'UTF-8') > $recent_comment_preview_max_chars) {
            $comment_preview = rtrim(mb_substr($comment_preview, 0, $recent_comment_preview_max_chars, 'UTF-8')) . '...';
          }
        } elseif (strlen($comment_preview) > $recent_comment_preview_max_chars) {
          $comment_preview = rtrim(substr($comment_preview, 0, $recent_comment_preview_max_chars)) . '...';
        }
      ?>
      <div style="padding:10px 0; border-bottom:1px solid #e5e7eb;">
        <div class="row" style="justify-content:space-between; align-items:center;">
          <div class="name-with-badge" style="margin-bottom:6px;">
            <a href="<?= h($link) ?>">
              <?= ((string)$comment['comment_type'] === 'task') ? 'Task' : 'Project' ?>:
              <?= h($comment['item_title'] ?? '') ?>
            </a>
            <?php if ((string)$comment['comment_type'] === 'task' && !empty($comment['project_name'])): ?>
              <span class="muted">in <?= h($comment['project_name']) ?></span>
            <?php endif; ?>
            <?php if ($is_new): ?>
              <span class="badge new">New</span>
            <?php endif; ?>
          </div>
          <div class="muted">
            <?php
              if ($created_ts !== false) {
                echo h(date('m-d-Y H:i', $created_ts));
              } else {
                echo '—';
              }
            ?>
          </div>
        </div>
        <div style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= h($comment_preview) ?></div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="muted"><?= is_admin() ? 'No recent comments.' : 'No recent comments for your assigned work.' ?></div>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0; margin-bottom:8px;">Version</h2>
  <p class="muted" style="margin-top:0;">
    v<?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8') ?>
  </p>
  <h3 style="margin-bottom:6px;">Changes</h3>
  <ul style="margin:0; padding-left:18px;">
    <?php foreach ($changes as $change): ?>
      <li><?= htmlspecialchars((string)$change, ENT_QUOTES, 'UTF-8') ?></li>
    <?php endforeach; ?>
  </ul>
</div>

<?php render_footer(); ?>
