<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';
require_admin_or_moderator();

const STRIPE_AMOUNT_TOLERANCE    = 0.01;
const STRIPE_API_TIMEOUT_SECONDS = 20;
const INVOICE_PAYMENT_STATUS_UNPAID = 'unpaid';
const INVOICE_PAYMENT_STATUS_PAID   = 'paid';

// Required for Stripe integration
function app_ensure_integration_settings_table(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_val TEXT,
        is_encrypted TINYINT(1) DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function app_decrypt_setting_value(string $encrypted): string {
    return $encrypted; // placeholder - update if you implement encryption later
}

// ---- Helpers ----------------------------------------------------------------

function invoice_env_value(string $key): string {
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

function invoice_online_payment_enabled(array $quote): bool {
    return (int)($quote['enable_online_payment'] ?? 0) === 1;
}

function invoice_is_paid(array $quote): bool {
    return strtolower(trim((string)($quote['payment_status'] ?? INVOICE_PAYMENT_STATUS_UNPAID))) === INVOICE_PAYMENT_STATUS_PAID;
}

function invoice_sender_profile(PDO $pdo, ?int $created_by): array {
    $profile = ['sender_name' => '', 'company_name' => '', 'address' => '', 'phone' => '', 'email' => ''];
    $candidate_ids = [];
    if ($created_by !== null && $created_by > 0) {
        $candidate_ids[] = $created_by;
    }
    $session_user_id = (int)($_SESSION['user_id'] ?? 0);
    if ($session_user_id > 0 && !in_array($session_user_id, $candidate_ids, true)) {
        $candidate_ids[] = $session_user_id;
    }
    if (!$candidate_ids) return $profile;
    $stmt = $pdo->prepare(
        "SELECT username, contact_name, company_name, delivery_address, contact_phone, email
         FROM users WHERE id = ? LIMIT 1"
    );
    foreach ($candidate_ids as $uid) {
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        if (!$row) continue;
        $contact_name        = trim((string)($row['contact_name'] ?? ''));
        $username            = trim((string)($row['username']     ?? ''));
        $profile['sender_name']  = $contact_name !== '' ? $contact_name : $username;
        $profile['company_name'] = trim((string)($row['company_name']     ?? ''));
        $profile['address']      = trim((string)($row['delivery_address'] ?? ''));
        $profile['phone']        = trim((string)($row['contact_phone']    ?? ''));
        $profile['email']        = trim((string)($row['email']            ?? ''));
        break;
    }
    return $profile;
}

function invoice_has_valid_checkout_session(array $quote, float $amount): bool {
    $existing_url        = trim((string)($quote['stripe_checkout_url']        ?? ''));
    $existing_session_id = trim((string)($quote['stripe_checkout_session_id'] ?? ''));
    $existing_amount     = isset($quote['stripe_checkout_amount'])
        ? round((float)$quote['stripe_checkout_amount'], 2)
        : null;
    return $existing_url !== ''
        && $existing_session_id !== ''
        && $existing_amount !== null
        && abs($existing_amount - $amount) < STRIPE_AMOUNT_TOLERANCE;
}

function invoice_stripe_secret_key(PDO $pdo): string {
    $secret_key = invoice_env_value('STRIPE_SECRET_KEY');
    if ($secret_key !== '') return $secret_key;
    try {
        app_ensure_integration_settings_table($pdo);
        $stmt = $pdo->prepare(
            "SELECT setting_val, is_encrypted
             FROM integration_settings
             WHERE setting_key = 'stripe_secret_key'
             LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if (is_array($row)) {
            $stored = trim((string)($row['setting_val'] ?? ''));
            if ($stored !== '') {
                $is_encrypted = (int)($row['is_encrypted'] ?? 0) === 1;
                $resolved = $is_encrypted ? app_decrypt_setting_value($stored) : $stored;
                $resolved = trim((string)$resolved);
                if ($resolved !== '') return $resolved;
            }
        }
    } catch (Throwable $e) {
        error_log('Stripe secret key lookup failed: ' . $e->getMessage());
    }
    return '';
}

function invoice_public_base_url(): string {
    $configured = trim(invoice_env_value('APP_URL'));
    if ($configured !== '') return rtrim($configured, '/');
    $forwarded_proto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $https_on = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $forwarded_proto !== '' ? $forwarded_proto : ($https_on ? 'https' : 'http');
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
    $script_dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($script_dir === '.' || $script_dir === '/') $script_dir = '';
    return $scheme . '://' . $host . rtrim($script_dir, '/');
}

function invoice_public_url(string $path, array $params = []): string {
    $url = invoice_public_base_url() . '/' . ltrim($path, '/');
    if ($params) {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($query !== '') $url .= '?' . $query;
    }
    return $url;
}

function invoice_checkout_session_url(PDO $pdo, array &$quote, ?string &$error_message = null): string {
    $error_message = null;
    if (!invoice_online_payment_enabled($quote)) return '';
    $quote_id = (int)($quote['id'] ?? 0);
    if ($quote_id <= 0) {
        $error_message = 'Invoice must be saved before generating an online payment link.';
        return '';
    }
    $amount = round((float)($quote['subtotal_amount'] ?? 0), 2);
    if ($amount <= 0) {
        $error_message = 'Online payment requires an invoice total greater than $0.00.';
        return '';
    }
    if (invoice_has_valid_checkout_session($quote, $amount)) {
        return trim((string)($quote['stripe_checkout_url'] ?? ''));
    }
    if (!function_exists('curl_init')) {
        $error_message = 'Stripe checkout could not be created because cURL is not available on this server.';
        return '';
    }
    $secret_key = invoice_stripe_secret_key($pdo);
    if ($secret_key === '') {
        $error_message = 'Stripe secret key is not configured. Please save it in Admin > Integrations or set STRIPE_SECRET_KEY.';
        return '';
    }
    $invoice_number = trim((string)($quote['converted_invoice_no'] ?? ''));
    if ($invoice_number === '') $invoice_number = '#' . $quote_id;
    $customer_name  = trim((string)($quote['customer_name'] ?? ''));
    $company_name   = trim((string)($quote['company_name']  ?? ''));
    $customer_email = trim((string)($quote['email']         ?? ''));
    $description_parts = array_filter([
        $company_name   !== '' ? $company_name   : null,
        $customer_name  !== '' ? $customer_name  : null,
    ]);
    $product_description = implode(' • ', $description_parts);
    $amount_cents = (int)round($amount * 100);
    $payload = [
        'mode' => 'payment',
        'success_url' => invoice_public_url('invoice_payment_status.php', ['status' => 'success']),
        'cancel_url'  => invoice_public_url('invoice_payment_status.php', ['status' => 'cancel']),
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
        CURLOPT_TIMEOUT        => STRIPE_API_TIMEOUT_SECONDS,
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
        $error_message = $curl_error !== '' ? $curl_error : 'Stripe checkout request failed.';
        return '';
    }
    $response = json_decode($response_body, true);
    if (!is_array($response)) {
        $error_message = 'Stripe returned an invalid response.';
        return '';
    }
    if ($http_code >= 400) {
        $stripe_error  = trim((string)($response['error']['message'] ?? ''));
        $error_message = $stripe_error !== '' ? $stripe_error : 'Stripe checkout request failed.';
        return '';
    }
    $checkout_url = trim((string)($response['url'] ?? ''));
    $session_id   = trim((string)($response['id']  ?? ''));
    if ($checkout_url === '' || $session_id === '') {
        $error_message = 'Stripe did not return a hosted checkout link.';
        return '';
    }
    $pdo->prepare(
        "UPDATE quotes
            SET stripe_checkout_url = ?,
                stripe_checkout_session_id = ?,
                stripe_checkout_created_at = NOW(),
                stripe_checkout_amount = ?
          WHERE id = ?"
    )->execute([$checkout_url, $session_id, $amount, $quote_id]);
    $quote['stripe_checkout_url']        = $checkout_url;
    $quote['stripe_checkout_session_id'] = $session_id;
    $quote['stripe_checkout_amount']     = $amount;
    return $checkout_url;
}

function invoice_build_email_message_data(PDO $pdo, array $quote, array $items, bool $require_payment_link = true, ?string &$error_message = null): ?array {
    $error_message  = null;
    $created_by     = isset($quote['created_by']) && $quote['created_by'] !== null ? (int)$quote['created_by'] : null;
    $sender         = invoice_sender_profile($pdo, $created_by);
    $sender_name    = $sender['sender_name'];
    $smtp_from_name = trim(str_replace(["\r", "\n"], ' ', invoice_env_value('SMTP_FROM_NAME')));
    $sender_company = $sender['company_name'] !== '' ? $sender['company_name'] : $smtp_from_name;
    if ($sender_company === '') $sender_company = 'Our Company';
    $sender_address   = $sender['address'];
    $sender_phone     = $sender['phone'];
    $sender_email_env = invoice_env_value('SMTP_FROM_EMAIL');
    $sender_email     = $sender['email'] !== '' ? $sender['email'] : $sender_email_env;

    $inv_no          = trim((string)($quote['converted_invoice_no'] ?? ''));
    $customer_name   = trim((string)($quote['customer_name'] ?? ''));
    $customer_company = trim((string)($quote['company_name'] ?? ''));
    $inv_date        = trim((string)($quote['quote_date']    ?? ''));
    $subtotal        = number_format((float)($quote['subtotal_amount'] ?? 0), 2);
    $inv_tax_rate    = (float)($quote['tax_rate']   ?? 0);
    $inv_tax_amount  = number_format((float)($quote['tax_amount']  ?? 0), 2);
    $inv_grand_total = number_format((float)($quote['subtotal_amount'] ?? 0) + (float)($quote['tax_amount'] ?? 0), 2);

    $bill_street = trim((string)($quote['billing_street'] ?? ''));
    $bill_city   = trim((string)($quote['billing_city']   ?? ''));
    $bill_state  = trim((string)($quote['billing_state']  ?? ''));
    $bill_zip    = trim((string)($quote['billing_zip']    ?? ''));

    $is_paid     = invoice_is_paid($quote);
    $payment_link = '';
    if (!$is_paid && invoice_online_payment_enabled($quote)) {
        $payment_error = null;
        $payment_link  = invoice_checkout_session_url($pdo, $quote, $payment_error);
        if ($payment_link === '' && $require_payment_link) {
            $error_message = trim((string)$payment_error) !== '' ? trim((string)$payment_error) : 'Unable to create Stripe checkout link for this invoice.';
            return null;
        }
    }

    $subject = $sender_company . ' - Invoice ' . ($inv_no !== '' ? $inv_no : '#' . (int)$quote['id']);

    // ---- Build HTML rows ----
    $rows_html = [];
    $rows_text = [];
    $row_index = 0;
    foreach ($items as $item) {
        $desc       = trim((string)($item['description'] ?? ''));
        $qty        = number_format((float)($item['quantity']   ?? 0), 2);
        $unit_price = number_format((float)($item['unit_price'] ?? 0), 2);
        $line_total = number_format((float)($item['line_total'] ?? 0), 2);
        $row_bg     = ($row_index % 2 === 0) ? '#ffffff' : '#f9fafb';
        $rows_html[] = '<tr style="background:' . $row_bg . ';">'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#374151;">' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">' . htmlspecialchars($qty, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">$' . htmlspecialchars($unit_price, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">$' . htmlspecialchars($line_total, ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr>';
        $rows_text[] = '- ' . $desc . ' | Qty: ' . $qty . ' | Price: $' . $unit_price . ' | Total: $' . $line_total;
        $row_index++;
    }
    if (!$rows_html) {
        $rows_html[] = '<tr><td colspan="4" style="padding:10px 12px;text-align:center;color:#6b7280;">No line items.</td></tr>';
        $rows_text[] = '- No line items.';
    }

    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $header_parts = [];
    if ($sender_address !== '') {
        $addr_oneline    = str_replace(["\r\n", "\r", "\n"], ' · ', $sender_address);
        $addr_oneline    = preg_replace('/\s+/', ' ', $addr_oneline);
        $header_parts[]  = $h($addr_oneline);
    }
    if ($sender_phone !== '') $header_parts[] = $h($sender_phone);
    if ($sender_email !== '') {
        $header_parts[] = '<a href="mailto:' . $h($sender_email) . '" style="color:#93c5fd;text-decoration:none;">' . $h($sender_email) . '</a>';
    }
    $header_contact_html = implode(' &nbsp;·&nbsp; ', $header_parts);

    $prepared_by_html = '';
    if ($sender_name !== '') {
        $prepared_by_html = 'This invoice was prepared by <strong style="color:#1e293b;">' . $h($sender_name) . '</strong>';
        if ($sender_company !== 'Our Company') {
            $prepared_by_html .= ' at <strong style="color:#1e293b;">' . $h($sender_company) . '</strong>';
        }
        $prepared_by_html .= '.';
    }

    $footer_parts = [];
    if ($sender_address !== '') {
        $footer_parts[] = $h(preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], ', ', $sender_address)));
    }
    if ($sender_phone !== '') $footer_parts[] = $h($sender_phone);
    if ($sender_email !== '') {
        $footer_parts[] = '<a href="mailto:' . $h($sender_email) . '" style="color:#93c5fd;text-decoration:none;">' . $h($sender_email) . '</a>';
    }
    $footer_contact_html = implode(' &nbsp;·&nbsp; ', $footer_parts);

    $inv_label = $inv_no !== '' ? $h($inv_no) : '#' . (int)$quote['id'];

    $bill_to_lines = [];
    if ($customer_company !== '') $bill_to_lines[] = '<strong style="color:#0f172a;">' . $h($customer_company) . '</strong>';
    if ($customer_name !== '')    $bill_to_lines[] = $h($customer_name);
    if ($bill_street !== '')      $bill_to_lines[] = $h($bill_street);
    $bill_csz_parts = array_filter([$bill_city, $bill_state . ($bill_zip !== '' ? ' ' . $bill_zip : '')]);
    $bill_csz = implode(', ', $bill_csz_parts);
    if ($bill_csz !== '')         $bill_to_lines[] = $h($bill_csz);
    if (trim((string)($quote['phone_number'] ?? '')) !== '') $bill_to_lines[] = $h(trim((string)($quote['phone_number'] ?? '')));
    if (trim((string)($quote['email'] ?? '')) !== '') $bill_to_lines[] = '<a href="mailto:' . $h(trim((string)($quote['email'] ?? ''))) . '" style="color:#1d4ed8;text-decoration:none;">' . $h(trim((string)($quote['email'] ?? ''))) . '</a>';
    $bill_to_html = implode('<br>', $bill_to_lines);

    $from_lines = [];
    $from_lines[] = '<strong style="color:#0f172a;">' . $h($sender_company) . '</strong>';
    if ($sender_name !== '' && $sender_name !== $sender_company) $from_lines[] = $h($sender_name);
    foreach (array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $sender_address))) as $addr_line) {
        $from_lines[] = $h($addr_line);
    }
    if ($sender_phone !== '') $from_lines[] = $h($sender_phone);
    if ($sender_email !== '') $from_lines[] = '<a href="mailto:' . $h($sender_email) . '" style="color:#1d4ed8;text-decoration:none;">' . $h($sender_email) . '</a>';
    $from_html = implode('<br>', $from_lines);

    $html_body = '<!doctype html>'
        . '<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="max-width:680px;margin:32px auto 32px;">'
        . '<div style="background:#1e3a5f;border-radius:8px 8px 0 0;padding:28px 32px 24px;">'
          . '<p style="margin:0 0 6px;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.3px;">' . $h($sender_company) . '</p>'
          . ($header_contact_html !== '' ? '<p style="margin:0;font-size:13px;color:#93c5fd;line-height:1.6;">' . $header_contact_html . '</p>' : '')
        . '</div>'
        . ($is_paid
            ? '<div style="background:#ffffff;padding:16px 32px 0;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
                . '<div style="margin:0 0 4px;padding:14px 18px;border:4px solid #dc2626;border-radius:10px;background:#fee2e2;text-align:center;">'
                  . '<span style="display:inline-block;font-size:56px;line-height:1;font-weight:900;letter-spacing:0.16em;color:#b91c1c;text-transform:uppercase;">PAID</span>'
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
                . '<p style="margin:0;font-size:13px;color:#64748b;">Date: ' . $h($inv_date) . '</p>'
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
          . ($payment_link !== ''
              ? '<div style="margin:0 0 24px;padding:16px 18px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;">'
                  . '<p style="margin:0 0 10px;font-size:14px;font-weight:600;color:#1d4ed8;">Pay this invoice online</p>'
                  . '<p style="margin:0 0 14px;font-size:13px;color:#334155;">Use Stripe\'s secure checkout page to pay this invoice online. Card details are entered directly on Stripe and are not collected on our site.</p>'
                  . '<p style="margin:0;"><a href="' . $h($payment_link) . '" style="display:inline-block;padding:11px 18px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:999px;font-weight:700;">Pay Invoice on Stripe</a></p>'
                . '</div>'
              : '')
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
              . ($inv_tax_rate > 0
                  ? '<tr>'
                      . '<td colspan="3" style="padding:4px 12px;text-align:right;font-weight:600;font-size:13px;color:#1e293b;">Tax (' . $h(number_format($inv_tax_rate, 2)) . '%):</td>'
                      . '<td style="padding:4px 12px;text-align:right;font-weight:600;font-size:14px;color:#1e3a5f;">$' . $h($inv_tax_amount) . '</td>'
                    . '</tr>'
                  : '')
              . '<tr>'
                . '<td colspan="3" style="padding:10px 12px;text-align:right;font-weight:700;font-size:14px;color:#1e293b;">Grand Total:</td>'
                . '<td style="padding:10px 12px;text-align:right;font-weight:700;font-size:16px;color:#1e3a5f;">$' . $h($inv_grand_total) . '</td>'
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

    $text_body  = $sender_company . "\r\n";
    if ($sender_address !== '') $text_body .= preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], ', ', $sender_address)) . "\r\n";
    if ($sender_phone !== '')   $text_body .= $sender_phone . "\r\n";
    if ($sender_email !== '')   $text_body .= $sender_email . "\r\n";
    $text_body .= "\r\nInvoice " . ($inv_no !== '' ? $inv_no : '#' . (int)$quote['id']) . "  |  Date: {$inv_date}\r\n";
    $text_body .= str_repeat('-', 40) . "\r\n";
    $text_body .= "Bill To: " . ($customer_company !== '' ? $customer_company . ' / ' : '') . $customer_name . "\r\n";
    if ($bill_street !== '') $text_body .= $bill_street . "\r\n";
    if ($bill_csz !== '')    $text_body .= $bill_csz . "\r\n";
    $text_body .= str_repeat('-', 40) . "\r\n\r\n";
    if ($is_paid) $text_body .= "PAID\r\n\r\n";
    $text_body .= "Hello" . ($customer_name !== '' ? ", {$customer_name}" : '') . ",\r\n\r\n";
    $text_body .= "Please find your invoice details below.\r\n\r\nLine Items:\r\n";
    if ($payment_link !== '') {
        $text_body .= "Pay online with Stripe: {$payment_link}\r\n";
        $text_body .= "Card details are entered directly on Stripe's secure checkout page.\r\n\r\n";
    }
    $text_body .= implode("\r\n", $rows_text) . "\r\n\r\n";
    $text_body .= "Subtotal: \${$subtotal}\r\n";
    if ($inv_tax_rate > 0) {
        $text_body .= "Tax (" . number_format($inv_tax_rate, 2) . "%): \${$inv_tax_amount}\r\n";
    }
    $text_body .= "Grand Total: \${$inv_grand_total}\r\n\r\n";
    $text_body .= "Thank you for your business.\r\n";
    if ($sender_name !== '') {
        $text_body .= "\r\nPrepared by: {$sender_name}" . ($sender_company !== 'Our Company' ? " at {$sender_company}" : '') . "\r\n";
    }

    return [
        'subject'   => $subject,
        'html_body' => $html_body,
        'text_body' => $text_body,
    ];
}

// ---- Main -------------------------------------------------------------------

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($id === false || $id === null) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Unable to generate email preview.</h2>';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if ($quote) {
    $items_stmt = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC');
    $items_stmt->execute([$id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    $error   = null;
    $payload = invoice_build_email_message_data($pdo, $quote, $items, false, $error);

    if (is_array($payload) && !empty($payload['html_body'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo $payload['html_body'];
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
echo '<h2>Unable to generate email preview.</h2>';
