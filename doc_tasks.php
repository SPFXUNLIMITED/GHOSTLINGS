<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
if (!$category_id) { header('Location: documents.php'); exit; }

$stmt = $pdo->prepare("SELECT id, name, owner_id FROM projects WHERE id = ? AND is_doc_category = 1");
$stmt->execute([$category_id]);
$category = $stmt->fetch();
if (!$category) { http_response_code(404); exit('Category not found'); }

$uid = current_user_id();

if (is_admin()) {
  $stmt = $pdo->prepare("
    SELECT t.*, COUNT(u.id) AS upload_count, usr.username AS assigned_username
    FROM tasks t
    LEFT JOIN task_uploads u ON u.task_id = t.id
    LEFT JOIN users usr ON usr.id = t.assigned_to
    WHERE t.project_id = ?
    GROUP BY t.id
    ORDER BY FIELD(t.priority, 'critical', 'high', 'medium', 'low'), FIELD(t.status, 'todo', 'doing', 'done')
  ");
  $stmt->execute([$category_id]);
} else {
  $stmt = $pdo->prepare("
    SELECT t.*, COUNT(u.id) AS upload_count, usr.username AS assigned_username
    FROM tasks t
    LEFT JOIN task_uploads u ON u.task_id = t.id
    LEFT JOIN users usr ON usr.id = t.assigned_to
    JOIN projects p ON p.id = t.project_id
    WHERE t.project_id = ?
      AND (t.assigned_to = ? OR p.owner_id = ?)
    GROUP BY t.id
    ORDER BY FIELD(t.priority, 'critical', 'high', 'medium', 'low'), FIELD(t.status, 'todo', 'doing', 'done')
  ");
  $stmt->execute([$category_id, $uid, $uid]);
}
$documents = $stmt->fetchAll();
$doc_placeholder_values = [];

if ($uid) {
  $stmt = $pdo->prepare("
    SELECT username, contact_name, company_name, email, contact_phone
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$uid]);
  $profile = $stmt->fetch() ?: [];
  $doc_placeholder_values = [
    'username' => trim((string)($profile['username'] ?? '')),
    'contact_name' => trim((string)($profile['contact_name'] ?? '')),
    'company_name' => trim((string)($profile['company_name'] ?? '')),
    'email' => trim((string)($profile['email'] ?? '')),
    'contact_phone' => trim((string)($profile['contact_phone'] ?? '')),
  ];
}

render_header('Documents');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div>
      <h1 style="margin:0;">Documents</h1>
      <div class="muted">Category: <strong><?= h($category['name']) ?></strong> (ID <?= (int)$category_id ?>)</div>
    </div>
    <div class="actions">
      <a class="btn" href="documents.php">Back to Documents</a>
      <a class="btn primary" href="doc_form.php?category_id=<?= (int)$category_id ?>">+ New Document</a>
    </div>
  </div>
</div>

<div class="card">
  <table>
    <thead>
      <tr>
        <th>Title</th>
        <th>Status</th>
        <th>Priority</th>
        <th>Due</th>
        <th>Assigned To</th>
        <th>Details</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$documents): ?>
        <tr><td colspan="7" class="muted">No documents yet.</td></tr>
      <?php endif; ?>

      <?php foreach ($documents as $d): ?>
        <tr>
          <td>
            <strong><?= h($d['title']) ?></strong><br>
            <?php $count = (int)($d['upload_count'] ?? 0); ?>
            <a class="muted" href="task_uploads.php?task_id=<?= (int)$d['id'] ?>">Files (<?= $count ?>)</a>
          </td>
          <td><span class="badge <?= h($d['status']) ?>"><?= h($d['status']) ?></span></td>
          <td><span class="badge priority-<?= h($d['priority'] ?? 'medium') ?>"><?= h(ucfirst($d['priority'] ?? 'medium')) ?></span></td>

          <td>
            <?php if (!empty($d['due_date'])): ?>
              <?= h(fmt_date_mdY($d['due_date'])) ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>

          <td>
            <?php if (!empty($d['assigned_username'])): ?>
              <?= h($d['assigned_username']) ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>

          <td><?= render_doc_details($d['details'] ?? '', $doc_placeholder_values) ?></td>
          <td>
            <div class="actions">
              <a class="btn" href="doc_form.php?category_id=<?= (int)$category_id ?>&id=<?= (int)$d['id'] ?>">Edit</a>
              <a class="btn danger"
                 href="doc_delete.php?category_id=<?= (int)$category_id ?>&id=<?= (int)$d['id'] ?>"
                 onclick="return confirm('Delete this document?');">
                Delete
              </a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
