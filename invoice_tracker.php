<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const INVOICE_TRACKER_TABLE_COLUMN_COUNT = 5;

function invoice_tracker_format_money($value): string {
  return number_format((float)$value, 2);
}

function invoice_tracker_number(array $row, string $stamp): string {
  $existing = trim((string)($row['converted_invoice_no'] ?? ''));
  if ($existing !== '') {
    return $existing;
  }

  $quote_id = (int)($row['id'] ?? 0);
  if ($quote_id <= 0) {
    return '—';
  }

  return 'INV-' . $stamp . '-' . str_pad((string)$quote_id, 5, '0', STR_PAD_LEFT);
}

function invoice_tracker_status_label(string $status): string {
  $status = trim($status);
  if ($status === '') {
    return '—';
  }

  return ucwords(str_replace('_', ' ', $status));
}

$stmt = $pdo->prepare(
  "SELECT id, customer_name, company_name, quote_date, subtotal_amount, status, converted_invoice_no, created_at
   FROM quotes
   WHERE (converted_invoice_no IS NOT NULL AND converted_invoice_no <> '')
      OR status = 'converted'
   ORDER BY created_at DESC, id DESC
   LIMIT 300"
);
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
$invoice_number_stamp = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Ymd');

render_header('Invoice Tracker');
?>

<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
    <h2 style="margin:0;">All Invoices</h2>
    <a class="btn primary" href="invoice_form.php">New Invoice</a>
  </div>

  <div style="overflow-x:auto;">
    <table style="min-width:780px;">
      <thead>
        <tr>
          <th>Invoice Number</th>
          <th>Customer</th>
          <th>Date</th>
          <th>Total</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$invoices): ?>
          <tr><td colspan="<?= INVOICE_TRACKER_TABLE_COLUMN_COUNT ?>" class="muted">No invoices found.</td></tr>
        <?php endif; ?>

        <?php foreach ($invoices as $invoice): ?>
          <?php
            $customer_name = trim((string)($invoice['customer_name'] ?? ''));
            $company_name = trim((string)($invoice['company_name'] ?? ''));
            $customer_display = $customer_name !== '' ? $customer_name : '—';
            $status_raw = (string)($invoice['status'] ?? '');
            $status_label = invoice_tracker_status_label($status_raw);
            $status_bg = $status_raw === 'converted' ? '#dcfce7' : '#e2e8f0';
            $status_color = $status_raw === 'converted' ? '#166534' : '#334155';
          ?>
          <tr>
            <td style="white-space:nowrap;"><strong><?= h(invoice_tracker_number($invoice, $invoice_number_stamp)) ?></strong></td>
            <td>
              <?= h($customer_display) ?>
              <?php if ($company_name !== ''): ?>
                <div class="muted" style="font-size:0.85em;"><?= h($company_name) ?></div>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;"><?= h((string)($invoice['quote_date'] ?? '—')) ?></td>
            <td style="white-space:nowrap;"><strong>$<?= h(invoice_tracker_format_money($invoice['subtotal_amount'] ?? 0)) ?></strong></td>
            <td>
              <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:0.82em; font-weight:600; letter-spacing:0.02em; background:<?= h($status_bg) ?>; color:<?= h($status_color) ?>;">
                <?= h($status_label) ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer();
