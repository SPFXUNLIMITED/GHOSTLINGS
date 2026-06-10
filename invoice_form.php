<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';
require_admin_or_moderator();

const INVOICE_DEFAULT_QTY = '1.00';
const INVOICE_DEFAULT_COST = '0.00';
const INVOICE_DEFAULT_MARKUP = '20.00';
const INVOICE_DEFAULT_PRICE = '0.00';
const INVOICE_MIN_QTY = 0.01;
const STRIPE_AMOUNT_TOLERANCE = 0.00001;
const STRIPE_API_TIMEOUT_SECONDS = 20;

// ---------- CSRF ----------
if (empty($_SESSION['invoice_form_csrf'])) {
  $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));
}

$view_mode_requested = isset($_GET['mode']) && $_GET['mode'] === 'view';

// ---------- Invoice email helpers ----------

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

function invoice_stripe_secret_key(PDO $pdo): string {
  $secret_key = invoice_env_value('STRIPE_SECRET_KEY');
  if ($secret_key !== '') {
    return $secret_key;
  }

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
        if ($resolved !== '') {
          return $resolved;
        }
      }
    }
  } catch (Throwable $e) {
    error_log('Stripe secret key lookup failed: ' . $e->getMessage());
  }

  return '';
}

function invoice_public_base_url(): string {
  $configured = trim(invoice_env_value('APP_URL'));
  if ($configured !== '') {
    return rtrim($configured, '/');
  }

  $forwarded_proto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
  $https_on = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
  $scheme = $forwarded_proto !== '' ? $forwarded_proto : ($https_on ? 'https' : 'http');
  $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
  $script_dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
  if ($script_dir === '.' || $script_dir === '/') {
    $script_dir = '';
  }

  return $scheme . '://' . $host . rtrim($script_dir, '/');
}

function invoice_public_url(string $path, array $params = []): string {
  $url = invoice_public_base_url() . '/' . ltrim($path, '/');
  if ($params) {
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    if ($query !== '') {
      $url .= '?' . $query;
    }
  }

  return $url;
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
  $existing_url = trim((string)($quote['stripe_checkout_url'] ?? ''));
  $existing_session_id = trim((string)($quote['stripe_checkout_session_id'] ?? ''));
  $existing_amount = isset($quote['stripe_checkout_amount'])
    ? round((float)$quote['stripe_checkout_amount'], 2)
    : null;

  return $existing_url !== ''
    && $existing_session_id !== ''
    && $existing_amount !== null
    && abs($existing_amount - $amount) < STRIPE_AMOUNT_TOLERANCE;
}

function invoice_checkout_session_url(PDO $pdo, array &$quote, ?string &$error_message = null): string {
  $error_message = null;
  if (!invoice_online_payment_enabled($quote)) {
    return '';
  }

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
  if ($invoice_number === '') {
    $invoice_number = '#' . $quote_id;
  }
  $customer_name = trim((string)($quote['customer_name'] ?? ''));
  $company_name = trim((string)($quote['company_name'] ?? ''));
  $customer_email = trim((string)($quote['email'] ?? ''));
  $description_parts = array_filter([
    $company_name !== '' ? $company_name : null,
    $customer_name !== '' ? $customer_name : null,
  ]);
  $product_description = implode(' • ', $description_parts);
  $amount_cents = (int)round($amount * 100);

  $payload = [
    'mode' => 'payment',
    'success_url' => invoice_public_url('invoice_payment_status.php', ['status' => 'success']),
    'cancel_url' => invoice_public_url('invoice_payment_status.php', ['status' => 'cancel']),
    'payment_method_types' => ['card'],
    'line_items' => [[
      'price_data' => [
        'currency' => 'usd',
        'product_data' => array_filter([
          'name' => 'Invoice ' . $invoice_number,
          'description' => $product_description !== '' ? $product_description : null,
        ], static fn($value) => $value !== null && $value !== ''),
        'unit_amount' => $amount_cents,
      ],
      'quantity' => 1,
    ]],
    'metadata' => array_filter([
      'invoice_id' => (string)$quote_id,
      'invoice_number' => $invoice_number,
      'customer_name' => $customer_name,
      'company_name' => $company_name,
    ], static fn($value) => $value !== ''),
    'submit_type' => 'pay',
  ];
  if ($customer_email !== '' && filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
    $payload['customer_email'] = $customer_email;
  }

  $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
    CURLOPT_TIMEOUT => STRIPE_API_TIMEOUT_SECONDS,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $secret_key,
      'Content-Type: application/x-www-form-urlencoded',
    ],
  ]);
  $response_body = curl_exec($ch);
  $curl_error = curl_error($ch);
  $http_code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
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
    $stripe_error = trim((string)($response['error']['message'] ?? ''));
    $error_message = $stripe_error !== '' ? $stripe_error : 'Stripe checkout request failed.';
    return '';
  }

  $checkout_url = trim((string)($response['url'] ?? ''));
  $session_id = trim((string)($response['id'] ?? ''));
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
  )->execute([
    $checkout_url,
    $session_id,
    $amount,
    $quote_id,
  ]);

  $quote['stripe_checkout_url'] = $checkout_url;
  $quote['stripe_checkout_session_id'] = $session_id;
  $quote['stripe_checkout_amount'] = $amount;

  return $checkout_url;
}

function invoice_send_email_msg(PDO $pdo, array $quote, array $items, ?string &$error_message = null): bool {
  $error_message = null;
  $to = trim((string)($quote['email'] ?? ''));
  if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $error_message = 'Invoice email address is missing or invalid.';
    return false;
  }

  $smtp_host     = invoice_env_value('SMTP_HOST');
  $smtp_port     = (int)invoice_env_value('SMTP_PORT');
  $smtp_username = invoice_env_value('SMTP_USERNAME');
  $smtp_password = invoice_env_value('SMTP_PASSWORD');
  $smtp_from_email = invoice_env_value('SMTP_FROM_EMAIL');
  $smtp_from_name  = trim(str_replace(["\r", "\n"], ' ', invoice_env_value('SMTP_FROM_NAME')));

  $smtp_errors = [];
  if ($smtp_host === '') $smtp_errors[] = 'SMTP_HOST';
  if ($smtp_port <= 0)   $smtp_errors[] = 'SMTP_PORT';
  if ($smtp_username === '') $smtp_errors[] = 'SMTP_USERNAME';
  if ($smtp_password === '') $smtp_errors[] = 'SMTP_PASSWORD';
  if ($smtp_from_email === '' || !filter_var($smtp_from_email, FILTER_VALIDATE_EMAIL)) $smtp_errors[] = 'SMTP_FROM_EMAIL';
  if ($smtp_errors) {
    $error_message = 'Missing or invalid SMTP configuration: ' . implode(', ', $smtp_errors);
    error_log('Invoice email send failed — missing SMTP config: ' . implode(', ', $smtp_errors));
    return false;
  }

  $created_by    = isset($quote['created_by']) && $quote['created_by'] !== null ? (int)$quote['created_by'] : null;
  $sender        = invoice_sender_profile($pdo, $created_by);
  $sender_name   = $sender['sender_name'];
  $sender_company = $sender['company_name'] !== '' ? $sender['company_name'] : $smtp_from_name;
  if ($sender_company === '') $sender_company = 'Our Company';
  $sender_address = $sender['address'];
  $sender_phone   = $sender['phone'];
  $sender_email   = $sender['email'] !== '' ? $sender['email'] : $smtp_from_email;

  $inv_no        = trim((string)($quote['converted_invoice_no'] ?? ''));
  $customer_name = trim((string)($quote['customer_name'] ?? ''));
  $inv_date      = trim((string)($quote['quote_date'] ?? ''));
  $subtotal      = number_format((float)($quote['subtotal_amount'] ?? 0), 2);
  $payment_link = '';
  if (invoice_online_payment_enabled($quote)) {
    $payment_error = null;
    $payment_link = invoice_checkout_session_url($pdo, $quote, $payment_error);
    if ($payment_link === '') {
      $error_message = trim((string)$payment_error) !== '' ? trim((string)$payment_error) : 'Unable to create Stripe checkout link for this invoice.';
      return false;
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

  // ---- Contact / footer parts ----
  $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

  $header_parts = [];
  if ($sender_address !== '') {
    $addr_oneline = str_replace(["\r\n", "\r", "\n"], ' · ', $sender_address);
    $addr_oneline = preg_replace('/\s+/', ' ', $addr_oneline);
    $header_parts[] = $h($addr_oneline);
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

  // ---- Assemble HTML email ----
  $html_body = '<!doctype html>'
    . '<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
    . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">'

    . '<div style="max-width:680px;margin:32px auto 32px;">'

    // ── Header banner ──
    . '<div style="background:#1e3a5f;border-radius:8px 8px 0 0;padding:28px 32px 24px;">'
      . '<p style="margin:0 0 6px;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.3px;">' . $h($sender_company) . '</p>'
      . ($header_contact_html !== '' ? '<p style="margin:0;font-size:13px;color:#93c5fd;line-height:1.6;">' . $header_contact_html . '</p>' : '')
    . '</div>'

    // ── Document title strip ──
    . '<div style="background:#ffffff;padding:20px 32px 0;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
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

    // ── Body ──
    . '<div style="background:#ffffff;padding:24px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'

      . '<p style="margin:0 0 8px;font-size:15px;color:#1e293b;">Hello' . ($customer_name !== '' ? ', ' . $h($customer_name) : '') . ',</p>'
      . '<p style="margin:0 0 24px;font-size:14px;color:#475569;">Please find your invoice details below. Thank you for your business.</p>'
      . ($payment_link !== ''
          ? '<div style="margin:0 0 24px;padding:16px 18px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;">'
              . '<p style="margin:0 0 10px;font-size:14px;font-weight:600;color:#1d4ed8;">Pay this invoice online</p>'
              . '<p style="margin:0 0 14px;font-size:13px;color:#334155;">Use Stripe’s secure checkout page to pay this invoice online. Card details are entered directly on Stripe and are not collected on our site.</p>'
              . '<p style="margin:0;"><a href="' . $h($payment_link) . '" style="display:inline-block;padding:11px 18px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:999px;font-weight:700;">Pay Invoice on Stripe</a></p>'
            . '</div>'
          : '')

      // Line items table
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
            . '<td colspan="3" style="padding:14px 12px;text-align:right;font-weight:700;font-size:14px;color:#1e293b;border-top:2px solid #e2e8f0;">Subtotal:</td>'
            . '<td style="padding:14px 12px;text-align:right;font-weight:700;font-size:16px;color:#1e3a5f;border-top:2px solid #e2e8f0;">$' . $h($subtotal) . '</td>'
          . '</tr>'
        . '</tfoot>'
      . '</table>'

      . '<p style="margin:0;font-size:14px;color:#475569;">If you have any questions regarding this invoice, please do not hesitate to contact us.</p>'
    . '</div>'

    // ── Prepared-by strip ──
    . ($prepared_by_html !== ''
        ? '<div style="background:#f8fafc;padding:14px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">'
            . '<p style="margin:0;font-size:13px;color:#64748b;">' . $prepared_by_html . '</p>'
          . '</div>'
        : '')

    // ── Footer ──
    . '<div style="background:#1e3a5f;border-radius:0 0 8px 8px;padding:18px 32px;">'
      . '<p style="margin:0;font-size:12px;color:#93c5fd;line-height:1.6;">'
        . $h($sender_company)
        . ($footer_contact_html !== '' ? ' &nbsp;·&nbsp; ' . $footer_contact_html : '')
      . '</p>'
    . '</div>'

    . '</div>'
    . '</body></html>';

  // ---- Plain-text fallback ----
  $text_body  = $sender_company . "\r\n";
  if ($sender_address !== '') $text_body .= preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], ', ', $sender_address)) . "\r\n";
  if ($sender_phone !== '')   $text_body .= $sender_phone . "\r\n";
  if ($sender_email !== '')   $text_body .= $sender_email . "\r\n";
  $text_body .= "\r\nInvoice " . ($inv_no !== '' ? $inv_no : '#' . (int)$quote['id']) . "  |  Date: {$inv_date}\r\n";
  $text_body .= str_repeat('-', 40) . "\r\n\r\n";
  $text_body .= "Hello" . ($customer_name !== '' ? ", {$customer_name}" : '') . ",\r\n\r\n";
  $text_body .= "Please find your invoice details below.\r\n\r\nLine Items:\r\n";
  if ($payment_link !== '') {
    $text_body .= "Pay online with Stripe: {$payment_link}\r\n";
    $text_body .= "Card details are entered directly on Stripe's secure checkout page.\r\n\r\n";
  }
  $text_body .= implode("\r\n", $rows_text) . "\r\n\r\nSubtotal: \${$subtotal}\r\n\r\n";
  $text_body .= "Thank you for your business.\r\n";
  if ($sender_name !== '') {
    $text_body .= "\r\nPrepared by: {$sender_name}" . ($sender_company !== 'Our Company' ? " at {$sender_company}" : '') . "\r\n";
  }

  try {
    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
    $mailer->Host       = $smtp_host;
    $mailer->Port       = $smtp_port;
    $mailer->SMTPAuth   = true;
    $mailer->Username   = $smtp_username;
    $mailer->Password   = $smtp_password;
    if ($smtp_port === 465) {
      $mailer->SMTPSecure  = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
      $mailer->SMTPAutoTLS = false;
    } else {
      $mailer->SMTPSecure  = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      $mailer->SMTPAutoTLS = true;
    }
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($smtp_from_email, $smtp_from_name);
    $mailer->addAddress($to);
    $mailer->Subject  = $subject;
    $mailer->isHTML(true);
    $mailer->Body     = $html_body;
    $mailer->AltBody  = $text_body;
    if (!$mailer->send()) {
      $error_message = trim((string)$mailer->ErrorInfo);
      return false;
    }
    return true;
  } catch (Throwable $e) {
    $error_message = $e->getMessage();
    error_log('Invoice email send failed for quote #' . (int)$quote['id'] . ' to ' . $to . ': ' . $e->getMessage());
    return false;
  }
}

// ---------- GET: Customer live search ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['customer_search'])) {
  header('Content-Type: application/json; charset=utf-8');

  $csrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  if ($csrf === '' || !hash_equals((string)$_SESSION['invoice_form_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode([]);
    exit;
  }

  $query = trim((string)($_GET['q'] ?? ''));
  if ($query === '') {
    echo json_encode([]);
    exit;
  }

  $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
  $stmt = $pdo->prepare(
    "SELECT
       id,
       COALESCE(
         NULLIF(TRIM(CONCAT_WS(' ', NULLIF(first_name, ''), NULLIF(last_name, ''))), ''),
         NULLIF(company, ''),
         NULLIF(email, ''),
         ''
       ) AS customer_name,
       company AS company_name,
       phone,
       email
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

// ---------- GET: Labor live search ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['labor_search'])) {
  header('Content-Type: application/json; charset=utf-8');
  $csrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  if ($csrf === '' || !hash_equals((string)$_SESSION['invoice_form_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode([]);
    exit;
  }
  $query = trim((string)($_GET['q'] ?? ''));
  if ($query === '') { echo json_encode([]); exit; }
  $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
  try {
    $stmt = $pdo->prepare(
      "SELECT id, service_name, pricing_type, hourly_rate, typical_hours
       FROM labor_items
       WHERE service_name LIKE ? ESCAPE '\\\\'
          OR description LIKE ? ESCAPE '\\\\'
       ORDER BY service_name ASC
       LIMIT 8"
    );
    $stmt->execute([$like, $like]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    echo json_encode([]);
  }
  exit;
}

// ---------- GET: Inventory live search ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['inventory_search'])) {
  header('Content-Type: application/json; charset=utf-8');
  $csrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  if ($csrf === '' || !hash_equals((string)$_SESSION['invoice_form_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode([]);
    exit;
  }
  $query = trim((string)($_GET['q'] ?? ''));
  if ($query === '') { echo json_encode([]); exit; }
  $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
  try {
    $stmt = $pdo->prepare(
      "SELECT id, item_name, cost_price, markup_percent
       FROM inventory_items
       WHERE item_name LIKE ? ESCAPE '\\\\'
          OR part_number LIKE ? ESCAPE '\\\\'
       ORDER BY item_name ASC
       LIMIT 8"
    );
    $stmt->execute([$like, $like]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    echo json_encode([]);
  }
  exit;
}

// ---------- POST: Save invoice / Email invoice ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['invoice_form_csrf'], $csrf)) {
    http_response_code(403);
    exit('Invalid CSRF token.');
  }

  // Handle email action — allowed even in view mode
  if (trim((string)($_POST['action'] ?? '')) === 'send_email') {
    $row_id = (int)($_POST['row_id'] ?? 0);
    $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));
    if ($row_id > 0) {
      $eq_stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = ? LIMIT 1");
      $eq_stmt->execute([$row_id]);
      $eq_quote = $eq_stmt->fetch(PDO::FETCH_ASSOC);
      if ($eq_quote) {
        $eq_items_stmt = $pdo->prepare("SELECT description, quantity, unit_price, line_total FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC");
        $eq_items_stmt->execute([$row_id]);
        $eq_items = $eq_items_stmt->fetchAll(PDO::FETCH_ASSOC);
        $eq_error = null;
        if (invoice_send_email_msg($pdo, $eq_quote, $eq_items, $eq_error)) {
          $mode_param = $view_mode_requested ? '&mode=view' : '';
          header('Location: invoice_form.php?id=' . $row_id . $mode_param . '&email_sent=1');
        } else {
          $mode_param = $view_mode_requested ? '&mode=view' : '';
          header('Location: invoice_form.php?id=' . $row_id . $mode_param . '&email_error=' . urlencode($eq_error ?? 'Unknown error'));
        }
      } else {
        header('Location: invoice_tracker.php');
      }
    } else {
      header('Location: invoice_tracker.php');
    }
    exit;
  }

  if ($view_mode_requested) {
    http_response_code(405);
    exit('Viewing mode is read only.');
  }
  $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));

  $post_source_quote_id = (int)trim((string)($_POST['source_quote_id'] ?? ''));
  $post_invoice_number  = trim((string)($_POST['invoice_number'] ?? ''));
  $post_invoice_date    = trim((string)($_POST['invoice_date'] ?? ''));
  $post_customer_name   = trim((string)($_POST['customer_name'] ?? ''));
  $post_company_name    = trim((string)($_POST['company_name'] ?? ''));
  $post_phone_number    = trim((string)($_POST['phone_number'] ?? ''));
  $post_email           = trim((string)($_POST['email'] ?? ''));
  $post_enable_online_payment = !empty($_POST['enable_online_payment']);
  $post_notes           = trim((string)($_POST['notes'] ?? ''));

  // Validate date; fall back to today
  $tz = new DateTimeZone(APP_TZ);
  $post_invoice_date_obj = DateTime::createFromFormat('Y-m-d', $post_invoice_date, $tz);
  if (!$post_invoice_date_obj) {
    $post_invoice_date = (new DateTime('now', $tz))->format('Y-m-d');
  }

  $item_descs   = (array)($_POST['item_desc']   ?? []);
  $item_qtys    = (array)($_POST['item_qty']    ?? []);
  $item_costs   = (array)($_POST['item_cost']   ?? []);
  $item_markups = (array)($_POST['item_markup'] ?? []);

  $subtotal = 0.0;
  $line_items_to_save = [];
  $count = count($item_descs);
  for ($i = 0; $i < $count; $i++) {
    $desc      = trim((string)($item_descs[$i]   ?? ''));
    $qty       = max(INVOICE_MIN_QTY, (float)($item_qtys[$i]    ?? 1));
    $cost      = max(0.0,  (float)($item_costs[$i]   ?? 0));
    $markup    = max(0.0,  (float)($item_markups[$i] ?? 0));
    $price     = $cost * (1 + $markup / 100);
    $line_total = $qty * $price;
    $subtotal  += $line_total;
    $line_items_to_save[] = [
      'description'   => $desc,
      'quantity'      => $qty,
      'cost'          => $cost,
      'markup_percent'=> $markup,
      'unit_price'    => $price,
      'line_total'    => $line_total,
    ];
  }

  $created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
  if ($created_by !== null && $created_by <= 0) {
    $created_by = null;
  }

  $pdo->beginTransaction();
  try {
    if ($post_source_quote_id > 0) {
      // Update the existing quote row and mark it as converted
      $inv_no = $post_invoice_number !== '' ? $post_invoice_number
        : invoice_generate_number($post_source_quote_id);

      // Any invoice edit invalidates the old Stripe checkout session to prevent Stripe amount mismatches after invoice updates.
      $upd = $pdo->prepare(
        "UPDATE quotes
            SET customer_name     = ?,
                company_name      = ?,
                phone_number      = ?,
                email             = ?,
                quote_date        = ?,
                notes             = ?,
                subtotal_amount   = ?,
                enable_online_payment = ?,
                stripe_checkout_url = NULL,
                stripe_checkout_session_id = NULL,
                stripe_checkout_created_at = NULL,
                stripe_checkout_amount = NULL,
                status            = 'converted',
                converted_invoice_no = ?,
                converted_at      = COALESCE(converted_at, NOW())
          WHERE id = ?"
      );
      $upd->execute([
        $post_customer_name,
        $post_company_name !== '' ? $post_company_name : null,
        $post_phone_number  !== '' ? $post_phone_number  : null,
        $post_email         !== '' ? $post_email         : null,
        $post_invoice_date,
        $post_notes         !== '' ? $post_notes         : null,
        round($subtotal, 2),
        $post_enable_online_payment ? 1 : 0,
        $inv_no,
        $post_source_quote_id,
      ]);

      // Replace line items
      $pdo->prepare("DELETE FROM quote_items WHERE quote_id = ?")->execute([$post_source_quote_id]);

      $ins = $pdo->prepare(
        "INSERT INTO quote_items
           (quote_id, line_position, description, quantity, cost, markup_percent, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
      );
      foreach ($line_items_to_save as $pos => $item) {
        $ins->execute([
          $post_source_quote_id, $pos + 1,
          $item['description'], $item['quantity'], $item['cost'],
          $item['markup_percent'], $item['unit_price'], $item['line_total'],
        ]);
      }
    } else {
      // Insert a new quote row representing the standalone invoice
      $ins_q = $pdo->prepare(
        "INSERT INTO quotes
           (customer_name, company_name, phone_number, email, quote_date,
            status, notes, subtotal_amount, enable_online_payment, converted_invoice_no, converted_at, created_by)
         VALUES (?, ?, ?, ?, ?, 'converted', ?, ?, ?, '', NOW(), ?)"
      );
      $ins_q->execute([
        $post_customer_name,
        $post_company_name !== '' ? $post_company_name : null,
        $post_phone_number  !== '' ? $post_phone_number  : null,
        $post_email         !== '' ? $post_email         : null,
        $post_invoice_date,
        $post_notes         !== '' ? $post_notes         : null,
        round($subtotal, 2),
        $post_enable_online_payment ? 1 : 0,
        $created_by,
      ]);
      $new_id = (int)$pdo->lastInsertId();

      // Assign invoice number using the new row's ID
      $inv_no = invoice_generate_number($new_id);
      $pdo->prepare("UPDATE quotes SET converted_invoice_no = ? WHERE id = ?")->execute([$inv_no, $new_id]);

      // Insert line items
      $ins = $pdo->prepare(
        "INSERT INTO quote_items
           (quote_id, line_position, description, quantity, cost, markup_percent, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
      );
      foreach ($line_items_to_save as $pos => $item) {
        $ins->execute([
          $new_id, $pos + 1,
          $item['description'], $item['quantity'], $item['cost'],
          $item['markup_percent'], $item['unit_price'], $item['line_total'],
        ]);
      }
    }

    $pdo->commit();
    header('Location: invoice_tracker.php?success=created');
    exit;
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function invoice_format_money($value): string {
  return number_format((float)$value, 2);
}

function invoice_generate_number(int $id): string {
  $stamp = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Ymd');
  return 'INV-' . $stamp . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

function invoice_number_from_quote(array $quote, int $quote_id): string {
  $existing = trim((string)($quote['converted_invoice_no'] ?? ''));
  if ($existing !== '') {
    return $existing;
  }

  return invoice_generate_number($quote_id);
}

function invoice_default_number(): string {
  $stamp = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Ymd');
  return 'INV-' . $stamp . '-NEW';
}

function invoice_readonly_style(): string {
  return 'background:var(--surface,#f8fafc); color:var(--muted,#64748b);';
}

function invoice_readonly_attrs(): string {
  return ' readonly style="' . invoice_readonly_style() . '"';
}

function invoice_field_lock_attrs(bool $is_view_mode): string {
  return $is_view_mode ? invoice_readonly_attrs() : '';
}

function invoice_quote_date_value(?array $quote, string $fallback): string {
  $quote_date = trim((string)($quote['quote_date'] ?? ''));
  return $quote_date !== '' ? $quote_date : $fallback;
}

$quote_id_param = trim((string)($_GET['id'] ?? ''));
$has_quote_id = $quote_id_param !== '';
$quote_id = $has_quote_id ? (int)$quote_id_param : 0;
$quote = null;
$rows = [];

if ($has_quote_id) {
  if ($quote_id <= 0) {
    http_response_code(404);
    render_header('Invoice Not Found');
    ?>
    <div class="card">
      <h1 style="margin-top:0;">Invoice Not Found</h1>
      <p class="muted">The requested quote ID is invalid.</p>
      <a class="btn" href="quotes.php?view=all">Back to Quotes</a>
    </div>
    <?php
    render_footer();
    exit;
  }

  $quote_stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = ? LIMIT 1");
  $quote_stmt->execute([$quote_id]);
  $quote = $quote_stmt->fetch(PDO::FETCH_ASSOC);
  if (!$quote) {
    http_response_code(404);
    render_header('Invoice Not Found');
    ?>
    <div class="card">
      <h1 style="margin-top:0;">Invoice Not Found</h1>
      <p class="muted">We couldn’t find the quote used to pre-fill this invoice.</p>
      <a class="btn" href="quotes.php?view=all">Back to Quotes</a>
    </div>
    <?php
    render_footer();
    exit;
  }

  $item_stmt = $pdo->prepare("SELECT description, quantity, cost, markup_percent, unit_price, line_total FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC");
  $item_stmt->execute([$quote_id]);
  $rows = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$today = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Y-m-d');
$is_view_mode = $view_mode_requested && $quote !== null;
$invoice_heading = $is_view_mode ? 'View Invoice' : ($quote ? 'Edit Invoice' : 'New Invoice');
$invoice_subtitle = $quote
  ? ($is_view_mode
      ? 'Read-only invoice view for invoice #' . invoice_number_from_quote($quote, $quote_id) . '.'
      : 'Update invoice details for invoice #' . invoice_number_from_quote($quote, $quote_id) . '.')
  : 'Basic invoice scaffold for creating a standalone invoice.';
$fields = [
  'invoice_number' => $quote ? invoice_number_from_quote($quote, $quote_id) : invoice_default_number(),
  'source_quote_id' => $quote ? (string)$quote_id : '',
  'customer_name' => (string)($quote['customer_name'] ?? ''),
  'company_name' => (string)($quote['company_name'] ?? ''),
  'phone_number' => (string)($quote['phone_number'] ?? ''),
  'email' => (string)($quote['email'] ?? ''),
  'invoice_date' => invoice_quote_date_value($quote, $today),
  'enable_online_payment' => $quote && invoice_online_payment_enabled($quote) ? '1' : '0',
  'notes' => (string)($quote['notes'] ?? ''),
];

$line_items = [];
foreach ($rows as $row) {
  $line_items[] = [
    'description' => (string)($row['description'] ?? ''),
    'quantity' => invoice_format_money($row['quantity'] ?? 0),
    'cost' => invoice_format_money($row['cost'] ?? 0),
    'markup_percent' => number_format((float)($row['markup_percent'] ?? 0), 2),
    'unit_price' => invoice_format_money($row['unit_price'] ?? 0),
    'line_total' => invoice_format_money($row['line_total'] ?? 0),
  ];
}
if (!$line_items) {
  $line_items[] = [
    'description' => '',
    'quantity' => INVOICE_DEFAULT_QTY,
    'cost' => INVOICE_DEFAULT_COST,
    'markup_percent' => INVOICE_DEFAULT_MARKUP,
    'unit_price' => INVOICE_DEFAULT_PRICE,
    'line_total' => INVOICE_DEFAULT_PRICE,
  ];
}

$invoice_converted = isset($_GET['invoice_converted']) && $_GET['invoice_converted'] === '1';
$invoice_email_sent  = isset($_GET['email_sent'])  && $_GET['email_sent']  === '1';
$invoice_email_error = isset($_GET['email_error']) && $_GET['email_error'] !== '' ? trim((string)$_GET['email_error']) : '';

render_header($invoice_heading);
?>

<style>
  .invoice-toggle{position:relative;display:inline-flex;align-items:center;cursor:pointer;}
  .invoice-toggle input{position:absolute;opacity:0;pointer-events:none;}
  .invoice-toggle-slider{width:54px;height:30px;border-radius:999px;background:#cbd5e1;position:relative;transition:background-color .2s ease;}
  .invoice-toggle-slider::after{content:'';position:absolute;top:4px;left:4px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(15,23,42,.28);transition:transform .2s ease;}
  .invoice-toggle input:checked + .invoice-toggle-slider{background:#2563eb;}
  .invoice-toggle input:checked + .invoice-toggle-slider::after{transform:translateX(24px);}
  .invoice-toggle input:focus-visible + .invoice-toggle-slider{outline:3px solid rgba(37,99,235,.25);outline-offset:2px;}
</style>

<div class="card page-header">
  <div class="page-header-body">
    <h1><?= h($invoice_heading) ?></h1>
    <p class="muted"><?= h($invoice_subtitle) ?></p>
  </div>
  <div class="actions">
    <?php if ($is_view_mode && $quote): ?>
      <a class="btn primary" href="invoice_form.php?id=<?= (int)$quote_id ?>">Edit Invoice</a>
    <?php endif; ?>
    <?php if ($quote && trim((string)($quote['email'] ?? '')) !== ''): ?>
      <form method="post" style="margin:0;" action="">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
        <input type="hidden" name="action" value="send_email" />
        <input type="hidden" name="row_id" value="<?= (int)$quote_id ?>" />
        <button type="submit" class="btn">Email Invoice</button>
      </form>
    <?php endif; ?>
    <a class="btn" href="invoice_tracker.php">Invoice Tracker</a>
    <?php if ($quote): ?>
      <a class="btn" href="quotes.php?view=id&id=<?= (int)$quote_id ?>">Back to Quote</a>
    <?php endif; ?>
    <a class="btn" href="quotes.php?view=all">All Quotes</a>
  </div>
</div>

<div class="card">
  <?php if ($invoice_converted): ?>
    <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; margin-bottom:14px;">Quote converted to invoice successfully.</div>
  <?php endif; ?>
  <?php if ($invoice_email_sent): ?>
    <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534; margin-bottom:14px;">Invoice email sent successfully.</div>
  <?php endif; ?>
  <?php if ($invoice_email_error !== ''): ?>
    <div class="alert" style="border-color:#fecaca; background:#fef2f2; color:#991b1b; margin-bottom:14px;">Failed to send invoice email: <?= h($invoice_email_error) ?></div>
  <?php endif; ?>

  <?php if (!$is_view_mode): ?>
  <form method="post" action="">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
    <input type="hidden" name="source_quote_id" value="<?= h($fields['source_quote_id']) ?>" />
  <?php endif; ?>

    <div style="display:grid; gap:14px; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));">
      <div>
        <label for="invoice_number">Invoice #</label>
        <input id="invoice_number" type="text" name="invoice_number" value="<?= h($fields['invoice_number']) ?>"<?= invoice_readonly_attrs() ?> />
      </div>
      <div>
        <label for="invoice_date">Invoice Date</label>
        <input id="invoice_date" type="date" name="invoice_date" value="<?= h($fields['invoice_date']) ?>"<?= invoice_field_lock_attrs($is_view_mode) ?> />
      </div>
      <div>
        <label for="source_quote_label">Source Quote</label>
        <input id="source_quote_label" type="text" value="<?= h($quote ? 'Quote #' . (int)$quote_id : 'Standalone Invoice') ?>"<?= invoice_readonly_attrs() ?> />
      </div>
    </div>

    <div style="display:grid; gap:14px; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); margin-top:16px;">
      <div style="position:relative;">
        <label for="customer_name">Customer Name</label>
        <input id="customer_name" type="text" name="customer_name" maxlength="255" autocomplete="off" value="<?= h($fields['customer_name']) ?>"<?= invoice_field_lock_attrs($is_view_mode) ?> />
        <?php if (!$is_view_mode): ?>
          <div id="customerSuggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:40; background:#fff; border:1px solid #d1d5db; border-radius:10px; box-shadow:0 12px 24px rgba(2,6,23,.12); margin-top:6px; max-height:220px; overflow:auto;"></div>
        <?php endif; ?>
      </div>
      <div>
        <label for="company_name">Company</label>
        <input id="company_name" type="text" name="company_name" maxlength="255" value="<?= h($fields['company_name']) ?>"<?= invoice_field_lock_attrs($is_view_mode) ?> />
      </div>
      <div>
        <label for="phone_number">Phone</label>
        <input id="phone_number" type="text" name="phone_number" maxlength="100" value="<?= h($fields['phone_number']) ?>"<?= invoice_field_lock_attrs($is_view_mode) ?> />
      </div>
      <div>
        <label for="email">Email</label>
        <input id="email" type="email" name="email" maxlength="255" value="<?= h($fields['email']) ?>"<?= invoice_field_lock_attrs($is_view_mode) ?> />
      </div>
    </div>

    <!-- ── Labor / Services ── -->
    <div style="margin-top:20px;">
      <h3 style="margin:0 0 10px;">Labor / Services</h3>
      <div style="overflow-x:auto;">
        <table style="min-width:700px;" id="laborItemsTable">
          <thead>
            <tr>
              <th>Description</th>
              <th style="width:100px;">Qty</th>
              <th style="width:130px;">Cost</th>
              <th style="width:150px;">Line Total</th>
              <th style="width:90px;">Remove</th>
            </tr>
          </thead>
          <tbody id="laborItemsBody">
            <tr class="labor-row">
              <td style="position:relative;">
                <?php if (!$is_view_mode): ?>
                  <input type="text" class="item-desc labor-desc" name="item_desc[]" maxlength="500" value="" autocomplete="off" placeholder="Search labor / service…" />
                  <input type="hidden" name="item_markup[]" value="0" />
                  <input type="hidden" name="item_price[]" class="labor-price" value="0.00" />
                  <div class="item-suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:50; background:#fff; border:1px solid #d1d5db; border-radius:10px; box-shadow:0 12px 24px rgba(2,6,23,.12); margin-top:4px; max-height:200px; overflow:auto;"></div>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td><?php if (!$is_view_mode): ?><input type="number" step="0.01" min="0.01" class="labor-qty" name="item_qty[]" value="1" /><?php else: ?><span class="muted">—</span><?php endif; ?></td>
              <td><?php if (!$is_view_mode): ?><input type="number" step="0.01" min="0" class="labor-cost" name="item_cost[]" value="0.00" /><?php else: ?><span class="muted">—</span><?php endif; ?></td>
              <td class="labor-line-total" style="white-space:nowrap;">$0.00</td>
              <td><?php if (!$is_view_mode): ?><button type="button" class="btn remove-labor-row">×</button><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            </tr>
          </tbody>
        </table>
        <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
          <?php if (!$is_view_mode): ?>
            <button type="button" class="btn" id="addLaborRow">+ Add Labor Item</button>
          <?php else: ?>
            <span class="muted">Labor items are read only in view mode.</span>
          <?php endif; ?>
          <div><strong>Labor Subtotal: $<span id="laborSubtotal">0.00</span></strong></div>
        </div>
      </div>
    </div>

    <!-- ── Inventory / Parts ── -->
    <div style="margin-top:20px;">
      <h3 style="margin:0 0 10px;">Inventory / Parts</h3>
      <div style="overflow-x:auto;">
        <table style="min-width:900px;" id="inventoryItemsTable">
          <thead>
            <tr>
              <th>Description</th>
              <th style="width:100px;">Qty</th>
              <th style="width:130px;">Cost</th>
              <th style="width:110px;">Markup %</th>
              <th style="width:130px;">Price</th>
              <th style="width:150px;">Line Total</th>
              <th style="width:90px;">Remove</th>
            </tr>
          </thead>
          <tbody id="inventoryItemsBody">
            <?php foreach ($line_items as $row): ?>
              <tr class="inv-row">
                <td style="position:relative;">
                  <?php if (!$is_view_mode): ?>
                    <input type="text" class="item-desc inv-desc" name="item_desc[]" maxlength="500" value="<?= h((string)$row['description']) ?>" autocomplete="off" placeholder="Search inventory / part…" />
                    <div class="item-suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:50; background:#fff; border:1px solid #d1d5db; border-radius:10px; box-shadow:0 12px 24px rgba(2,6,23,.12); margin-top:4px; max-height:200px; overflow:auto;"></div>
                  <?php else: ?>
                    <?= h((string)$row['description']) ?>
                  <?php endif; ?>
                </td>
                <td><?php if (!$is_view_mode): ?><input type="number" step="0.01" min="0.01" class="inv-qty" name="item_qty[]" value="<?= h((string)$row['quantity']) ?>" /><?php else: ?><?= h((string)$row['quantity']) ?><?php endif; ?></td>
                <td><?php if (!$is_view_mode): ?><input type="number" step="0.01" min="0" class="inv-cost" name="item_cost[]" value="<?= h((string)$row['cost']) ?>" /><?php else: ?>$<?= h((string)$row['cost']) ?><?php endif; ?></td>
                <td><?php if (!$is_view_mode): ?><input type="number" step="0.01" min="0" class="inv-markup" name="item_markup[]" value="<?= h((string)$row['markup_percent']) ?>" /><?php else: ?><?= h((string)$row['markup_percent']) ?>%<?php endif; ?></td>
                <td><?php if (!$is_view_mode): ?><input type="number" step="0.01" min="0" class="inv-price" name="item_price[]" value="<?= h((string)$row['unit_price']) ?>" readonly style="background:var(--surface,#f8fafc); color:var(--muted,#64748b);" /><?php else: ?>$<?= h((string)$row['unit_price']) ?><?php endif; ?></td>
                <td class="inv-line-total" style="white-space:nowrap;">$<?= h((string)$row['line_total']) ?></td>
                <td>
                  <?php if (!$is_view_mode): ?>
                    <button type="button" class="btn remove-inv-row">×</button>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
          <?php if (!$is_view_mode): ?>
            <button type="button" class="btn" id="addInventoryRow">+ Add Inventory Item</button>
          <?php else: ?>
            <span class="muted">Inventory items are read only in view mode.</span>
          <?php endif; ?>
          <div><strong>Parts Subtotal: $<span id="partsSubtotal">0.00</span></strong></div>
        </div>
      </div>
    </div>

    <div style="margin-top:10px; text-align:right; font-size:1.05em;">
      <strong>Grand Total: $<span id="invoiceSubtotal">0.00</span></strong>
    </div>

    <div style="margin-top:14px;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:14px 16px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc; margin-bottom:14px;">
        <div style="min-width:min(100%, 320px);">
          <label for="enable_online_payment" style="display:block; margin:0 0 4px; font-weight:600;">Enable Online Payment</label>
          <p class="muted" style="margin:0; font-size:13px;">When enabled, invoice emails include a secure Stripe checkout link. Save changes before emailing the invoice.</p>
        </div>
        <?php if (!$is_view_mode): ?>
          <label class="invoice-toggle">
            <input id="enable_online_payment" type="checkbox" name="enable_online_payment" value="1" <?= $fields['enable_online_payment'] === '1' ? 'checked' : '' ?> />
            <span class="invoice-toggle-slider" aria-hidden="true"></span>
          </label>
        <?php else: ?>
          <span style="font-weight:700; color:<?= $fields['enable_online_payment'] === '1' ? '#1d4ed8' : '#64748b' ?>;">
            <?= $fields['enable_online_payment'] === '1' ? 'Enabled' : 'Disabled' ?>
          </span>
        <?php endif; ?>
      </div>
      <label for="notes">Notes</label>
      <textarea id="notes" name="notes" rows="5"<?= invoice_field_lock_attrs($is_view_mode) ?>><?= h($fields['notes']) ?></textarea>
    </div>

    <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
      <?php if (!$is_view_mode): ?>
        <button type="submit" class="btn primary" style="font-size:18px; padding:14px 22px;">Save Invoice</button>
      <?php else: ?>
        <a class="btn primary" href="invoice_form.php?id=<?= (int)$quote_id ?>">Edit Invoice</a>
      <?php endif; ?>
      <?php if ($quote && trim((string)($quote['email'] ?? '')) !== ''): ?>
        <?php if ($is_view_mode): ?>
          <form method="post" style="margin:0;" action="">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
            <input type="hidden" name="action" value="send_email" />
            <input type="hidden" name="row_id" value="<?= (int)$quote_id ?>" />
            <button type="submit" class="btn">Email Invoice</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
      <a class="btn" href="invoice_tracker.php">Invoice Tracker</a>
      <?php if ($quote): ?>
        <a class="btn" href="quotes.php?view=id&id=<?= (int)$quote_id ?>">Back to Quote</a>
      <?php endif; ?>
    </div>
  <?php if (!$is_view_mode): ?>
  </form>
  <?php endif; ?>
</div>

<?php if (!$is_view_mode): ?>
<script>
(() => {
  const csrfToken = '<?= h($_SESSION['invoice_form_csrf']) ?>';

  // ── Customer live search ──────────────────────────────────────────
  const customerNameInput = document.getElementById('customer_name');
  const companyInput      = document.getElementById('company_name');
  const phoneInput        = document.getElementById('phone_number');
  const emailInput        = document.getElementById('email');
  const customerSugg      = document.getElementById('customerSuggestions');
  if (customerNameInput && customerSugg) {
    let customerDebounce = null;
    function hideCustomerSugg() { customerSugg.style.display = 'none'; customerSugg.innerHTML = ''; }
    function renderCustomerSugg(rows) {
      customerSugg.innerHTML = '';
      if (!rows.length) { hideCustomerSugg(); return; }
      rows.forEach((row) => {
        const rowCompany = row.company_name || '';
        const rowPhone   = row.phone        || '';
        const rowEmail   = row.email        || '';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn';
        btn.style.cssText = 'display:block;width:100%;text-align:left;border-radius:0;border:0;border-bottom:1px solid #e5e7eb;background:#fff;padding:10px 12px;';
        const title = document.createElement('strong');
        title.textContent = row.customer_name || '';
        btn.appendChild(title);
        const meta = document.createElement('div');
        meta.className = 'muted';
        meta.style.marginTop = '3px';
        meta.textContent = (rowCompany || '—') + ' • ' + (rowPhone || '—') + ' • ' + (rowEmail || '—');
        btn.appendChild(meta);
        btn.addEventListener('click', () => {
          customerNameInput.value = row.customer_name || '';
          companyInput.value = rowCompany;
          phoneInput.value   = rowPhone;
          emailInput.value   = rowEmail;
          hideCustomerSugg();
        });
        customerSugg.appendChild(btn);
      });
      customerSugg.style.display = 'block';
    }
    customerNameInput.addEventListener('input', () => {
      const q = customerNameInput.value.trim();
      if (customerDebounce) clearTimeout(customerDebounce);
      if (q.length < 1) { hideCustomerSugg(); return; }
      customerDebounce = setTimeout(() => {
        fetch('invoice_form.php?customer_search=1&q=' + encodeURIComponent(q), {
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrfToken }
        }).then((r) => r.ok ? r.json() : [])
          .then((rows) => renderCustomerSugg(Array.isArray(rows) ? rows : []))
          .catch(() => hideCustomerSugg());
      }, 180);
    });
    document.addEventListener('click', (e) => {
      if (!e.target.closest('#customerSuggestions') && e.target !== customerNameInput) hideCustomerSugg();
    });
  }

  // ── Shared helpers ────────────────────────────────────────────────
  function parseNum(v) { const n = parseFloat(v); return Number.isFinite(n) ? n : 0; }

  const laborSubtotalNode = document.getElementById('laborSubtotal');
  const partsSubtotalNode = document.getElementById('partsSubtotal');
  const grandTotalNode    = document.getElementById('invoiceSubtotal');

  function updateGrandTotal() {
    grandTotalNode.textContent = (parseNum(laborSubtotalNode.textContent) + parseNum(partsSubtotalNode.textContent)).toFixed(2);
  }

  function makeSuggestDropdown() {
    return 'display:none; position:absolute; top:100%; left:0; right:0; z-index:50; '
      + 'background:#fff; border:1px solid #d1d5db; border-radius:10px; '
      + 'box-shadow:0 12px 24px rgba(2,6,23,.12); margin-top:4px; max-height:200px; overflow:auto;';
  }

  function buildSuggestBtn(mainText, subText) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.style.cssText = 'display:block;width:100%;text-align:left;border-radius:0;border:0;border-bottom:1px solid #e5e7eb;background:#fff;padding:10px 12px;cursor:pointer;';
    const t = document.createElement('strong');
    t.textContent = mainText;
    btn.appendChild(t);
    if (subText) {
      const m = document.createElement('div');
      m.className = 'muted';
      m.style.marginTop = '3px';
      m.textContent = subText;
      btn.appendChild(m);
    }
    return btn;
  }

  // ── Labor section ─────────────────────────────────────────────────
  const laborBody   = document.getElementById('laborItemsBody');
  const addLaborBtn = document.getElementById('addLaborRow');

  function computeLaborTotals() {
    let total = 0;
    laborBody.querySelectorAll('tr.labor-row').forEach((row) => {
      const qty  = parseNum(row.querySelector('.labor-qty')?.value);
      const cost = parseNum(row.querySelector('.labor-cost')?.value);
      const lineTotal = qty * cost;
      const ltCell = row.querySelector('.labor-line-total');
      if (ltCell) ltCell.textContent = '$' + lineTotal.toFixed(2);
      const priceHidden = row.querySelector('.labor-price');
      if (priceHidden) priceHidden.value = cost.toFixed(2);
      total += lineTotal;
    });
    laborSubtotalNode.textContent = total.toFixed(2);
    updateGrandTotal();
  }

  function setupLaborSearch(row) {
    const descInput  = row.querySelector('.labor-desc');
    const costInput  = row.querySelector('.labor-cost');
    const qtyInput   = row.querySelector('.labor-qty');
    const suggestBox = row.querySelector('.item-suggestions');
    if (!descInput || !suggestBox) return;
    let timer = null;
    descInput.addEventListener('input', () => {
      const q = descInput.value.trim();
      if (timer) clearTimeout(timer);
      if (q.length < 1) { suggestBox.style.display = 'none'; suggestBox.innerHTML = ''; return; }
      timer = setTimeout(() => {
        fetch('invoice_form.php?labor_search=1&q=' + encodeURIComponent(q), {
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrfToken }
        }).then((r) => r.ok ? r.json() : []).then((items) => {
          suggestBox.innerHTML = '';
          if (!items.length) { suggestBox.style.display = 'none'; return; }
          items.forEach((item) => {
            const rate = item.hourly_rate ? '$' + parseFloat(item.hourly_rate).toFixed(2) : '';
            const sub  = item.pricing_type + (rate ? ' • ' + rate : '');
            const btn  = buildSuggestBtn(item.service_name, sub);
            btn.addEventListener('click', () => {
              descInput.value = item.service_name;
              if (item.hourly_rate != null) costInput.value = parseFloat(item.hourly_rate).toFixed(2);
              if (item.typical_hours && parseFloat(item.typical_hours) > 0) qtyInput.value = parseFloat(item.typical_hours).toFixed(2);
              suggestBox.style.display = 'none'; suggestBox.innerHTML = '';
              computeLaborTotals();
            });
            suggestBox.appendChild(btn);
          });
          suggestBox.style.display = 'block';
        }).catch(() => { suggestBox.style.display = 'none'; });
      }, 200);
    });
    descInput.addEventListener('blur', () => {
      setTimeout(() => { suggestBox.style.display = 'none'; suggestBox.innerHTML = ''; }, 200);
    });
  }

  function bindLaborRow(row) {
    setupLaborSearch(row);
    row.querySelector('.labor-qty')?.addEventListener('input', computeLaborTotals);
    row.querySelector('.labor-cost')?.addEventListener('input', computeLaborTotals);
    const removeBtn = row.querySelector('.remove-labor-row');
    if (!removeBtn) return;
    removeBtn.addEventListener('click', () => {
      if (laborBody.querySelectorAll('tr.labor-row').length <= 1) {
        row.querySelector('.labor-desc').value = '';
        row.querySelector('.labor-qty').value  = '1';
        row.querySelector('.labor-cost').value = '0.00';
      } else {
        row.remove();
      }
      computeLaborTotals();
    });
  }

  if (addLaborBtn) {
    addLaborBtn.addEventListener('click', () => {
      const tr = document.createElement('tr');
      tr.className = 'labor-row';
      tr.innerHTML = '<td style="position:relative;">'
        + '<input type="text" class="item-desc labor-desc" name="item_desc[]" maxlength="500" autocomplete="off" placeholder="Search labor / service…" />'
        + '<input type="hidden" name="item_markup[]" value="0" />'
        + '<input type="hidden" name="item_price[]" class="labor-price" value="0.00" />'
        + '<div class="item-suggestions" style="' + makeSuggestDropdown() + '"></div>'
        + '</td>'
        + '<td><input type="number" step="0.01" min="0.01" class="labor-qty" name="item_qty[]" value="1" /></td>'
        + '<td><input type="number" step="0.01" min="0" class="labor-cost" name="item_cost[]" value="0.00" /></td>'
        + '<td class="labor-line-total" style="white-space:nowrap;">$0.00</td>'
        + '<td><button type="button" class="btn remove-labor-row">×</button></td>';
      laborBody.appendChild(tr);
      bindLaborRow(tr);
      computeLaborTotals();
      tr.querySelector('.labor-desc').focus();
    });
  }

  // ── Inventory section ─────────────────────────────────────────────
  const invBody   = document.getElementById('inventoryItemsBody');
  const addInvBtn = document.getElementById('addInventoryRow');

  function computeInvTotals() {
    let total = 0;
    invBody.querySelectorAll('tr.inv-row').forEach((row) => {
      const qty    = parseNum(row.querySelector('.inv-qty')?.value);
      const cost   = parseNum(row.querySelector('.inv-cost')?.value);
      const markup = parseNum(row.querySelector('.inv-markup')?.value);
      const price  = cost * (1 + markup / 100);
      const priceInput = row.querySelector('.inv-price');
      if (priceInput) priceInput.value = price.toFixed(2);
      const lineTotal = qty * price;
      const ltCell = row.querySelector('.inv-line-total');
      if (ltCell) ltCell.textContent = '$' + lineTotal.toFixed(2);
      total += lineTotal;
    });
    partsSubtotalNode.textContent = total.toFixed(2);
    updateGrandTotal();
  }

  function setupInvSearch(row) {
    const descInput   = row.querySelector('.inv-desc');
    const costInput   = row.querySelector('.inv-cost');
    const markupInput = row.querySelector('.inv-markup');
    const suggestBox  = row.querySelector('.item-suggestions');
    if (!descInput || !suggestBox) return;
    let timer = null;
    descInput.addEventListener('input', () => {
      const q = descInput.value.trim();
      if (timer) clearTimeout(timer);
      if (q.length < 1) { suggestBox.style.display = 'none'; suggestBox.innerHTML = ''; return; }
      timer = setTimeout(() => {
        fetch('invoice_form.php?inventory_search=1&q=' + encodeURIComponent(q), {
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrfToken }
        }).then((r) => r.ok ? r.json() : []).then((items) => {
          suggestBox.innerHTML = '';
          if (!items.length) { suggestBox.style.display = 'none'; return; }
          items.forEach((item) => {
            const costVal   = item.cost_price    != null ? '$' + parseFloat(item.cost_price).toFixed(2)    : '';
            const markupVal = item.markup_percent != null ? parseFloat(item.markup_percent).toFixed(0) + '%' : '20%';
            const sub = (costVal ? 'Cost: ' + costVal + ' • ' : '') + 'Markup: ' + markupVal;
            const btn = buildSuggestBtn(item.item_name, sub);
            btn.addEventListener('click', () => {
              descInput.value   = item.item_name;
              costInput.value   = item.cost_price    != null ? parseFloat(item.cost_price).toFixed(2)    : '0.00';
              markupInput.value = item.markup_percent != null ? parseFloat(item.markup_percent).toFixed(2) : '20.00';
              suggestBox.style.display = 'none'; suggestBox.innerHTML = '';
              computeInvTotals();
            });
            suggestBox.appendChild(btn);
          });
          suggestBox.style.display = 'block';
        }).catch(() => { suggestBox.style.display = 'none'; });
      }, 200);
    });
    descInput.addEventListener('blur', () => {
      setTimeout(() => { suggestBox.style.display = 'none'; suggestBox.innerHTML = ''; }, 200);
    });
  }

  function bindInvRow(row) {
    setupInvSearch(row);
    row.querySelector('.inv-qty')?.addEventListener('input', computeInvTotals);
    row.querySelector('.inv-cost')?.addEventListener('input', computeInvTotals);
    row.querySelector('.inv-markup')?.addEventListener('input', computeInvTotals);
    const removeBtn = row.querySelector('.remove-inv-row');
    if (!removeBtn) return;
    removeBtn.addEventListener('click', () => {
      if (invBody.querySelectorAll('tr.inv-row').length <= 1) {
        row.querySelector('.inv-desc').value   = '';
        row.querySelector('.inv-qty').value    = '1';
        row.querySelector('.inv-cost').value   = '0.00';
        row.querySelector('.inv-markup').value = '20.00';
        row.querySelector('.inv-price').value  = '0.00';
      } else {
        row.remove();
      }
      computeInvTotals();
    });
  }

  if (addInvBtn) {
    addInvBtn.addEventListener('click', () => {
      const tr = document.createElement('tr');
      tr.className = 'inv-row';
      tr.innerHTML = '<td style="position:relative;">'
        + '<input type="text" class="item-desc inv-desc" name="item_desc[]" maxlength="500" autocomplete="off" placeholder="Search inventory / part…" />'
        + '<div class="item-suggestions" style="' + makeSuggestDropdown() + '"></div>'
        + '</td>'
        + '<td><input type="number" step="0.01" min="0.01" class="inv-qty" name="item_qty[]" value="1" /></td>'
        + '<td><input type="number" step="0.01" min="0" class="inv-cost" name="item_cost[]" value="0.00" /></td>'
        + '<td><input type="number" step="0.01" min="0" class="inv-markup" name="item_markup[]" value="20.00" /></td>'
        + '<td><input type="number" step="0.01" min="0" class="inv-price" name="item_price[]" value="0.00" readonly style="background:var(--surface,#f8fafc);color:var(--muted,#64748b);" /></td>'
        + '<td class="inv-line-total" style="white-space:nowrap;">$0.00</td>'
        + '<td><button type="button" class="btn remove-inv-row">×</button></td>';
      invBody.appendChild(tr);
      bindInvRow(tr);
      computeInvTotals();
      tr.querySelector('.inv-desc').focus();
    });
  }

  // ── Pre-submit: strip blank rows ──────────────────────────────────
  const invoiceForm = laborBody.closest('form');
  if (invoiceForm) {
    invoiceForm.addEventListener('submit', () => {
      laborBody.querySelectorAll('tr.labor-row').forEach((row) => {
        if ((row.querySelector('.labor-desc')?.value ?? '').trim() === '') row.remove();
      });
      invBody.querySelectorAll('tr.inv-row').forEach((row) => {
        if ((row.querySelector('.inv-desc')?.value ?? '').trim() === '') row.remove();
      });
    });
  }

  // ── Init ─────────────────────────────────────────────────────────
  laborBody.querySelectorAll('tr.labor-row').forEach(bindLaborRow);
  invBody.querySelectorAll('tr.inv-row').forEach(bindInvRow);
  computeLaborTotals();
  computeInvTotals();
})();
</script>
<?php else: ?>
<script>
(() => {
  // View mode: compute totals from rendered cell text
  let laborTotal = 0;
  let partsTotal = 0;
  document.querySelectorAll('#laborItemsBody .labor-line-total').forEach((cell) => {
    const v = parseFloat((cell.textContent || '').replace(/[^0-9.-]/g, ''));
    if (Number.isFinite(v)) laborTotal += v;
  });
  document.querySelectorAll('#inventoryItemsBody .inv-line-total').forEach((cell) => {
    const v = parseFloat((cell.textContent || '').replace(/[^0-9.-]/g, ''));
    if (Number.isFinite(v)) partsTotal += v;
  });
  const laborNode = document.getElementById('laborSubtotal');
  const partsNode = document.getElementById('partsSubtotal');
  const grandNode = document.getElementById('invoiceSubtotal');
  if (laborNode) laborNode.textContent = laborTotal.toFixed(2);
  if (partsNode) partsNode.textContent = partsTotal.toFixed(2);
  if (grandNode) grandNode.textContent = (laborTotal + partsTotal).toFixed(2);
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
