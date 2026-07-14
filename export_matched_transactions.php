<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

$stmt = $pdo->query(
  "SELECT bt.id,
          bt.transaction_date,
          bt.description,
          bt.amount,
          bt.reference,
          bt.source,
          bt.linked_payment_id,
          COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ', NULLIF(c.first_name,''), NULLIF(c.last_name,''))), ''),
            NULLIF(c.company, ''),
            NULLIF(c.email, ''),
            NULLIF(bt.customer_name, ''),
            ''
          ) AS customer_name,
          COALESCE(NULLIF(q.converted_invoice_no, ''), '') AS invoice_number
   FROM bank_transactions bt
   LEFT JOIN customer_payments cp ON cp.id = bt.linked_payment_id
   LEFT JOIN customers c ON c.id = COALESCE(bt.matched_customer_id, cp.customer_id)
   LEFT JOIN quotes q ON q.id = bt.matched_invoice_id
   WHERE bt.match_status = 'matched'
   ORDER BY bt.transaction_date ASC, bt.id ASC"
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = 'bank_matched_transactions_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'Description', 'Customer', 'Amount', 'Reference', 'Source', 'Transaction ID', 'Payment ID', 'Invoice Number']);

foreach ($rows as $row) {
  fputcsv($out, [
    (string)($row['transaction_date'] ?? ''),
    (string)($row['description'] ?? ''),
    (string)($row['customer_name'] ?? ''),
    number_format((float)($row['amount'] ?? 0), 2, '.', ''),
    (string)($row['reference'] ?? ''),
    (string)($row['source'] ?? ''),
    (int)($row['id'] ?? 0),
    (int)($row['linked_payment_id'] ?? 0) ?: '',
    (string)($row['invoice_number'] ?? ''),
  ]);
}

fclose($out);
exit;
