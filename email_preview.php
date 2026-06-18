<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';
require_admin_or_moderator();

function preview_format_money($value): string {
    return number_format((float)$value, 2);
}

function preview_single_line(string $value, string $separator): string {
    return trim((string)preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], $separator, $value)));
}

function preview_format_quantity($value): string {
    $quantity = (float)$value;
    if (abs($quantity - round($quantity)) < 0.00001) {
        return number_format($quantity, 0, '.', '');
    }

    return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
}

function preview_sender_profile(PDO $pdo, array $quote): array {
    $profile = [
        'sender_name'  => '',
        'company_name' => '',
        'address'      => '',
        'phone'        => '',
        'email'        => '',
    ];

    $candidate_ids = [];
    $created_by = (int)($quote['created_by'] ?? 0);
    if ($created_by > 0) {
        $candidate_ids[] = $created_by;
    }

    $session_user_id = (int)($_SESSION['user_id'] ?? 0);
    if ($session_user_id > 0 && !in_array($session_user_id, $candidate_ids, true)) {
        $candidate_ids[] = $session_user_id;
    }

    if (!$candidate_ids) {
        return $profile;
    }

    $stmt = $pdo->prepare(
        "SELECT username, contact_name, company_name, delivery_address, contact_phone, email
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    foreach ($candidate_ids as $user_id) {
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }

        $contact_name = trim((string)($row['contact_name'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        $profile['sender_name'] = $contact_name !== '' ? $contact_name : $username;
        $profile['company_name'] = trim((string)($row['company_name'] ?? ''));
        $profile['address'] = trim((string)($row['delivery_address'] ?? ''));
        $profile['phone'] = trim((string)($row['contact_phone'] ?? ''));
        $profile['email'] = trim((string)($row['email'] ?? ''));
        break;
    }

    return $profile;
}

try {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        throw new RuntimeException('Missing or invalid record ID.');
    }

    $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quote) {
        throw new RuntimeException('Record not found.');
    }

    $stmt = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sender = preview_sender_profile($pdo, $quote);
    $sender_name = $sender['sender_name'];
    $sender_company = trim((string)($sender['company_name'] ?? ''));
    if ($sender_company === '') {
        $sender_company = trim((string)(getenv('SMTP_FROM_NAME') ?: ''));
    }
    if ($sender_company === '') {
        $sender_company = 'Our Company';
    }
    $sender_address = $sender['address'];
    $sender_phone = $sender['phone'];
    $sender_email = $sender['email'];
    if ($sender_email === '') {
        $sender_email = trim((string)(getenv('SMTP_FROM_EMAIL') ?: ''));
    }

    $escape_html = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $quote_id = (int)($quote['id'] ?? 0);
    $quote_status = strtolower(trim((string)($quote['status'] ?? '')));
    $invoice_number = trim((string)($quote['converted_invoice_no'] ?? ''));
    $converted_at = trim((string)($quote['converted_at'] ?? ''));
    // Older records may rely on invoice number or converted_at even if status was not backfilled.
    $has_invoice_markers = $quote_status === 'converted' || $invoice_number !== '' || $converted_at !== '';

    // Allow callers to explicitly set context via ?context=quote|invoice so that
    // a converted quote can still be previewed as a quote (e.g. from quotes.php).
    $context_raw = strtolower(trim((string)filter_input(INPUT_GET, 'context', FILTER_DEFAULT)));
    $context_param = in_array($context_raw, ['quote', 'invoice'], true) ? $context_raw : '';
    if ($context_param === 'quote') {
        $is_invoice = false;
    } elseif ($context_param === 'invoice') {
        $is_invoice = true;
    } else {
        $is_invoice = $has_invoice_markers;
    }

    $document_noun = $is_invoice ? 'invoice' : 'quote';
    $document_heading = $is_invoice
        ? 'Invoice ' . ($invoice_number !== '' ? $escape_html($invoice_number) : $escape_html('#' . $quote_id))
        : 'Quote ' . $escape_html('#' . $quote_id);
    $document_intro = $is_invoice
        ? 'Please find your invoice details below. Thank you for your business.'
        : 'Please find your quote details below. We appreciate the opportunity to earn your business.';
    $document_closing = $is_invoice
        ? 'If you have any questions regarding this invoice, please do not hesitate to contact us.'
        : 'Thank you for considering our services. Please do not hesitate to reach out if you have any questions.';
    $prepared_by_html = '';
    $document_noun_html = $escape_html($document_noun);

    $document_date = trim((string)($quote['quote_date'] ?? ''));
    $customer_name = trim((string)($quote['customer_name'] ?? ''));
    $customer_company = trim((string)($quote['company_name'] ?? ''));
    $subtotal = preview_format_money($quote['subtotal_amount'] ?? 0);
    $tax_rate = (float)($quote['tax_rate'] ?? 0);
    $tax_amount = preview_format_money($quote['tax_amount'] ?? 0);
    $grand_total = preview_format_money((float)($quote['subtotal_amount'] ?? 0) + (float)($quote['tax_amount'] ?? 0));
    // Quotes and invoices share the same table, but paid state only applies after invoice conversion.
    $is_paid = $is_invoice && strtolower(trim((string)($quote['payment_status'] ?? ''))) === 'paid';

    $bill_street = trim((string)($quote['billing_street'] ?? ''));
    $bill_city = trim((string)($quote['billing_city'] ?? ''));
    $bill_state = trim((string)($quote['billing_state'] ?? ''));
    $bill_zip = trim((string)($quote['billing_zip'] ?? ''));

    $rows_html = [];
    $row_index = 0;
    foreach ($items as $item) {
        $description = trim((string)($item['description'] ?? ''));
        $quantity = preview_format_quantity($item['quantity'] ?? 0);
        $unit_price = preview_format_money($item['unit_price'] ?? 0);
        $line_total = preview_format_money($item['line_total'] ?? 0);
        $row_bg = ($row_index++ % 2 === 0) ? '#ffffff' : '#f9fafb';
        $rows_html[] = '<tr style="background:' . $row_bg . ';">'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#374151;">' . $escape_html($description) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">' . $escape_html($quantity) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">$' . $escape_html($unit_price) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">$' . $escape_html($line_total) . '</td>'
            . '</tr>';
    }
    if (!$rows_html) {
        $rows_html[] = '<tr><td colspan="4" style="padding:10px 12px;text-align:center;color:#6b7280;">No line items.</td></tr>';
    }

    $header_contact_parts = [];
    if ($sender_address !== '') {
        $header_contact_parts[] = $escape_html(preview_single_line($sender_address, ' · '));
    }
    if ($sender_phone !== '') {
        $header_contact_parts[] = $escape_html($sender_phone);
    }
    if ($sender_email !== '') {
        $header_contact_parts[] = '<a href="mailto:' . $escape_html($sender_email) . '" style="color:#93c5fd;text-decoration:none;">' . $escape_html($sender_email) . '</a>';
    }
    $header_contact_html = implode(' &nbsp;·&nbsp; ', $header_contact_parts);

    $footer_parts = [];
    if ($sender_address !== '') {
        $footer_parts[] = $escape_html(preview_single_line($sender_address, ', '));
    }
    if ($sender_phone !== '') {
        $footer_parts[] = $escape_html($sender_phone);
    }
    if ($sender_email !== '') {
        $footer_parts[] = '<a href="mailto:' . $escape_html($sender_email) . '" style="color:#93c5fd;text-decoration:none;">' . $escape_html($sender_email) . '</a>';
    }
    $footer_contact_html = implode(' &nbsp;·&nbsp; ', $footer_parts);

    $bill_to_lines = [];
    if ($customer_company !== '') {
        $bill_to_lines[] = '<strong style="color:#0f172a;">' . $escape_html($customer_company) . '</strong>';
    }
    if ($customer_name !== '') {
        $bill_to_lines[] = $escape_html($customer_name);
    }
    if ($bill_street !== '') {
        $bill_to_lines[] = $escape_html($bill_street);
    }
    $state_zip = trim($bill_state . ($bill_zip !== '' ? ' ' . $bill_zip : ''));
    $city_state_zip = implode(', ', array_filter([$bill_city, $state_zip]));
    if ($city_state_zip !== '') {
        $bill_to_lines[] = $escape_html($city_state_zip);
    }
    $quote_phone = trim((string)($quote['phone_number'] ?? ''));
    if ($quote_phone !== '') {
        $bill_to_lines[] = $escape_html($quote_phone);
    }
    $quote_email = trim((string)($quote['email'] ?? ''));
    if ($quote_email !== '') {
        $bill_to_lines[] = '<a href="mailto:' . $escape_html($quote_email) . '" style="color:#1d4ed8;text-decoration:none;">' . $escape_html($quote_email) . '</a>';
    }
    $bill_to_html = implode('<br>', $bill_to_lines);

    $from_lines = ['<strong style="color:#0f172a;">' . $escape_html($sender_company) . '</strong>'];
    if ($sender_name !== '' && $sender_name !== $sender_company) {
        $from_lines[] = $escape_html($sender_name);
    }
    foreach (array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $sender_address))) as $addr_line) {
        $from_lines[] = $escape_html($addr_line);
    }
    if ($sender_phone !== '') {
        $from_lines[] = $escape_html($sender_phone);
    }
    if ($sender_email !== '') {
        $from_lines[] = '<a href="mailto:' . $escape_html($sender_email) . '" style="color:#1d4ed8;text-decoration:none;">' . $escape_html($sender_email) . '</a>';
    }
    $from_html = implode('<br>', $from_lines);

    if ($sender_name !== '') {
        $prepared_by_html = 'This ' . $document_noun_html . ' was prepared by <strong style="color:#1e293b;">' . $escape_html($sender_name) . '</strong>';
        if ($sender_company !== 'Our Company') {
            $prepared_by_html .= ' at <strong style="color:#1e293b;">' . $escape_html($sender_company) . '</strong>';
        }
        $prepared_by_html .= '.';
    }

    $html = '<!doctype html>'
        . '<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="max-width:680px;margin:32px auto 32px;">'
        . '<div style="background:#1e3a5f;border-radius:8px 8px 0 0;padding:28px 32px 24px;">'
          . '<p style="margin:0 0 6px;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.3px;">' . $escape_html($sender_company) . '</p>'
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
                . '<p style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">' . $document_heading . '</p>'
              . '</td>'
              . '<td style="padding:0 0 16px;text-align:right;">'
                . '<p style="margin:0;font-size:13px;color:#64748b;">Date: ' . $escape_html($document_date) . '</p>'
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
          . '<p style="margin:0 0 8px;font-size:15px;color:#1e293b;">Hello' . ($customer_name !== '' ? ', ' . $escape_html($customer_name) : '') . ',</p>'
          . '<p style="margin:0 0 24px;font-size:14px;color:#475569;">' . $document_intro . '</p>'
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
                . '<td style="padding:10px 12px;text-align:right;font-weight:600;font-size:14px;color:#1e3a5f;border-top:2px solid #e2e8f0;">$' . $escape_html($subtotal) . '</td>'
              . '</tr>'
              . ($tax_rate > 0
                  ? '<tr>'
                      . '<td colspan="3" style="padding:4px 12px;text-align:right;font-weight:600;font-size:13px;color:#1e293b;">Tax (' . $escape_html(number_format($tax_rate, 2)) . '%):</td>'
                      . '<td style="padding:4px 12px;text-align:right;font-weight:600;font-size:14px;color:#1e3a5f;">$' . $escape_html($tax_amount) . '</td>'
                    . '</tr>'
                  : '')
              . '<tr>'
                . '<td colspan="3" style="padding:10px 12px;text-align:right;font-weight:700;font-size:14px;color:#1e293b;">Grand Total:</td>'
                . '<td style="padding:10px 12px;text-align:right;font-weight:700;font-size:16px;color:#1e3a5f;">$' . $escape_html($grand_total) . '</td>'
              . '</tr>'
            . '</tfoot>'
          . '</table>'
          . '<p style="margin:0;font-size:14px;color:#475569;">' . $document_closing . '</p>'
        . '</div>'
        . ($prepared_by_html !== ''
            ? '<div style="background:#f8fafc;padding:14px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">'
                . '<p style="margin:0;font-size:13px;color:#64748b;">' . $prepared_by_html . '</p>'
              . '</div>'
            : '')
        . '<div style="background:#1e3a5f;border-radius:0 0 8px 8px;padding:18px 32px;">'
          . '<p style="margin:0;font-size:12px;color:#93c5fd;line-height:1.6;">'
            . $escape_html($sender_company)
            . ($footer_contact_html !== '' ? ' &nbsp;·&nbsp; ' . $footer_contact_html : '')
          . '</p>'
        . '</div>'
        . '</div>'
        . '</body></html>';

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><body style="margin:0;padding:16px;font-family:Arial,sans-serif;">'
        . '<div style="padding:14px 16px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:6px;line-height:1.5;">'
        . '<strong>Error:</strong><br>'
        . nl2br(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'))
        . '</div></body></html>';
}
