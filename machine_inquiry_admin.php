<?php
/**
 * machine_inquiry_admin.php – Admin panel for Machine Inquiry Form.
 * View/manage submissions and edit the promotional text shown on the form.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['mia_csrf'])) {
  $_SESSION['mia_csrf'] = bin2hex(random_bytes(24));
}

$errors  = [];
$success = '';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['mia_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_inquiry') {
      $id = (int)($_POST['inquiry_id'] ?? 0);
      if ($id <= 0) {
        $errors[] = 'Invalid inquiry.';
      } else {
        $pdo->prepare("DELETE FROM machine_inquiries WHERE id = ?")->execute([$id]);
        $success = 'Inquiry deleted.';
      }
    }

    if ($action === 'save_promo') {
      // Allow HTML from TinyMCE (admin-only field, not public input)
      $promo = $_POST['promo_text'] ?? '';
      if (strlen($promo) > 65535) {
        $errors[] = 'Promotion text is too long (max 65,535 characters).';
      } else {
        $pdo->prepare(
          "INSERT INTO machine_inquiry_settings (setting_key, setting_val)
           VALUES ('promo_text', ?)
           ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        )->execute([$promo]);
        $_SESSION['mia_csrf'] = bin2hex(random_bytes(24));
        $success = 'Promotion text saved.';
      }
    }
  }
}

// ── Section routing ───────────────────────────────────────────────────────────
$section = (string)($_GET['section'] ?? 'inquiries');

// ── Fetch promo text ──────────────────────────────────────────────────────────
$promo_text = '';
if ($section === 'promo') {
  $stmt = $pdo->prepare("SELECT setting_val FROM machine_inquiry_settings WHERE setting_key = 'promo_text' LIMIT 1");
  $stmt->execute();
  $row = $stmt->fetch();
  $promo_text = (string)($row['setting_val'] ?? '');
}

// ── Fetch inquiries ───────────────────────────────────────────────────────────
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;
$search   = trim($_GET['q'] ?? '');
$condition_filter = trim($_GET['condition'] ?? '');

$where_parts = [];
$bind        = [];
if ($search !== '') {
  $where_parts[] = "(mi.first_name LIKE :q OR mi.last_name LIKE :q OR mi.email LIKE :q
                       OR mi.city LIKE :q OR mi.state LIKE :q)";
  $bind[':q'] = '%' . $search . '%';
}
if (in_array($condition_filter, ['new','used','either'], true)) {
  $where_parts[] = "mi.machine_condition = :cond";
  $bind[':cond'] = $condition_filter;
}
$where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$count_sql = "SELECT COUNT(*) FROM machine_inquiries mi $where";
$data_sql  = "SELECT mi.* FROM machine_inquiries mi $where
              ORDER BY mi.created_at DESC
              LIMIT :limit OFFSET :offset";

if ($bind) {
  $cnt_stmt = $pdo->prepare($count_sql);
  foreach ($bind as $k => $v) $cnt_stmt->bindValue($k, $v);
  $cnt_stmt->execute();
  $total = (int)$cnt_stmt->fetchColumn();

  $data_stmt = $pdo->prepare($data_sql);
  foreach ($bind as $k => $v) $data_stmt->bindValue($k, $v);
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
$inquiries = $data_stmt->fetchAll();
$total_pages = max(1, (int)ceil($total / $per_page));

// ── Feature label map ─────────────────────────────────────────────────────────
$feature_labels = [
  'autofocus'    => 'Auto-Focus',
  'camera'       => 'Camera',
  'rotary'       => 'Rotary',
  'pass_through' => 'Pass-Through',
  'air_assist'   => 'Air Assist',
  'wifi'         => 'Wi-Fi',
  'enclosed'     => 'Enclosed',
  'chiller'      => 'Chiller',
  'red_dot'      => 'Red Dot',
  'lcd_panel'    => 'LCD Panel',
];

function fmt_feature_list(string $raw, array $labels): string {
  if (trim($raw) === '') return '<span class="muted">—</span>';
  $out = [];
  foreach (explode(',', $raw) as $f) {
    $f = trim($f);
    $out[] = '<span class="badge mia-feat-badge">' . htmlspecialchars($labels[$f] ?? $f, ENT_QUOTES, 'UTF-8') . '</span>';
  }
  return implode(' ', $out);
}

render_header('Machine Inquiry Admin');
?>

<style>
.mia-layout { display: grid; grid-template-columns: 200px 1fr; gap: 14px; align-items: start; }
@media (max-width: 700px) { .mia-layout { grid-template-columns: 1fr; } }
.mia-sidebar .menu-link { display: block; padding: 8px 12px; border-radius: 6px; text-decoration: none; color: var(--t); font-size: 14px; }
.mia-sidebar .menu-link.active, .mia-sidebar .menu-link:hover { background: #eff6ff; color: var(--p); }
.mia-feat-badge { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; font-size: 11px; padding: 2px 7px; white-space: nowrap; }
.cond-new   { background: #dcfce7; color: #166534; border-color: #86efac; }
.cond-used  { background: #fef9c3; color: #854d0e; border-color: #fde047; }
.cond-either{ background: #f3e8ff; color: #6b21a8; border-color: #d8b4fe; }
.mia-detail-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; overflow-y:auto; padding:32px 16px; }
.mia-detail-modal.open { display:flex; align-items:flex-start; justify-content:center; }
.mia-detail-box { background:#fff; border-radius:12px; padding:28px; max-width:720px; width:100%; position:relative; box-shadow:0 16px 48px rgba(0,0,0,.2); }
.mia-detail-close { position:absolute; top:14px; right:16px; cursor:pointer; font-size:20px; background:none; border:none; color:var(--m); }
.mia-dl { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:16px; }
.mia-dl-item label { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--m); display:block; margin-bottom:3px; }
.mia-dl-item p { margin:0; font-size:14px; }
.mia-dl-item.full { grid-column:1/-1; }
</style>

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">🏭 Machine Inquiry Admin</h1>
  <p class="muted" style="margin:0;">Manage CO2 laser machine purchase inquiries and promotion settings.</p>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;"><?= h($success) ?></div>
<?php endif; ?>

<div class="mia-layout">
  <!-- ── Sidebar ── -->
  <div class="card mia-sidebar" style="padding:10px;">
    <a class="menu-link <?= $section === 'inquiries' ? 'active' : '' ?>"
       href="machine_inquiry_admin.php?section=inquiries">📋 Inquiries</a>
    <?php if (is_admin()): ?>
    <a class="menu-link <?= $section === 'promo' ? 'active' : '' ?>"
       href="machine_inquiry_admin.php?section=promo">🎁 Promotion Text</a>
    <?php endif; ?>
    <hr style="margin:8px 0; border:none; border-top:1px solid var(--b);">
    <a class="menu-link" href="machine_inquiry_form.php" target="_blank">🔗 View Public Form</a>
  </div>

  <!-- ── Main Content ── -->
  <div>

    <?php if ($section === 'promo' && is_admin()): ?>
    <!-- ── Promotion Text Editor ── -->
    <div class="card">
      <h2 style="margin-top:0;">🎁 Promotion Text</h2>
      <p class="muted" style="margin-bottom:16px;">
        This text is displayed as a highlighted banner on the public Machine Inquiry Form.
        Use it to promote special offers, discounts, or announcements. HTML is supported.
      </p>

      <form method="post" action="machine_inquiry_admin.php?section=promo" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mia_csrf']) ?>" />
        <input type="hidden" name="action" value="save_promo" />

        <div>
          <label style="font-weight:600;">Promotion Banner Content</label>
          <textarea id="promo_text" name="promo_text" rows="8"
                    style="width:100%; margin-top:6px;"><?= h($promo_text) ?></textarea>
          <p class="muted" style="margin:6px 0 0; font-size:12px;">
            Leave blank to hide the promotion banner on the form.
          </p>
        </div>

        <div style="margin-top:18px; display:flex; gap:10px; align-items:center;">
          <button type="submit" class="btn primary">💾 Save Promotion</button>
          <a class="btn" href="machine_inquiry_form.php" target="_blank">👁️ Preview Form</a>
        </div>
      </form>
    </div>

    <script src="/project/tinymce/js/tinymce/tinymce.min.js"></script>
    <script>
    tinymce.init({
      selector: '#promo_text',
      base_url: '/project/tinymce/js/tinymce',
      suffix: '.min',
      content_css: '/project/tinymce/js/tinymce/skins/content/default/content.min.css',
      height: 320,
      menubar: false,
      plugins: ['lists', 'link', 'emoticons'],
      toolbar: 'bold italic underline | forecolor backcolor | bullist numlist | link emoticons | removeformat',
      promotion: false,
      branding: false,
    });
    </script>

    <?php else: ?>
    <!-- ── Inquiries Table ── -->
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
        <div>
          <strong>Total Inquiries: <?= (int)$total ?></strong>
          <span class="muted"> &bull; Page <?= $page ?> of <?= $total_pages ?></span>
        </div>
        <a class="btn" href="machine_inquiry_form.php" target="_blank">🔗 Public Form</a>
      </div>

      <!-- Search & Filter -->
      <form method="get" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:14px;">
        <input type="hidden" name="section" value="inquiries" />
        <input type="text" name="q" value="<?= h($search) ?>"
               placeholder="Search name, email, city…" style="max-width:280px; flex:1;" />
        <select name="condition" style="width:170px;">
          <option value="">All Conditions</option>
          <option value="new"   <?= $condition_filter === 'new'   ? 'selected':'' ?>>🆕 New</option>
          <option value="used"  <?= $condition_filter === 'used'  ? 'selected':'' ?>>♻️ Used</option>
          <option value="either"<?= $condition_filter === 'either'? 'selected':'' ?>>🤷 Either</option>
        </select>
        <button type="submit" class="btn primary">Search</button>
        <?php if ($search !== '' || $condition_filter !== ''): ?>
          <a class="btn" href="machine_inquiry_admin.php?section=inquiries">Clear</a>
        <?php endif; ?>
      </form>

      <div style="overflow-x:auto;">
        <table style="min-width:860px;">
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Email / Phone</th>
              <th>Location</th>
              <th>Condition</th>
              <th>Watts</th>
              <th>Budget</th>
              <th>Submitted</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$inquiries): ?>
              <tr><td colspan="9" class="muted">No inquiries found.</td></tr>
            <?php endif; ?>
            <?php foreach ($inquiries as $inq): ?>
              <tr>
                <td class="muted"><?= (int)$inq['id'] ?></td>
                <td>
                  <strong><?= h($inq['first_name']) ?> <?= h($inq['last_name']) ?></strong>
                </td>
                <td>
                  <?= h($inq['email']) ?><br>
                  <span class="muted"><?= h($inq['cell_phone']) ?></span>
                </td>
                <td class="muted"><?= h($inq['city']) ?>, <?= h($inq['state']) ?> <?= h($inq['zip_code']) ?></td>
                <td>
                  <?php $cond = $inq['machine_condition']; ?>
                  <span class="badge cond-<?= h($cond) ?>">
                    <?= $cond === 'new' ? '🆕 New' : ($cond === 'used' ? '♻️ Used' : '🤷 Either') ?>
                  </span>
                </td>
                <td class="muted"><?= h($inq['desired_watts'] ?: '—') ?></td>
                <td class="muted" style="white-space:nowrap;"><?= h($inq['budget'] ?: '—') ?></td>
                <td class="muted" style="white-space:nowrap;"><?= h(substr($inq['created_at'], 0, 16)) ?></td>
                <td>
                  <button type="button" class="btn"
                          onclick="openDetail(<?= (int)$inq['id'] ?>)"
                          style="margin-bottom:4px;">View</button>
                  <?php if (is_admin()): ?>
                  <form method="post" style="display:inline;" action="machine_inquiry_admin.php?section=inquiries"
                        onsubmit="return confirm('Delete this inquiry? This cannot be undone.')">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mia_csrf']) ?>" />
                    <input type="hidden" name="action" value="delete_inquiry" />
                    <input type="hidden" name="inquiry_id" value="<?= (int)$inq['id'] ?>" />
                    <button type="submit" class="btn danger">Delete</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <div class="pagination" style="margin-top:14px; display:flex; gap:8px; align-items:center;">
        <?php if ($page > 1): ?>
          <a class="btn" href="?section=inquiries&page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&condition=<?= urlencode($condition_filter) ?>">← Prev</a>
        <?php endif; ?>
        <span class="muted">Page <?= $page ?> of <?= $total_pages ?></span>
        <?php if ($page < $total_pages): ?>
          <a class="btn" href="?section=inquiries&page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&condition=<?= urlencode($condition_filter) ?>">Next →</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Detail Modals ── -->
    <?php foreach ($inquiries as $inq): ?>
    <div class="mia-detail-modal" id="detail-<?= (int)$inq['id'] ?>" role="dialog" aria-modal="true">
      <div class="mia-detail-box">
        <button class="mia-detail-close" onclick="closeDetail(<?= (int)$inq['id'] ?>)" aria-label="Close">✕</button>
        <h2 style="margin:0 30px 0 0;">
          <?= h($inq['first_name']) ?> <?= h($inq['last_name']) ?>
          <span class="badge cond-<?= h($inq['machine_condition']) ?>" style="font-size:13px; margin-left:8px;">
            <?= $inq['machine_condition'] === 'new' ? '🆕 New' : ($inq['machine_condition'] === 'used' ? '♻️ Used' : '🤷 Either') ?>
          </span>
        </h2>
        <p class="muted" style="margin:4px 0 0;">Submitted: <?= h($inq['created_at']) ?></p>

        <div class="mia-dl">
          <div class="mia-dl-item"><label>Email</label><p><?= h($inq['email']) ?></p></div>
          <div class="mia-dl-item"><label>Phone</label><p><?= h($inq['cell_phone']) ?></p></div>
          <div class="mia-dl-item"><label>Location</label><p><?= h($inq['city']) ?>, <?= h($inq['state']) ?> <?= h($inq['zip_code']) ?></p></div>
          <div class="mia-dl-item"><label>Laser Type</label><p><?= h($inq['laser_type'] ?: '—') ?></p></div>
          <div class="mia-dl-item"><label>Desired Wattage</label><p><?= h($inq['desired_watts'] ?: '—') ?></p></div>
          <div class="mia-dl-item"><label>Work Area</label><p><?= h($inq['work_area'] ?: '—') ?></p></div>
          <div class="mia-dl-item"><label>Budget</label><p><?= h($inq['budget'] ?: '—') ?></p></div>
          <div class="mia-dl-item"><label>Timeline</label><p><?= h($inq['timeline'] ?: '—') ?></p></div>
          <div class="mia-dl-item"><label>Owns a Laser?</label><p><?= $inq['current_machine'] ? 'Yes' : 'No' ?><?= $inq['current_machine_brand'] ? ' — ' . h($inq['current_machine_brand']) : '' ?></p></div>
          <div class="mia-dl-item"><label>Heard About Us</label><p><?= h($inq['heard_about_us'] ?: '—') ?></p></div>
          <div class="mia-dl-item full"><label>Desired Features</label><p><?= fmt_feature_list((string)$inq['features_wanted'], $feature_labels) ?></p></div>
          <div class="mia-dl-item full"><label>Intended Use</label><p style="white-space:pre-wrap;"><?= h($inq['intended_use'] ?: '—') ?></p></div>
          <?php if (trim((string)$inq['additional_notes']) !== ''): ?>
          <div class="mia-dl-item full"><label>Additional Notes</label><p style="white-space:pre-wrap;"><?= h($inq['additional_notes']) ?></p></div>
          <?php endif; ?>
        </div>

        <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
          <?php if (is_admin()): ?>
          <form method="post" action="machine_inquiry_admin.php?section=inquiries"
                onsubmit="return confirm('Delete this inquiry?')">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mia_csrf']) ?>" />
            <input type="hidden" name="action" value="delete_inquiry" />
            <input type="hidden" name="inquiry_id" value="<?= (int)$inq['id'] ?>" />
            <button type="submit" class="btn danger">Delete</button>
          </form>
          <?php endif; ?>
          <button type="button" class="btn" onclick="closeDetail(<?= (int)$inq['id'] ?>)">Close</button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <script>
    function openDetail(id) {
      document.getElementById('detail-' + id).classList.add('open');
      document.body.style.overflow = 'hidden';
    }
    function closeDetail(id) {
      document.getElementById('detail-' + id).classList.remove('open');
      document.body.style.overflow = '';
    }
    document.querySelectorAll('.mia-detail-modal').forEach(function(m) {
      m.addEventListener('click', function(e) {
        if (e.target === m) closeDetail(parseInt(m.id.replace('detail-', '')));
      });
    });
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.mia-detail-modal.open').forEach(function(m) {
          m.classList.remove('open');
          document.body.style.overflow = '';
        });
      }
    });
    </script>

    <?php endif; // end section check ?>

  </div><!-- /.admin-right -->
</div><!-- /.mia-layout -->

<?php render_footer(); ?>
