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

function preview_contact_address_line(string $address, string $company, string $separator): string {
    $line = preview_single_line($address, $separator);
    if ($line === '' || $company === '') {
        return $line;
    }

    $pattern = '/^' . preg_quote($company, '/') . '(?:\s*(?:,|·|-)\s*)?/i';
    $line = trim((string)preg_replace($pattern, '', $line));
    return $line;
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

function preview_env_value(string $key): string {
    static $dotenv_values = null;
    if ($dotenv_values === null) {
        $dotenv_values = [];
        $dotenv_path = __DIR__ . '/.env';
        if (is_file($dotenv_path) && is_readable($dotenv_path)) {
            $lines = file($dotenv_path, FILE_IGNORE_NEW_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim((string)$line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    $separator_pos = strpos($line, '=');
                    if ($separator_pos === false) continue;
                    $name = trim(substr($line, 0, $separator_pos));
                    if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) continue;
                    $value = trim(substr($line, $separator_pos + 1));
                    if (strlen($value) >= 2) {
                        $first = $value[0]; $last = $value[strlen($value) - 1];
                        if ($first === '"' && $last === '"') {
                            $value = substr($value, 1, -1);
                            $value = strtr($value, ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n", '\\r' => "\r", '\\t' => "\t"]);
                        } elseif ($first === "'" && $last === "'") {
                            $value = substr($value, 1, -1);
                            $value = strtr($value, ['\\\\' => '\\', "\\'" => "'"]);
                        }
                    } else {
                        $value = rtrim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
                    }
                    $dotenv_values[$name] = $value;
                }
            }
        }
    }
    foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null, $dotenv_values[$key] ?? null] as $candidate) {
        $v = trim((string)$candidate);
        if ($v !== '') return $v;
    }
    return '';
}

function preview_logo_path(): string {
    $path = __DIR__ . '/logo1.jpg';
    return is_file($path) && is_readable($path) ? $path : '';
}

function preview_logo_html(string $src): string {
    if ($src === '') {
        return '';
    }

    $escaped_src = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
    return '<img src="' . $escaped_src . '" alt="Company logo" style="display:block;max-width:100%;width:auto;height:auto;max-height:72px;border:0;outline:none;text-decoration:none;">';
}

function preview_stripe_secret_key(PDO $pdo): string {
    $secret_key = preview_env_value('STRIPE_SECRET_KEY');
    if ($secret_key !== '') {
        return $secret_key;
    }
    try {
        app_ensure_integration_settings_table($pdo);
        $stmt = $pdo->prepare(
            "SELECT setting_val, is_encrypted FROM integration_settings WHERE setting_key = 'stripe_secret_key' LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if (is_array($row)) {
            $stored = trim((string)($row['setting_val'] ?? ''));
            if ($stored !== '') {
                $is_encrypted = (int)($row['is_encrypted'] ?? 0) === 1;
                $resolved = $is_encrypted ? app_decrypt_setting_value($stored) : $stored;
                $resolved = trim((string)$resolved);
                if ($resolved !== '') {
                    return $resolved;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Invoice preview: Stripe secret key lookup failed: ' . $e->getMessage());
    }
    return '';
}

function preview_has_valid_checkout_session(array $quote, float $amount): bool {
    $existing_url = trim((string)($quote['stripe_checkout_url'] ?? ''));
    $existing_session_id = trim((string)($quote['stripe_checkout_session_id'] ?? ''));
    $existing_amount = isset($quote['stripe_checkout_amount'])
        ? round((float)$quote['stripe_checkout_amount'], 2)
        : null;
    return $existing_url !== ''
        && $existing_session_id !== ''
        && $existing_amount !== null
        && abs($existing_amount - $amount) < 0.01;
}

function preview_checkout_session_url(PDO $pdo, array &$quote): string {
    if ((int)($quote['enable_online_payment'] ?? 0) !== 1) {
        return '';
    }
    $quote_id = (int)($quote['id'] ?? 0);
    if ($quote_id <= 0) {
        return '';
    }
    $amount = round((float)($quote['subtotal_amount'] ?? 0), 2);
    if ($amount <= 0) {
        return '';
    }
    if (preview_has_valid_checkout_session($quote, $amount)) {
        return trim((string)($quote['stripe_checkout_url'] ?? ''));
    }
    if (!function_exists('curl_init')) {
        error_log('Invoice preview: Stripe checkout could not be created — cURL not available.');
        return '';
    }
    $secret_key = preview_stripe_secret_key($pdo);
    if ($secret_key === '') {
        return '';
    }
    $invoice_number = trim((string)($quote['converted_invoice_no'] ?? ''));
    if ($invoice_number === '') {
        $invoice_number = '#' . $quote_id;
    }
    $customer_name  = trim((string)($quote['customer_name'] ?? ''));
    $company_name   = trim((string)($quote['company_name'] ?? ''));
    $customer_email = trim((string)($quote['email'] ?? ''));
    $description_parts = array_filter([
        $company_name !== '' ? $company_name : null,
        $customer_name !== '' ? $customer_name : null,
    ]);
    $product_description = implode(' • ', $description_parts);
    $amount_cents = (int)round($amount * 100);

    $configured_base = rtrim(preview_env_value('APP_URL'), '/');
    if ($configured_base === '') {
        $forwarded_proto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        $https_on = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        $scheme = $forwarded_proto !== '' ? $forwarded_proto : ($https_on ? 'https' : 'http');
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
        $script_dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
        if ($script_dir === '.' || $script_dir === '/') {
            $script_dir = '';
        }
        $configured_base = $scheme . '://' . $host . rtrim($script_dir, '/');
    }
    $success_url = $configured_base . '/invoice_payment_status.php?' . http_build_query(['status' => 'success'], '', '&', PHP_QUERY_RFC3986);
    $cancel_url  = $configured_base . '/invoice_payment_status.php?' . http_build_query(['status' => 'cancel'],  '', '&', PHP_QUERY_RFC3986);

    $payload = [
        'mode' => 'payment',
        'success_url' => $success_url,
        'cancel_url'  => $cancel_url,
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'usd',
                'product_data' => array_filter([
                    'name'        => 'Invoice ' . $invoice_number,
                    'description' => $product_description !== '' ? $product_description : null,
                ], static fn($v) => $v !== null && $v !== ''),
                'unit_amount' => $amount_cents,
            ],
            'quantity' => 1,
        ]],
        'metadata' => array_filter([
            'invoice_id'     => (string)$quote_id,
            'invoice_number' => $invoice_number,
            'customer_name'  => $customer_name,
            'company_name'   => $company_name,
        ], static fn($v) => $v !== ''),
        'submit_type' => 'pay',
    ];
    if ($customer_email !== '' && filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        $payload['customer_email'] = $customer_email;
    }

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secret_key,
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $response_body = curl_exec($ch);
    $curl_error    = curl_error($ch);
    $http_code     = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response_body === false) {
        error_log('Invoice preview: Stripe checkout failed for #' . $quote_id . ': ' . ($curl_error !== '' ? $curl_error : 'cURL request failed'));
        return '';
    }
    $response = json_decode($response_body, true);
    if (!is_array($response)) {
        error_log('Invoice preview: Stripe checkout failed for #' . $quote_id . ': invalid JSON response');
        return '';
    }
    if ($http_code >= 400) {
        $stripe_err = trim((string)($response['error']['message'] ?? ''));
        error_log('Invoice preview: Stripe checkout failed for #' . $quote_id . ': ' . ($stripe_err !== '' ? $stripe_err : 'HTTP ' . $http_code));
        return '';
    }
    $checkout_url = trim((string)($response['url'] ?? ''));
    $session_id   = trim((string)($response['id']  ?? ''));
    if ($checkout_url === '' || $session_id === '') {
        error_log('Invoice preview: Stripe checkout failed for #' . $quote_id . ': no URL or session ID in response');
        return '';
    }

    $pdo->prepare(
        "UPDATE quotes SET stripe_checkout_url = ?, stripe_checkout_session_id = ?, stripe_checkout_created_at = NOW(), stripe_checkout_amount = ? WHERE id = ?"
    )->execute([$checkout_url, $session_id, $amount, $quote_id]);

    $quote['stripe_checkout_url']        = $checkout_url;
    $quote['stripe_checkout_session_id'] = $session_id;
    $quote['stripe_checkout_amount']     = $amount;

    return $checkout_url;
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
    $enable_online_payment = (int)($quote['enable_online_payment'] ?? 0) === 1;
    $stripe_checkout_url = trim((string)($quote['stripe_checkout_url'] ?? ''));
    if ($is_invoice && !$is_paid && $enable_online_payment) {
        $generated_url = preview_checkout_session_url($pdo, $quote);
        if ($generated_url !== '') {
            $stripe_checkout_url = $generated_url;
        }
    }

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
    $header_address = preview_contact_address_line($sender_address, $sender_company, ' · ');
    if ($header_address !== '') {
        $header_contact_parts[] = $escape_html($header_address);
    }
    if ($sender_phone !== '') {
        $header_contact_parts[] = $escape_html($sender_phone);
    }
    if ($sender_email !== '') {
        $header_contact_parts[] = '<a href="mailto:' . $escape_html($sender_email) . '" style="color:#93c5fd;text-decoration:none;">' . $escape_html($sender_email) . '</a>';
    }
    $header_contact_html = implode(' &nbsp;·&nbsp; ', $header_contact_parts);
    $logo_html = preview_logo_html(preview_logo_path() !== '' ? 'logo1.jpg' : '');
    $header_company_name = 'Laser Cutter Repair';
    $header_brand_html = '<p style="margin:0 0 6px;font-size:32px;font-weight:800;line-height:1.1;color:#ffffff;letter-spacing:0.4px;">' . $escape_html($header_company_name) . '</p>'
        . ($header_contact_html !== '' ? '<p style="margin:0;font-size:13px;font-weight:400;color:#93c5fd;line-height:1.6;">' . $header_contact_html . '</p>' : '');
    $header_identity_html = $logo_html !== ''
        ? '<div style="display:flex;align-items:center;gap:16px;">'
            . '<div style="flex:0 0 auto;">' . $logo_html . '</div>'
            . '<div style="flex:1 1 auto;">' . $header_brand_html . '</div>'
          . '</div>'
        : $header_brand_html;

    $footer_parts = [];
    $footer_address = preview_contact_address_line($sender_address, $sender_company, ', ');
    if ($footer_address !== '') {
        $footer_parts[] = $escape_html($footer_address);
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
          . $header_identity_html
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
          . ($is_invoice && $enable_online_payment
              ? '<div style="margin:0 0 20px;padding:16px 18px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;">'
                  . '<p style="margin:0 0 10px;font-size:14px;font-weight:600;color:#1d4ed8;">Pay this invoice online</p>'
                  . '<p style="margin:0 0 14px;font-size:13px;color:#334155;">Use Stripe\'s secure checkout page to pay this invoice online. Card details are entered directly on Stripe and are not collected on our site.</p>'
                  . ($stripe_checkout_url !== ''
                      ? '<p style="margin:0;"><a href="' . $escape_html($stripe_checkout_url) . '" style="display:inline-block;padding:11px 18px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:999px;font-weight:700;">Pay Invoice on Stripe</a></p>'
                      : '<p style="margin:0;font-size:13px;color:#64748b;font-style:italic;">Payment link will be included when this invoice is emailed.</p>')
                . '</div>'
              : '')
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