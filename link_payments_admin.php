<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (empty($_SESSION['lpa_csrf'])) {
  $_SESSION['lpa_csrf'] = bin2hex(random_bytes(24));
}

// ── POST: execute linking ────────────────────────────────────────────────────
$post_results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = trim((string)($_POST['csrf_token'] ?? ''));
  if ($csrf === '' || !hash_equals((string)$_SESSION['lpa_csrf'], $csrf)) {
    http_response_code(403);
    die('CSRF token invalid.');
  }

  $links = $_POST['link'] ?? [];
  $linked_count = 0;
  $skipped_count = 0;

  if (is_array($links)) {
    $update_stmt = $pdo->prepare(
      "UPDATE invoice_credit_applications SET payment_id = ? WHERE id = ? AND payment_id IS NULL"
    );
    foreach ($links as $ica_id_raw => $payment_id_raw) {
      $ica_id    = (int)$ica_id_raw;
      $payment_id = (int)$payment_id_raw;
      if ($ica_id <= 0) continue;
      if ($payment_id <= 0) {
        $skipped_count++;
        continue;
      }
      $update_stmt->execute([$payment_id, $ica_id]);
      if ($update_stmt->rowCount() > 0) {
        $linked_count++;
      } else {
        $skipped_count++;
      }
    }
  }

  // Regenerate CSRF
  $_SESSION['lpa_csrf'] = bin2hex(random_bytes(24));
  $post_results = ['linked' => $linked_count, 'skipped' => $skipped_count];
}

// ── GET: build review data ───────────────────────────────────────────────────

// Count remaining unlinked rows
$unlinked_count_stmt = $pdo->query(
  "SELECT COUNT(*) FROM invoice_credit_applications WHERE payment_id IS NULL"
);
$unlinked_total = (int)$unlinked_count_stmt->fetchColumn();

// Fetch all unlinked applications with customer info and invoice number
$unlinked_stmt = $pdo->query(
  "SELECT ica.id, ica.quote_id, ica.customer_id, ica.applied_amount, ica.applied_date,
          ica.notes,
          COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ', NULLIF(c.first_name,''), NULLIF(c.last_name,''))), ''),
            c.company, c.email, 'Unknown'
          ) AS customer_name,
          q.converted_invoice_no AS invoice_no
   FROM invoice_credit_applications ica
   JOIN customers c ON c.id = ica.customer_id
   LEFT JOIN quotes q ON q.id = ica.quote_id
   WHERE ica.payment_id IS NULL
   ORDER BY ica.customer_id, ica.applied_date, ica.id"
);
$unlinked_rows = $unlinked_stmt->fetchAll(PDO::FETCH_ASSOC);

// For each unlinked row, find candidate customer_payments, scored by closeness
// Score = ABS(applied_amount - cp.amount) + ABS(DATEDIFF(applied_date, cp.payment_date)) * 5
// Lower score = better match.
// Also compute how much of each payment is already consumed by existing linked applications.
$candidates_by_ica = [];
if ($unlinked_rows) {
  // Collect distinct customer_ids
  $cids = array_unique(array_column($unlinked_rows, 'customer_id'));
  $ph   = implode(',', array_fill(0, count($cids), '?'));

  // Get all candidate payments for those customers with their already-used amount
  $cand_stmt = $pdo->prepare(
    "SELECT cp.id, cp.customer_id, cp.payment_date, cp.amount, cp.payment_method, cp.reference_no,
            COALESCE(used.total_used, 0) AS total_used
     FROM customer_payments cp
     LEFT JOIN (
       SELECT payment_id, SUM(applied_amount) AS total_used
       FROM invoice_credit_applications
       WHERE payment_id IS NOT NULL
       GROUP BY payment_id
     ) used ON used.payment_id = cp.id
     WHERE cp.customer_id IN ($ph)
     ORDER BY cp.customer_id, cp.payment_date DESC"
  );
  $cand_stmt->execute($cids);
  $payments_by_customer = [];
  foreach ($cand_stmt->fetchAll(PDO::FETCH_ASSOC) as $cp) {
    $payments_by_customer[(int)$cp['customer_id']][] = $cp;
  }

  foreach ($unlinked_rows as $ica) {
    $ica_id      = (int)$ica['id'];
    $cid         = (int)$ica['customer_id'];
    $app_amount  = (float)$ica['applied_amount'];
    $app_date    = $ica['applied_date'];

    $pool = $payments_by_customer[$cid] ?? [];
    $scored = [];
    foreach ($pool as $cp) {
      $cp_id     = (int)$cp['id'];
      $cp_amount = (float)$cp['amount'];
      $cp_used   = (float)$cp['total_used'];
      $cp_avail  = round($cp_amount - $cp_used, 2);
      $amt_diff  = abs($app_amount - $cp_amount);
      $day_diff  = (int)abs((strtotime($app_date) - strtotime($cp['payment_date'])) / 86400);
      $score     = $amt_diff + $day_diff * 5;

      // Confidence
      if ($amt_diff <= 0.01 && $day_diff <= 3) {
        $confidence = 'high';
      } elseif ($amt_diff <= 5.00 && $day_diff <= 14) {
        $confidence = 'medium';
      } else {
        $confidence = 'low';
      }

      $scored[] = [
        'id'          => $cp_id,
        'payment_date' => $cp['payment_date'],
        'amount'      => $cp_amount,
        'available'   => $cp_avail,
        'method'      => $cp['payment_method'],
        'reference_no' => $cp['reference_no'],
        'score'       => $score,
        'confidence'  => $confidence,
        'overused'    => $cp_avail < 0,
      ];
    }
    usort($scored, fn($a, $b) => $a['score'] <=> $b['score']);
    $candidates_by_ica[$ica_id] = array_slice($scored, 0, 3);
  }
}

function lpa_method_label(string $m): string {
  return match ($m) {
    'check'       => 'Check',
    'cash'        => 'Cash',
    'ach_wire'    => 'ACH/Wire',
    'credit_card' => 'Credit Card',
    default       => 'Other',
  };
}

render_header('Link Existing Payments — Admin');
?>

<style>
.lpa-table { width:100%; border-collapse:collapse; font-size:0.87em; }
.lpa-table th { background:#f1f5f9; font-weight:600; color:#475569; padding:7px 10px; border:1px solid #e2e8f0; text-align:left; white-space:nowrap; }
.lpa-table td { padding:7px 10px; border:1px solid #e2e8f0; vertical-align:top; }
.lpa-table tr:nth-child(even) td { background:#fafafa; }
.conf-high   { color:#166534; font-weight:700; }
.conf-medium { color:#92400e; font-weight:700; }
.conf-low    { color:#64748b; }
.conf-none   { color:#94a3b8; }
.lpa-overused { color:#991b1b; font-size:0.8em; }
</style>

<div class="card laser-rfq-hero page-header">
  <div class="laser-rfq-hero-beams" aria-hidden="true">
    <span class="laser-rfq-beam laser-rfq-beam-1"></span>
    <span class="laser-rfq-beam laser-rfq-beam-2"></span>
    <span class="laser-rfq-beam laser-rfq-beam-3"></span>
  </div>
  <div class="laser-rfq-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body laser-rfq-hero-body">
    <span class="laser-rfq-hero-tag">🔗 Admin Tool</span>
    <h1>Link Existing Payments</h1>
    <p class="muted">One-time backfill tool: match existing <code>invoice_credit_applications</code> rows (where <code>payment_id IS NULL</code>) to the most likely <code>customer_payments</code> record. This tool only operates on unlinked rows and is safe to re-run.</p>
  </div>
</div>

<?php if ($post_results !== null): ?>
<div class="alert" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534;">
  ✅ Done. <strong><?= (int)$post_results['linked'] ?></strong> application(s) linked to a payment.
  <?= (int)$post_results['skipped'] > 0 ? '<strong>' . (int)$post_results['skipped'] . '</strong> skipped (no match selected or already linked).' : '' ?>
  <a href="link_payments_admin.php" style="margin-left:12px;">Review remaining unlinked rows</a>
  &nbsp;·&nbsp;
  <a href="customer_payments.php">Go to Customer Payments</a>
</div>
<?php endif; ?>

<?php if ($unlinked_total === 0): ?>
<div class="card">
  <p style="font-size:1em; color:#166534; font-weight:600;">✅ All credit applications are linked to a payment. Nothing left to do.</p>
  <p><a href="customer_payments.php" class="btn primary">Go to Customer Payments</a></p>
</div>
<?php else: ?>
<div class="card">
  <p style="margin:0 0 4px;"><strong><?= (int)$unlinked_total ?></strong> credit application(s) have no linked payment.</p>
  <p class="muted" style="font-size:0.87em; margin:0;">Review the suggested matches below. For each row, confirm or override the payment selection, then click <em>Apply All Selections</em>.</p>
  <p class="muted" style="font-size:0.87em; margin:6px 0 0;">
    <strong>Confidence:</strong>
    <span class="conf-high">High</span> = amount diff ≤ $0.01 &amp; date diff ≤ 3 days. &nbsp;
    <span class="conf-medium">Medium</span> = diff ≤ $5.00 &amp; ≤ 14 days. &nbsp;
    <span class="conf-low">Low</span> = outside those ranges. &nbsp;
    <span class="conf-none">—</span> = no candidates for this customer.
  </p>
</div>

<form method="post" action="link_payments_admin.php">
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['lpa_csrf']) ?>" />

  <div class="card" style="padding:0; overflow:hidden;">
    <div style="overflow-x:auto;">
      <table class="lpa-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Customer</th>
            <th>Invoice</th>
            <th>Applied Date</th>
            <th>Applied Amount</th>
            <th>Notes</th>
            <th>Select Payment to Link</th>
            <th>Best Match Confidence</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($unlinked_rows as $ica): ?>
          <?php
            $ica_id     = (int)$ica['id'];
            $candidates = $candidates_by_ica[$ica_id] ?? [];
            $best       = $candidates[0] ?? null;

            // Pre-select best candidate if High or Medium confidence
            $preselect = 0;
            if ($best && in_array($best['confidence'], ['high', 'medium'], true)) {
              $preselect = (int)$best['id'];
            }

            $conf_label = '—';
            $conf_class = 'conf-none';
            if ($best) {
              $conf_label = match ($best['confidence']) {
                'high'   => 'High',
                'medium' => 'Medium',
                default  => 'Low',
              };
              $conf_class = 'conf-' . $best['confidence'];
            }
          ?>
          <tr>
            <td class="muted"><?= $ica_id ?></td>
            <td><?= h((string)$ica['customer_name']) ?></td>
            <td><?= $ica['invoice_no'] !== null && $ica['invoice_no'] !== '' ? h((string)$ica['invoice_no']) : '<span class="muted">—</span>' ?></td>
            <td style="white-space:nowrap;"><?= h((string)$ica['applied_date']) ?></td>
            <td style="white-space:nowrap; text-align:right;"><strong>$<?= h(number_format((float)$ica['applied_amount'], 2)) ?></strong></td>
            <td style="max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h((string)($ica['notes'] ?? '')) ?>">
              <?= $ica['notes'] !== null && $ica['notes'] !== '' ? h((string)$ica['notes']) : '<span class="muted">—</span>' ?>
            </td>
            <td style="min-width:280px;">
              <?php if (!$candidates): ?>
                <span class="conf-none">No payments on file for this customer</span>
                <input type="hidden" name="link[<?= $ica_id ?>]" value="0" />
              <?php else: ?>
              <select name="link[<?= $ica_id ?>]" style="width:100%; font-size:0.85em;">
                <option value="0">— Skip / No match —</option>
                <?php foreach ($candidates as $cand): ?>
                <?php
                  $cand_label = h(fmt_date_mdY($cand['payment_date']))
                    . ' · $' . h(number_format($cand['amount'], 2))
                    . ' · ' . h(lpa_method_label($cand['method']))
                    . ($cand['reference_no'] ? ' · #' . h((string)$cand['reference_no']) : '')
                    . ' (avail $' . h(number_format(max(0.0, (float)$cand['available']), 2)) . ')';
                  if ($cand['overused']) {
                    $cand_label .= ' ⚠ over-applied';
                  }
                ?>
                <option value="<?= (int)$cand['id'] ?>" <?= $preselect === (int)$cand['id'] ? 'selected' : '' ?>>
                  <?= $cand_label ?>
                </option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </td>
            <td><span class="<?= $conf_class ?>"><?= $conf_label ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <button type="submit" class="btn primary">Apply All Selections</button>
    <a href="customer_payments.php" class="btn">Cancel — Back to Payments</a>
    <span class="muted" style="font-size:0.85em;">Only rows with a payment selected will be updated. Rows set to "Skip" are left unchanged.</span>
  </div>
</form>
<?php endif; ?>

<?php render_footer(); ?>
