<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require __DIR__ . '/auth.php';
require_login();

$per_page    = 15;
$cat_page    = max(1, (int)($_GET['cat_page']  ?? 1));
$doc_page    = max(1, (int)($_GET['doc_page']  ?? 1));
$cat_offset  = ($cat_page - 1) * $per_page;
$doc_offset  = ($doc_page - 1) * $per_page;

if (is_admin()) {
  $cat_total = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE is_sop_category = 1 AND archived = 0")->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT id, name, description, created_at, priority
    FROM projects
    WHERE is_sop_category = 1 AND archived = 0
    ORDER BY id DESC
    LIMIT :limit OFFSET :offset
  ");
  $stmt->bindValue(':limit',  $per_page,   PDO::PARAM_INT);
  $stmt->bindValue(':offset', $cat_offset, PDO::PARAM_INT);
  $stmt->execute();
  $categories = $stmt->fetchAll();

  $doc_total = (int)$pdo->query("
    SELECT COUNT(*)
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE p.is_sop_category = 1 AND p.archived = 0
  ")->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT
      t.id, t.project_id, t.title, t.status, t.due_date, t.created_at, t.priority,
      p.name AS category_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE p.is_sop_category = 1 AND p.archived = 0
    ORDER BY FIELD(t.priority, 'critical', 'high', 'medium', 'low'), FIELD(t.status, 'todo', 'doing', 'done')
    LIMIT :limit OFFSET :offset
  ");
  $stmt->bindValue(':limit',  $per_page,  PDO::PARAM_INT);
  $stmt->bindValue(':offset', $doc_offset, PDO::PARAM_INT);
  $stmt->execute();
  $recent_docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $uid = current_user_id();

  $stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT pr.id)
    FROM projects pr
    LEFT JOIN tasks t ON t.project_id = pr.id
    WHERE pr.is_sop_category = 1 AND pr.archived = 0
      AND (pr.owner_id = ? OR (t.assigned_to = ? AND t.assigned_to IS NOT NULL))
  ");
  $stmt->execute([$uid, $uid]);
  $cat_total = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT DISTINCT pr.id, pr.name, pr.description, pr.created_at, pr.priority
    FROM projects pr
    LEFT JOIN tasks t ON t.project_id = pr.id
    WHERE pr.is_sop_category = 1 AND pr.archived = 0
      AND (pr.owner_id = ? OR (t.assigned_to = ? AND t.assigned_to IS NOT NULL))
    ORDER BY pr.id DESC
    LIMIT ? OFFSET ?
  ");
  $stmt->bindValue(1, $uid,         PDO::PARAM_INT);
  $stmt->bindValue(2, $uid,         PDO::PARAM_INT);
  $stmt->bindValue(3, $per_page,    PDO::PARAM_INT);
  $stmt->bindValue(4, $cat_offset,  PDO::PARAM_INT);
  $stmt->execute();
  $categories = $stmt->fetchAll();

  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE p.is_sop_category = 1 AND p.archived = 0
      AND t.assigned_to = ?
  ");
  $stmt->execute([$uid]);
  $doc_total = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT
      t.id, t.project_id, t.title, t.status, t.due_date, t.created_at, t.priority,
      p.name AS category_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE p.is_sop_category = 1 AND p.archived = 0
      AND t.assigned_to = ?
    ORDER BY FIELD(t.priority, 'critical', 'high', 'medium', 'low'), FIELD(t.status, 'todo', 'doing', 'done')
    LIMIT ? OFFSET ?
  ");
  $stmt->bindValue(1, $uid,        PDO::PARAM_INT);
  $stmt->bindValue(2, $per_page,   PDO::PARAM_INT);
  $stmt->bindValue(3, $doc_offset, PDO::PARAM_INT);
  $stmt->execute();
  $recent_docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

render_header('SOP');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;">SOP</h1>
    <a class="btn primary" href="sop_category_form.php">+ New SOP Category</a>
  </div>
  <p class="muted">Create SOP categories, then manage SOP pages inside each category.</p>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table-auto" data-table-key="sop-categories" data-default-sort-col="priority" data-default-sort-dir="desc">
      <thead>
        <tr>
          <th>
            <button type="button" class="linklike" data-sort-col="name" data-sort-type="text" aria-label="Sort SOP categories by name">
              Name
            </button>
          </th>
          <th>Description</th>
          <th class="col-status">
            <button type="button" class="linklike" data-sort-col="priority" data-sort-type="priority" aria-label="Sort SOP categories by priority">
              Priority
            </button>
          </th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$categories): ?>
          <tr><td colspan="4" class="muted">No SOP categories yet.</td></tr>
        <?php endif; ?>

        <?php foreach ($categories as $c): ?>
          <tr data-name="<?= h(strtolower($c['name'])) ?>"
              data-priority="<?= h($c['priority'] ?? 'medium') ?>"
              data-created-at="<?= h($c['created_at']) ?>">
            <td>
              <strong><?= h($c['name']) ?></strong><br />
              <span class="muted">
                Category #<?= (int)$c['id'] ?> <br>
                <?php
                  $dt = new DateTime($c['created_at']);
                  $dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
                  echo nl2br(h($dt->format("m-d-Y\ng:i A")));
                ?>
              </span>
            </td>
            <td class="col-desc"><?= nl2br(h($c['description'] ?? '')) ?></td>
            <td class="col-status"><span class="badge priority-<?= h($c['priority'] ?? 'medium') ?>"><?= h(ucfirst($c['priority'] ?? 'medium')) ?></span></td>
            <td class="col-actions">
              <div class="actions">
                <a class="btn" href="sop_pages.php?category_id=<?= (int)$c['id'] ?>">Pages</a>
                <a class="btn" href="sop_category_form.php?id=<?= (int)$c['id'] ?>">Edit</a>
                <a class="btn danger"
                   href="sop_category_delete.php?id=<?= (int)$c['id'] ?>"
                   onclick="return confirm('Delete this category and all its SOP pages?');">
                  Delete
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php render_pagination($cat_page, $cat_total, $per_page, 'cat_page'); ?>
</div>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h2 style="margin:0;">Recent SOP Pages</h2>
    <span class="muted">Most recent <?= (int)$per_page ?></span>
  </div>

  <div class="table-wrap">
    <table class="table-auto" data-table-key="sop-recent" data-default-sort-col="priority" data-default-sort-dir="desc">
      <thead>
        <tr>
          <th>
            <button type="button" class="linklike" data-sort-col="title" data-sort-type="text" aria-label="Sort SOP pages by title">
              SOP Page
            </button>
          </th>
          <th class="col-project">Category</th>
          <th class="col-status">
            <button type="button" class="linklike" data-sort-col="status" data-sort-type="status" aria-label="Sort SOP pages by status">
              Status
            </button>
          </th>
          <th class="col-status">
            <button type="button" class="linklike" data-sort-col="priority" data-sort-type="priority" aria-label="Sort SOP pages by priority">
              Priority
            </button>
          </th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent_docs as $d): ?>
          <?php
            $due = '';
            if (!empty($d['due_date'])) {
              $dueDt = DateTime::createFromFormat('Y-m-d', $d['due_date'], new DateTimeZone('America/Los_Angeles'));
              if ($dueDt) $due = $dueDt->format('m-d-Y');
            }
          ?>
          <tr data-title="<?= h(strtolower($d['title'])) ?>"
              data-status="<?= h($d['status']) ?>"
              data-priority="<?= h($d['priority'] ?? 'medium') ?>"
              data-due="<?= h($d['due_date'] ?? '') ?>"
              data-created-at="<?= h($d['created_at']) ?>">
            <td class="col-task">
              <strong><?= h($d['title']) ?></strong><br>
              Due: <?= h($due) ?>
            </td>
            <td class="col-project col-project-wrap">
              <?= h($d['category_name']) ?> <br>
              <a class="muted" href="sop_pages.php?category_id=<?= (int)$d['project_id'] ?>">View SOP category pages</a>
            </td>
            <td class="col-status"><span class="badge <?= h($d['status']) ?>"><?= h($d['status']) ?></span></td>
            <td class="col-status"><span class="badge priority-<?= h($d['priority'] ?? 'medium') ?>"><?= h(ucfirst($d['priority'] ?? 'medium')) ?></span></td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($recent_docs)): ?>
          <tr><td colspan="4" class="muted">No SOP pages yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php render_pagination($doc_page, $doc_total, $per_page, 'doc_page'); ?>
</div>

<?php render_footer(); ?>
