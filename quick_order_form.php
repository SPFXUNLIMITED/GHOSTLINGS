<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

const MAX_NOTES_LENGTH = 10000;
const INQUIRY_STATUS_OPTIONS = [
  'pending'  => 'Pending',
  'urgent'   => 'Urgent',
  'critical' => 'Critical',
  'ordered'  => 'Ordered',
];
const INQUIRY_STATUS_BADGES = [
  'pending'  => ['#fef9c3', '#854d0e'],
  'urgent'   => ['#ffedd5', '#9a3412'],
  'critical' => ['#fee2e2', '#991b1b'],
  'ordered'  => ['#dcfce7', '#166534'],
];
const INQUIRY_TABLE_COLUMN_COUNT = 7;

function quick_order_status_redirect_url(): string {
  return 'quick_order_form.php?view=all&status_updated=1';
}

function quick_order_notes_preview(?string $notes, int $max_length = 120): string {
  $notes = trim((string)$notes);
  if ($notes === '') {
    return '—';
  }
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    return mb_strlen($notes) > $max_length
      ? mb_substr($notes, 0, $max_length - 1) . '…'
      : $notes;
  }
  return strlen($notes) > $max_length
    ? substr($notes, 0, $max_length - 1) . '…'
    : $notes;
}

if (empty($_SESSION['quick_order_csrf'])) {
  $_SESSION['quick_order_csrf'] = bin2hex(random_bytes(24));
}

$today = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Y-m-d');
$errors = [];
$fields = [
  'customer_name' => '',
  'company_name' => '',
  'phone_number' => '',
  'email' => '',
  'inquiry_date' => $today,
  'notes' => '',
];

$view = (string)($_GET['view'] ?? '');
$show_all = $view === 'all';
$detail_id = $view === 'id' ? (int)($_GET['id'] ?? 0) : 0;
$show_detail = $detail_id > 0;
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';
$updated = isset($_GET['updated']) && $_GET['updated'] === '1';
$status_updated = isset($_GET['status_updated']) && $_GET['status_updated'] === '1';
$deleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';

// Load existing record when editing
$edit_id = null;
$edit_record = null;
$raw_edit = $_GET['edit'] ?? $_POST['edit_id'] ?? null;
if ($raw_edit !== null && (int)$raw_edit > 0) {
  $edit_id = (int)$raw_edit;
  $stmt = $pdo->prepare("SELECT * FROM customer_phone_inquiries WHERE id = ?");
  $stmt->execute([$edit_id]);
  $edit_record = $stmt->fetch();
  if (!$edit_record) {
    $edit_id = null; // record not found, treat as new
  }
}

// Pre-fill fields when loading edit form via GET
if ($edit_record && $_SERVER['REQUEST_METHOD'] === 'GET') {
  $fields['customer_name'] = (string)($edit_record['customer_name'] ?? '');
  $fields['company_name']  = (string)($edit_record['company_name'] ?? '');
  $fields['phone_number']  = (string)($edit_record['phone_number'] ?? '');
  $fields['email']         = (string)($edit_record['email'] ?? '');
  $fields['inquiry_date']  = (string)($edit_record['inquiry_date'] ?? $today);
  $fields['notes']         = (string)($edit_record['notes'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $post_action = (string)($_POST['action'] ?? 'save');
  $should_process_form_save = $post_action === 'save';
  $post_row_id = (int)($_POST['row_id'] ?? 0);
  $show_all = $post_action === 'status' || $post_action === 'delete';
  $saved = false;
  $updated = false;
  $status_updated = false;
  $deleted = false;
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['quick_order_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    if ($post_action === 'status') {
      $row_id = $post_row_id;
      $next_status = (string)($_POST['status'] ?? '');
      if ($row_id <= 0 || !isset(INQUIRY_STATUS_OPTIONS[$next_status])) {
        $errors[] = 'Invalid status update request.';
      } else {
        $upd = $pdo->prepare("UPDATE customer_phone_inquiries SET status = ? WHERE id = ?");
        $upd->execute([$next_status, $row_id]);
        $_SESSION['quick_order_csrf'] = bin2hex(random_bytes(24));
        header('Location: ' . quick_order_status_redirect_url());
        exit;
      }
    } elseif ($post_action === 'delete') {
      $row_id = (int)($_POST['row_id'] ?? 0);
      if ($row_id <= 0) {
        $errors[] = 'Invalid delete request.';
      } else {
        $del = $pdo->prepare("DELETE FROM customer_phone_inquiries WHERE id = ?");
        $del->execute([$row_id]);
        $_SESSION['quick_order_csrf'] = bin2hex(random_bytes(24));
        header('Location: quick_order_form.php?view=all&deleted=1');
        exit;
      }
    }

    if ($should_process_form_save) {
     foreach (array_keys($fields) as $key) {
       $fields[$key] = trim((string)($_POST[$key] ?? ''));
     }

     if ($fields['customer_name'] === '') {
       $errors[] = 'Customer Name is required.';
     } elseif (strlen($fields['customer_name']) > 255) {
       $errors[] = 'Customer Name must be 255 characters or fewer.';
     }

     if ($fields['company_name'] !== '' && strlen($fields['company_name']) > 255) {
       $errors[] = 'Company Name must be 255 characters or fewer.';
     }
     if ($fields['phone_number'] !== '' && strlen($fields['phone_number']) > 50) {
       $errors[] = 'Phone Number must be 50 characters or fewer.';
     }
     if ($fields['email'] !== '' && strlen($fields['email']) > 255) {
       $errors[] = 'Email must be 255 characters or fewer.';
     }
     if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
       $errors[] = 'Please enter a valid email address.';
     }
     if ($fields['notes'] !== '' && strlen($fields['notes']) > MAX_NOTES_LENGTH) {
       $errors[] = 'Notes must be ' . MAX_NOTES_LENGTH . ' characters or fewer.';
     }

    if (!$errors) {
      if ($edit_id !== null) {
        // Update existing record
        $upd = $pdo->prepare(
          "UPDATE customer_phone_inquiries SET
             customer_name = ?, company_name = ?, phone_number = ?,
             email = ?, inquiry_date = ?, notes = ?
           WHERE id = ?"
        );
        $upd->execute([
          $fields['customer_name'],
          $fields['company_name'] !== '' ? $fields['company_name'] : null,
          $fields['phone_number'] !== '' ? $fields['phone_number'] : null,
          $fields['email'] !== '' ? $fields['email'] : null,
          $fields['inquiry_date'],
          $fields['notes'] !== '' ? $fields['notes'] : null,
          $edit_id,
        ]);
        try {
          $actor_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
          if ($actor_id !== null && $actor_id <= 0) {
            $actor_id = null;
          }
          $actor_name = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : '';
          $detail = 'Quick Order #' . (int)$edit_id . ' updated for ' . $fields['customer_name'];
          if ($fields['company_name'] !== '') {
            $detail .= ' (' . $fields['company_name'] . ')';
          }
          log_admin_activity($pdo, $actor_id, 'Quick Order Updated', $detail, $actor_name);
        } catch (Throwable $e) {
          // Non-blocking audit log write.
        }
        $_SESSION['quick_order_csrf'] = bin2hex(random_bytes(24));
        header('Location: quick_order_form.php?view=id&id=' . $edit_id . '&updated=1');
        exit;
      } else {
        // Insert new record
        $created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        if ($created_by !== null && $created_by <= 0) {
          $created_by = null;
        }

        $ins = $pdo->prepare(
          "INSERT INTO customer_phone_inquiries
             (customer_name, company_name, phone_number, email, inquiry_date, notes, created_by)
           VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([
          $fields['customer_name'],
          $fields['company_name'] !== '' ? $fields['company_name'] : null,
          $fields['phone_number'] !== '' ? $fields['phone_number'] : null,
          $fields['email'] !== '' ? $fields['email'] : null,
          $fields['inquiry_date'],
          $fields['notes'] !== '' ? $fields['notes'] : null,
          $created_by,
        ]);
        $new_id = (int)$pdo->lastInsertId();
        try {
          $actor_name = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : '';
         if ($new_id > 0) {
           $detail = 'Quick Order #' . $new_id . ' created for ' . $fields['customer_name'];
           if ($fields['company_name'] !== '') {
             $detail .= ' (' . $fields['company_name'] . ')';
           }
           log_admin_activity($pdo, $created_by, 'Quick Order Created', $detail, $actor_name);
         }
       } catch (Throwable $e) {
         // Non-blocking audit log write.
       }
       $_SESSION['quick_order_csrf'] = bin2hex(random_bytes(24));
       header('Location: quick_order_form.php?view=id&id=' . $new_id . '&saved=1');
       exit;
       }
     }
    }
  }
}

$inquiries = [];
if ($show_all) {
  $stmt = $pdo->query(
    "SELECT cpi.*, u.username AS created_by_username
     FROM customer_phone_inquiries cpi
     LEFT JOIN users u ON u.id = cpi.created_by
    ORDER BY FIELD(cpi.status, 'pending', 'urgent', 'critical', 'ordered'), cpi.inquiry_date DESC, cpi.id DESC
     LIMIT 200"
  );
  $inquiries = $stmt->fetchAll();
}
$detail_inquiry = null;
if ($show_detail) {
  $stmt = $pdo->prepare(
    "SELECT cpi.*, u.username AS created_by_username
     FROM customer_phone_inquiries cpi
     LEFT JOIN users u ON u.id = cpi.created_by
     WHERE cpi.id = ?
     LIMIT 1"
  );
  $stmt->execute([$detail_id]);
  $detail_inquiry = $stmt->fetch();
  if (!$detail_inquiry) {
    http_response_code(404);
    render_header('Quick Order Not Found');
    ?>
    <div class="card">
      <h1 style="margin-top:0;">Quick Order Not Found</h1>
      <p class="muted">We couldn’t find that quick order record.</p>
      <div class="actions">
        <a class="btn" href="quick_order_form.php?view=all">Back to All Quick Orders</a>
        <a class="btn primary" href="quick_order_form.php">New Quick Order</a>
      </div>
    </div>
    <?php
    render_footer();
    exit;
  }
}
render_header('Quick Order List');
?>

<div class="card">
  <h1 style="margin:0;">Quick Order List</h1>
  <p class="muted" style="margin:6px 0 0;">Log quick orders with contact details and notes.</p>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($show_detail): ?>
  <?php if ($saved): ?>
    <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; margin-bottom:14px;">
      Quick order saved successfully.
    </div>
  <?php endif; ?>
  <?php if ($updated): ?>
    <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; margin-bottom:14px;">
      Quick order updated successfully.
    </div>
  <?php endif; ?>
  <?php if ($status_updated): ?>
    <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; margin-bottom:14px;">
      Quick order status updated successfully.
    </div>
  <?php endif; ?>
  <?php
    $detail_status = (string)($detail_inquiry['status'] ?? 'pending');
    [$detail_badge_bg, $detail_badge_color] = INQUIRY_STATUS_BADGES[$detail_status] ?? ['#fef9c3', '#854d0e'];
    $detail_created_at_text = !empty($detail_inquiry['created_at']) ? ' • Created ' . (string)$detail_inquiry['created_at'] : '';
  ?>
  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
      <div>
        <h2 style="margin:0;">Quick Order #<?= (int)$detail_inquiry['id'] ?> — <?= h($detail_inquiry['customer_name']) ?></h2>
        <p class="muted" style="margin:6px 0 0;">Logged on <?= h($detail_inquiry['inquiry_date']) ?><?= h($detail_created_at_text) ?></p>
      </div>
      <div class="actions">
        <a class="btn" href="quick_order_form.php?view=all">Back to All Quick Orders</a>
        <a class="btn primary" href="quick_order_form.php?edit=<?= (int)$detail_inquiry['id'] ?>">Edit Quick Order</a>
      </div>
    </div>
  </div>

  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
      <h3 style="margin:0;">Quick Order Details</h3>
      <span style="display:inline-flex; align-items:center; border-radius:999px; padding:6px 12px; font-weight:600; background:<?= h($detail_badge_bg) ?>; color:<?= h($detail_badge_color) ?>;">
        <?= h(INQUIRY_STATUS_OPTIONS[$detail_status] ?? 'Pending') ?>
      </span>
    </div>
    <table>
      <tbody>
        <tr>
          <th style="width:220px;">Customer Name</th>
          <td><?= h($detail_inquiry['customer_name']) ?></td>
        </tr>
        <tr>
          <th>Company Name</th>
          <td><?= h($detail_inquiry['company_name'] ?: '—') ?></td>
        </tr>
        <tr>
          <th>Phone Number</th>
          <td><?= h($detail_inquiry['phone_number'] ?: '—') ?></td>
        </tr>
        <tr>
          <th>Email</th>
          <td><?= h($detail_inquiry['email'] ?: '—') ?></td>
        </tr>
        <tr>
          <th>Date of Inquiry</th>
          <td><?= h($detail_inquiry['inquiry_date']) ?></td>
        </tr>
        <tr>
          <th>Logged By</th>
          <td><?= h($detail_inquiry['created_by_username'] ?: '—') ?></td>
        </tr>
        <tr>
          <th>Created At</th>
          <td><?= h($detail_inquiry['created_at'] ?: '—') ?></td>
        </tr>
        <tr>
          <th>Notes / What they want</th>
          <td style="white-space:pre-wrap;"><?= h($detail_inquiry['notes'] ?: '—') ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="card">
    <div style="display:flex; justify-content:flex-end; align-items:flex-start; gap:14px; flex-wrap:wrap;">
      <div style="flex:0 0 auto;">
        <h3 style="margin:0 0 12px;">Delete Quick Order</h3>
        <form method="post" onsubmit="return confirm('Delete this inquiry? This cannot be undone.');">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quick_order_csrf']) ?>" />
          <input type="hidden" name="action" value="delete" />
          <input type="hidden" name="row_id" value="<?= (int)$detail_inquiry['id'] ?>" />
          <button type="submit" class="btn" aria-label="Delete quick order for <?= h($detail_inquiry['customer_name']) ?>" style="background:#fee2e2; border-color:#fecaca; color:#991b1b;">Delete Quick Order</button>
        </form>
      </div>
    </div>
  </div>
<?php elseif ($show_all): ?>
  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
      <h2 style="margin:0;">All Quick Orders</h2>
      <a class="btn primary" href="quick_order_form.php">New Quick Order</a>
    </div>
    <?php if ($updated): ?>
      <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; margin-bottom:14px;">
        Quick order updated successfully.
      </div>
    <?php endif; ?>
    <?php if ($status_updated): ?>
      <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; margin-bottom:14px;">
        Quick order status updated successfully.
      </div>
    <?php endif; ?>
    <?php if ($deleted): ?>
      <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; margin-bottom:14px;">
        Quick order deleted successfully.
      </div>
    <?php endif; ?>
    <div style="overflow-x:auto;">
      <table style="min-width:760px;">
        <thead>
          <tr>
            <th>Date</th>
            <th>Status</th>
            <th>Customer Name</th>
            <th>Phone Number</th>
            <th>Notes / What they want</th>
            <th>Logged By</th>
            <th>View</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$inquiries): ?>
            <tr><td colspan="<?= INQUIRY_TABLE_COLUMN_COUNT ?>" class="muted">No quick orders logged yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($inquiries as $inquiry): ?>
            <?php $status_key = (string)($inquiry['status'] ?? 'new'); ?>
            <tr>
              <td style="white-space:nowrap;"><?= h($inquiry['inquiry_date']) ?></td>
              <td style="white-space:nowrap;">
                <?php
                  [$sb_bg, $sb_color] = INQUIRY_STATUS_BADGES[$status_key] ?? ['#fef9c3', '#854d0e'];
                ?>
                <form method="post" style="display:flex; gap:8px; align-items:center; margin:0;">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quick_order_csrf']) ?>" />
                  <input type="hidden" name="action" value="status" />
                  <input type="hidden" name="row_id" value="<?= (int)$inquiry['id'] ?>" />
                  <span class="qo-status-dot" style="display:inline-block; width:12px; height:12px; border-radius:50%; flex-shrink:0; background:<?= h($sb_color) ?>;"></span>
                  <select name="status" aria-label="Status for inquiry dated <?= h($inquiry['inquiry_date']) ?>" style="min-width:130px; font-weight:600; background:<?= h($sb_bg) ?>; color:<?= h($sb_color) ?>; border-color:<?= h($sb_color) ?>;" onchange="qoSyncStatus(this)">
                    <?php foreach (INQUIRY_STATUS_OPTIONS as $option_key => $option_label): ?>
                      <option value="<?= h($option_key) ?>" <?= ($status_key === $option_key) ? 'selected' : '' ?>><?= h($option_label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn">Save</button>
                </form>
              </td>
              <td><?= h($inquiry['customer_name']) ?></td>
              <td><?= h($inquiry['phone_number'] ?: '—') ?></td>
              <td style="min-width:240px; white-space:normal;"><?= h(quick_order_notes_preview($inquiry['notes'] ?? null)) ?></td>
              <td><?= h($inquiry['created_by_username'] ?: '—') ?></td>
              <td style="white-space:nowrap;">
                <a class="btn" href="quick_order_form.php?view=id&id=<?= (int)$inquiry['id'] ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <form method="post" class="card" style="max-width:960px;">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quick_order_csrf']) ?>" />
    <?php if ($edit_id !== null): ?>
      <input type="hidden" name="edit_id" value="<?= $edit_id ?>" />
      <h2 style="margin:0 0 14px;">Edit Quick Order</h2>
    <?php endif; ?>
    <div class="form-grid">
      <div>
        <label for="customer_name">Customer Name <span style="color:var(--d)">*</span></label>
        <input id="customer_name" type="text" name="customer_name" maxlength="255" required value="<?= h($fields['customer_name']) ?>" />
      </div>
      <div>
        <label for="company_name">Company Name</label>
        <input id="company_name" type="text" name="company_name" maxlength="255" value="<?= h($fields['company_name']) ?>" />
      </div>
      <div>
        <label for="phone_number">Phone Number</label>
        <input id="phone_number" type="text" name="phone_number" maxlength="50" value="<?= h($fields['phone_number']) ?>" />
      </div>
      <div>
        <label for="email">Email</label>
        <input id="email" type="email" name="email" maxlength="255" value="<?= h($fields['email']) ?>" />
      </div>
      <div>
        <label for="inquiry_date">Date of Inquiry</label>
        <input id="inquiry_date" type="date" name="inquiry_date" value="<?= h($fields['inquiry_date']) ?>" />
      </div>
      <div class="full">
        <label for="notes">Notes / What they want</label>
        <textarea id="notes" name="notes" rows="6" maxlength="<?= MAX_NOTES_LENGTH ?>"><?= h($fields['notes']) ?></textarea>
      </div>
    </div>
    <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
      <button type="submit" class="btn primary" style="font-size:18px; padding:14px 22px;"><?= $edit_id !== null ? 'Update Quick Order' : 'Save Quick Order' ?></button>
      <a class="btn" href="<?= $edit_id !== null ? 'quick_order_form.php?view=id&id=' . (int)$edit_id : 'quick_order_form.php?view=all' ?>"><?= $edit_id !== null ? 'Back to Quick Order' : 'View All Quick Orders' ?></a>
    </div>
  </form>
<?php endif; ?>

<?php if ($show_all): ?>
<script>
var QO_STATUS_COLORS = <?= json_encode(array_map(fn($v) => ['bg' => $v[0], 'fg' => $v[1]], INQUIRY_STATUS_BADGES), JSON_UNESCAPED_UNICODE) ?>;
function qoSyncStatus(sel) {
  var c = QO_STATUS_COLORS[sel.value];
  if (!c) return;
  sel.style.background   = c.bg;
  sel.style.color        = c.fg;
  sel.style.borderColor  = c.fg;
  var dot = sel.parentElement.querySelector('.qo-status-dot');
  if (dot) dot.style.background = c.fg;
}
</script>
<?php endif; ?>
<?php render_footer(); ?>
