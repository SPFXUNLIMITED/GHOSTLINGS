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

function quote_approval_label(string $status): string {
  return match ($status) {
    'pending_approval' => 'Pending Approval',
    'approved' => 'Approved',
    default => 'Not Submitted',
  };
}

function quote_approval_badge_colors(string $status): array {
  return match ($status) {
    'pending_approval' => ['#fef3c7', '#92400e'],
    'approved' => ['#dcfce7', '#166534'],
    default => ['#f1f5f9', '#475569'],
  };
}

function quote_create_admin_approval_alerts(PDO $pdo, int $entity_id, string $entity_type, string $link_url, string $message): void {
  $admin_ids = $pdo->query("SELECT id FROM users WHERE is_admin = 1 OR role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
  if (!$admin_ids) {
    return;
  }

  $ins = $pdo->prepare("
    INSERT INTO approval_alerts (recipient_id, entity_type, entity_id, message, link_url)
    VALUES (?, ?, ?, ?, ?)
  ");
  foreach ($admin_ids as $admin_id_raw) {
    $admin_id = (int)$admin_id_raw;
    if ($admin_id <= 0) {
      continue;
    }
    $ins->execute([$admin_id, $entity_type, $entity_id, $message, $link_url]);
  }
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
  $customer_company = trim((string)($quote['company_name'] ?? ''));
  $quote_date    = trim((string)($quote['quote_date'] ?? ''));
  $subtotal      = quote_format_money($quote['subtotal_amount'] ?? 0);
  $tax_rate_val  = (float)($quote['tax_rate'] ?? 0);
  $tax_amount    = quote_format_money($quote['tax_amount'] ?? 0);
  $grand_total   = quote_format_money((float)($quote['subtotal_amount'] ?? 0) + (float)($quote['tax_amount'] ?? 0));

  // Bill To address
  $bill_street = trim((string)($quote['billing_street'] ?? ''));
  $bill_city   = trim((string)($quote['billing_city']   ?? ''));
  $bill_state  = trim((string)($quote['billing_state']  ?? ''));
  $bill_zip    = trim((string)($quote['billing_zip']    ?? ''));

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

  // Build Bill To address block HTML
  $bill_to_lines = [];
  if ($customer_company !== '') $bill_to_lines[] = '<strong style="color:#0f172a;">' . $h($customer_company) . '</strong>';
  if ($customer_name !== '')    $bill_to_lines[] = $h($customer_name);
  if ($bill_street !== '')      $bill_to_lines[] = $h($bill_street);
  $city_state_zip_parts = array_filter([$bill_city, $bill_state . ($bill_zip !== '' ? ' ' . $bill_zip : '')]);
  $city_state_zip = implode(', ', $city_state_zip_parts);
  if ($city_state_zip !== '')   $bill_to_lines[] = $h($city_state_zip);
  if (trim((string)($quote['phone_number'] ?? '')) !== '') $bill_to_lines[] = $h(trim((string)($quote['phone_number'] ?? '')));
  if (trim((string)($quote['email'] ?? '')) !== '') $bill_to_lines[] = '<a href="mailto:' . $h(trim((string)($quote['email'] ?? ''))) . '" style="color:#1d4ed8;text-decoration:none;">' . $h(trim((string)($quote['email'] ?? ''))) . '</a>';
  $bill_to_html = implode('<br>', $bill_to_lines);

  // Build From address block HTML
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

    // ── Bill To / From boxes ──
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
            . '<td colspan="3" style="padding:10px 12px;text-align:right;font-weight:600;font-size:13px;color:#1e293b;border-top:2px solid #e2e8f0;">Subtotal:</td>'
            . '<td style="padding:10px 12px;text-align:right;font-weight:600;font-size:14px;color:#1e3a5f;border-top:2px solid #e2e8f0;">$' . $h($subtotal) . '</td>'
          . '</tr>'
          . ($tax_rate_val > 0
              ? '<tr>'
                  . '<td colspan="3" style="padding:4px 12px;text-align:right;font-weight:600;font-size:13px;color:#1e293b;">Tax (' . $h(number_format($tax_rate_val, 2)) . '%):</td>'
                  . '<td style="padding:4px 12px;text-align:right;font-weight:600;font-size:14px;color:#1e3a5f;">$' . $h($tax_amount) . '</td>'
                . '</tr>'
              : '')
          . '<tr>'
            . '<td colspan="3" style="padding:10px 12px;text-align:right;font-weight:700;font-size:14px;color:#1e293b;">Grand Total:</td>'
            . '<td style="padding:10px 12px;text-align:right;font-weight:700;font-size:16px;color:#1e3a5f;">$' . $h($grand_total) . '</td>'
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
  $text_body .= str_repeat('-', 40) . "\r\n";
  $text_body .= "Bill To: " . ($customer_company !== '' ? $customer_company . ' / ' : '') . $customer_name . "\r\n";
  if ($bill_street !== '') $text_body .= $bill_street . "\r\n";
  if ($city_state_zip !== '') $text_body .= $city_state_zip . "\r\n";
  $text_body .= str_repeat('-', 40) . "\r\n\r\n";
  $text_body .= "Hello" . ($customer_name !== '' ? ", {$customer_name}" : '') . ",\r\n\r\n";
  $text_body .= "Please find your quote details below.\r\n\r\n";
  $text_body .= "Line Items:\r\n";
  $text_body .= implode("\r\n", $rows_text) . "\r\n\r\n";
  $text_body .= "Subtotal: \${$subtotal}\r\n";
  if ($tax_rate_val > 0) {
    $text_body .= "Tax (" . number_format($tax_rate_val, 2) . "%): \${$tax_amount}\r\n";
  }
  $text_body .= "Grand Total: \${$grand_total}\r\n\r\n";
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
      email,
      address,
      city,
      state,
      zip
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

// ---------- AJAX: print preview ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'print_preview') {
  header('Content-Type: application/json; charset=utf-8');
  $pv_id = (int)($_GET['quote_id'] ?? 0);
  if ($pv_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid quote ID.']);
    exit;
  }

  $pv_stmt = $pdo->prepare(
    "SELECT id, customer_name, company_name, phone_number, email,
            billing_street, billing_city, billing_state, billing_zip,
            quote_date, subtotal_amount, tax_rate, tax_amount, notes, created_by, created_at
     FROM quotes WHERE id = ? LIMIT 1"
  );
  $pv_stmt->execute([$pv_id]);
  $pv_quote = $pv_stmt->fetch(PDO::FETCH_ASSOC);
  if (!$pv_quote) {
    echo json_encode(['ok' => false, 'error' => 'Quote not found.']);
    exit;
  }

  $pv_items_stmt = $pdo->prepare(
    "SELECT description, quantity, unit_price, line_total, is_taxable FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC"
  );
  $pv_items_stmt->execute([$pv_id]);
  $pv_items = $pv_items_stmt->fetchAll(PDO::FETCH_ASSOC);

  $pv_sender = quote_sender_profile($pdo, $pv_quote);
  $pv_sender_name    = $pv_sender['sender_name'];
  $pv_sender_company = $pv_sender['company_name'] !== '' ? $pv_sender['company_name'] : 'Our Company';
  $pv_sender_address = $pv_sender['address'];
  $pv_sender_phone   = $pv_sender['phone'];
  $pv_sender_email   = $pv_sender['email'];

  $pv_quote_id       = (int)($pv_quote['id'] ?? 0);
  $pv_customer_name  = trim((string)($pv_quote['customer_name']  ?? ''));
  $pv_customer_co    = trim((string)($pv_quote['company_name']   ?? ''));
  $pv_quote_date     = trim((string)($pv_quote['quote_date']     ?? ''));
  if ($pv_quote_date === '') {
    $pv_quote_date = substr(trim((string)($pv_quote['created_at'] ?? '')), 0, 10);
  }
  $pv_subtotal   = quote_format_money($pv_quote['subtotal_amount'] ?? 0);
  $pv_tax_rate   = (float)($pv_quote['tax_rate'] ?? 0);
  $pv_tax_amount = quote_format_money($pv_quote['tax_amount'] ?? 0);
  $pv_grand_total = quote_format_money((float)($pv_quote['subtotal_amount'] ?? 0) + (float)($pv_quote['tax_amount'] ?? 0));
  $pv_notes    = trim((string)($pv_quote['notes'] ?? ''));

  $pv_bill_street = trim((string)($pv_quote['billing_street'] ?? ''));
  $pv_bill_city   = trim((string)($pv_quote['billing_city']   ?? ''));
  $pv_bill_state  = trim((string)($pv_quote['billing_state']  ?? ''));
  $pv_bill_zip    = trim((string)($pv_quote['billing_zip']    ?? ''));

  $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

  // Build item rows
  $pv_rows_html = [];
  $pv_row_index = 0;
  foreach ($pv_items as $pv_item) {
    $pv_desc       = trim((string)($pv_item['description'] ?? ''));
    $pv_qty        = quote_format_money($pv_item['quantity']   ?? 0);
    $pv_unit_price = quote_format_money($pv_item['unit_price'] ?? 0);
    $pv_line_total = quote_format_money($pv_item['line_total'] ?? 0);
    $pv_row_bg     = ($pv_row_index % 2 === 0) ? '#ffffff' : '#f9fafb';
    $pv_rows_html[] = '<tr style="background:' . $pv_row_bg . ';">'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#374151;">' . $h($pv_desc) . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">' . $h($pv_qty) . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">$' . $h($pv_unit_price) . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">$' . $h($pv_line_total) . '</td>'
      . '</tr>';
    $pv_row_index++;
  }
  if (!$pv_rows_html) {
    $pv_rows_html[] = '<tr><td colspan="4" style="padding:10px 12px;text-align:center;color:#6b7280;">No line items.</td></tr>';
  }

  // Header contact line
  $pv_header_parts = [];
  if ($pv_sender_address !== '') {
    $pv_addr_oneline = (string)preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], ' · ', $pv_sender_address));
    $pv_header_parts[] = $h($pv_addr_oneline);
  }
  if ($pv_sender_phone !== '') $pv_header_parts[] = $h($pv_sender_phone);
  if ($pv_sender_email !== '') $pv_header_parts[] = '<a href="mailto:' . $h($pv_sender_email) . '" style="color:#93c5fd;text-decoration:none;">' . $h($pv_sender_email) . '</a>';
  $pv_header_contact_html = implode(' &nbsp;·&nbsp; ', $pv_header_parts);

  // Prepared-by
  $pv_prepared_by_html = '';
  if ($pv_sender_name !== '') {
    $pv_prepared_by_html = 'This quote was prepared by <strong style="color:#1e293b;">' . $h($pv_sender_name) . '</strong>';
    if ($pv_sender_company !== 'Our Company') {
      $pv_prepared_by_html .= ' at <strong style="color:#1e293b;">' . $h($pv_sender_company) . '</strong>';
    }
    $pv_prepared_by_html .= '.';
  }

  // Footer contact line
  $pv_footer_parts = [];
  if ($pv_sender_address !== '') {
    $pv_footer_parts[] = $h((string)preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], ', ', $pv_sender_address)));
  }
  if ($pv_sender_phone !== '') $pv_footer_parts[] = $h($pv_sender_phone);
  if ($pv_sender_email !== '') $pv_footer_parts[] = '<a href="mailto:' . $h($pv_sender_email) . '" style="color:#93c5fd;text-decoration:none;">' . $h($pv_sender_email) . '</a>';
  $pv_footer_contact_html = implode(' &nbsp;·&nbsp; ', $pv_footer_parts);

  // Bill To block
  $pv_bill_to_lines = [];
  if ($pv_customer_co !== '')  $pv_bill_to_lines[] = '<strong style="color:#0f172a;">' . $h($pv_customer_co) . '</strong>';
  if ($pv_customer_name !== '') $pv_bill_to_lines[] = $h($pv_customer_name);
  if ($pv_bill_street !== '')  $pv_bill_to_lines[] = $h($pv_bill_street);
  $pv_city_state_zip_parts = array_filter([$pv_bill_city, $pv_bill_state . ($pv_bill_zip !== '' ? ' ' . $pv_bill_zip : '')]);
  $pv_city_state_zip = implode(', ', $pv_city_state_zip_parts);
  if ($pv_city_state_zip !== '') $pv_bill_to_lines[] = $h($pv_city_state_zip);
  $pv_quote_phone = trim((string)($pv_quote['phone_number'] ?? ''));
  if ($pv_quote_phone !== '')  $pv_bill_to_lines[] = $h($pv_quote_phone);
  $pv_quote_email = trim((string)($pv_quote['email'] ?? ''));
  if ($pv_quote_email !== '')  $pv_bill_to_lines[] = '<a href="mailto:' . $h($pv_quote_email) . '" style="color:#1d4ed8;text-decoration:none;">' . $h($pv_quote_email) . '</a>';
  $pv_bill_to_html = implode('<br>', $pv_bill_to_lines) ?: '&mdash;';

  // From block
  $pv_from_lines = [];
  $pv_from_lines[] = '<strong style="color:#0f172a;">' . $h($pv_sender_company) . '</strong>';
  if ($pv_sender_name !== '' && $pv_sender_name !== $pv_sender_company) $pv_from_lines[] = $h($pv_sender_name);
  foreach (array_filter(array_map('trim', (array)preg_split('/\r\n|\r|\n/', $pv_sender_address))) as $pv_addr_line) {
    $pv_from_lines[] = $h($pv_addr_line);
  }
  if ($pv_sender_phone !== '') $pv_from_lines[] = $h($pv_sender_phone);
  if ($pv_sender_email !== '') $pv_from_lines[] = '<a href="mailto:' . $h($pv_sender_email) . '" style="color:#1d4ed8;text-decoration:none;">' . $h($pv_sender_email) . '</a>';
  $pv_from_html = implode('<br>', $pv_from_lines);

  $pv_label = 'Quote #' . $pv_quote_id;

  $pv_preview_html =
    '<div style="max-width:680px;margin:0 auto;">'

    // ── Header banner ──
    . '<div style="background:#1e3a5f;border-radius:8px 8px 0 0;padding:28px 32px 24px;">'
      . '<p style="margin:0 0 6px;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.3px;">' . $h($pv_sender_company) . '</p>'
      . ($pv_header_contact_html !== '' ? '<p style="margin:0;font-size:13px;color:#93c5fd;line-height:1.6;">' . $pv_header_contact_html . '</p>' : '')
    . '</div>'

    // ── Document title strip ──
    . '<div style="background:#ffffff;padding:20px 32px 0;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
      . '<table style="width:100%;border-collapse:collapse;">'
        . '<tr>'
          . '<td style="padding:0 0 16px;">'
            . '<p style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">' . $h($pv_label) . '</p>'
          . '</td>'
          . '<td style="padding:0 0 16px;text-align:right;">'
            . '<p style="margin:0;font-size:13px;color:#64748b;">Date: ' . $h($pv_quote_date) . '</p>'
          . '</td>'
        . '</tr>'
      . '</table>'
      . '<hr style="margin:0;border:none;border-top:2px solid #e2e8f0;">'
    . '</div>'

    // ── Bill To / From boxes ──
    . '<div style="background:#ffffff;padding:20px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-top:0;">'
      . '<table style="width:100%;border-collapse:collapse;">'
        . '<tr>'
          . '<td style="width:50%;padding:0 8px 0 0;vertical-align:top;">'
            . '<div style="border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;background:#f8fafc;">'
              . '<p style="margin:0 0 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#64748b;">Bill To</p>'
              . '<p style="margin:0;font-size:13px;color:#374151;line-height:1.7;">' . $pv_bill_to_html . '</p>'
            . '</div>'
          . '</td>'
          . '<td style="width:50%;padding:0 0 0 8px;vertical-align:top;">'
            . '<div style="border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;background:#f8fafc;">'
              . '<p style="margin:0 0 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#64748b;">From</p>'
              . '<p style="margin:0;font-size:13px;color:#374151;line-height:1.7;">' . $pv_from_html . '</p>'
            . '</div>'
          . '</td>'
        . '</tr>'
      . '</table>'
    . '</div>'

    // ── Body ──
    . '<div style="background:#ffffff;padding:24px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
      . '<p style="margin:0 0 8px;font-size:15px;color:#1e293b;">Hello' . ($pv_customer_name !== '' ? ', ' . $h($pv_customer_name) : '') . ',</p>'
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
        . '<tbody>' . implode('', $pv_rows_html) . '</tbody>'
        . '<tfoot>'
          . '<tr>'
            . '<td colspan="3" style="padding:10px 12px;text-align:right;font-weight:600;font-size:13px;color:#1e293b;border-top:2px solid #e2e8f0;">Subtotal:</td>'
            . '<td style="padding:10px 12px;text-align:right;font-weight:600;font-size:14px;color:#1e3a5f;border-top:2px solid #e2e8f0;">$' . $h($pv_subtotal) . '</td>'
          . '</tr>'
          . ($pv_tax_rate > 0
              ? '<tr>'
                  . '<td colspan="3" style="padding:4px 12px;text-align:right;font-weight:600;font-size:13px;color:#1e293b;">Tax (' . $h(number_format($pv_tax_rate, 2)) . '%):</td>'
                  . '<td style="padding:4px 12px;text-align:right;font-weight:600;font-size:14px;color:#1e3a5f;">$' . $h($pv_tax_amount) . '</td>'
                . '</tr>'
              : '')
          . '<tr>'
            . '<td colspan="3" style="padding:10px 12px;text-align:right;font-weight:700;font-size:14px;color:#1e293b;">Grand Total:</td>'
            . '<td style="padding:10px 12px;text-align:right;font-weight:700;font-size:16px;color:#1e3a5f;">$' . $h($pv_grand_total) . '</td>'
          . '</tr>'
        . '</tfoot>'
      . '</table>'

      . ($pv_notes !== '' ? '<div style="margin-bottom:20px;padding:14px 16px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0;"><p style="margin:0 0 4px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Notes</p><p style="margin:0;font-size:14px;color:#475569;">' . nl2br($h($pv_notes)) . '</p></div>' : '')

      . '<p style="margin:0;font-size:14px;color:#475569;">Thank you for considering our services. Please do not hesitate to reach out if you have any questions.</p>'
    . '</div>'

    // ── Prepared-by strip ──
    . ($pv_prepared_by_html !== ''
        ? '<div style="background:#f8fafc;padding:14px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">'
            . '<p style="margin:0;font-size:13px;color:#64748b;">' . $pv_prepared_by_html . '</p>'
          . '</div>'
        : '')

    // ── Footer ──
    . '<div style="background:#1e3a5f;border-radius:0 0 8px 8px;padding:18px 32px;">'
      . '<p style="margin:0;font-size:12px;color:#93c5fd;line-height:1.6;">'
        . $h($pv_sender_company)
        . ($pv_footer_contact_html !== '' ? ' &nbsp;·&nbsp; ' . $pv_footer_contact_html : '')
      . '</p>'
    . '</div>'

    . '</div>';

  echo json_encode(['ok' => true, 'html' => $pv_preview_html, 'quote_label' => $pv_label]);
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
  'billing_street' => '',
  'billing_city' => '',
  'billing_state' => '',
  'billing_zip' => '',
  'quote_date' => $today,
  'notes' => '',
  'tax_rate' => '0.00',
];
$line_items = [
  ['description' => '', 'quantity' => '1', 'cost' => '0.00', 'markup_percent' => '20', 'unit_price' => '0.00', 'is_taxable' => 0],
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
$status_updated = isset($_GET['status_updated']) && $_GET['status_updated'] === '1';
$approval_sent = isset($_GET['approval_sent']) && $_GET['approval_sent'] === '1';
$approval_approved = isset($_GET['approval_approved']) && $_GET['approval_approved'] === '1';

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
    $fields['billing_street'] = (string)($edit_record['billing_street'] ?? '');
    $fields['billing_city'] = (string)($edit_record['billing_city'] ?? '');
    $fields['billing_state'] = (string)($edit_record['billing_state'] ?? '');
    $fields['billing_zip'] = (string)($edit_record['billing_zip'] ?? '');
    $fields['quote_date'] = (string)($edit_record['quote_date'] ?? $today);
    $fields['notes'] = (string)($edit_record['notes'] ?? '');
    $fields['tax_rate'] = number_format((float)($edit_record['tax_rate'] ?? 0), 2);

    $item_stmt = $pdo->prepare("SELECT description, quantity, cost, markup_percent, unit_price, is_taxable FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC");
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
          'is_taxable'     => (int)($row['is_taxable'] ?? 0),
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
    } elseif ($action === 'change_status') {
      $row_id     = (int)($_POST['row_id'] ?? 0);
      $new_status = trim((string)($_POST['new_status'] ?? ''));
      if ($row_id <= 0) {
        $errors[] = 'Invalid quote selected for status change.';
      } elseif (!in_array($new_status, ['draft', 'sent'], true)) {
        $errors[] = 'Invalid status value.';
      } else {
        $stmt = $pdo->prepare("UPDATE quotes SET status = ? WHERE id = ? AND status <> 'converted'");
        $stmt->execute([$new_status, $row_id]);
        if ($stmt->rowCount() < 1) {
          // Either already converted (immutable) or ID not found — treat as no-op
        }
        $_SESSION['quotes_csrf'] = bin2hex(random_bytes(24));
        header('Location: quotes.php?view=all&status_updated=1');
        exit;
      }
    } elseif ($action === 'send_for_approval') {
      $row_id = (int)($_POST['row_id'] ?? 0);
      if ($row_id <= 0) {
        $errors[] = 'Invalid quote selected for approval.';
      } else {
        $check = $pdo->prepare("SELECT id, customer_name FROM quotes WHERE id = ? LIMIT 1");
        $check->execute([$row_id]);
        $check_row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$check_row) {
          $errors[] = 'Quote not found.';
        } else {
          $pdo->prepare("UPDATE quotes SET approval_status = 'pending_approval' WHERE id = ?")->execute([$row_id]);
          $actor = trim((string)($_SESSION['username'] ?? 'A team member'));
          $customer_name_val = trim((string)($check_row['customer_name'] ?? ''));
          $customer_bold = $customer_name_val !== '' ? ' for <strong>' . h($customer_name_val) . '</strong>' : '';
          $msg = h($actor) . ' sent Quote #' . $row_id . $customer_bold . ' for approval.';
          quote_create_admin_approval_alerts($pdo, $row_id, 'quote', 'quotes.php?view=id&id=' . $row_id, $msg);
          $_SESSION['quotes_csrf'] = bin2hex(random_bytes(24));
          header('Location: quotes.php?view=all&approval_sent=1');
          exit;
        }
      }
    } elseif ($action === 'approve_quote') {
      $row_id = (int)($_POST['row_id'] ?? 0);
      if (!is_admin()) {
        $errors[] = 'Only admins can approve quotes.';
      } elseif ($row_id <= 0) {
        $errors[] = 'Invalid quote selected for approval.';
      } else {
        $check = $pdo->prepare("SELECT id FROM quotes WHERE id = ? LIMIT 1");
        $check->execute([$row_id]);
        if (!$check->fetch()) {
          $errors[] = 'Quote not found.';
        } else {
          $pdo->prepare("UPDATE quotes SET approval_status = 'approved' WHERE id = ?")->execute([$row_id]);
          $pdo->prepare("UPDATE approval_alerts SET is_read = 1 WHERE entity_type = 'quote' AND entity_id = ?")->execute([$row_id]);
          $_SESSION['quotes_csrf'] = bin2hex(random_bytes(24));
          header('Location: quotes.php?view=id&id=' . $row_id . '&approval_approved=1');
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
      $posted_taxable = $_POST['item_taxable'] ?? [];
      if (!is_array($posted_desc) || !is_array($posted_qty) || !is_array($posted_cost) || !is_array($posted_markup) || !is_array($posted_price) || !is_array($posted_taxable)) {
        $errors[] = 'Line item data is invalid.';
      } elseif (count($posted_desc) !== count($posted_qty) || count($posted_desc) !== count($posted_cost) || count($posted_desc) !== count($posted_markup) || count($posted_desc) !== count($posted_price) || count($posted_desc) !== count($posted_taxable)) {
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
          $is_taxable = (int)($posted_taxable[$i] ?? 0) === 1 ? 1 : 0;
          $line_items[] = [
            'description'    => $desc,
            'quantity'       => $qty,
            'cost'           => $cost,
            'markup_percent' => $markup,
            'unit_price'     => $price,
            'line_total'     => round($qty * $price, 2),
            'is_taxable'     => $is_taxable,
          ];
        }

        if (!$line_items) {
          $errors[] = 'Add at least one line item.';
        }
      }

      if (!$errors) {
        $post_tax_rate = max(0.0, min(100.0, round((float)trim((string)($_POST['tax_rate'] ?? '0')), 2)));
        $fields['tax_rate'] = number_format($post_tax_rate, 2);
        $subtotal = 0.00;
        $taxable_subtotal = 0.00;
        foreach ($line_items as $row) {
          $subtotal += (float)$row['line_total'];
          if ((int)($row['is_taxable'] ?? 0) === 1) {
            $taxable_subtotal += (float)$row['line_total'];
          }
        }
        $subtotal = round($subtotal, 2);
        $tax_amount = round($taxable_subtotal * $post_tax_rate / 100, 2);

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
                 billing_street = ?,
                 billing_city = ?,
                 billing_state = ?,
                 billing_zip = ?,
                 quote_date = ?,
                 notes = ?,
                 subtotal_amount = ?,
                 tax_rate = ?,
                 tax_amount = ?
               WHERE id = ?"
            );
            $upd->execute([
              $customer_id,
              $fields['customer_name'],
              $fields['company_name'] !== '' ? $fields['company_name'] : null,
              $fields['phone_number'] !== '' ? $fields['phone_number'] : null,
              $fields['email'] !== '' ? $fields['email'] : null,
              $fields['billing_street'] !== '' ? $fields['billing_street'] : null,
              $fields['billing_city'] !== '' ? $fields['billing_city'] : null,
              $fields['billing_state'] !== '' ? $fields['billing_state'] : null,
              $fields['billing_zip'] !== '' ? $fields['billing_zip'] : null,
              $fields['quote_date'] !== '' ? $fields['quote_date'] : $today,
              $fields['notes'] !== '' ? $fields['notes'] : null,
              $subtotal,
              $post_tax_rate,
              $tax_amount,
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
                 (customer_id, customer_name, company_name, phone_number, email, billing_street, billing_city, billing_state, billing_zip, quote_date, notes, subtotal_amount, tax_rate, tax_amount, created_by)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([
              $customer_id,
              $fields['customer_name'],
              $fields['company_name'] !== '' ? $fields['company_name'] : null,
              $fields['phone_number'] !== '' ? $fields['phone_number'] : null,
              $fields['email'] !== '' ? $fields['email'] : null,
              $fields['billing_street'] !== '' ? $fields['billing_street'] : null,
              $fields['billing_city'] !== '' ? $fields['billing_city'] : null,
              $fields['billing_state'] !== '' ? $fields['billing_state'] : null,
              $fields['billing_zip'] !== '' ? $fields['billing_zip'] : null,
              $fields['quote_date'] !== '' ? $fields['quote_date'] : $today,
              $fields['notes'] !== '' ? $fields['notes'] : null,
              $subtotal,
              $post_tax_rate,
              $tax_amount,
              $created_by,
            ]);
            $quote_id = (int)$pdo->lastInsertId();
          }
          quote_backfill_customer($pdo, $customer_id, $fields);

          $item_ins = $pdo->prepare(
            "INSERT INTO quote_items (quote_id, line_position, description, quantity, cost, markup_percent, unit_price, line_total, is_taxable)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
              $row['is_taxable'],
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
            q.approval_status,
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
<?php if ($status_updated): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Quote status updated.</div>
<?php endif; ?>
<?php if ($approval_sent): ?>
  <div class="alert" style="border-color:#fde68a; background:#fffbeb; color:#92400e;">Quote sent for approval.</div>
<?php endif; ?>
<?php if ($approval_approved): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Quote approved.</div>
<?php endif; ?>
<?php foreach ($messages as $msg): ?>
  <div class="alert" style="border-color:#bfdbfe; background:#eff6ff; color:#1e3a8a;"><?= h($msg) ?></div>
<?php endforeach; ?>

<?php if ($show_detail): ?>
  <?php
    $status = (string)$detail_quote['status'];
    $approval_status = (string)($detail_quote['approval_status'] ?? 'none');
    $status_colors = [
      'draft' => ['#fef9c3', '#854d0e'],
      'sent' => ['#dbeafe', '#1d4ed8'],
      'converted' => ['#dcfce7', '#166534'],
    ];
    [$badge_bg, $badge_color] = $status_colors[$status] ?? ['#f1f5f9', '#334155'];
    [$approval_bg, $approval_color] = quote_approval_badge_colors($approval_status);
    $approval_label = quote_approval_label($approval_status);
    $dq_sender = quote_sender_profile($pdo, $detail_quote);
    $dq_sender_company = $dq_sender['company_name'] !== '' ? $dq_sender['company_name'] : ($dq_sender['sender_name'] !== '' ? $dq_sender['sender_name'] : 'Our Company');
    $dq_billing_street = trim((string)($detail_quote['billing_street'] ?? ''));
    $dq_billing_city   = trim((string)($detail_quote['billing_city']   ?? ''));
    $dq_billing_state  = trim((string)($detail_quote['billing_state']  ?? ''));
    $dq_billing_zip    = trim((string)($detail_quote['billing_zip']    ?? ''));
    $dq_addr_parts = array_filter([$dq_billing_city, $dq_billing_state . ($dq_billing_zip !== '' ? ' ' . $dq_billing_zip : '')]);
    $dq_city_state_zip = implode(', ', $dq_addr_parts);
    $dq_sender_addr_lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $dq_sender['address'])));
  ?>
  <div class="card">
    <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
      <div>
        <h2 style="margin:0;">Quote #<?= (int)$detail_quote['id'] ?> — <?= h((string)$detail_quote['customer_name']) ?></h2>
        <p class="muted" style="margin:6px 0 0;">Quote Date: <?= h((string)$detail_quote['quote_date']) ?><?= !empty($detail_quote['created_at']) ? ' • Created ' . h((string)$detail_quote['created_at']) : '' ?></p>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <span style="display:inline-flex; align-items:center; border-radius:999px; padding:6px 12px; font-weight:600; background:<?= h($badge_bg) ?>; color:<?= h($badge_color) ?>;"><?= h(ucfirst($status)) ?></span>
        <span style="display:inline-flex; align-items:center; border-radius:999px; padding:6px 12px; font-weight:600; background:<?= h($approval_bg) ?>; color:<?= h($approval_color) ?>;">Approval: <?= h($approval_label) ?></span>
      </div>
    </div>
  </div>

  <div class="card">
    <p>Customer Email Preview — This is exactly what the customer will receive:</p>
    <iframe src="email_preview.php?id=<?= (int)$detail_quote['id'] ?>&context=quote"
        style="width:100%; height:1100px; border:1px solid #e2e8f0; border-radius:8px;"
        title="Quote Email Preview"></iframe>
  </div>

  <div class="card">
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <a class="btn" href="quotes.php?view=all">Back to Quotes</a>
      <a class="btn" href="quotes.php?edit=<?= (int)$detail_quote['id'] ?>">Edit Quote</a>

      <form method="post" style="margin:0;" onsubmit="return confirm('Are you sure you want to email this quote to <?= addslashes(h((string)($detail_quote["customer_name"] ?? ""))) ?>? This cannot be undone.');">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
        <input type="hidden" name="action" value="send_email" />
        <input type="hidden" name="row_id" value="<?= (int)$detail_quote['id'] ?>" />
        <button type="submit" class="btn">Email Quote</button>
      </form>

      <form method="post" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
        <input type="hidden" name="action" value="send_for_approval" />
        <input type="hidden" name="row_id" value="<?= (int)$detail_quote['id'] ?>" />
        <button type="submit" class="btn">Send for Approval</button>
      </form>

      <?php if (is_admin() && (string)($detail_quote['approval_status'] ?? 'none') !== 'approved'): ?>
        <form method="post" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
          <input type="hidden" name="action" value="approve_quote" />
          <input type="hidden" name="row_id" value="<?= (int)$detail_quote['id'] ?>" />
          <button type="submit" class="btn primary">Approve</button>
        </form>
      <?php endif; ?>

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
            <?php
              $row_status = (string)$quote['status'];
              $row_status_colors = [
                'draft'     => ['#fef9c3', '#854d0e'],
                'sent'      => ['#dbeafe', '#1d4ed8'],
                'converted' => ['#dcfce7', '#166534'],
              ];
              $row_approval_status = (string)($quote['approval_status'] ?? 'none');
              [$row_approval_bg, $row_approval_color] = quote_approval_badge_colors($row_approval_status);
              [$row_badge_bg, $row_badge_color] = $row_status_colors[$row_status] ?? ['#f1f5f9', '#334155'];
            ?>
            <td style="white-space:nowrap;">
              <?php if ($row_status === 'converted'): ?>
                <span style="display:inline-flex;align-items:center;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;background:<?= h($row_badge_bg) ?>;color:<?= h($row_badge_color) ?>;">Converted</span><?= !empty($quote['converted_invoice_no']) ? ' <span style="font-size:12px;color:#64748b;">(<a href="invoice_form.php?id=' . (int)$quote['id'] . '&mode=view" style="color:inherit;text-decoration:none;">' . h((string)$quote['converted_invoice_no']) . '</a>)</span>' : '' ?>
              <?php else: ?>
                <form method="post" style="display:inline-flex;align-items:center;gap:4px;">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
                  <input type="hidden" name="action" value="change_status" />
                  <input type="hidden" name="row_id" value="<?= (int)$quote['id'] ?>" />
                  <select name="new_status" aria-label="Status for quote #<?= (int)$quote['id'] ?>" style="font-size:0.82em;padding:3px 6px;">
                    <option value="draft" <?= $row_status === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="sent"  <?= $row_status === 'sent'  ? 'selected' : '' ?>>Sent</option>
                  </select>
                  <button type="submit" class="btn" style="font-size:0.78em;padding:3px 8px;">Save</button>
                </form>
              <?php endif; ?>
              <span style="display:inline-flex;align-items:center;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;background:<?= h($row_approval_bg) ?>;color:<?= h($row_approval_color) ?>;margin-left:6px;">
                <?= h(quote_approval_label($row_approval_status)) ?>
              </span>
            </td>
            <td style="white-space:nowrap;">
              <a class="btn" href="quotes.php?view=id&id=<?= (int)$quote['id'] ?>">View</a>
              <a class="btn" href="quotes.php?edit=<?= (int)$quote['id'] ?>">Edit</a>
              <button type="button" class="btn qt-print-btn" data-quote-id="<?= (int)$quote['id'] ?>">🖨 Print</button>
              <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to email this quote to <?= addslashes(h((string)($quote["customer_name"] ?? ""))) ?>? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
                <input type="hidden" name="action" value="send_email" />
                <input type="hidden" name="row_id" value="<?= (int)$quote['id'] ?>" />
                <button type="submit" class="btn">Email Quote</button>
              </form>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
                <input type="hidden" name="action" value="send_for_approval" />
                <input type="hidden" name="row_id" value="<?= (int)$quote['id'] ?>" />
                <button type="submit" class="btn">Send for Approval</button>
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

<!-- ===== Quote Print Modal ===== -->
<div id="qt-print-modal" role="dialog" aria-modal="true" aria-labelledby="qt-print-modal-title" style="display:none;">
  <div class="qt-modal-backdrop"></div>
  <div class="qt-modal-shell">
    <!-- Close button -->
    <button type="button" class="qt-modal-close" aria-label="Close preview">&times;</button>

    <!-- Modal header -->
    <div class="qt-modal-header">
      <div class="qt-modal-header-icon" aria-hidden="true">🧾</div>
      <div>
        <h2 id="qt-print-modal-title" class="qt-modal-title">Quote Preview</h2>
        <p class="qt-modal-subtitle">Review your quote before printing</p>
      </div>
    </div>

    <!-- Quote content area (populated via JS) -->
    <div class="qt-modal-body">
      <div id="qt-print-modal-loading" class="qt-modal-loading" aria-live="polite">
        <span class="qt-spinner" aria-hidden="true"></span>
        Loading quote&hellip;
      </div>
      <div id="qt-print-modal-content" style="display:none;"></div>
      <div id="qt-print-modal-error" class="qt-modal-error" style="display:none;" role="alert"></div>
    </div>

    <!-- Footer actions -->
    <div class="qt-modal-footer">
      <button type="button" class="qt-modal-cancel-btn" id="qt-modal-cancel">Cancel</button>
      <button type="button" class="qt-modal-print-btn" id="qt-modal-print-btn" disabled>
        <span class="qt-modal-print-icon" aria-hidden="true">🖨</span>
        Print Quote
      </button>
    </div>
  </div>
</div>

<style>
/* ---- Quote Print Modal ---- */
#qt-print-modal {
  position:fixed; inset:0; z-index:9000;
}
.qt-modal-backdrop {
  position:absolute; inset:0;
  background:rgba(15,23,42,0.72);
  backdrop-filter:blur(4px);
  -webkit-backdrop-filter:blur(4px);
  animation:qt-fade-in .18s ease;
}
@keyframes qt-fade-in { from { opacity:0; } to { opacity:1; } }
.qt-modal-shell {
  position:absolute;
  top:50%; left:50%;
  transform:translate(-50%,-50%);
  width:min(760px, calc(100vw - 32px));
  max-height:calc(100vh - 48px);
  display:flex;
  flex-direction:column;
  background:#fff;
  border-radius:16px;
  box-shadow:0 32px 80px rgba(0,0,0,.4), 0 0 0 1px rgba(0,0,0,.08);
  animation:qt-slide-up .22s cubic-bezier(.34,1.26,.64,1);
  overflow:hidden;
}
@keyframes qt-slide-up {
  from { opacity:0; transform:translate(-50%,calc(-50% + 24px)); }
  to   { opacity:1; transform:translate(-50%,-50%); }
}
.qt-modal-close {
  position:absolute; top:14px; right:16px;
  width:32px; height:32px;
  border:none; border-radius:50%;
  background:rgba(255,255,255,.15);
  color:#fff;
  font-size:20px; line-height:1;
  cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  transition:background .15s;
  z-index:1;
}
.qt-modal-close:hover { background:rgba(255,255,255,.3); }
.qt-modal-header {
  display:flex; align-items:center; gap:16px;
  padding:22px 28px 20px;
  background:linear-gradient(135deg,#1e3a5f 0%,#1d4ed8 100%);
  flex-shrink:0;
}
.qt-modal-header-icon {
  font-size:32px; line-height:1;
  filter:drop-shadow(0 2px 6px rgba(0,0,0,.25));
}
.qt-modal-title {
  margin:0 0 2px;
  font-size:20px; font-weight:700; color:#fff;
  letter-spacing:0.2px;
}
.qt-modal-subtitle {
  margin:0;
  font-size:13px; color:#93c5fd;
}
.qt-modal-body {
  flex:1 1 auto;
  overflow-y:auto;
  padding:28px 28px 16px;
  background:#f1f5f9;
}
.qt-modal-loading {
  display:flex; align-items:center; gap:12px;
  justify-content:center;
  padding:48px 0;
  font-size:15px; color:#64748b;
}
.qt-spinner {
  display:inline-block;
  width:22px; height:22px;
  border:3px solid #e2e8f0;
  border-top-color:#1d4ed8;
  border-radius:50%;
  animation:qt-spin .7s linear infinite;
}
@keyframes qt-spin { to { transform:rotate(360deg); } }
.qt-modal-error {
  padding:14px 18px;
  background:#fef2f2;
  border:1px solid #fecaca;
  border-radius:8px;
  color:#991b1b;
  font-size:14px;
}
.qt-modal-footer {
  display:flex; align-items:center; justify-content:flex-end;
  gap:12px;
  padding:16px 28px;
  background:#fff;
  border-top:1px solid #e2e8f0;
  flex-shrink:0;
}
.qt-modal-cancel-btn {
  padding:10px 20px;
  background:#fff;
  color:#475569;
  border:1px solid #cbd5e1;
  border-radius:8px;
  font-size:14px; font-weight:600;
  cursor:pointer;
  transition:background .15s, border-color .15s;
}
.qt-modal-cancel-btn:hover { background:#f8fafc; border-color:#94a3b8; }
.qt-modal-print-btn {
  display:flex; align-items:center; gap:8px;
  padding:12px 28px;
  background:linear-gradient(135deg,#1d4ed8,#1e3a5f);
  color:#fff;
  border:none;
  border-radius:10px;
  font-size:15px; font-weight:700;
  cursor:pointer;
  box-shadow:0 4px 14px rgba(29,78,216,.45);
  transition:opacity .15s, box-shadow .15s, transform .1s;
  letter-spacing:0.2px;
}
.qt-modal-print-btn:hover:not(:disabled) { opacity:.92; box-shadow:0 6px 20px rgba(29,78,216,.55); transform:translateY(-1px); }
.qt-modal-print-btn:active:not(:disabled) { transform:translateY(0); }
.qt-modal-print-btn:disabled { opacity:.5; cursor:not-allowed; box-shadow:none; }
.qt-modal-print-icon { font-size:18px; }

/* ---- @media print: suppress the main page entirely when printing from popup ---- */
@media print {
  body { display:none !important; }
}
</style>

<script>
(function () {
  'use strict';

  var modal      = document.getElementById('qt-print-modal');
  var loadingEl  = document.getElementById('qt-print-modal-loading');
  var contentEl  = document.getElementById('qt-print-modal-content');
  var errorEl    = document.getElementById('qt-print-modal-error');
  var printBtn   = document.getElementById('qt-modal-print-btn');
  var cancelBtns = [
    document.getElementById('qt-modal-cancel'),
    modal.querySelector('.qt-modal-close')
  ];

  function openModal() {
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    modal.querySelector('.qt-modal-close').focus();
  }

  function closeModal() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
    contentEl.innerHTML = '';
    contentEl.style.display = 'none';
    loadingEl.style.display = 'flex';
    errorEl.style.display = 'none';
    errorEl.textContent = '';
    printBtn.disabled = true;
  }

  cancelBtns.forEach(function (btn) {
    if (btn) btn.addEventListener('click', closeModal);
  });

  // Close on backdrop click
  modal.querySelector('.qt-modal-backdrop').addEventListener('click', closeModal);

  // Close on Escape
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') closeModal();
  });

  // Print button — open a clean popup window containing only the quote HTML
  printBtn.addEventListener('click', function () {
    var html = contentEl.innerHTML;
    if (!html) return;

    var popup = window.open('', '_blank', 'width=800,height=700,scrollbars=yes,resizable=yes');
    if (!popup) {
      alert('A pop-up was blocked. Please allow pop-ups for this site in your browser settings and try again.');
      return;
    }

    popup.document.open();
    popup.document.write(
      '<!DOCTYPE html>' +
      '<html lang="en">' +
      '<head>' +
        '<meta charset="UTF-8">' +
        '<meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<title>Quote</title>' +
        '<style>' +
          '*, *::before, *::after { box-sizing: border-box; }' +
          'html, body { margin: 0; padding: 0; background: #fff; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #1e293b; }' +
          '@media screen { body { padding: 24px; } }' +
          '@page { margin: 15mm 12mm; }' +
          '@media print {' +
            'html, body { margin: 0; padding: 0; background: #fff; }' +
            'a { color: inherit !important; text-decoration: none !important; }' +
          '}' +
        '</style>' +
      '</head>' +
      '<body>' + html + '</body>' +
      '</html>'
    );
    popup.document.close();

    // onload may not fire after document.write(); use a short timeout as fallback
    var printed = false;
    function doPrint() {
      if (printed) return;
      printed = true;
      popup.focus();
      popup.print();
    }
    popup.onload = doPrint;
    setTimeout(doPrint, 400);
  });

  // Print buttons in table rows
  document.querySelectorAll('.qt-print-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var quoteId = btn.getAttribute('data-quote-id');
      openModal();

      fetch('quotes.php?action=print_preview&quote_id=' + encodeURIComponent(quoteId), {
        credentials: 'same-origin'
      })
        .then(function (res) {
          if (!res.ok) throw new Error('Server returned ' + res.status);
          return res.json();
        })
        .then(function (data) {
          if (!data.ok) {
            loadingEl.style.display = 'none';
            errorEl.textContent = data.error || 'Failed to load quote.';
            errorEl.style.display = 'block';
            return;
          }
          contentEl.innerHTML = data.html;
          loadingEl.style.display = 'none';
          contentEl.style.display = 'block';
          printBtn.disabled = false;
        })
        .catch(function (err) {
          loadingEl.style.display = 'none';
          errorEl.textContent = 'Could not load quote preview: ' + err.message;
          errorEl.style.display = 'block';
        });
    });
  });
}());
</script>

<?php elseif ($show_form): ?>
  <form method="post" class="card" style="max-width:1280px; position:relative;">
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

    <div class="form-grid" style="margin-top:14px;">
      <div>
        <label for="billing_street">Billing Street Address</label>
        <input id="billing_street" type="text" name="billing_street" maxlength="255" value="<?= h($fields['billing_street']) ?>" placeholder="Street address" />
      </div>
      <div>
        <label for="billing_city">City</label>
        <input id="billing_city" type="text" name="billing_city" maxlength="100" value="<?= h($fields['billing_city']) ?>" />
      </div>
      <div>
        <label for="billing_state">State</label>
        <input id="billing_state" type="text" name="billing_state" maxlength="100" value="<?= h($fields['billing_state']) ?>" />
      </div>
      <div>
        <label for="billing_zip">ZIP / Postal Code</label>
        <input id="billing_zip" type="text" name="billing_zip" maxlength="20" value="<?= h($fields['billing_zip']) ?>" />
      </div>
    </div>

    <!-- ── Labor / Services ── -->
    <div style="margin-top:20px;">
      <h3 style="margin:0 0 10px;">Labor / Services</h3>
      <div style="overflow-x:auto;">
        <table style="min-width:780px;" id="laborItemsTable">
          <thead>
            <tr>
              <th>Description</th>
              <th style="width:100px;">Qty</th>
              <th style="width:130px;">Cost</th>
              <th style="width:150px;">Line Total</th>
              <th style="width:80px; text-align:center;">Taxable</th>
              <th style="width:90px;">Remove</th>
            </tr>
          </thead>
          <tbody id="laborItemsBody">
            <tr class="labor-row">
              <td>
                <input type="text" class="item-desc labor-desc" name="item_desc[]" maxlength="500" value="" autocomplete="off" placeholder="Search labor / service…" />
                <input type="hidden" name="item_markup[]" value="0" />
                <input type="hidden" name="item_price[]" class="labor-price" value="0.00" />
              </td>
              <td><input type="number" step="0.01" min="0.01" class="labor-qty" name="item_qty[]" value="1" /></td>
              <td><input type="number" step="0.01" min="0" class="labor-cost" name="item_cost[]" value="0.00" /></td>
              <td class="labor-line-total" style="white-space:nowrap;">$0.00</td>
              <td style="text-align:center;">
                <input type="hidden" class="taxable-hidden" name="item_taxable[]" value="0" />
                <input type="checkbox" class="taxable-check" style="width:18px;height:18px;cursor:pointer;" />
              </td>
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
        <table style="min-width:980px;" id="inventoryItemsTable">
          <thead>
            <tr>
              <th>Description</th>
              <th style="width:100px;">Qty</th>
              <th style="width:130px;">Cost</th>
              <th style="width:110px;">Markup %</th>
              <th style="width:130px;">Price</th>
              <th style="width:150px;">Line Total</th>
              <th style="width:80px; text-align:center;">Taxable</th>
              <th style="width:90px;">Remove</th>
            </tr>
          </thead>
          <tbody id="inventoryItemsBody">
            <?php foreach ($line_items as $row): ?>
              <tr class="inv-row">
                <td>
                  <input type="text" class="item-desc inv-desc" name="item_desc[]" maxlength="500" value="<?= h((string)$row['description']) ?>" autocomplete="off" placeholder="Search inventory / part…" />
                </td>
                <td><input type="number" step="0.01" min="0.01" class="inv-qty" name="item_qty[]" value="<?= h((string)$row['quantity']) ?>" /></td>
                <td><input type="number" step="0.01" min="0" class="inv-cost" name="item_cost[]" value="<?= h((string)$row['cost']) ?>" /></td>
                <td><input type="number" step="0.01" min="0" class="inv-markup" name="item_markup[]" value="<?= h((string)$row['markup_percent']) ?>" /></td>
                <td><input type="number" step="0.01" min="0" class="inv-price" name="item_price[]" value="<?= h((string)$row['unit_price']) ?>" readonly style="background:var(--surface,#f8fafc); color:var(--muted,#64748b);" /></td>
                <td class="inv-line-total" style="white-space:nowrap;">$0.00</td>
                <td style="text-align:center;">
                  <input type="hidden" class="taxable-hidden" name="item_taxable[]" value="<?= (int)($row['is_taxable'] ?? 0) === 1 ? '1' : '0' ?>" />
                  <input type="checkbox" class="taxable-check" style="width:18px;height:18px;cursor:pointer;"<?= (int)($row['is_taxable'] ?? 0) === 1 ? ' checked' : '' ?> />
                </td>
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

    <div style="margin-top:14px; display:flex; justify-content:flex-end; align-items:flex-start; gap:14px; flex-wrap:wrap;">
      <div style="text-align:right;">
        <label for="tax_rate" style="display:block; margin-bottom:4px; font-weight:600;">Tax Rate (%)</label>
        <input id="tax_rate" type="number" name="tax_rate" step="0.01" min="0" max="100" value="<?= h($fields['tax_rate']) ?>" style="width:120px; text-align:right;" />
      </div>
      <div style="font-size:1.05em; padding-top:28px; line-height:1.8;">
        <div>Subtotal: $<span id="quoteSubtotalDisplay">0.00</span></div>
        <div id="quoteTaxRow" style="display:none;">Tax (<span id="quoteTaxRateDisplay">0.00</span>%): $<span id="quoteTaxAmount">0.00</span></div>
        <div><strong>Grand Total: $<span id="quoteSubtotal">0.00</span></strong></div>
      </div>
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
      const streetInput       = document.getElementById('billing_street');
      const cityInput         = document.getElementById('billing_city');
      const stateInput        = document.getElementById('billing_state');
      const zipInput          = document.getElementById('billing_zip');
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
            if (streetInput) streetInput.value = row.address || '';
            if (cityInput)   cityInput.value   = row.city    || '';
            if (stateInput)  stateInput.value  = row.state   || '';
            if (zipInput)    zipInput.value    = row.zip     || '';
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

      const laborSubtotalNode      = document.getElementById('laborSubtotal');
      const partsSubtotalNode      = document.getElementById('partsSubtotal');
      const grandTotalNode         = document.getElementById('quoteSubtotal');
      const subtotalDisplayNode    = document.getElementById('quoteSubtotalDisplay');
      const taxAmountNode          = document.getElementById('quoteTaxAmount');
      const taxRateDisplayNode     = document.getElementById('quoteTaxRateDisplay');
      const taxRowNode             = document.getElementById('quoteTaxRow');
      const taxRateInput           = document.getElementById('tax_rate');

      // taxable subtotals tracked by each section
      let _laborTaxable = 0;
      let _invTaxable   = 0;

      function updateGrandTotal() {
        const subtotal     = parseNum(laborSubtotalNode.textContent) + parseNum(partsSubtotalNode.textContent);
        const taxRate      = parseNum(taxRateInput ? taxRateInput.value : 0);
        const taxableTotal = _laborTaxable + _invTaxable;
        const taxAmount    = taxableTotal * taxRate / 100;
        const grandTotal   = subtotal + taxAmount;

        if (subtotalDisplayNode) subtotalDisplayNode.textContent = subtotal.toFixed(2);
        if (taxRateDisplayNode)  taxRateDisplayNode.textContent  = taxRate.toFixed(2);
        if (taxAmountNode)       taxAmountNode.textContent       = taxAmount.toFixed(2);
        if (taxRowNode)          taxRowNode.style.display        = (taxRate > 0) ? '' : 'none';
        grandTotalNode.textContent = grandTotal.toFixed(2);
      }

      if (taxRateInput) taxRateInput.addEventListener('input', updateGrandTotal);

      // ── Global floating suggestion dropdown (body-appended to escape overflow) ──
      const globalSuggestBox = document.createElement('div');
      globalSuggestBox.id = 'globalSuggestBox';
      globalSuggestBox.style.cssText = 'display:none;position:fixed;z-index:9999;background:#fff;'
        + 'border:1px solid #d1d5db;border-radius:10px;'
        + 'box-shadow:0 12px 24px rgba(2,6,23,.12);max-height:200px;overflow:auto;';
      document.body.appendChild(globalSuggestBox);

      function positionSuggestBox(input) {
        const rect = input.getBoundingClientRect();
        globalSuggestBox.style.top   = (rect.bottom + 4) + 'px';
        globalSuggestBox.style.left  = rect.left + 'px';
        globalSuggestBox.style.width = rect.width + 'px';
      }

      const BLUR_HIDE_DELAY = 200;

      function hideSuggestBox() {
        globalSuggestBox.style.display = 'none';
        globalSuggestBox.innerHTML = '';
      }

      window.addEventListener('scroll', (e) => {
        if (!globalSuggestBox.contains(e.target)) hideSuggestBox();
      }, { capture: true, passive: true });
      window.addEventListener('resize', hideSuggestBox, { passive: true });

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
        let total = 0, taxable = 0;
        laborBody.querySelectorAll('tr.labor-row').forEach((row) => {
          const qty  = parseNum(row.querySelector('.labor-qty')?.value);
          const cost = parseNum(row.querySelector('.labor-cost')?.value);
          const lineTotal = qty * cost;
          const ltCell = row.querySelector('.labor-line-total');
          if (ltCell) ltCell.textContent = '$' + lineTotal.toFixed(2);
          const priceHidden = row.querySelector('.labor-price');
          if (priceHidden) priceHidden.value = cost.toFixed(2);
          total += lineTotal;
          const taxHidden = row.querySelector('.taxable-hidden');
          if (taxHidden && taxHidden.value === '1') taxable += lineTotal;
        });
        laborSubtotalNode.textContent = total.toFixed(2);
        _laborTaxable = taxable;
        updateGrandTotal();
      }

      function setupLaborSearch(row) {
        const descInput = row.querySelector('.labor-desc');
        const costInput = row.querySelector('.labor-cost');
        const qtyInput  = row.querySelector('.labor-qty');
        let timer = null;

        descInput.addEventListener('input', () => {
          const q = descInput.value.trim();
          if (timer) clearTimeout(timer);
          if (q.length < 1) { hideSuggestBox(); return; }
          timer = setTimeout(() => {
            fetch('quotes.php?labor_search=1&q=' + encodeURIComponent(q), {
              credentials: 'same-origin',
              headers: { 'X-CSRF-Token': csrfToken }
            }).then((r) => r.ok ? r.json() : []).then((items) => {
              globalSuggestBox.innerHTML = '';
              if (!items.length) { hideSuggestBox(); return; }
              items.forEach((item) => {
                const rate = item.hourly_rate ? '$' + parseFloat(item.hourly_rate).toFixed(2) : '';
                const sub  = item.pricing_type + (rate ? ' • ' + rate : '');
                const btn  = buildSuggestBtn(item.service_name, sub);
                btn.addEventListener('click', () => {
                  descInput.value = item.service_name;
                  if (item.hourly_rate != null) costInput.value = parseFloat(item.hourly_rate).toFixed(2);
                  if (item.typical_hours && parseFloat(item.typical_hours) > 0) qtyInput.value = parseFloat(item.typical_hours).toFixed(2);
                  hideSuggestBox();
                  computeLaborTotals();
                });
                globalSuggestBox.appendChild(btn);
              });
              positionSuggestBox(descInput);
              globalSuggestBox.style.display = 'block';
            }).catch(() => { hideSuggestBox(); });
          }, 200);
        });

        descInput.addEventListener('blur', () => {
          setTimeout(hideSuggestBox, BLUR_HIDE_DELAY);
        });
      }

      function bindLaborRow(row) {
        setupLaborSearch(row);
        row.querySelector('.labor-qty')?.addEventListener('input', computeLaborTotals);
        row.querySelector('.labor-cost')?.addEventListener('input', computeLaborTotals);
        const taxCheck  = row.querySelector('.taxable-check');
        const taxHidden = row.querySelector('.taxable-hidden');
        if (taxCheck && taxHidden) {
          taxCheck.addEventListener('change', () => {
            taxHidden.value = taxCheck.checked ? '1' : '0';
            computeLaborTotals();
          });
        }
        const removeBtn = row.querySelector('.remove-labor-row');
        if (!removeBtn) return;
        removeBtn.addEventListener('click', () => {
          if (laborBody.querySelectorAll('tr.labor-row').length <= 1) {
            row.querySelector('.labor-desc').value = '';
            row.querySelector('.labor-qty').value  = '1';
            row.querySelector('.labor-cost').value = '0.00';
            if (taxCheck)  taxCheck.checked  = false;
            if (taxHidden) taxHidden.value   = '0';
          } else {
            row.remove();
          }
          computeLaborTotals();
        });
      }

      addLaborBtn.addEventListener('click', () => {
        const tr = document.createElement('tr');
        tr.className = 'labor-row';
        tr.innerHTML = '<td>'
          + '<input type="text" class="item-desc labor-desc" name="item_desc[]" maxlength="500" autocomplete="off" placeholder="Search labor / service…" />'
          + '<input type="hidden" name="item_markup[]" value="0" />'
          + '<input type="hidden" name="item_price[]" class="labor-price" value="0.00" />'
          + '</td>'
          + '<td><input type="number" step="0.01" min="0.01" class="labor-qty" name="item_qty[]" value="1" /></td>'
          + '<td><input type="number" step="0.01" min="0" class="labor-cost" name="item_cost[]" value="0.00" /></td>'
          + '<td class="labor-line-total" style="white-space:nowrap;">$0.00</td>'
          + '<td style="text-align:center;"><input type="hidden" class="taxable-hidden" name="item_taxable[]" value="0" />'
          + '<input type="checkbox" class="taxable-check" style="width:18px;height:18px;cursor:pointer;" /></td>'
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
        let total = 0, taxable = 0;
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
          const taxHidden = row.querySelector('.taxable-hidden');
          if (taxHidden && taxHidden.value === '1') taxable += lineTotal;
        });
        partsSubtotalNode.textContent = total.toFixed(2);
        _invTaxable = taxable;
        updateGrandTotal();
      }

      function setupInvSearch(row) {
        const descInput   = row.querySelector('.inv-desc');
        const costInput   = row.querySelector('.inv-cost');
        const markupInput = row.querySelector('.inv-markup');
        let timer = null;

        descInput.addEventListener('input', () => {
          const q = descInput.value.trim();
          if (timer) clearTimeout(timer);
          if (q.length < 1) { hideSuggestBox(); return; }
          timer = setTimeout(() => {
            fetch('quotes.php?inventory_search=1&q=' + encodeURIComponent(q), {
              credentials: 'same-origin',
              headers: { 'X-CSRF-Token': csrfToken }
            }).then((r) => r.ok ? r.json() : []).then((items) => {
              globalSuggestBox.innerHTML = '';
              if (!items.length) { hideSuggestBox(); return; }
              items.forEach((item) => {
                const costVal   = item.cost_price   != null ? '$' + parseFloat(item.cost_price).toFixed(2)   : '';
                const markupVal = item.markup_percent != null ? parseFloat(item.markup_percent).toFixed(0) + '%' : '20%';
                const sub = (costVal ? 'Cost: ' + costVal + ' • ' : '') + 'Markup: ' + markupVal;
                const btn = buildSuggestBtn(item.item_name, sub);
                btn.addEventListener('click', () => {
                  descInput.value   = item.item_name;
                  costInput.value   = item.cost_price   != null ? parseFloat(item.cost_price).toFixed(2)   : '0.00';
                  markupInput.value = item.markup_percent != null ? parseFloat(item.markup_percent).toFixed(2) : '20.00';
                  hideSuggestBox();
                  computeInvTotals();
                });
                globalSuggestBox.appendChild(btn);
              });
              positionSuggestBox(descInput);
              globalSuggestBox.style.display = 'block';
            }).catch(() => { hideSuggestBox(); });
          }, 200);
        });

        descInput.addEventListener('blur', () => {
          setTimeout(hideSuggestBox, BLUR_HIDE_DELAY);
        });
      }

      function bindInvRow(row) {
        setupInvSearch(row);
        row.querySelector('.inv-qty')?.addEventListener('input', computeInvTotals);
        row.querySelector('.inv-cost')?.addEventListener('input', computeInvTotals);
        row.querySelector('.inv-markup')?.addEventListener('input', computeInvTotals);
        const taxCheck  = row.querySelector('.taxable-check');
        const taxHidden = row.querySelector('.taxable-hidden');
        if (taxCheck && taxHidden) {
          taxCheck.addEventListener('change', () => {
            taxHidden.value = taxCheck.checked ? '1' : '0';
            computeInvTotals();
          });
        }
        const removeBtn = row.querySelector('.remove-inv-row');
        if (!removeBtn) return;
        removeBtn.addEventListener('click', () => {
          if (invBody.querySelectorAll('tr.inv-row').length <= 1) {
            row.querySelector('.inv-desc').value   = '';
            row.querySelector('.inv-qty').value    = '1';
            row.querySelector('.inv-cost').value   = '0.00';
            row.querySelector('.inv-markup').value = '20.00';
            row.querySelector('.inv-price').value  = '0.00';
            if (taxCheck)  taxCheck.checked  = false;
            if (taxHidden) taxHidden.value   = '0';
          } else {
            row.remove();
          }
          computeInvTotals();
        });
      }

      addInvBtn.addEventListener('click', () => {
        const tr = document.createElement('tr');
        tr.className = 'inv-row';
        tr.innerHTML = '<td>'
          + '<input type="text" class="item-desc inv-desc" name="item_desc[]" maxlength="500" autocomplete="off" placeholder="Search inventory / part…" />'
          + '</td>'
          + '<td><input type="number" step="0.01" min="0.01" class="inv-qty" name="item_qty[]" value="1" /></td>'
          + '<td><input type="number" step="0.01" min="0" class="inv-cost" name="item_cost[]" value="0.00" /></td>'
          + '<td><input type="number" step="0.01" min="0" class="inv-markup" name="item_markup[]" value="20.00" /></td>'
          + '<td><input type="number" step="0.01" min="0" class="inv-price" name="item_price[]" value="0.00" readonly style="background:var(--surface,#f8fafc);color:var(--muted,#64748b);" /></td>'
          + '<td class="inv-line-total" style="white-space:nowrap;">$0.00</td>'
          + '<td style="text-align:center;"><input type="hidden" class="taxable-hidden" name="item_taxable[]" value="0" />'
          + '<input type="checkbox" class="taxable-check" style="width:18px;height:18px;cursor:pointer;" /></td>'
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