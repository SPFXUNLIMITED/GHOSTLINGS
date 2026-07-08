<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (empty($_SESSION['app_request_tracker_csrf'])) {
  $_SESSION['app_request_tracker_csrf'] = bin2hex(random_bytes(24));
}

$type_labels = [
  'bug' => 'Bug',
  'software_change' => 'Software Change',
  'feature_request' => 'Feature Request',
];

$priority_labels = [
  'low' => 'Low',
  'medium' => 'Medium',
  'high' => 'High',
];

$status_labels = [
  'new' => 'New',
  'in_review' => 'In Review',
  'planned' => 'Planned',
  'completed' => 'Completed',
  'declined' => 'Declined',
];

$errors = [];
$success = '';

function excerpt_text(string $text, int $limit): string {
  $text = trim($text);
  if ($limit <= 0 || $text === '') return '';
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
    return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '…';
  }
  if (strlen($text) <= $limit) return $text;
  return rtrim(substr($text, 0, $limit)) . '…';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['app_request_tracker_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $admin_notes = trim((string)($_POST['admin_notes'] ?? ''));

    if ($request_id <= 0) {
      $errors[] = 'Invalid request.';
    }
    if (!isset($status_labels[$status])) {
      $errors[] = 'Invalid status.';
    }
    if (strlen($admin_notes) > 8000) {
      $errors[] = 'Admin notes must be 8000 characters or fewer.';
    }

    if (!$errors) {
      $upd = $pdo->prepare(
        "UPDATE app_requests
         SET status = ?, admin_notes = ?
         WHERE id = ?"
      );
      $upd->execute([
        $status,
        $admin_notes === '' ? null : $admin_notes,
        $request_id,
      ]);
      $_SESSION['app_request_tracker_csrf'] = bin2hex(random_bytes(24));
      $success = 'Request updated.';
    }
  }
}

$rows = $pdo->query(
  "SELECT ar.id, ar.request_type, ar.request_title, ar.request_details, ar.priority,
          ar.status, ar.admin_notes, ar.created_at, ar.updated_at,
          u.username, u.contact_name, u.email
   FROM app_requests ar
   JOIN users u ON u.id = ar.requested_by
   ORDER BY ar.created_at DESC, ar.id DESC"
)->fetchAll();

render_header('Request Tracker');
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1 style="margin-top:0; margin-bottom:4px;">Request Tracker</h1>
    <p class="muted" style="margin:0;">Review and triage user-submitted app requests.</p>
  </div>
  <a class="btn primary" href="app_request_form.php">+ New Request</a>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
    <?= h($success) ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="table-wrap" style="overflow-x:auto;">
    <table style="width:100%; min-width:900px; border-collapse:collapse;">
      <colgroup>
        <col style="width:3%;">
        <col style="width:27%;">
        <col style="width:35%;">
        <col style="width:20%;">
        <col style="width:15%;">
      </colgroup>
      <thead>
        <tr>
          <th>ID</th>
          <th>Request Info</th>
          <th>Details</th>
          <th>Admin Notes</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="5" class="muted">No app requests found.</td>
          </tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td class="muted" style="vertical-align:top;"><?= (int)$row['id'] ?></td>
            <td style="vertical-align:top; white-space:normal;">
              <strong><?= h($row['contact_name'] ?: $row['username']) ?></strong><br>
              <span class="muted" style="font-size:0.85em;"><?= h($row['email']) ?></span>
              <div style="margin-top:6px; font-size:0.9em; line-height:1.6;">
                <span class="muted">Type:</span> <?= h($type_labels[$row['request_type']] ?? $row['request_type']) ?><br>
                <span class="muted">Priority:</span> <?= h($priority_labels[$row['priority']] ?? $row['priority']) ?><br>
                <span class="muted">Status:</span> <span class="badge <?= h($row['status']) ?>"><?= h($status_labels[$row['status']] ?? $row['status']) ?></span><br>
                <span class="muted">Created:</span> <?= h($row['created_at']) ?><br>
                <span class="muted">Updated:</span> <?= h($row['updated_at']) ?>
              </div>
            </td>
            <td style="vertical-align:top; white-space:normal; word-break:break-word;">
              <strong style="display:block; margin-bottom:4px;"><?= h($row['request_title']) ?></strong>
              <span class="muted" style="font-size:0.92em;"><?= nl2br(h(excerpt_text((string)$row['request_details'], 400))) ?></span>
            </td>
            <td style="vertical-align:top; white-space:normal; word-break:break-word;">
              <span class="muted" style="font-size:0.92em;"><?= nl2br(h(excerpt_text((string)($row['admin_notes'] ?? ''), 300))) ?></span>
            </td>
            <td style="vertical-align:top;" class="col-actions">
              <form method="post" style="display:grid; gap:6px; min-width:180px;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['app_request_tracker_csrf']) ?>" />
                <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>" />
                <select name="status" required>
                  <?php foreach ($status_labels as $status_value => $status_label): ?>
                    <option value="<?= h($status_value) ?>" <?= $row['status'] === $status_value ? 'selected' : '' ?>>
                      <?= h($status_label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <textarea name="admin_notes" rows="3" maxlength="8000" placeholder="Optional notes"><?= h((string)($row['admin_notes'] ?? '')) ?></textarea>
                <button type="submit" class="btn primary">Update</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
