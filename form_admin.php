<?php
/**
 * form_admin.php – Admin/moderator panel for viewing and managing laser form entries.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

$errors  = [];
$success = '';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
  $action = $_POST['action'] ?? '';

  if ($action === 'delete_entry') {
    $entry_id = (int)($_POST['entry_id'] ?? 0);
    $user_id  = (int)($_POST['user_id']  ?? 0);
    if ($entry_id <= 0) {
      $errors[] = 'Invalid entry.';
    } else {
      $pdo->prepare("DELETE FROM laser_entries WHERE id = ?")->execute([$entry_id]);
      $success = 'Entry deleted.';
    }
  }

  if ($action === 'delete_user') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid <= 0) {
      $errors[] = 'Invalid user.';
    } elseif ($uid === current_user_id()) {
      $errors[] = 'You cannot delete your own account.';
    } else {
      $check = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
      $check->execute([$uid]);
      $target_user = $check->fetch();
      if (!$target_user) {
        $errors[] = 'User not found.';
      } elseif ($target_user['role'] !== 'user') {
        $errors[] = 'Only regular user accounts created via the form can be deleted here. Use the Users panel for admin/moderator accounts.';
      } else {
        $pdo->prepare("DELETE FROM laser_entries WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
        $success = 'User and their entry deleted.';
      }
    }
  }
}

// ── Fetch all entries ─────────────────────────────────────────────────────────
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$search = trim($_GET['q'] ?? '');
$where  = $search !== ''
  ? "WHERE le.first_name LIKE :q OR le.last_name LIKE :q OR le.email LIKE :q
         OR le.city LIKE :q OR le.state LIKE :q OR le.laser_brand LIKE :q"
  : '';

$count_sql = "SELECT COUNT(*) FROM laser_entries le $where";
$data_sql  = "SELECT le.id AS entry_id, le.user_id, le.first_name, le.last_name,
                     le.cell_phone, le.city, le.state, le.zip_code, le.email,
                     le.laser_brand, le.laser_model, le.laser_watts, le.laser_age,
                     le.laser_problem, le.service_type, le.submission_ip, le.created_at,
                     u.username, u.email_verified
              FROM laser_entries le
              JOIN users u ON u.id = le.user_id
              $where
              ORDER BY le.created_at DESC
              LIMIT :limit OFFSET :offset";

if ($search !== '') {
  $like = '%' . $search . '%';
  $cnt_stmt = $pdo->prepare($count_sql);
  $cnt_stmt->bindValue(':q', $like);
  $cnt_stmt->execute();
  $total = (int)$cnt_stmt->fetchColumn();

  $data_stmt = $pdo->prepare($data_sql);
  $data_stmt->bindValue(':q', $like);
  $data_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
  $data_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $data_stmt->execute();
} else {
  $total     = (int)$pdo->query($count_sql)->fetchColumn();
  $data_stmt = $pdo->prepare($data_sql);
  $data_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
  $data_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $data_stmt->execute();
}
$entries = $data_stmt->fetchAll();

render_header('Form Entries – Admin');
?>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">Laser Customer Service Request Entries</h1>
  <p class="muted" style="margin:0;">Total entries: <strong><?= $total ?></strong></p>
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

<!-- ── Search ──────────────────────────────────────────────────────────────── -->
<div class="card">
  <form method="get" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
    <input type="text" name="q" value="<?= h($search) ?>"
           placeholder="Search name, email, city, state, brand…"
           style="max-width:360px; flex:1;" />
    <button type="submit" class="btn primary">Search</button>
    <?php if ($search !== ''): ?>
      <a class="btn" href="form_admin.php">Clear</a>
    <?php endif; ?>
  </form>
</div>

<!-- ── Entries table ──────────────────────────────────────────────────────── -->
<div class="card">
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:900px;">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Location</th>
          <th>Machine</th>
          <th>Service Type</th>
          <th>Problem</th>
          <th>Submitted</th>
          <th>Verified</th>
          <?php if (is_admin()): ?>
          <th class="col-actions">Actions</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$entries): ?>
          <tr><td colspan="<?= is_admin() ? 11 : 10 ?>" class="muted">No entries found.</td></tr>
        <?php endif; ?>
        <?php foreach ($entries as $e): ?>
          <tr>
            <td class="muted"><?= (int)$e['entry_id'] ?></td>
            <td><strong><?= h($e['first_name']) ?> <?= h($e['last_name']) ?></strong></td>
            <td><?= h($e['email']) ?></td>
            <td><?= h($e['cell_phone']) ?></td>
            <td><?= h($e['city']) ?>, <?= h($e['state']) ?> <?= h($e['zip_code']) ?></td>
            <td>
              <?= h($e['laser_brand']) ?><br>
              <span class="muted"><?= h($e['laser_model']) ?> &bull; <?= h($e['laser_watts']) ?> &bull; <?= h($e['laser_age']) ?></span>
            </td>
            <td>
              <?php
                $st_label = $e['service_type'] === 'vip' ? 'VIP Service' : 'Standard Service';
                $st_color = $e['service_type'] === 'vip' ? 'background:#fef9c3; color:#854d0e; border-color:#fde047;' : '';
              ?>
              <span class="badge" style="<?= $st_color ?>"><?= h($st_label) ?></span>
            </td>
            <td style="max-width:220px; white-space:normal;">
              <span title="<?= h($e['laser_problem']) ?>">
                <?= h(mb_strimwidth($e['laser_problem'], 0, 80, '…')) ?>
              </span>
            </td>
            <td class="muted" style="white-space:nowrap;"><?= h($e['created_at']) ?></td>
            <td>
              <?php if ((int)$e['email_verified']): ?>
                <span class="badge" style="background:#dcfce7; color:#166534; border-color:#86efac;">Yes</span>
              <?php else: ?>
                <span class="badge" style="background:#fef2f2; color:#991b1b; border-color:#fca5a5;">No</span>
              <?php endif; ?>
            </td>
            <?php if (is_admin()): ?>
            <td class="col-actions">
              <!-- Delete entry only -->
              <form method="post" style="display:inline;"
                onsubmit="return confirm('Delete this entry? The user account will remain.')">
                <input type="hidden" name="action" value="delete_entry" />
                <input type="hidden" name="entry_id" value="<?= (int)$e['entry_id'] ?>" />
                <input type="hidden" name="user_id"  value="<?= (int)$e['user_id'] ?>" />
                <button type="submit" class="btn danger">Del Entry</button>
              </form>
              <!-- Delete user + entry -->
              <form method="post" style="display:inline; margin-left:4px;"
                onsubmit="return confirm('Delete user <?= h($e['first_name'] . ' ' . $e['last_name']) ?> AND their entry? This cannot be undone.')">
                <input type="hidden" name="action" value="delete_user" />
                <input type="hidden" name="user_id" value="<?= (int)$e['user_id'] ?>" />
                <button type="submit" class="btn danger">Del User</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php
  $total_pages = max(1, (int)ceil($total / $per_page));
  if ($total_pages > 1):
  ?>
  <div class="pagination" style="margin-top:14px; display:flex; gap:8px; align-items:center;">
    <?php if ($page > 1): ?>
      <a class="btn" href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>">← Prev</a>
    <?php endif; ?>
    <span class="muted">Page <?= $page ?> of <?= $total_pages ?></span>
    <?php if ($page < $total_pages): ?>
      <a class="btn" href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>">Next →</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
