<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const CP_TABLE_COLUMNS = 8;

if (empty($_SESSION['cp_csrf'])) {
  $_SESSION['cp_csrf'] = bin2hex(random_bytes(24));
}

function cp_escape_like(string $s): string {
  return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

function cp_payment_method_label(string $method): string {
  return match ($method) {
    'check'       => 'Check',
    'cash'        => 'Cash',
    'ach_wire'    => 'ACH / Wire',
    'credit_card' => 'Credit Card',
    default       => 'Other',
  };
}

function cp_format_money($value): string {
  return number_format((float)$value, 2);
}

function cp_find_matching_customer(PDO $pdo, string $customer_name): ?array {
  $customer_name = trim($customer_name);
  if ($customer_name === '') {
    return null;
  }

  $stmt = $pdo->prepare(
    "SELECT id,
            COALESCE(
              NULLIF(TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(last_name,''))), ''),
              NULLIF(company, ''),
              NULLIF(email, ''),
              ''
            ) AS customer_name,
            company
     FROM customers
     WHERE TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(last_name,''))) = ?
        OR company = ?
     ORDER BY id DESC
     LIMIT 2"
  );
  $stmt->execute([$customer_name, $customer_name]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  return count($rows) === 1 ? $rows[0] : null;
}

// ── Customer search AJAX ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['customer_search'])) {
  header('Content-Type: application/json; charset=utf-8');
  $csrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  if ($csrf === '' || !hash_equals((string)$_SESSION['cp_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode([]);
    exit;
  }
  $query = trim((string)($_GET['q'] ?? ''));
  if ($query === '') {
    echo json_encode([]);
    exit;
  }
  $like = '%' . cp_escape_like($query) . '%';
  $stmt = $pdo->prepare(
    "SELECT id,
       COALESCE(
         NULLIF(TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(last_name,''))), ''),
         NULLIF(company, ''),
         NULLIF(email, ''),
         ''
       ) AS customer_name,
       company, email, phone
     FROM customers
     WHERE first_name LIKE ? ESCAPE '\\\\'
        OR last_name LIKE ? ESCAPE '\\\\'
        OR CONCAT_WS(' ', first_name, last_name) LIKE ? ESCAPE '\\\\'
        OR company LIKE ? ESCAPE '\\\\'
        OR email LIKE ? ESCAPE '\\\\'
        OR phone LIKE ? ESCAPE '\\\\'
     ORDER BY customer_name ASC, id DESC
     LIMIT 8"
  );
  $stmt->execute([$like, $like, $like, $like, $like, $like]);
  echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
  exit;
}

$errors = [];

// ── POST handlers ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['cp_csrf']) || !hash_equals((string)$_SESSION['cp_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $_SESSION['cp_csrf'] = bin2hex(random_bytes(24));
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add_payment' || $action === 'edit_payment') {
      $customer_id    = (int)($_POST['customer_id'] ?? 0);
      $bank_transaction_id = (int)($_POST['bank_transaction_id'] ?? 0);
      $payment_date   = trim((string)($_POST['payment_date'] ?? ''));
      $amount_raw     = trim((string)($_POST['amount'] ?? ''));
      $payment_method = trim((string)($_POST['payment_method'] ?? ''));
      $reference_no   = trim((string)($_POST['reference_no'] ?? ''));
      $notes          = trim((string)($_POST['notes'] ?? ''));

      $amount = (float)str_replace(',', '', $amount_raw);
      $valid_methods = ['check', 'cash', 'ach_wire', 'credit_card', 'other'];
      if (!in_array($payment_method, $valid_methods, true)) {
        $payment_method = 'other';
      }

      if ($customer_id <= 0) {
        $errors[] = 'Please select a valid customer.';
      } else {
        $chk = $pdo->prepare("SELECT id FROM customers WHERE id = ? LIMIT 1");
        $chk->execute([$customer_id]);
        if (!$chk->fetch()) {
          $errors[] = 'Customer not found.';
        }
      }
      if ($payment_date === '') {
        $errors[] = 'Payment date is required.';
      }
      if ($amount <= 0) {
        $errors[] = 'Amount must be greater than zero.';
      }
      if ($bank_transaction_id > 0) {
        $bank_tx_stmt = $pdo->prepare("SELECT id, linked_payment_id FROM bank_transactions WHERE id = ? LIMIT 1");
        $bank_tx_stmt->execute([$bank_transaction_id]);
        $bank_tx_row = $bank_tx_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bank_tx_row) {
          $errors[] = 'Imported bank transaction not found.';
        } elseif ($action === 'add_payment' && (int)($bank_tx_row['linked_payment_id'] ?? 0) > 0) {
          $errors[] = 'This imported bank transaction is already linked to customer payment #' . (int)$bank_tx_row['linked_payment_id'] . '.';
        }
      } else {
        $bank_tx_row = null;
      }

      if (empty($errors)) {
        $created_by = ((int)($_SESSION['user_id'] ?? 0)) ?: null;
        $ref        = $reference_no !== '' ? $reference_no : null;
        $note       = $notes !== '' ? $notes : null;

        if ($action === 'add_payment') {
          $pdo->prepare(
            "INSERT INTO customer_payments (customer_id, payment_date, amount, payment_method, reference_no, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
          )->execute([$customer_id, $payment_date, $amount, $payment_method, $ref, $note, $created_by]);
          $payment_id = (int)$pdo->lastInsertId();
          if ($bank_transaction_id > 0 && $payment_id > 0) {
            $pdo->prepare(
              "UPDATE bank_transactions
               SET linked_payment_id = ?, matched_customer_id = ?, match_status = 'matched'
               WHERE id = ?"
            )->execute([$payment_id, $customer_id, $bank_transaction_id]);
          }
          header('Location: customer_payments.php?saved=added');
          exit;
        } else {
          $payment_id = (int)($_POST['payment_id'] ?? 0);
          if ($payment_id <= 0) {
            $errors[] = 'Invalid payment.';
          } else {
            $pdo->prepare(
              "UPDATE customer_payments
               SET customer_id=?, payment_date=?, amount=?, payment_method=?, reference_no=?, notes=?
               WHERE id=?"
            )->execute([$customer_id, $payment_date, $amount, $payment_method, $ref, $note, $payment_id]);
            $linked_bank_tx_id = $bank_transaction_id;
            if ($linked_bank_tx_id <= 0) {
              $linked_stmt = $pdo->prepare("SELECT id FROM bank_transactions WHERE linked_payment_id = ? LIMIT 1");
              $linked_stmt->execute([$payment_id]);
              $linked_bank_tx_id = (int)($linked_stmt->fetchColumn() ?: 0);
            }
            if ($linked_bank_tx_id > 0) {
              $pdo->prepare(
                "UPDATE bank_transactions
                 SET matched_customer_id = ?, match_status = 'matched'
                 WHERE id = ?"
              )->execute([$customer_id, $linked_bank_tx_id]);
            }
            header('Location: customer_payments.php?saved=updated');
            exit;
          }
        }
      }

    } elseif ($action === 'delete_payment') {
      $payment_id = (int)($_POST['payment_id'] ?? 0);
      if ($payment_id <= 0) {
        $errors[] = 'Invalid payment.';
      } else {
        $linked_stmt = $pdo->prepare("SELECT id FROM bank_transactions WHERE linked_payment_id = ? LIMIT 1");
        $linked_stmt->execute([$payment_id]);
        $linked_bank_tx_id = (int)($linked_stmt->fetchColumn() ?: 0);
        $pdo->prepare("DELETE FROM customer_payments WHERE id = ?")->execute([$payment_id]);
        if ($linked_bank_tx_id > 0) {
          $pdo->prepare(
            "UPDATE bank_transactions
             SET linked_payment_id = NULL, matched_customer_id = NULL, matched_invoice_id = NULL, match_status = 'unmatched'
             WHERE id = ?"
          )->execute([$linked_bank_tx_id]);
        }
        header('Location: customer_payments.php?deleted=1');
        exit;
      }
    }
  }
}

// ── Search / filter ─────────────────────────────────────────────────────────
$search        = trim((string)($_GET['q'] ?? ''));
$method_filter = trim((string)($_GET['method'] ?? ''));
$payment_id_filter = (int)($_GET['payment_id'] ?? 0);

$payment_methods = [
  'check'       => 'Check',
  'cash'        => 'Cash',
  'ach_wire'    => 'ACH / Wire',
  'credit_card' => 'Credit Card',
  'other'       => 'Other',
];

$bank_import_prefill = null;
$bank_import_notice = '';
$import_bank_transaction_id = (int)($_GET['bank_transaction_id'] ?? 0);
if ($import_bank_transaction_id > 0) {
  $prefill_stmt = $pdo->prepare(
    "SELECT bt.id, bt.transaction_date, bt.description, bt.amount, bt.source, bt.reference, bt.customer_name,
            bt.linked_payment_id,
            cp.id AS payment_id
     FROM bank_transactions bt
     LEFT JOIN customer_payments cp ON cp.id = bt.linked_payment_id
     WHERE bt.id = ?
     LIMIT 1"
  );
  $prefill_stmt->execute([$import_bank_transaction_id]);
  $prefill_row = $prefill_stmt->fetch(PDO::FETCH_ASSOC);
  if (!$prefill_row) {
    $errors[] = 'Imported bank transaction not found.';
  } elseif ((int)($prefill_row['linked_payment_id'] ?? 0) > 0) {
    $bank_import_notice = 'This imported bank transaction is already linked to payment #' . (int)$prefill_row['linked_payment_id'] . '.';
  } else {
    $matched_customer = cp_find_matching_customer($pdo, (string)($prefill_row['customer_name'] ?? ''));
    $bank_import_prefill = [
      'bank_transaction_id' => (int)$prefill_row['id'],
      'customer_id' => (int)($matched_customer['id'] ?? 0),
      'customer_name' => $matched_customer
        ? trim((string)($matched_customer['customer_name'] ?? ''))
        : trim((string)($prefill_row['customer_name'] ?? '')),
      'payment_date' => (string)($prefill_row['transaction_date'] ?? ''),
      'amount' => number_format(abs((float)($prefill_row['amount'] ?? 0)), 2, '.', ''),
      'payment_method' => bank_tx_suggest_payment_method((string)($prefill_row['source'] ?? ''), (string)($prefill_row['description'] ?? '')),
      'reference_no' => trim((string)($prefill_row['reference'] ?? '')),
      'notes' => trim((string)($prefill_row['description'] ?? '')),
    ];
  }
}

$where_parts = ['1=1'];
$params      = [];

if ($search !== '') {
  $like = '%' . cp_escape_like($search) . '%';
  $where_parts[] = "(TRIM(CONCAT_WS(' ', NULLIF(c.first_name,''), NULLIF(c.last_name,''))) LIKE :q
                    OR c.company LIKE :q
                    OR cp.reference_no LIKE :q
                    OR c.email LIKE :q)";
  $params[':q'] = $like;
}
if ($method_filter !== '' && isset($payment_methods[$method_filter])) {
  $where_parts[] = 'cp.payment_method = :method';
  $params[':method'] = $method_filter;
}
if ($payment_id_filter > 0) {
  $where_parts[] = 'cp.id = :payment_id';
  $params[':payment_id'] = $payment_id_filter;
}

$list_stmt = $pdo->prepare(
  "SELECT cp.id, cp.customer_id, cp.payment_date, cp.amount, cp.payment_method,
          cp.reference_no, cp.notes, cp.created_at, bt.id AS bank_transaction_id,
          COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ', NULLIF(c.first_name,''), NULLIF(c.last_name,''))), ''),
            c.company, c.email, 'Unknown'
          ) AS customer_name,
          c.company AS customer_company
   FROM customer_payments cp
   JOIN customers c ON c.id = cp.customer_id
   LEFT JOIN bank_transactions bt ON bt.linked_payment_id = cp.id
   WHERE " . implode(' AND ', $where_parts) . "
   ORDER BY cp.payment_date DESC, cp.id DESC
   LIMIT 300"
);
foreach ($params as $k => $v) {
  $list_stmt->bindValue($k, $v);
}
$list_stmt->execute();
$payments = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

// Collect unique customer IDs in the result set
$all_customer_ids = [];
foreach ($payments as $p) {
  $cid = (int)$p['customer_id'];
  if ($cid > 0) {
    $all_customer_ids[$cid] = $cid;
  }
}
$all_customer_ids = array_values($all_customer_ids);

// Compute per-customer balance in a single query:
// total invoiced (converted invoices) − total payments for each customer.
// The converted-invoice condition matches INVOICE_TRACKER_BASE_FILTER in invoice_tracker.php.
$customer_balances = [];
if ($all_customer_ids) {
  $ph = implode(',', array_fill(0, count($all_customer_ids), '?'));

  $balance_stmt = $pdo->prepare(
    "SELECT cp.customer_id,
            COALESCE(SUM(cp.amount), 0) AS total_paid,
            COALESCE(inv.total_invoiced, 0) AS total_invoiced
     FROM customer_payments cp
     LEFT JOIN (
       SELECT customer_id, SUM(subtotal_amount) AS total_invoiced
       FROM quotes
       WHERE customer_id IN ($ph)
         AND (converted_invoice_no IS NOT NULL AND converted_invoice_no <> ''
              OR status = 'converted')
       GROUP BY customer_id
     ) inv ON inv.customer_id = cp.customer_id
     WHERE cp.customer_id IN ($ph)
     GROUP BY cp.customer_id"
  );
  // Bind the customer ID list twice (once for the subquery, once for the outer WHERE)
  $balance_stmt->execute(array_merge($all_customer_ids, $all_customer_ids));
  foreach ($balance_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cid = (int)$row['customer_id'];
    $customer_balances[$cid] = (float)$row['total_invoiced'] - (float)$row['total_paid'];
  }
}

// Hero stats
$now_dt        = new DateTime('now', new DateTimeZone(APP_TZ));
$current_month = $now_dt->format('Y-m');
$today_ymd     = $now_dt->format('Y-m-d'); // Server-side today for new payment default
$hero_total    = count($payments);
$hero_total_amt = array_sum(array_column($payments, 'amount'));
$hero_this_month = 0;
foreach ($payments as $p) {
  if (substr((string)($p['payment_date'] ?? ''), 0, 7) === $current_month) {
    $hero_this_month++;
  }
}
$hero_customers = count($all_customer_ids);

render_header('Customer Payments');
?>

<?php if (($_GET['saved'] ?? '') === 'added'): ?>
  <div class="alert" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534;">Payment recorded successfully.</div>
<?php elseif (($_GET['saved'] ?? '') === 'updated'): ?>
  <div class="alert" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534;">Payment updated.</div>
<?php endif; ?>
<?php if (($_GET['deleted'] ?? '') === '1'): ?>
  <div class="alert" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;">Payment deleted.</div>
<?php endif; ?>
<?php if ($bank_import_notice !== ''): ?>
  <div class="alert" style="border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8;"><?= h($bank_import_notice) ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
  <div class="alert" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;"><?= h($err) ?></div>
<?php endforeach; ?>

<div class="card laser-rfq-hero page-header">
  <div class="laser-rfq-hero-beams" aria-hidden="true">
    <span class="laser-rfq-beam laser-rfq-beam-1"></span>
    <span class="laser-rfq-beam laser-rfq-beam-2"></span>
    <span class="laser-rfq-beam laser-rfq-beam-3"></span>
  </div>
  <div class="laser-rfq-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body laser-rfq-hero-body">
    <span class="laser-rfq-hero-tag">💰 Payment Operations</span>
    <h1>Customer Payments <span class="laser-rfq-hero-count">(<?= (int)$hero_total ?>)</span></h1>
    <p class="muted">Record and track customer payments, monitor outstanding balances, and keep billing up to date.</p>
    <ul class="laser-rfq-hero-pills" aria-label="Customer payments highlights">
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">📋</span> Payment log</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🔍</span> Customer search</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">💳</span> Multiple methods</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">⚖️</span> Balance tracking</li>
    </ul>
    <div class="laser-rfq-hero-stats" aria-label="Payment summary">
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_total ?></strong>
        <span>Total Payments</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_this_month ?></strong>
        <span>This Month</span>
      </div>
      <?php if (is_admin()): ?>
      <div class="laser-rfq-hero-stat admin-only-stat">
        <strong>$<?= h(cp_format_money($hero_total_amt)) ?></strong>
        <span>Total Received</span>
      </div>
      <?php endif; ?>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_customers ?></strong>
        <span>Customers</span>
      </div>
    </div>
  </div>
  <div class="laser-rfq-hero-actions">
    <button type="button" class="btn primary" id="cp-add-btn">+ Add Payment</button>
  </div>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <div style="flex:1 1 280px;">
      <label for="cp_search">Search Payments</label>
      <input
        id="cp_search"
        type="text"
        name="q"
        value="<?= h($search) ?>"
        placeholder="Search customer name, company, reference #..."
      />
    </div>
    <div style="width:200px;">
      <label for="cp_method">Payment Method</label>
      <select id="cp_method" name="method">
        <option value="">All methods</option>
        <?php foreach ($payment_methods as $mv => $ml): ?>
          <option value="<?= h($mv) ?>" <?= $method_filter === $mv ? 'selected' : '' ?>><?= h($ml) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <button type="submit" class="btn primary">Filter</button>
      <a class="btn" href="customer_payments.php">Clear</a>
    </div>
  </form>
</div>

<style>
.cp-badge {
  display:inline-flex; align-items:center;
  border-radius:999px; padding:3px 10px;
  font-size:12px; font-weight:600; white-space:nowrap;
}
.cp-actions { display:flex; flex-wrap:wrap; gap:4px; }
.cp-actions .btn { font-size:0.78em; padding:3px 8px; white-space:nowrap; }
.cp-actions .btn-danger { background:#fef2f2; color:#991b1b; border-color:#fecaca; }
.cp-actions .btn-danger:hover { background:#fee2e2; }
.cp-balance-pos { color:#991b1b; font-weight:600; }
.cp-balance-zero { color:#166534; font-weight:600; }

/* ── Add / Edit modal ─────────────────────────────────────────────────────── */
#cp-modal {
  position:fixed; inset:0; z-index:9000; display:none;
}
#cp-modal.open { display:block; }
.cp-modal-backdrop {
  position:absolute; inset:0;
  background:rgba(15,23,42,0.72);
  backdrop-filter:blur(4px);
  -webkit-backdrop-filter:blur(4px);
  animation:cp-fade-in .18s ease;
}
@keyframes cp-fade-in { from { opacity:0; } to { opacity:1; } }
.cp-modal-shell {
  position:absolute;
  top:50%; left:50%;
  transform:translate(-50%,-50%);
  width:min(560px, calc(100vw - 32px));
  max-height:calc(100vh - 48px);
  display:flex; flex-direction:column;
  background:#fff;
  border-radius:16px;
  box-shadow:0 32px 80px rgba(0,0,0,.4), 0 0 0 1px rgba(0,0,0,.08);
  animation:cp-slide-up .22s cubic-bezier(.34,1.26,.64,1);
  overflow:hidden;
}
@keyframes cp-slide-up {
  from { opacity:0; transform:translate(-50%,calc(-50% + 24px)); }
  to   { opacity:1; transform:translate(-50%,-50%); }
}
.cp-modal-header {
  padding:20px 24px 14px;
  border-bottom:1px solid #e2e8f0;
  display:flex; align-items:center; gap:12px;
}
.cp-modal-title { font-size:1.15em; font-weight:700; color:#0f172a; margin:0; }
.cp-modal-close {
  margin-left:auto;
  width:30px; height:30px; border:none; border-radius:50%;
  background:#f1f5f9; color:#64748b; font-size:18px; line-height:1;
  cursor:pointer; display:flex; align-items:center; justify-content:center;
  transition:background .15s;
}
.cp-modal-close:hover { background:#e2e8f0; }
.cp-modal-body {
  padding:20px 24px;
  overflow-y:auto;
  flex:1;
}
.cp-modal-footer {
  padding:14px 24px;
  border-top:1px solid #e2e8f0;
  display:flex; justify-content:flex-end; gap:8px;
}

/* Customer search suggestion dropdown */
#cp-customer-suggestions {
  display:none;
  position:absolute; top:100%; left:0; right:0; z-index:50;
  background:#fff; border:1px solid #d1d5db;
  border-radius:10px;
  box-shadow:0 12px 24px rgba(2,6,23,.12);
  margin-top:6px; max-height:220px; overflow:auto;
}
.cp-sugg-btn {
  display:block; width:100%; text-align:left;
  padding:10px 14px; border:none; background:none;
  cursor:pointer; font:inherit; color:#0f172a;
  border-bottom:1px solid #f1f5f9;
}
.cp-sugg-btn:last-child { border-bottom:none; }
.cp-sugg-btn:hover { background:#f8fafc; }
.cp-sugg-main { font-weight:600; display:block; }
.cp-sugg-sub  { font-size:0.82em; color:#64748b; display:block; }
</style>

<div class="card">
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:860px;">
      <thead>
        <tr>
          <th>#</th>
          <th>Customer</th>
          <th>Date</th>
          <th>Amount</th>
          <th>Method</th>
          <th>Reference / Check #</th>
          <th>Customer Balance</th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$payments): ?>
          <tr><td colspan="<?= CP_TABLE_COLUMNS ?>" class="muted">No payments found.
            <?= ($search !== '' || $method_filter !== '') ? '<a href="customer_payments.php">Clear filters</a>' : '' ?>
          </td></tr>
        <?php endif; ?>

        <?php foreach ($payments as $pay): ?>
          <?php
            $pid          = (int)$pay['id'];
            $cid          = (int)$pay['customer_id'];
            $cname        = (string)$pay['customer_name'];
            $ccompany     = trim((string)($pay['customer_company'] ?? ''));
            $pdate        = (string)($pay['payment_date'] ?? '');
            $amount       = (float)$pay['amount'];
            $method       = (string)($pay['payment_method'] ?? '');
            $ref_no       = trim((string)($pay['reference_no'] ?? ''));
            $pay_notes    = trim((string)($pay['notes'] ?? ''));
            $balance      = $customer_balances[$cid] ?? null;

            // Method badge colours
            [$mbg, $mfg] = match ($method) {
              'check'       => ['#eff6ff', '#1d4ed8'],
              'cash'        => ['#f0fdf4', '#166534'],
              'ach_wire'    => ['#faf5ff', '#7c3aed'],
              'credit_card' => ['#fff7ed', '#c2410c'],
              default       => ['#f1f5f9', '#475569'],
            };

            // JSON for edit modal pre-fill
            $edit_data = json_encode([
              'id'             => $pid,
              'bank_transaction_id' => (int)($pay['bank_transaction_id'] ?? 0),
              'customer_id'    => $cid,
              'customer_name'  => $cname . ($ccompany !== '' ? ' — ' . $ccompany : ''),
              'payment_date'   => $pdate,
              'amount'         => number_format(abs((float)$amount), 2, '.', ''),
              'payment_method' => $method,
              'reference_no'   => $ref_no,
              'notes'          => $pay_notes,
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
          ?>
          <tr>
            <td class="muted"><?= $pid ?></td>
            <td>
              <strong><?= h($cname) ?></strong>
              <?php if ($ccompany !== ''): ?>
                <br><span class="muted"><?= h($ccompany) ?></span>
              <?php endif; ?>
            </td>
            <td><?= h($pdate !== '' ? fmt_date_mdY($pdate) : '—') ?></td>
            <td><strong>$<?= h(cp_format_money($amount)) ?></strong></td>
            <td>
              <span class="cp-badge" style="background:<?= h($mbg) ?>;color:<?= h($mfg) ?>;">
                <?= h(cp_payment_method_label($method)) ?>
              </span>
            </td>
            <td><?= $ref_no !== '' ? h($ref_no) : '<span class="muted">—</span>' ?></td>
            <td>
              <?php if ($balance === null): ?>
                <span class="muted">—</span>
              <?php elseif ($balance > 0): ?>
                <span class="cp-balance-pos">$<?= h(cp_format_money($balance)) ?> owed</span>
              <?php else: ?>
                <span class="cp-balance-zero">Paid up</span>
              <?php endif; ?>
            </td>
            <td class="col-actions">
              <div class="cp-actions">
                <button
                  type="button"
                  class="btn cp-edit-btn"
                  data-payment='<?= htmlspecialchars($edit_data, ENT_QUOTES, 'UTF-8') ?>'
                  aria-label="Edit payment #<?= $pid ?>"
                >Edit</button>

                <form method="post" action="customer_payments.php" style="display:contents;"
                      onsubmit="return confirm('Delete this payment record permanently? This cannot be undone.');">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['cp_csrf']) ?>" />
                  <input type="hidden" name="action" value="delete_payment" />
                  <input type="hidden" name="payment_id" value="<?= $pid ?>" />
                  <button type="submit" class="btn btn-danger">Delete</button>
                </form>
              </div>
              <?php if ($pay_notes !== ''): ?>
                <div class="muted" style="font-size:0.8em;margin-top:4px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($pay_notes) ?>">
                  📝 <?= h($pay_notes) ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Add / Edit Payment Modal ──────────────────────────────────────────── -->
<div id="cp-modal" role="dialog" aria-modal="true" aria-labelledby="cp-modal-title">
  <div class="cp-modal-backdrop" id="cp-modal-backdrop"></div>
  <div class="cp-modal-shell">
    <div class="cp-modal-header">
      <span id="cp-modal-icon" aria-hidden="true">💰</span>
      <h2 id="cp-modal-title" class="cp-modal-title">Add Payment</h2>
      <button type="button" class="cp-modal-close" id="cp-modal-close" aria-label="Close">&times;</button>
    </div>

    <form method="post" action="customer_payments.php" id="cp-form">
      <div class="cp-modal-body">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['cp_csrf']) ?>" />
        <input type="hidden" name="action" value="add_payment" id="cp-action-input" />
        <input type="hidden" name="payment_id" value="" id="cp-payment-id" />
        <input type="hidden" name="bank_transaction_id" value="" id="cp-bank-transaction-id" />
        <input type="hidden" name="customer_id" value="" id="cp-customer-id" />

        <div class="form-grid">
          <!-- Customer -->
          <div style="grid-column:1/-1; position:relative;">
            <label for="cp-customer-input">Customer <span style="color:var(--d);">*</span></label>
            <input
              id="cp-customer-input"
              type="text"
              autocomplete="off"
              placeholder="Type to search customers..."
              required
            />
            <div id="cp-customer-suggestions"></div>
          </div>

          <!-- Date -->
          <div>
            <label for="cp-date">Date <span style="color:var(--d);">*</span></label>
            <input id="cp-date" type="date" name="payment_date" required />
          </div>

          <!-- Amount -->
          <div>
            <label for="cp-amount">Amount <span style="color:var(--d);">*</span></label>
            <input id="cp-amount" type="number" name="amount" step="0.01" min="0.01" placeholder="0.00" required />
          </div>

          <!-- Payment Method -->
          <div>
            <label for="cp-method-select">Payment Method</label>
            <select id="cp-method-select" name="payment_method">
              <?php foreach ($payment_methods as $mv => $ml): ?>
                <option value="<?= h($mv) ?>"><?= h($ml) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Reference -->
          <div>
            <label for="cp-reference">Reference / Check #</label>
            <input id="cp-reference" type="text" name="reference_no" maxlength="100" placeholder="Optional" />
          </div>

          <!-- Notes -->
          <div style="grid-column:1/-1;">
            <label for="cp-notes">Notes</label>
            <textarea id="cp-notes" name="notes" rows="3" maxlength="2000" placeholder="Optional notes..."></textarea>
          </div>
        </div>
      </div>

      <div class="cp-modal-footer">
        <button type="button" class="btn" id="cp-modal-cancel">Cancel</button>
        <button type="submit" class="btn primary" id="cp-submit-btn">Save Payment</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  'use strict';

  var csrfToken = <?= json_encode($_SESSION['cp_csrf']) ?>;
  var modal     = document.getElementById('cp-modal');
  var backdrop  = document.getElementById('cp-modal-backdrop');
  var closeBtn  = document.getElementById('cp-modal-close');
  var cancelBtn = document.getElementById('cp-modal-cancel');
  var addBtn    = document.getElementById('cp-add-btn');
  var titleEl   = document.getElementById('cp-modal-title');
  var actionIn  = document.getElementById('cp-action-input');
  var paymentId = document.getElementById('cp-payment-id');
  var bankTransactionId = document.getElementById('cp-bank-transaction-id');
  var customerId = document.getElementById('cp-customer-id');
  var customerInput = document.getElementById('cp-customer-input');
  var suggestions  = document.getElementById('cp-customer-suggestions');
  var dateInput    = document.getElementById('cp-date');
  var amountInput  = document.getElementById('cp-amount');
  var methodSelect = document.getElementById('cp-method-select');
  var refInput     = document.getElementById('cp-reference');
  var notesInput   = document.getElementById('cp-notes');
  var submitBtn    = document.getElementById('cp-submit-btn');

  var todayYmd  = <?= json_encode($today_ymd) ?>;
  var importPrefill = <?= json_encode($bank_import_prefill, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

  // ── Modal open / close ──────────────────────────────────────────────────
  function openModal(isEdit) {
    modal.classList.add('open');
    modal.removeAttribute('aria-hidden');
    titleEl.textContent = isEdit ? 'Edit Payment' : 'Add Payment';
    submitBtn.textContent = isEdit ? 'Update Payment' : 'Save Payment';
    actionIn.value = isEdit ? 'edit_payment' : 'add_payment';
    // Set server-side today as default date for new payments
    if (!isEdit && dateInput.value === '') {
      dateInput.value = todayYmd;
    }
    customerInput.focus();
  }

  function closeModal() {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    hideSugg();
  }

  function resetForm() {
    paymentId.value   = '';
    bankTransactionId.value = '';
    customerId.value  = '';
    customerInput.value = '';
    dateInput.value   = '';
    amountInput.value = '';
    methodSelect.value = 'check';
    refInput.value    = '';
    notesInput.value  = '';
  }

  addBtn.addEventListener('click', function () {
    resetForm();
    openModal(false);
  });

  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', closeModal);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
  });

  // ── Edit button click: pre-fill form ─────────────────────────────────────
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.cp-edit-btn');
    if (!btn) return;
    try {
      var d = JSON.parse(btn.dataset.payment);
      resetForm();
      paymentId.value      = d.id;
      bankTransactionId.value = d.bank_transaction_id || '';
      customerId.value     = d.customer_id;
      customerInput.value  = d.customer_name;
      dateInput.value      = d.payment_date;
      amountInput.value    = d.amount;
      methodSelect.value   = d.payment_method;
      refInput.value       = d.reference_no;
      notesInput.value     = d.notes;
      openModal(true);
    } catch (err) {
      alert('Could not load payment data. Please try again.');
    }
  });

  // ── Customer search typeahead ─────────────────────────────────────────────
  var sugg = document.getElementById('cp-customer-suggestions');
  var debounceTimer = null;

  function hideSugg() {
    sugg.style.display = 'none';
    sugg.innerHTML = '';
  }

  function renderSugg(rows) {
    sugg.innerHTML = '';
    if (!rows.length) { hideSugg(); return; }
    rows.forEach(function (row) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cp-sugg-btn';
      var main = row.customer_name || row.company || row.email || '(no name)';
      var sub  = [row.company, row.email, row.phone].filter(Boolean).join(' · ');
      btn.innerHTML = '<span class="cp-sugg-main">' + escHtml(main) + '</span>'
                    + (sub ? '<span class="cp-sugg-sub">' + escHtml(sub) + '</span>' : '');
      btn.addEventListener('click', function () {
        customerId.value    = row.id;
        customerInput.value = main;
        hideSugg();
      });
      sugg.appendChild(btn);
    });
    sugg.style.display = 'block';
  }

  customerInput.addEventListener('input', function () {
    customerId.value = '';
    var q = customerInput.value.trim();
    clearTimeout(debounceTimer);
    if (q.length < 1) { hideSugg(); return; }
    debounceTimer = setTimeout(function () {
      fetch('customer_payments.php?customer_search=1&q=' + encodeURIComponent(q), {
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrfToken }
      }).then(function (r) { return r.ok ? r.json() : []; })
        .then(function (rows) { renderSugg(Array.isArray(rows) ? rows : []); })
        .catch(hideSugg);
    }, 200);
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('#cp-customer-suggestions') && e.target !== customerInput) {
      hideSugg();
    }
  });

  // ── Form submit validation ────────────────────────────────────────────────
  document.getElementById('cp-form').addEventListener('submit', function (e) {
    if (!customerId.value) {
      e.preventDefault();
      alert('Please select a customer from the search results.');
      customerInput.focus();
    }
  });

  if (importPrefill) {
    resetForm();
    bankTransactionId.value = importPrefill.bank_transaction_id || '';
    customerId.value = importPrefill.customer_id || '';
    customerInput.value = importPrefill.customer_name || '';
    dateInput.value = importPrefill.payment_date || '';
    amountInput.value = importPrefill.amount || '';
    methodSelect.value = importPrefill.payment_method || 'other';
    refInput.value = importPrefill.reference_no || '';
    notesInput.value = importPrefill.notes || '';
    openModal(false);
  }

  // ── Utility ──────────────────────────────────────────────────────────────
  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
})();
</script>

<?php render_footer(); ?>
