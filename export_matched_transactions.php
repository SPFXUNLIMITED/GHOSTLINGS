<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

$stmt = $pdo->prepare(
  "SELECT bt.id,
          bt.transaction_date,
          bt.description,
          bt.amount,
          bt.reference,
          bt.source,
          bt.linked_payment_id,
          bt.match_status,
          COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ', NULLIF(c.first_name,''), NULLIF(c.last_name,''))), ''),
            NULLIF(c.company, ''),
            NULLIF(c.email, ''),
            NULLIF(bt.customer_name, ''),
            ''
          ) AS customer_name,
          COALESCE(NULLIF(q.converted_invoice_no, ''), NULLIF(CAST(q.id AS CHAR), ''), '') AS invoice_number
   FROM bank_transactions bt
   LEFT JOIN customer_payments cp ON cp.id = bt.linked_payment_id
   LEFT JOIN customers c ON c.id = COALESCE(bt.matched_customer_id, cp.customer_id)
   LEFT JOIN (
     SELECT payment_id, MIN(quote_id) AS quote_id
     FROM invoice_credit_applications
     GROUP BY payment_id
   ) ica ON ica.payment_id = bt.linked_payment_id
   LEFT JOIN quotes q ON q.id = ica.quote_id
   ORDER BY bt.transaction_date ASC, bt.id ASC"
);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$exportTimezone = defined('APP_TZ') ? APP_TZ : 'America/Los_Angeles';
$filename = 'bank_transactions_' . (new DateTime('now', new DateTimeZone($exportTimezone)))->format('Y-m-d') . '.csv';

$out = fopen('php://temp', 'w+');
if ($out === false) {
  http_response_code(500);
  exit('Unable to open export stream.');
}

fputcsv($out, ['Date', 'Description', 'Customer', 'Amount', 'Reference', 'Source', 'Transaction ID', 'Payment ID', 'Invoice Number', 'Status']);

foreach ($rows as $row) {
  $dateRaw = $row['transaction_date'] ?? '';
  $dateFormatted = '';
  if ($dateRaw !== '') {
    try {
      $dateFormatted = (new DateTime($dateRaw))->format('m/d/Y');
    } catch (Exception $e) {
      $dateFormatted = $dateRaw;
    }
  }
  fputcsv($out, [
    $dateFormatted,
    (string)($row['description'] ?? ''),
    (string)($row['customer_name'] ?? ''),
    number_format((float)($row['amount'] ?? 0), 2, '.', ''),
    (string)($row['reference'] ?? ''),
    (string)($row['source'] ?? ''),
    (string)($row['id'] ?? ''),
    (string)($row['linked_payment_id'] ?? ''),
    (string)($row['invoice_number'] ?? ''),
    (string)($row['match_status'] ?? ''),
  ]);
}

rewind($out);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));

fpassthru($out);
