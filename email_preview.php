<?php
require __DIR__ . '/db.php';
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';

try {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        throw new RuntimeException('Missing or invalid quote ID.');
    }

    $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quote) {
        throw new RuntimeException('Quote not found.');
    }

    $stmt = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sender info from the quote creator's user profile
    $sender_name    = '';
    $sender_company = '';
    $sender_address = '';
    $sender_phone   = '';
    $sender_email   = '';
    $created_by = isset($quote['created_by']) ? (int)$quote['created_by'] : 0;
    if ($created_by > 0) {
        $s = $pdo->prepare('SELECT username, contact_name, company_name, delivery_address, contact_phone, email FROM users WHERE id = ? LIMIT 1');
        $s->execute([$created_by]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cn = trim((string)($row['contact_name'] ?? ''));
            $sender_name    = $cn !== '' ? $cn : trim((string)($row['username'] ?? ''));
            $sender_company = trim((string)($row['company_name']     ?? ''));
            $sender_address = trim((string)($row['delivery_address'] ?? ''));
            $sender_phone   = trim((string)($row['contact_phone']    ?? ''));
            $sender_email   = trim((string)($row['email']            ?? ''));
        }
    }
    $smtp_from_name = trim((string)(getenv('SMTP_FROM_NAME') ?: ''));
    if ($sender_company === '') $sender_company = $smtp_from_name;
    if ($sender_company === '') $sender_company = 'Our Company';
    if ($sender_email === '')   $sender_email   = trim((string)(getenv('SMTP_FROM_EMAIL') ?: ''));

    // Quote fields
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $inv_no          = trim((string)($quote['converted_invoice_no'] ?? ''));
    $inv_label       = $inv_no !== '' ? $h($inv_no) : '#' . (int)$quote['id'];
    $inv_date        = $h(trim((string)($quote['quote_date'] ?? '')));
    $customer_name   = trim((string)($quote['customer_name'] ?? ''));
    $customer_company = trim((string)($quote['company_name']  ?? ''));
    $subtotal        = number_format((float)($quote['subtotal_amount'] ?? 0), 2);
    $tax_rate        = (float)($quote['tax_rate']   ?? 0);
    $tax_amount      = number_format((float)($quote['tax_amount']  ?? 0), 2);
    $grand_total     = number_format((float)($quote['subtotal_amount'] ?? 0) + (float)($quote['tax_amount'] ?? 0), 2);
    $is_paid         = strtolower(trim((string)($quote['payment_status'] ?? ''))) === 'paid';

    $bill_street = trim((string)($quote['billing_street'] ?? ''));
    $bill_city   = trim((string)($quote['billing_city']   ?? ''));
    $bill_state  = trim((string)($quote['billing_state']  ?? ''));
    $bill_zip    = trim((string)($quote['billing_zip']    ?? ''));

    // Bill-to block
    $bill_to_lines = [];
    if ($customer_company !== '') $bill_to_lines[] = '<strong style="color:#0f172a;">' . $h($customer_company) . '</strong>';
    if ($customer_name    !== '') $bill_to_lines[] = $h($customer_name);
    if ($bill_street      !== '') $bill_to_lines[] = $h($bill_street);
    $csz_parts = array_filter([$bill_city, $bill_state . ($bill_zip !== '' ? ' ' . $bill_zip : '')]);
    if ($csz_parts) $bill_to_lines[] = $h(implode(', ', $csz_parts));
    $cust_phone = trim((string)($quote['phone_number'] ?? ''));
    $cust_email = trim((string)($quote['email']        ?? ''));
    if ($cust_phone !== '') $bill_to_lines[] = $h($cust_phone);
    if ($cust_email !== '') $bill_to_lines[] = '<a href="mailto:' . $h($cust_email) . '" style="color:#1d4ed8;text-decoration:none;">' . $h($cust_email) . '</a>';
    $bill_to_html = implode('<br>', $bill_to_lines);

    // From block
    $from_lines = ['<strong style="color:#0f172a;">' . $h($sender_company) . '</strong>'];
    if ($sender_name !== '' && $sender_name !== $sender_company) $from_lines[] = $h($sender_name);
    foreach (array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $sender_address))) as $al) {
        $from_lines[] = $h($al);
    }
    if ($sender_phone !== '') $from_lines[] = $h($sender_phone);
    if ($sender_email !== '') $from_lines[] = '<a href="mailto:' . $h($sender_email) . '" style="color:#1d4ed8;text-decoration:none;">' . $h($sender_email) . '</a>';
    $from_html = implode('<br>', $from_lines);

    // Header contact line
    $hdr_parts = [];
    if ($sender_address !== '') {
        $hdr_parts[] = $h(trim(preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], ' · ', $sender_address))));
    }
    if ($sender_phone !== '') $hdr_parts[] = $h($sender_phone);
    if ($sender_email !== '') $hdr_parts[] = '<a href="mailto:' . $h($sender_email) . '" style="color:#93c5fd;text-decoration:none;">' . $h($sender_email) . '</a>';
    $header_contact_html = implode(' &nbsp;·&nbsp; ', $hdr_parts);

    // Footer contact line
    $ftr_parts = [];
    if ($sender_address !== '') {
        $ftr_parts[] = $h(trim(preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], ', ', $sender_address))));
    }
    if ($sender_phone !== '') $ftr_parts[] = $h($sender_phone);
    if ($sender_email !== '') $ftr_parts[] = '<a href="mailto:' . $h($sender_email) . '" style="color:#93c5fd;text-decoration:none;">' . $h($sender_email) . '</a>';
    $footer_contact_html = implode(' &nbsp;·&nbsp; ', $ftr_parts);

    // Prepared-by line
    $prepared_by_html = '';
    if ($sender_name !== '') {
        $prepared_by_html = 'This invoice was prepared by <strong style="color:#1e293b;">' . $h($sender_name) . '</strong>';
        if ($sender_company !== 'Our Company') {
            $prepared_by_html .= ' at <strong style="color:#1e293b;">' . $h($sender_company) . '</strong>';
        }
        $prepared_by_html .= '.';
    }

    // Line items rows
    $rows_html = [];
    $i = 0;
    foreach ($items as $item) {
        $desc       = trim((string)($item['description'] ?? ''));
        $qty        = number_format((float)($item['quantity']   ?? 0), 2);
        $unit_price = number_format((float)($item['unit_price'] ?? 0), 2);
        $line_total = number_format((float)($item['line_total'] ?? 0), 2);
        $row_bg     = ($i++ % 2 === 0) ? '#ffffff' : '#f9fafb';
        $rows_html[] = '<tr style="background:' . $row_bg . ';">'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#374151;">' . $h($desc) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">' . $h($qty) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">$' . $h($unit_price) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">$' . $h($line_total) . '</td>'
            . '</tr>';
    }
    if (!$rows_html) {
        $rows_html[] = '<tr><td colspan="4" style="padding:10px 12px;text-align:center;color:#6b7280;">No line items.</td></tr>';
    }

    // Build full HTML
    $html = '<!doctype html>'
        . '<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="max-width:680px;margin:32px auto 32px;">'
        . '<div style="background:#1e3a5f;border-radius:8px 8px 0 0;padding:28px 32px 24px;">'
          . '<p style="margin:0 0 6px;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.3px;">' . $h($sender_company) . '</p>'
          . ($header_contact_html !== '' ? '<p style="margin:0;font-size:13px;color:#93c5fd;line-height:1.6;">' . $header_contact_html . '</p>' : '')
        . '</div>'
        . ($is_paid
            ? '<div style="background:#ffffff;padding:6px 32px 0;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
                . '<div style="margin:0 0 4px;padding:4px 10px;border:2px solid #dc2626;border-radius:6px;background:#fee2e2;text-align:center;">'
                  . '<span style="display:inline-block;font-size:20px;line-height:1;font-weight:900;letter-spacing:0.12em;color:#b91c1c;text-transform:uppercase;">PAID</span>'
                . '</div>'
              . '</div>'
            : '')
        . '<div style="background:#ffffff;padding:' . ($is_paid ? '16px' : '20px') . ' 32px 0;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
          . '<table style="width:100%;border-collapse:collapse;">'
            . '<tr>'
              . '<td style="padding:0 0 16px;">'
                . '<p style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Invoice ' . $inv_label . '</p>'
              . '</td>'
              . '<td style="padding:0 0 16px;text-align:right;">'
                . '<p style="margin:0;font-size:13px;color:#64748b;">Date: ' . $inv_date . '</p>'
              . '</td>'
            . '</tr>'
          . '</table>'
          . '<hr style="margin:0;border:none;border-top:2px solid #e2e8f0;">'
        . '</div>'
        . '<div style="background:#ffffff;padding:20px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-top:0;">'
          . '<table style="width:100%;border-collapse:collapse;">'
            . '<tr>'
              . '<td style="width:50%;padding:0 8px 0 0;vertical-align:top;">'
                . '<div style="border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;background:#f8fafc;">'
                  . '<p style="margin:0 0 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#64748b;">Bill To</p>'
                  . '<p style="margin:0;font-size:13px;color:#374151;line-height:1.7;">' . $bill_to_html . '</p>'
                . '</div>'
              . '</td>'
              . '<td style="width:50%;padding:0 0 0 8px;vertical-align:top;">'
                . '<div style="border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;background:#f8fafc;">'
                  . '<p style="margin:0 0 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#64748b;">From</p>'
                  . '<p style="margin:0;font-size:13px;color:#374151;line-height:1.7;">' . $from_html . '</p>'
                . '</div>'
              . '</td>'
            . '</tr>'
          . '</table>'
        . '</div>'
        . '<div style="background:#ffffff;padding:24px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
          . '<p style="margin:0 0 8px;font-size:15px;color:#1e293b;">Hello' . ($customer_name !== '' ? ', ' . $h($customer_name) : '') . ',</p>'
          . '<p style="margin:0 0 24px;font-size:14px;color:#475569;">Please find your invoice details below. Thank you for your business.</p>'
          . '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">'
            . '<thead>'
              . '<tr style="background:#f8fafc;">'
                . '<th style="padding:10px 12px;text-align:left;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;">Description</th>'
                . '<th style="padding:10px 12px;text-align:right;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;">Qty</th>'
                . '<th style="padding:10px 12px;text-align:right;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;">Unit Price</th>'
                . '<th style="padding:10px 12px;text-align:right;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;">Total</th>'
              . '</tr>'
            . '</thead>'
            . '<tbody>' . implode('', $rows_html) . '</tbody>'
            . '<tfoot>'
              . '<tr>'
                . '<td colspan="3" style="padding:10px 12px;text-align:right;font-weight:600;font-size:13px;color:#1e293b;border-top:2px solid #e2e8f0;">Subtotal:</td>'
                . '<td style="padding:10px 12px;text-align:right;font-weight:600;font-size:14px;color:#1e3a5f;border-top:2px solid #e2e8f0;">$' . $h($subtotal) . '</td>'
              . '</tr>'
              . ($tax_rate > 0
                  ? '<tr>'
                      . '<td colspan="3" style="padding:4px 12px;text-align:right;font-weight:600;font-size:13px;color:#1e293b;">Tax (' . $h(number_format($tax_rate, 2)) . '%):</td>'
                      . '<td style="padding:4px 12px;text-align:right;font-weight:600;font-size:14px;color:#1e3a5f;">$' . $h($tax_amount) . '</td>'
                    . '</tr>'
                  : '')
              . '<tr>'
                . '<td colspan="3" style="padding:10px 12px;text-align:right;font-weight:700;font-size:14px;color:#1e293b;">Grand Total:</td>'
                . '<td style="padding:10px 12px;text-align:right;font-weight:700;font-size:16px;color:#1e3a5f;">$' . $h($grand_total) . '</td>'
              . '</tr>'
            . '</tfoot>'
          . '</table>'
          . '<p style="margin:0;font-size:14px;color:#475569;">If you have any questions regarding this invoice, please do not hesitate to contact us.</p>'
        . '</div>'
        . ($prepared_by_html !== ''
            ? '<div style="background:#f8fafc;padding:14px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">'
                . '<p style="margin:0;font-size:13px;color:#64748b;">' . $prepared_by_html . '</p>'
              . '</div>'
            : '')
        . '<div style="background:#1e3a5f;border-radius:0 0 8px 8px;padding:18px 32px;">'
          . '<p style="margin:0;font-size:12px;color:#93c5fd;line-height:1.6;">'
            . $h($sender_company)
            . ($footer_contact_html !== '' ? ' &nbsp;·&nbsp; ' . $footer_contact_html : '')
          . '</p>'
        . '</div>'
        . '</div>'
        . '</body></html>';

    header('Content-Type: text/html; charset=utf-8');
    echo $html;

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><body style="margin:0;padding:16px;font-family:Arial,sans-serif;">'
        . '<div style="padding:14px 16px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:6px;line-height:1.5;">'
        . '<strong>Error:</strong><br>'
        . nl2br(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'))
        . '</div></body></html>';
}
