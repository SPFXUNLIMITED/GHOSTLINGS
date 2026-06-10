<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';
require_admin_or_moderator();

const QUOTE_MAX_NOTES_LENGTH = 10000;
const QUOTE_MAX_LINE_ITEMS = 100;
const QUOTE_TABLE_COLUMN_COUNT = 8;

if (empty($_SESSION['quotes_csrf'])) {
  $_SESSION['quotes_csrf'] = bin2hex(random_bytes(24));
}

function quote_invoice_number(int $quote_id): string {
  $stamp = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Ymd');
  return 'INV-' . $stamp . '-' . str_pad((string)$quote_id, 5, '0', STR_PAD_LEFT);
}

function quote_format_money($value): string {
  return number_format((float)$value, 2);
}

function quote_mail_domain(): string {
  $configured = trim((string)getenv('APP_MAIL_FROM_DOMAIN'));
  if ($configured !== '' && preg_match('/^[a-z0-9.-]+$/i', $configured)) {
    return strtolower($configured);
  }

  $host = strtolower(trim((string)($_SERVER['SERVER_NAME'] ?? 'localhost')));
  if ($host !== '' && preg_match('/^[a-z0-9.-]+$/', $host)) {
    return $host;
  }

  return 'localhost';
}

function quote_is_development(): bool {
  $env_values = [
    getenv('APP_ENV'),
    getenv('APPLICATION_ENV'),
    $_ENV['APP_ENV'] ?? null,
    $_SERVER['APP_ENV'] ?? null,
  ];
  foreach ($env_values as $value) {
    $env = strtolower(trim((string)$value));
    if ($env === '') {
      continue;
    }
    if (in_array($env, ['dev', 'development', 'local', 'test', 'testing'], true)) {
      return true;
    }
    if (in_array($env, ['prod', 'production'], true)) {
      return false;
    }
  }

  $host = strtolower(trim((string)($_SERVER['SERVER_NAME'] ?? '')));
  if ($host === '') {
    return false;
  }

  if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
    return true;
  }

  return preg_match('/(\.local|\.test)$/', $host) === 1;
}

function quote_env_value(string $key): string {
  static $dotenv_values = null;

  if ($dotenv_values === null) {
    $dotenv_values = [];
    $dotenv_path = __DIR__ . '/.env';
    if (is_file($dotenv_path) && is_readable($dotenv_path)) {
      $lines = file($dotenv_path, FILE_IGNORE_NEW_LINES);
      if (is_array($lines)) {
        foreach ($lines as $line) {
          $line = trim((string)$line);
          if ($line === '' || str_starts_with($line, '#')) {
            continue;
          }

          $separator_pos = strpos($line, '=');
          if ($separator_pos === false) {
            continue;
          }

          $name = trim(substr($line, 0, $separator_pos));
          if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            continue;
          }

          $value = trim(substr($line, $separator_pos + 1));
          if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if ($first === '"' && $last === '"') {
              $value = substr($value, 1, -1);
              $value = strtr($value, [
                '\\\\' => '\\',
                '\\"' => '"',
                '\\n' => "\n",
                '\\r' => "\r",
                '\\t' => "\t",
              ]);
            } elseif ($first === "'" && $last === "'") {
              $value = substr($value, 1, -1);
              $value = strtr($value, [
                '\\\\' => '\\',
                "\\'" => "'",
              ]);
            }
          } else {
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            $value = rtrim($value);
          }

          $dotenv_values[$name] = $value;
        }
      }
    }
  }

  $env_value = getenv($key);
  if ($env_value === false) {
    $env_value = null;
  }

  $candidates = [
    $env_value,
    $_ENV[$key] ?? null,
    $_SERVER[$key] ?? null,
    $dotenv_values[$key] ?? null,
  ];

  foreach ($candidates as $candidate) {
    $value = trim((string)$candidate);
    if ($value !== '') return $value;
  }

  return '';
}

function quote_escape_like(string $value, string $escape = '\\'): string {
  return str_replace(
    [$escape, '%', '_'],
    [$escape . $escape, $escape . '%', $escape . '_'],
    $value
  );
}

function quote_split_name(string $customer_name): array {
  $customer_name = trim($customer_name);
  if ($customer_name === '') {
    return ['', ''];
  }

  $parts = preg_split('/\s+/', $customer_name);
  if (!is_array($parts) || !$parts) {
    return [$customer_name, ''];
  }

  $first_name = trim((string)array_shift($parts));
  $last_name = trim(implode(' ', $parts));
  return [$first_name, $last_name];
}

function quote_resolve_customer_id(PDO $pdo, array $fields): ?int {
  $posted_customer_id = trim((string)($fields['customer_id'] ?? ''));
  if ($posted_customer_id !== '') {
    $customer_id = (int)$posted_customer_id;
    if ($customer_id > 0) {
      $stmt = $pdo->prepare("SELECT id FROM customers WHERE id = ? LIMIT 1");
      $stmt->execute([$customer_id]);
      if ((int)$stmt->fetchColumn() > 0) {
        return $customer_id;
      }
    }
    return null;
  }

  $scores = [];
  $matchers = [];
  $customer_name = trim((string)($fields['customer_name'] ?? ''));
  if ($customer_name !== '') {
    [$first_name, $last_name] = quote_split_name($customer_name);
    $matchers[] = [
      "SELECT id FROM customers WHERE first_name = ? AND last_name = ? LIMIT 25",
      [$first_name, $last_name],
    ];
  }
  $company_name = trim((string)($fields['company_name'] ?? ''));
  if ($company_name !== '') {
    $matchers[] = ["SELECT id FROM customers WHERE company = ? LIMIT 25", [$company_name]];
  }
  $email = trim((string)($fields['email'] ?? ''));
  if ($email !== '') {
    $matchers[] = ["SELECT id FROM customers WHERE email = ? LIMIT 25", [$email]];
  }
  $phone_number = trim((string)($fields['phone_number'] ?? ''));
  if ($phone_number !== '') {
    $matchers[] = ["SELECT id FROM customers WHERE phone = ? LIMIT 25", [$phone_number]];
  }

  $prepared = [];
  foreach ($matchers as [$sql, $params]) {
    if (!isset($prepared[$sql])) {
      $prepared[$sql] = $pdo->prepare($sql);
    }
    $stmt = $prepared[$sql];
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $id = (int)($row['id'] ?? 0);
      if ($id > 0) {
        $scores[$id] = ($scores[$id] ?? 0) + 1;
      }
    }
  }

  if (!$scores) {
    return null;
  }

  arsort($scores);
  $top_score = (int)reset($scores);
  $top_ids = array_keys(array_filter($scores, static fn($score): bool => (int)$score === $top_score));
  if (count($top_ids) !== 1) {
    error_log('Quote customer backfill match ambiguity.');
    return null;
  }

  return (int)$top_ids[0];
}

function quote_backfill_customer(PDO $pdo, ?int $customer_id, array $fields): void {
  if ($customer_id === null || $customer_id <= 0) {
    return;
  }

  $stmt = $pdo->prepare("SELECT first_name, last_name, company, phone, email FROM customers WHERE id = ? LIMIT 1");
  $stmt->execute([$customer_id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return;
  }

  $updates = [];
  $params = [];

  if (trim((string)($row['company'] ?? '')) === '' && trim((string)($fields['company_name'] ?? '')) !== '') {
    $updates[] = "company = ?";
    $params[] = trim((string)$fields['company_name']);
  }
  if (trim((string)($row['phone'] ?? '')) === '' && trim((string)($fields['phone_number'] ?? '')) !== '') {
    $updates[] = "phone = ?";
    $params[] = trim((string)$fields['phone_number']);
  }
  if (trim((string)($row['email'] ?? '')) === '' && trim((string)($fields['email'] ?? '')) !== '') {
    $updates[] = "email = ?";
    $params[] = trim((string)$fields['email']);
  }

  if (trim((string)($row['first_name'] ?? '')) === '' && trim((string)($row['last_name'] ?? '')) === '' && trim((string)($fields['customer_name'] ?? '')) !== '') {
    [$first_name, $last_name] = quote_split_name((string)$fields['customer_name']);
    if ($first_name !== '' || $last_name !== '') {
      $updates[] = "first_name = ?";
      $params[] = $first_name;
      $updates[] = "last_name = ?";
      $params[] = $last_name;
    }
  }

  if (!$updates) {
    return;
  }

  $params[] = $customer_id;
  $update_stmt = $pdo->prepare("UPDATE customers SET " . implode(', ', $updates) . " WHERE id = ?");
  $update_stmt->execute($params);
}

function quote_sender_profile(PDO $pdo, array $quote): array {
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
    $row = $stmt->fetch();
    if (!$row) {
      continue;
    }

    $contact_name = trim((string)($row['contact_name'] ?? ''));
    $username     = trim((string)($row['username']     ?? ''));
    $profile['sender_name']  = $contact_name !== '' ? $contact_name : $username;
    $profile['company_name'] = trim((string)($row['company_name']      ?? ''));
    $profile['address']      = trim((string)($row['delivery_address']  ?? ''));
    $profile['phone']        = trim((string)($row['contact_phone']     ?? ''));
    $profile['email']        = trim((string)($row['email']             ?? ''));
    break;
  }

  return $profile;
}

function quote_send_email(PDO $pdo, array $quote, array $items, ?string &$error_message = null): bool {
  $error_message = null;
  $to = trim((string)($quote['email'] ?? ''));
  if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $error_message = 'Quote email address is missing or invalid.';
    return false;
  }

  $smtp_host = quote_env_value('SMTP_HOST');
  $smtp_port = (int)quote_env_value('SMTP_PORT');
  $smtp_username = quote_env_value('SMTP_USERNAME');
  $smtp_password = quote_env_value('SMTP_PASSWORD');
  $smtp_from_email = quote_env_value('SMTP_FROM_EMAIL');
  $smtp_from_name = trim(str_replace(["\r", "\n"], ' ', quote_env_value('SMTP_FROM_NAME')));

  $smtp_errors = [];
  if ($smtp_host === '') $smtp_errors[] = 'SMTP_HOST';
  if ($smtp_port <= 0) $smtp_errors[] = 'SMTP_PORT';
  if ($smtp_username === '') $smtp_errors[] = 'SMTP_USERNAME';
  if ($smtp_password === '') $smtp_errors[] = 'SMTP_PASSWORD';
  if ($smtp_from_email === '' || !filter_var($smtp_from_email, FILTER_VALIDATE_EMAIL)) $smtp_errors[] = 'SMTP_FROM_EMAIL';

  if ($smtp_errors) {
    $error_message = 'Missing or invalid SMTP configuration: ' . implode(', ', $smtp_errors);
    error_log('Quote email send failed due to missing or invalid SMTP configuration: ' . implode(', ', $smtp_errors));
    return false;
  }

  $sender_profile = quote_sender_profile($pdo, $quote);
  $sender_name    = $sender_profile['sender_name'];
  $sender_company = $sender_profile['company_name'] !== '' ? $sender_profile['company_name'] : $smtp_from_name;
  if ($sender_company === '') {
    $sender_company = 'Our Company';
  }
  $sender_address = $sender_profile['address'];
  $sender_phone   = $sender_profile['phone'];
  $sender_email   = $sender_profile['email'] !== '' ? $sender_profile['email'] : $smtp_from_email;

  $quote_id      = (int)($quote['id'] ?? 0);
  $customer_name = trim((string)($quote['customer_name'] ?? ''));
  $quote_date    = trim((string)($quote['quote_date'] ?? ''));
  $subtotal      = quote_format_money($quote['subtotal_amount'] ?? 0);

  $subject = $sender_company . ' - Quote #' . $quote_id;

  // ---- Build HTML rows ----
  $rows_html = [];
  $rows_text = [];
  $row_index = 0;
  foreach ($items as $item) {
    $description = trim((string)($item['description'] ?? ''));
    $quantity    = quote_format_money($item['quantity']   ?? 0);
    $unit_price  = quote_format_money($item['unit_price'] ?? 0);
    $line_total  = quote_format_money($item['line_total'] ?? 0);
    $row_bg      = ($row_index % 2 === 0) ? '#ffffff' : '#f9fafb';
    $rows_html[] = '<tr style="background:' . $row_bg . ';">'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#374151;">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">' . htmlspecialchars($quantity, ENT_QUOTES, 'UTF-8') . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">$' . htmlspecialchars($unit_price, ENT_QUOTES, 'UTF-8') . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">$' . htmlspecialchars($line_total, ENT_QUOTES, 'UTF-8') . '</td>'
      . '</tr>';
    $rows_text[] = '- ' . $description . ' | Qty: ' . $quantity . ' | Price: $' . $unit_price . ' | Total: $' . $line_total;
    $row_index++;
  }

  if (!$rows_html) {
    $rows_html[] = '<tr><td colspan="4" style="padding:10px 12px;text-align:center;color:#6b7280;">No line items.</td></tr>';
    $rows_text[] = '- No line items.';
  }

  // ---- Build company header contact line ----
  $header_contact_parts = [];
  if ($sender_address !== '') {
    $addr_oneline = str_replace(["\r\n", "\r", "\n"], ' · ', $sender_address);
    $addr_oneline = preg_replace('/\s+/', ' ', $addr_oneline);
    $header_contact_parts[] = htmlspecialchars($addr_oneline, ENT_QUOTES, 'UTF-8');
  }
  if ($sender_phone !== '') {
    $header_contact_parts[] = htmlspecialchars($sender_phone, ENT_QUOTES, 'UTF-8');
  }
  if ($sender_email !== '') {
    $header_contact_parts[] = '<a href="mailto:' . htmlspecialchars($sender_email, ENT_QUOTES, 'UTF-8') . '" style="color:#93c5fd;text-decoration:none;">' . htmlspecialchars($sender_email, ENT_QUOTES, 'UTF-8') . '</a>';
  }
  $header_contact_html = implode(' &nbsp;·&nbsp; ', $header_contact_parts);

  // ---- "Prepared by" line ----
  $prepared_by_html = '';
  if ($sender_name !== '') {
    $prepared_by_html = 'This quote was prepared by <strong style="color:#1e293b;">' . htmlspecialchars($sender_name, ENT_QUOTES, 'UTF-8') . '</strong>';
    if ($sender_company !== 'Our Company') {
      $prepared_by_html .= ' at <strong style="color:#1e293b;">' . htmlspecialchars($sender_company, ENT_QUOTES, 'UTF-8') . '</strong>';
    }
    $prepared_by_html .= '.';
  }

  // ---- Footer contact line (plain) ----
  $footer_parts = [];
  if ($sender_address !== '') {
    $footer_parts[] = htmlspecialchars(preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], ', ', $sender_address)), ENT_QUOTES, 'UTF-8');
  }
  if ($sender_phone !== '') {
    $footer_parts[] = htmlspecialchars($sender_phone, ENT_QUOTES, 'UTF-8');
  }
  if ($sender_email !== '') {
    $footer_parts[] = '<a href="mailto:' . htmlspecialchars($sender_email, ENT_QUOTES, 'UTF-8') . '" style="color:#93c5fd;text-decoration:none;">' . htmlspecialchars($sender_email, ENT_QUOTES, 'UTF-8') . '</a>';
  }
  $footer_contact_html = implode(' &nbsp;·&nbsp; ', $footer_parts);

  // ---- Assemble HTML email ----
  $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

  $html_body = '<!doctype html>'
    . '<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
    . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">'

    // Outer wrapper
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
            . '<p style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Quote #' . $h((string)$quote_id) . '</p>'
          . '</td>'
          . '<td style="padding:0 0 16px;text-align:right;">'
            . '<p style="margin:0;font-size:13px;color:#64748b;">Date: ' . $h($quote_date) . '</p>'
          . '</td>'
        . '</tr>'
      . '</table>'
      . '<hr style="margin:0;border:none;border-top:2px solid #e2e8f0;">'
    . '</div>'

    // ── Body ──
    . '<div style="background:#ffffff;padding:24px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'

      // Greeting
      . '<p style="margin:0 0 8px;font-size:15px;color:#1e293b;">Hello' . ($customer_name !== '' ? ', ' . $h($customer_name) : '') . ',</p>'
      . '<p style="margin:0 0 24px;font-size:14px;color:#475569;">Please find your quote details below. We appreciate the opportunity to earn your business.</p>'

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
        . '<tbody>'
          . implode('', $rows_html)
        . '</tbody>'
        . '<tfoot>'
          . '<tr>'
            . '<td colspan="3" style="padding:14px 12px;text-align:right;font-weight:700;font-size:14px;color:#1e293b;border-top:2px solid #e2e8f0;">Subtotal:</td>'
            . '<td style="padding:14px 12px;text-align:right;font-weight:700;font-size:16px;color:#1e3a5f;border-top:2px solid #e2e8f0;">$' . $h($subtotal) . '</td>'
          . '</tr>'
        . '</tfoot>'
      . '</table>'

      . '<p style="margin:0;font-size:14px;color:#475569;">Thank you for considering our services. Please do not hesitate to reach out if you have any questions.</p>'
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

    . '</div>' // end outer wrapper
    . '</body></html>';

  // ---- Plain-text fallback ----
  $text_body = $sender_company . "\r\n";
  if ($sender_address !== '') {
    $text_body .= preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], ', ', $sender_address)) . "\r\n";
  }
  if ($sender_phone !== '') $text_body .= $sender_phone . "\r\n";
  if ($sender_email !== '') $text_body .= $sender_email . "\r\n";
  $text_body .= "\r\n";
  $text_body .= "Quote #{$quote_id}  |  Date: {$quote_date}\r\n";
  $text_body .= str_repeat('-', 40) . "\r\n\r\n";
  $text_body .= "Hello" . ($customer_name !== '' ? ", {$customer_name}" : '') . ",\r\n\r\n";
  $text_body .= "Please find your quote details below.\r\n\r\n";
  $text_body .= "Line Items:\r\n";
  $text_body .= implode("\r\n", $rows_text) . "\r\n\r\n";
  $text_body .= "Subtotal: \${$subtotal}\r\n\r\n";
  $text_body .= "Thank you for considering our services.\r\n";
  if ($sender_name !== '') {
    $text_body .= "\r\nPrepared by: {$sender_name}" . ($sender_company !== 'Our Company' ? " at {$sender_company}" : '') . "\r\n";
  }

  try {
    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->SMTPOptions = array(
      'ssl' => array(
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
      )
    );
    $mailer->Host = $smtp_host;
    $mailer->Port = $smtp_port;
    $mailer->SMTPAuth = true;
    $mailer->Username = $smtp_username;
    $mailer->Password = $smtp_password;
    if ($smtp_port === 465) {
      $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
      $mailer->SMTPAutoTLS = false;
    } else {
      $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      $mailer->SMTPAutoTLS = true;
    }
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($smtp_from_email, $smtp_from_name);
    $mailer->addAddress($to);
    $mailer->Subject = $subject;
    $mailer->isHTML(true);
    $mailer->Body = $html_body;
    $mailer->AltBody = $text_body;
    if (!$mailer->send()) {
      $error_message = trim((string)$mailer->ErrorInfo);
      return false;
    }
    return true;
  } catch (Throwable $e) {
    $error_message = $e->getMessage();
    error_log(
      'Quote email send failed for quote #' . $quote_id
      . ' to ' . $to
      . ': ' . $e->getMessage()
    );
    return false;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['customer_search'])) {
  header('Content-Type: application/json; charset=utf-8');

  $csrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  if ($csrf === '' || !hash_equals((string)$_SESSION['quotes_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode([]);
    exit;
  }

  $query = trim((string)($_GET['q'] ?? ''));
  if ($query === '') {
    echo json_encode([]);
    exit;
  }

  $like = '%' . quote_escape_like($query) . '%';
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
  echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['labor_search'])) {
  header('Content-Type: application/json; charset=utf-8');
  $csrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  if ($csrf === '' || !hash_equals((string)$_SESSION['quotes_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode([]);
    exit;
  }
  $query = trim((string)($_GET['q'] ?? ''));
  if ($query === '') { echo json_encode([]); exit; }
  $like = '%' . quote_escape_like($query) . '%';
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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['inventory_search'])) {
  header('Content-Type: application/json; charset=utf-8');
  $csrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  if ($csrf === '' || !hash_equals((string)$_SESSION['quotes_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode([]);
    exit;
  }
  $query = trim((string)($_GET['q'] ?? ''));
  if ($query === '') { echo json_encode([]); exit; }
  $like = '%' . quote_escape_like($query) . '%';
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

$errors = [];
$messages = [];
$today = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Y-m-d');
$fields = [
  'customer_id' => '',
  'customer_name' => '',
  'company_name' => '',
  'phone_number' => '',
  'email' => '',
  'quote_date' => $today,
  'notes' => '',
];
$line_items = [
  ['description' => '', 'quantity' => '1', 'cost' => '0.00', 'markup_percent' => '20', 'unit_price' => '0.00'],
];

$view = (string)($_GET['view'] ?? 'all');
if (!in_array($view, ['all', 'id', 'new'], true)) {
  $view = 'all';
}

$detail_id = $view === 'id' ? (int)($_GET['id'] ?? 0) : 0;
if ($view === 'id' && $detail_id <= 0) {
  $view = 'all';
  $detail_id = 0;
}

$show_new_form = $view === 'new';
$show_detail = $view === 'id' && $detail_id > 0;
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';
$updated = isset($_GET['updated']) && $_GET['updated'] === '1';
$invoice_converted = isset($_GET['invoice_converted']) && $_GET['invoice_converted'] === '1';
$email_sent = isset($_GET['email_sent']) && $_GET['email_sent'] === '1';
$deleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';

$edit_id = null;
$raw_edit = $_GET['edit'] ?? $_POST['edit_id'] ?? null;
if ($raw_edit !== null && (int)$raw_edit > 0) {
  $edit_id = (int)$raw_edit;
  $stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = ?");
  $stmt->execute([$edit_id]);
  $edit_record = $stmt->fetch();
  if (!$edit_record) {
    $edit_id = null;
  } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $fields['customer_id'] = (string)($edit_record['customer_id'] ?? '');
    $fields['customer_name'] = (string)($edit_record['customer_name'] ?? '');
    $fields['company_name'] = (string)($edit_record['company_name'] ?? '');
    $fields['phone_number'] = (string)($edit_record['phone_number'] ?? '');
    $fields['email'] = (string)($edit_record['email'] ?? '');
    $fields['quote_date'] = (string)($edit_record['quote_date'] ?? $today);
    $fields['notes'] = (string)($edit_record['notes'] ?? '');

    $item_stmt = $pdo->prepare("SELECT description, quantity, cost, markup_percent, unit_price FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC");
    $item_stmt->execute([$edit_id]);
    $rows = $item_stmt->fetchAll();
    if ($rows) {
      $line_items = [];
      foreach ($rows as $row) {
        $line_items[] = [
          'description'    => (string)$row['description'],
          'quantity'       => quote_format_money($row['quantity']),
          'cost'           => quote_format_money($row['cost']),
          'markup_percent' => number_format((float)$row['markup_percent'], 2),
          'unit_price'     => quote_format_money($row['unit_price']),
        ];
      }
    }

  }
}

$show_all = $view === 'all' && $edit_id === null;
$show_form = $show_new_form || $edit_id !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? 'save');
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['quotes_csrf'], $csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    if ($action === 'delete_quote') {
      $row_id = (int)($_POST['row_id'] ?? 0);
      if ($row_id <= 0) {
        $errors[] = 'Invalid quote selected for deletion.';
      } else {
        $stmt = $pdo->prepare("DELETE FROM quotes WHERE id = ?");
        $stmt->execute([$row_id]);
        if ($stmt->rowCount() < 1) {
          $errors[] = 'Quote not found or already deleted.';
        } else {
          $_SESSION['quotes_csrf'] = bin2hex(random_bytes(24));
          header('Location: quotes.php?view=all&deleted=1');
          exit;
        }
      }
    } elseif ($action === 'convert_invoice') {
      $row_id = (int)($_POST['row_id'] ?? 0);
      if ($row_id <= 0) {
        $errors[] = 'Invalid quote selected for invoice conversion.';
      } else {
        $inv_no = quote_invoice_number($row_id);
        $stmt = $pdo->prepare(
          "UPDATE quotes
           SET status = 'converted',
               converted_invoice_no = COALESCE(converted_invoice_no, ?),
               converted_at = COALESCE(converted_at, NOW())
           WHERE id = ? AND status <> 'converted'"
        );
        $stmt->execute([$inv_no, $row_id]);
        if ($stmt->rowCount() < 1) {
          $errors[] = 'This quote is already converted to an invoice.';
        } else {
          $_SESSION['quotes_csrf'] = bin2hex(random_bytes(24));
          header('Location: invoice_form.php?id=' . $row_id . '&invoice_converted=1');
          exit;
        }
      }
    } elseif ($action === 'send_email') {
      $row_id = (int)($_POST['row_id'] ?? 0);
      $stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = ? LIMIT 1");
      $stmt->execute([$row_id]);
      $quote = $stmt->fetch();
      if (!$quote) {
        $errors[] = 'Quote not found.';
      } else {
        $item_stmt = $pdo->prepare("SELECT description, quantity, unit_price, line_total FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC");
        $item_stmt->execute([$row_id]);
        $items = $item_stmt->fetchAll();
        if (!$items) {
          $errors[] = 'Cannot send email: quote has no line items.';
        } else {
          $email_error = null;
          if (!quote_send_email($pdo, $quote, $items, $email_error)) {
            $errors[] = $email_error !== null && $email_error !== '' ? $email_error : 'Email was not sent.';
          } else {
            $pdo->prepare("UPDATE quotes SET status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END WHERE id = ?")->execute([$row_id]);
            $_SESSION['quotes_csrf'] = bin2hex(random_bytes(24));
            header('Location: quotes.php?view=id&id=' . $row_id . '&email_sent=1');
            exit;
          }
        }
      }
    } else {
      foreach (array_keys($fields) as $key) {
        $fields[$key] = trim((string)($_POST[$key] ?? ''));
      }

      if ($fields['customer_name'] === '') {
        $errors[] = 'Customer Name is required.';
      } elseif (strlen($fields['customer_name']) > 255) {
        $errors[] = 'Customer Name must be 255 characters or fewer.';
      }
      if ($fields['company_name'] !== '' && strlen($fields['company_name']) > 255) {
        $errors[] = 'Company must be 255 characters or fewer.';
      }
      if ($fields['phone_number'] !== '' && strlen($fields['phone_number']) > 100) {
        $errors[] = 'Phone must be 100 characters or fewer.';
      }
      if ($fields['email'] !== '' && strlen($fields['email']) > 255) {
        $errors[] = 'Email must be 255 characters or fewer.';
      }
      if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
      }
      if ($fields['notes'] !== '' && strlen($fields['notes']) > QUOTE_MAX_NOTES_LENGTH) {
        $errors[] = 'Notes must be ' . QUOTE_MAX_NOTES_LENGTH . ' characters or fewer.';
      }

      if ($fields['customer_id'] !== '' && (int)$fields['customer_id'] <= 0) {
        $errors[] = 'Invalid customer selected.';
      }

      $customer_id = quote_resolve_customer_id($pdo, $fields);

      $posted_desc = $_POST['item_desc'] ?? [];
      $posted_qty = $_POST['item_qty'] ?? [];
      $posted_cost = $_POST['item_cost'] ?? [];
      $posted_markup = $_POST['item_markup'] ?? [];
      $posted_price = $_POST['item_price'] ?? [];
      if (!is_array($posted_desc) || !is_array($posted_qty) || !is_array($posted_cost) || !is_array($posted_markup) || !is_array($posted_price)) {
        $errors[] = 'Line item data is invalid.';
      } elseif (count($posted_desc) !== count($posted_qty) || count($posted_desc) !== count($posted_cost) || count($posted_desc) !== count($posted_markup) || count($posted_desc) !== count($posted_price)) {
        $errors[] = 'Line item data is malformed. Please reload and try again.';
      } else {
        $line_items = [];
        $line_count = min(count($posted_desc), count($posted_qty), count($posted_cost), count($posted_markup), QUOTE_MAX_LINE_ITEMS);
        for ($i = 0; $i < $line_count; $i++) {
          $desc = trim((string)$posted_desc[$i]);
          $qty_raw = trim((string)$posted_qty[$i]);
          $cost_raw = trim((string)$posted_cost[$i]);
          $markup_raw = trim((string)$posted_markup[$i]);

          if ($desc === '' && $qty_raw === '' && $cost_raw === '' && $markup_raw === '') {
            continue;
          }

          if ($desc === '') {
            $errors[] = 'Each line item requires a description.';
            continue;
          }
          if (!is_numeric($qty_raw) || (float)$qty_raw <= 0) {
            $errors[] = 'Each line item quantity must be greater than 0.';
            continue;
          }
          if (!is_numeric($cost_raw) || (float)$cost_raw < 0) {
            $errors[] = 'Each line item cost must be 0 or greater.';
            continue;
          }
          if (!is_numeric($markup_raw) || (float)$markup_raw < 0) {
            $errors[] = 'Each line item markup must be 0 or greater.';
            continue;
          }

          $qty = round((float)$qty_raw, 2);
          $cost = round((float)$cost_raw, 2);
          $markup = round((float)$markup_raw, 2);
          $price = round($cost * (1 + $markup / 100), 2);
          $line_items[] = [
            'description'    => $desc,
            'quantity'       => $qty,
            'cost'           => $cost,
            'markup_percent' => $markup,
            'unit_price'     => $price,
            'line_total'     => round($qty * $price, 2),
          ];
        }

        if (!$line_items) {
          $errors[] = 'Add at least one line item.';
        }
      }

      if (!$errors) {
        $subtotal = 0.00;
        foreach ($line_items as $row) {
          $subtotal += (float)$row['line_total'];
        }
        $subtotal = round($subtotal, 2);

        $pdo->beginTransaction();
        try {
          if ($edit_id !== null) {
            $upd = $pdo->prepare(
              "UPDATE quotes SET
                 customer_id = ?,
                 customer_name = ?,
                 company_name = ?,
                 phone_number = ?,
                 email = ?,
                 quote_date = ?,
                 notes = ?,
                 subtotal_amount = ?
               WHERE id = ?"
            );
            $upd->execute([
              $customer_id,
              $fields['customer_name'],
              $fields['company_name'] !== '' ? $fields['company_name'] : null,
              $fields['phone_number'] !== '' ? $fields['phone_number'] : null,
              $fields['email'] !== '' ? $fields['email'] : null,
              $fields['quote_date'] !== '' ? $fields['quote_date'] : $today,
              $fields['notes'] !== '' ? $fields['notes'] : null,
              $subtotal,
              $edit_id,
            ]);
            $quote_id = $edit_id;
            $pdo->prepare("DELETE FROM quote_items WHERE quote_id = ?")->execute([$edit_id]);
          } else {
            $created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            if ($created_by !== null && $created_by <= 0) {
              $created_by = null;
            }
            $ins = $pdo->prepare(
              "INSERT INTO quotes
                 (customer_id, customer_name, company_name, phone_number, email, quote_date, notes, subtotal_amount, created_by)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([
              $customer_id,
              $fields['customer_name'],
              $fields['company_name'] !== '' ? $fields['company_name'] : null,
              $fields['phone_number'] !== '' ? $fields['phone_number'] : null,
              $fields['email'] !== '' ? $fields['email'] : null,
              $fields['quote_date'] !== '' ? $fields['quote_date'] : $today,
              $fields['notes'] !== '' ? $fields['notes'] : null,
              $subtotal,
              $created_by,
            ]);
            $quote_id = (int)$pdo->lastInsertId();
          }
          quote_backfill_customer($pdo, $customer_id, $fields);

          $item_ins = $pdo->prepare(
            "INSERT INTO quote_items (quote_id, line_position, description, quantity, cost, markup_percent, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
          );
          $position = 1;
          foreach ($line_items as $row) {
            $item_ins->execute([
              $quote_id,
              $position,
              $row['description'],
              $row['quantity'],
              $row['cost'],
              $row['markup_percent'],
              $row['unit_price'],
              $row['line_total'],
            ]);
            $position++;
          }

          $pdo->commit();
          $_SESSION['quotes_csrf'] = bin2hex(random_bytes(24));
          if ($edit_id !== null) {
            header('Location: quotes.php?view=id&id=' . $quote_id . '&updated=1');
          } else {
            header('Location: quotes.php?view=id&id=' . $quote_id . '&saved=1');
          }
          exit;
        } catch (Throwable $e) {
          $pdo->rollBack();
          error_log('Quote save failed: ' . $e->getMessage());
          $errors[] = 'Save failed: ' . $e->getMessage();
        }
      }
    }
  }
}

$quotes = [];
if ($show_all) {
  $stmt = $pdo->query(
    "SELECT q.id, q.customer_name, q.company_name, q.quote_date, q.status, q.subtotal_amount, q.converted_invoice_no, q.created_at,
            COUNT(qi.id) AS line_count
     FROM quotes q
     LEFT JOIN quote_items qi ON qi.quote_id = q.id
     GROUP BY q.id
     ORDER BY q.created_at DESC, q.id DESC
     LIMIT 200"
  );
  $quotes = $stmt->fetchAll();
}

$detail_quote = null;
$detail_items = [];
if ($show_detail) {
  $stmt = $pdo->prepare(
    "SELECT q.*, u.username AS created_by_username
     FROM quotes q
     LEFT JOIN users u ON u.id = q.created_by
     WHERE q.id = ?
     LIMIT 1"
  );
  $stmt->execute([$detail_id]);
  $detail_quote = $stmt->fetch();

  if (!$detail_quote) {
    http_response_code(404);
    render_header('Quote Not Found');
    ?>
    <div class="card">
      <h1 style="margin-top:0;">Quote Not Found</h1>
      <p class="muted">We couldn’t find that quote.</p>
      <a class="btn" href="quotes.php?view=all">Back to Quotes</a>
    </div>
    <?php
    render_footer();
    exit;
  }

  $item_stmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC");
  $item_stmt->execute([$detail_id]);
  $detail_items = $item_stmt->fetchAll();
}

render_header('Quotes');
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1>Quotes</h1>
    <p class="muted">Create quotes with searchable customer lookup, line items, and invoice conversion.</p>
  </div>
  <div class="actions">
    <?php if (!$show_all): ?>
      <a class="btn" href="quotes.php?view=all">Back to Quotes</a>
    <?php endif; ?>
    <a class="btn primary" href="quotes.php?view=new">New Quote</a>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($saved): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Quote saved successfully.</div>
<?php endif; ?>
<?php if ($updated): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Quote updated successfully.</div>
<?php endif; ?>
<?php if ($invoice_converted): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Quote converted to invoice successfully.</div>
<?php endif; ?>
<?php if ($email_sent): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Quote email sent successfully.</div>
<?php endif; ?>
<?php if ($deleted): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Quote deleted successfully.</div>
<?php endif; ?>
<?php foreach ($messages as $msg): ?>
  <div class="alert" style="border-color:#bfdbfe; background:#eff6ff; color:#1e3a8a;"><?= h($msg) ?></div>
<?php endforeach; ?>

<?php if ($show_detail): ?>
  <?php
    $status = (string)$detail_quote['status'];
    $status_colors = [
      'draft' => ['#fef9c3', '#854d0e'],
      'sent' => ['#dbeafe', '#1d4ed8'],
      'converted' => ['#dcfce7', '#166534'],
    ];
    [$badge_bg, $badge_color] = $status_colors[$status] ?? ['#f1f5f9', '#334155'];
  ?>
  <div class="card">
    <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
      <div>
        <h2 style="margin:0;">Quote #<?= (int)$detail_quote['id'] ?> — <?= h((string)$detail_quote['customer_name']) ?></h2>
        <p class="muted" style="margin:6px 0 0;">Quote Date: <?= h((string)$detail_quote['quote_date']) ?><?= !empty($detail_quote['created_at']) ? ' • Created ' . h((string)$detail_quote['created_at']) : '' ?></p>
      </div>
      <span style="display:inline-flex; align-items:center; border-radius:999px; padding:6px 12px; font-weight:600; background:<?= h($badge_bg) ?>; color:<?= h($badge_color) ?>;"><?= h(ucfirst($status)) ?></span>
    </div>
  </div>

  <div class="card" style="overflow-x:auto;">
    <table>
      <tbody>
        <tr><th style="width:220px;">Customer</th><td><?= h((string)$detail_quote['customer_name']) ?></td></tr>
        <tr><th>Company</th><td><?= h((string)($detail_quote['company_name'] ?: '—')) ?></td></tr>
        <tr><th>Phone</th><td><?= h((string)($detail_quote['phone_number'] ?: '—')) ?></td></tr>
        <tr><th>Email</th><td><?= h((string)($detail_quote['email'] ?: '—')) ?></td></tr>
        <tr><th>Subtotal</th><td><strong>$<?= h(quote_format_money($detail_quote['subtotal_amount'])) ?></strong></td></tr>
        <tr><th>Invoice #</th><td><?= h((string)($detail_quote['converted_invoice_no'] ?: '—')) ?></td></tr>
        <tr><th>Notes</th><td style="white-space:pre-wrap;"><?= h((string)($detail_quote['notes'] ?: '—')) ?></td></tr>
      </tbody>
    </table>
  </div>

  <div class="card" style="overflow-x:auto;">
    <h3 style="margin-top:0;">Line Items</h3>
    <table>
      <thead>
        <tr>
          <th style="width:60px;">#</th>
          <th>Description</th>
          <th style="width:100px;">Qty</th>
          <th style="width:130px;">Cost</th>
          <th style="width:110px;">Markup %</th>
          <th style="width:130px;">Price</th>
          <th style="width:150px;">Line Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($detail_items as $idx => $item): ?>
          <tr>
            <td><?= (int)$idx + 1 ?></td>
            <td><?= h((string)$item['description']) ?></td>
            <td><?= h(quote_format_money($item['quantity'])) ?></td>
            <td>$<?= h(quote_format_money($item['cost'] ?? 0)) ?></td>
            <td><?= h(number_format((float)($item['markup_percent'] ?? 20), 2)) ?>%</td>
            <td>$<?= h(quote_format_money($item['unit_price'])) ?></td>
            <td><strong>$<?= h(quote_format_money($item['line_total'])) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <a class="btn" href="quotes.php?view=all">Back to Quotes</a>
      <a class="btn" href="quotes.php?edit=<?= (int)$detail_quote['id'] ?>">Edit Quote</a>

      <form method="post" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
        <input type="hidden" name="action" value="send_email" />
        <input type="hidden" name="row_id" value="<?= (int)$detail_quote['id'] ?>" />
        <button type="submit" class="btn">Email Quote</button>
      </form>

      <?php if ((string)$detail_quote['status'] !== 'converted'): ?>
        <form method="post" style="margin:0;" onsubmit="return confirm('Convert this quote to invoice?');">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
          <input type="hidden" name="action" value="convert_invoice" />
          <input type="hidden" name="row_id" value="<?= (int)$detail_quote['id'] ?>" />
          <button type="submit" class="btn primary">Convert to Invoice</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($show_all): ?>
  <div class="card" style="overflow-x:auto;">
    <h2 style="margin-top:0;">All Quotes</h2>
    <table style="min-width:860px;">
      <thead>
        <tr>
          <th>Date</th>
          <th>Quote #</th>
          <th>Customer</th>
          <th>Company</th>
          <th>Items</th>
          <th>Total</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$quotes): ?>
          <tr><td colspan="<?= QUOTE_TABLE_COLUMN_COUNT ?>" class="muted">No quotes yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($quotes as $quote): ?>
          <tr>
            <td><?= h((string)$quote['quote_date']) ?></td>
            <td>#<?= (int)$quote['id'] ?></td>
            <td><?= h((string)$quote['customer_name']) ?></td>
            <td><?= h((string)($quote['company_name'] ?: '—')) ?></td>
            <td><?= (int)$quote['line_count'] ?></td>
            <td><strong>$<?= h(quote_format_money($quote['subtotal_amount'])) ?></strong></td>
            <td><?= h(ucfirst((string)$quote['status'])) ?><?= !empty($quote['converted_invoice_no']) ? ' (' . h((string)$quote['converted_invoice_no']) . ')' : '' ?></td>
            <td style="white-space:nowrap;">
              <a class="btn" href="quotes.php?view=id&id=<?= (int)$quote['id'] ?>">View</a>
              <a class="btn" href="quotes.php?edit=<?= (int)$quote['id'] ?>">Edit</a>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
                <input type="hidden" name="action" value="send_email" />
                <input type="hidden" name="row_id" value="<?= (int)$quote['id'] ?>" />
                <button type="submit" class="btn">Email Quote</button>
              </form>
              <?php if ((string)$quote['status'] !== 'converted'): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Convert this quote to invoice?');">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
                  <input type="hidden" name="action" value="convert_invoice" />
                  <input type="hidden" name="row_id" value="<?= (int)$quote['id'] ?>" />
                  <button type="submit" class="btn primary">Convert to Invoice</button>
                </form>
              <?php endif; ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this quote? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
                <input type="hidden" name="action" value="delete_quote" />
                <input type="hidden" name="row_id" value="<?= (int)$quote['id'] ?>" />
                <button type="submit" class="btn danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($show_form): ?>
  <form method="post" class="card" style="max-width:1100px; position:relative;">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
    <?php if ($edit_id !== null): ?>
      <input type="hidden" name="edit_id" value="<?= (int)$edit_id ?>" />
      <h2 style="margin:0 0 14px;">Edit Quote</h2>
    <?php else: ?>
      <h2 style="margin:0 0 14px;">New Quote</h2>
    <?php endif; ?>

    <div class="form-grid" style="position:relative;">
      <div style="position:relative;">
        <label for="customer_name">Customer Name <span style="color:var(--d);">*</span></label>
        <input id="customer_name" type="text" name="customer_name" maxlength="255" required autocomplete="off" value="<?= h($fields['customer_name']) ?>" />
        <input id="customer_id" type="hidden" name="customer_id" value="<?= h($fields['customer_id']) ?>" />
        <div id="customerSuggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:40; background:#fff; border:1px solid #d1d5db; border-radius:10px; box-shadow:0 12px 24px rgba(2,6,23,.12); margin-top:6px; max-height:220px; overflow:auto;"></div>
      </div>
      <div>
        <label for="company_name">Company</label>
        <input id="company_name" type="text" name="company_name" maxlength="255" value="<?= h($fields['company_name']) ?>" />
      </div>
      <div>
        <label for="phone_number">Phone</label>
        <input id="phone_number" type="text" name="phone_number" maxlength="100" value="<?= h($fields['phone_number']) ?>" />
      </div>
      <div>
        <label for="email">Email</label>
        <input id="email" type="email" name="email" maxlength="255" value="<?= h($fields['email']) ?>" />
      </div>
      <div>
        <label for="quote_date">Quote Date</label>
        <input id="quote_date" type="date" name="quote_date" value="<?= h($fields['quote_date']) ?>" />
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
                <input type="text" class="item-desc labor-desc" name="item_desc[]" maxlength="500" value="" autocomplete="off" placeholder="Search labor / service…" />
                <input type="hidden" name="item_markup[]" value="0" />
                <input type="hidden" name="item_price[]" class="labor-price" value="0.00" />
                <div class="item-suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:50; background:#fff; border:1px solid #d1d5db; border-radius:10px; box-shadow:0 12px 24px rgba(2,6,23,.12); margin-top:4px; max-height:200px; overflow:auto;"></div>
              </td>
              <td><input type="number" step="0.01" min="0.01" class="labor-qty" name="item_qty[]" value="1" /></td>
              <td><input type="number" step="0.01" min="0" class="labor-cost" name="item_cost[]" value="0.00" /></td>
              <td class="labor-line-total" style="white-space:nowrap;">$0.00</td>
              <td><button type="button" class="btn remove-labor-row">×</button></td>
            </tr>
          </tbody>
        </table>
        <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
          <button type="button" class="btn" id="addLaborRow">+ Add Labor Item</button>
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
                  <input type="text" class="item-desc inv-desc" name="item_desc[]" maxlength="500" value="<?= h((string)$row['description']) ?>" autocomplete="off" placeholder="Search inventory / part…" />
                  <div class="item-suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:50; background:#fff; border:1px solid #d1d5db; border-radius:10px; box-shadow:0 12px 24px rgba(2,6,23,.12); margin-top:4px; max-height:200px; overflow:auto;"></div>
                </td>
                <td><input type="number" step="0.01" min="0.01" class="inv-qty" name="item_qty[]" value="<?= h((string)$row['quantity']) ?>" /></td>
                <td><input type="number" step="0.01" min="0" class="inv-cost" name="item_cost[]" value="<?= h((string)$row['cost']) ?>" /></td>
                <td><input type="number" step="0.01" min="0" class="inv-markup" name="item_markup[]" value="<?= h((string)$row['markup_percent']) ?>" /></td>
                <td><input type="number" step="0.01" min="0" class="inv-price" name="item_price[]" value="<?= h((string)$row['unit_price']) ?>" readonly style="background:var(--surface,#f8fafc); color:var(--muted,#64748b);" /></td>
                <td class="inv-line-total" style="white-space:nowrap;">$0.00</td>
                <td><button type="button" class="btn remove-inv-row">×</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
          <button type="button" class="btn" id="addInventoryRow">+ Add Inventory Item</button>
          <div><strong>Parts Subtotal: $<span id="partsSubtotal">0.00</span></strong></div>
        </div>
      </div>
    </div>

    <div style="margin-top:10px; text-align:right; font-size:1.05em;">
      <strong>Grand Total: $<span id="quoteSubtotal">0.00</span></strong>
    </div>

    <div style="margin-top:14px;">
      <label for="notes">Notes</label>
      <textarea id="notes" name="notes" rows="5" maxlength="<?= QUOTE_MAX_NOTES_LENGTH ?>"><?= h($fields['notes']) ?></textarea>
    </div>

    <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
      <button type="submit" class="btn primary" style="font-size:18px; padding:14px 22px;"><?= $edit_id !== null ? 'Update Quote' : 'Save Quote' ?></button>
      <a class="btn" href="quotes.php?view=all">View Quotes</a>
    </div>
  </form>

  <script>
    (() => {
      // ── Customer live search ──────────────────────────────────────────
      const customerNameInput = document.getElementById('customer_name');
      const customerIdInput   = document.getElementById('customer_id');
      const companyInput      = document.getElementById('company_name');
      const phoneInput        = document.getElementById('phone_number');
      const emailInput        = document.getElementById('email');
      const customerSugg      = document.getElementById('customerSuggestions');
      let customerDebounce    = null;

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
            customerIdInput.value   = row.id            || '';
            companyInput.value      = rowCompany;
            phoneInput.value        = rowPhone;
            emailInput.value        = rowEmail;
            hideCustomerSugg();
          });
          customerSugg.appendChild(btn);
        });
        customerSugg.style.display = 'block';
      }

      const csrfToken = '<?= h($_SESSION['quotes_csrf']) ?>';

      customerNameInput.addEventListener('input', () => {
        customerIdInput.value = '';
        const q = customerNameInput.value.trim();
        if (customerDebounce) clearTimeout(customerDebounce);
        if (q.length < 1) { hideCustomerSugg(); return; }
        customerDebounce = setTimeout(() => {
          fetch('quotes.php?customer_search=1&q=' + encodeURIComponent(q), {
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

      // ── Shared helpers ────────────────────────────────────────────────
      function parseNum(v) { const n = parseFloat(v); return Number.isFinite(n) ? n : 0; }

      const laborSubtotalNode = document.getElementById('laborSubtotal');
      const partsSubtotalNode = document.getElementById('partsSubtotal');
      const grandTotalNode    = document.getElementById('quoteSubtotal');

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
      const laborBody = document.getElementById('laborItemsBody');
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
        const descInput = row.querySelector('.labor-desc');
        const costInput = row.querySelector('.labor-cost');
        const qtyInput  = row.querySelector('.labor-qty');
        const suggestBox = row.querySelector('.item-suggestions');
        let timer = null;

        descInput.addEventListener('input', () => {
          const q = descInput.value.trim();
          if (timer) clearTimeout(timer);
          if (q.length < 1) { suggestBox.style.display = 'none'; suggestBox.innerHTML = ''; return; }
          timer = setTimeout(() => {
            fetch('quotes.php?labor_search=1&q=' + encodeURIComponent(q), {
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

      // ── Inventory section ─────────────────────────────────────────────
      const invBody    = document.getElementById('inventoryItemsBody');
      const addInvBtn  = document.getElementById('addInventoryRow');

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
        let timer = null;

        descInput.addEventListener('input', () => {
          const q = descInput.value.trim();
          if (timer) clearTimeout(timer);
          if (q.length < 1) { suggestBox.style.display = 'none'; suggestBox.innerHTML = ''; return; }
          timer = setTimeout(() => {
            fetch('quotes.php?inventory_search=1&q=' + encodeURIComponent(q), {
              credentials: 'same-origin',
              headers: { 'X-CSRF-Token': csrfToken }
            }).then((r) => r.ok ? r.json() : []).then((items) => {
              suggestBox.innerHTML = '';
              if (!items.length) { suggestBox.style.display = 'none'; return; }
              items.forEach((item) => {
                const costVal   = item.cost_price   != null ? '$' + parseFloat(item.cost_price).toFixed(2)   : '';
                const markupVal = item.markup_percent != null ? parseFloat(item.markup_percent).toFixed(0) + '%' : '20%';
                const sub = (costVal ? 'Cost: ' + costVal + ' • ' : '') + 'Markup: ' + markupVal;
                const btn = buildSuggestBtn(item.item_name, sub);
                btn.addEventListener('click', () => {
                  descInput.value   = item.item_name;
                  costInput.value   = item.cost_price   != null ? parseFloat(item.cost_price).toFixed(2)   : '0.00';
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

      // ── Pre-submit: strip blank rows so backend counts stay consistent ──
      const quoteForm = laborBody.closest('form');
      if (quoteForm) {
        quoteForm.addEventListener('submit', () => {
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
<?php endif; ?>

<?php render_footer(); ?>
