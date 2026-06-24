<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';

require_admin_or_moderator();

const QQ_MAX_LINE_ITEMS = 100;
const QQ_MAX_NOTES_LENGTH = 10000;

if (empty($_SESSION['quick_quote_csrf'])) {
  $_SESSION['quick_quote_csrf'] = bin2hex(random_bytes(24));
}

function qq_money($value): string {
  return number_format((float)$value, 2);
}

function qq_escape_like(string $value, string $escape = '\\'): string {
  return strtr($value, [$escape => $escape . $escape, '%' => $escape . '%', '_' => $escape . '_']);
}

function qq_today_ymd(): string {
  $tz_name = defined('APP_TZ') ? (string)APP_TZ : 'UTC';
  try {
    $tz = new DateTimeZone($tz_name);
  } catch (Throwable $e) {
    $tz = new DateTimeZone('UTC');
  }
  return (new DateTime('now', $tz))->format('Y-m-d');
}

function qq_format_invoice_number(int $quote_id): string {
  return 'INV-' . str_pad((string)$quote_id, 6, '0', STR_PAD_LEFT);
}

function qq_quote_exists(PDO $pdo, int $quote_id): bool {
  if ($quote_id <= 0) {
    return false;
  }
  $check = $pdo->prepare("SELECT id FROM quotes WHERE id = ? LIMIT 1");
  $check->execute([$quote_id]);
  return (bool)$check->fetch(PDO::FETCH_ASSOC);
}

function qq_env_value(string $key): string {
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
          $len = strlen($value);
          if ($len >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && $value[$len - 1] === $quote) {
              $value = substr($value, 1, -1);
            }
          }
          $dotenv_values[$name] = $value;
        }
      }
    }
  }

  $value = getenv($key);
  if ($value !== false && $value !== null && trim((string)$value) !== '') {
    return trim((string)$value);
  }
  if (isset($_ENV[$key]) && trim((string)$_ENV[$key]) !== '') {
    return trim((string)$_ENV[$key]);
  }
  if (isset($_SERVER[$key]) && trim((string)$_SERVER[$key]) !== '') {
    return trim((string)$_SERVER[$key]);
  }
  if (isset($dotenv_values[$key]) && trim((string)$dotenv_values[$key]) !== '') {
    return trim((string)$dotenv_values[$key]);
  }

  return '';
}

function qq_sender_profile(PDO $pdo, array $quote): array {
  $profile = [
    'sender_name' => '',
    'company_name' => '',
    'address' => '',
    'phone' => '',
    'email' => '',
  ];

  $created_by = (int)($quote['created_by'] ?? 0);
  if ($created_by <= 0) {
    return $profile;
  }

  $stmt = $pdo->prepare(
    "SELECT username, contact_name, company_name, delivery_address, contact_phone, email
     FROM users
     WHERE id = ?
     LIMIT 1"
  );
  $stmt->execute([$created_by]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return $profile;
  }

  $username = trim((string)($row['username'] ?? ''));
  $contact_name = trim((string)($row['contact_name'] ?? ''));

  $profile['sender_name'] = $contact_name !== '' ? $contact_name : $username;
  $profile['company_name'] = trim((string)($row['company_name'] ?? ''));
  $profile['address'] = trim((string)($row['delivery_address'] ?? ''));
  $profile['phone'] = trim((string)($row['contact_phone'] ?? ''));
  $profile['email'] = trim((string)($row['email'] ?? ''));

  return $profile;
}

function qq_ensure_quote_item_columns(PDO $pdo): void {
  $stmt = $pdo->query("SHOW COLUMNS FROM quote_items LIKE 'item_group'");
  if ($stmt === false || $stmt->fetch(PDO::FETCH_ASSOC) === false) {
    try {
      $pdo->exec("ALTER TABLE quote_items ADD COLUMN item_group ENUM('labor','inventory') NOT NULL DEFAULT 'inventory' AFTER line_position");
    } catch (Throwable $e) {
      $recheck = $pdo->query("SHOW COLUMNS FROM quote_items LIKE 'item_group'");
      if ($recheck === false || $recheck->fetch(PDO::FETCH_ASSOC) === false) {
        throw $e;
      }
    }
  }
  $stmt2 = $pdo->query("SHOW COLUMNS FROM quote_items LIKE 'inventory_item_id'");
  if ($stmt2 === false || $stmt2->fetch(PDO::FETCH_ASSOC) === false) {
    try {
      $pdo->exec("ALTER TABLE quote_items ADD COLUMN inventory_item_id INT UNSIGNED NULL DEFAULT NULL AFTER is_taxable");
    } catch (Throwable $e) {
      $recheck = $pdo->query("SHOW COLUMNS FROM quote_items LIKE 'inventory_item_id'");
      if ($recheck === false || $recheck->fetch(PDO::FETCH_ASSOC) === false) {
        throw $e;
      }
    }
  }
  $stmt3 = $pdo->query("SHOW COLUMNS FROM quote_items LIKE 'image_filename'");
  if ($stmt3 === false || $stmt3->fetch(PDO::FETCH_ASSOC) === false) {
    try {
      $pdo->exec("ALTER TABLE quote_items ADD COLUMN image_filename VARCHAR(255) NULL DEFAULT NULL AFTER inventory_item_id");
    } catch (Throwable $e) {
      $recheck = $pdo->query("SHOW COLUMNS FROM quote_items LIKE 'image_filename'");
      if ($recheck === false || $recheck->fetch(PDO::FETCH_ASSOC) === false) {
        throw $e;
      }
    }
  }
}

function qq_document_html(array $quote, array $items, array $sender, string $base_url = ''): string {
  $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

  $sender_name = trim((string)($sender['sender_name'] ?? ''));
  $sender_company = trim((string)($sender['company_name'] ?? ''));
  if ($sender_company === '') {
    $sender_company = trim((string)qq_env_value('SMTP_FROM_NAME'));
  }
  if ($sender_company === '') {
    $sender_company = 'Our Company';
  }
  $sender_address = trim((string)($sender['address'] ?? ''));
  $sender_phone = trim((string)($sender['phone'] ?? ''));
  $sender_email = trim((string)($sender['email'] ?? ''));

  $quote_id = (int)($quote['id'] ?? 0);
  $quote_date = trim((string)($quote['quote_date'] ?? ''));
  if ($quote_date === '') {
    $quote_date = substr((string)($quote['created_at'] ?? ''), 0, 10);
  }

  $bill_to_lines = [];
  if (trim((string)($quote['company_name'] ?? '')) !== '') {
    $bill_to_lines[] = '<strong>' . $h(trim((string)$quote['company_name'])) . '</strong>';
  }
  if (trim((string)($quote['customer_name'] ?? '')) !== '') {
    $bill_to_lines[] = $h(trim((string)$quote['customer_name']));
  }

  $street = trim((string)($quote['billing_street'] ?? ''));
  $city = trim((string)($quote['billing_city'] ?? ''));
  $state = trim((string)($quote['billing_state'] ?? ''));
  $zip = trim((string)($quote['billing_zip'] ?? ''));
  if ($street !== '') {
    $bill_to_lines[] = $h($street);
  }
  $city_line = trim(implode(', ', array_filter([$city, $state])) . ($zip !== '' ? (' ' . $zip) : ''));
  if ($city_line !== '') {
    $bill_to_lines[] = $h($city_line);
  }
  if (trim((string)($quote['phone_number'] ?? '')) !== '') {
    $bill_to_lines[] = $h(trim((string)$quote['phone_number']));
  }
  if (trim((string)($quote['email'] ?? '')) !== '') {
    $email = trim((string)$quote['email']);
    $bill_to_lines[] = '<a href="mailto:' . $h($email) . '">' . $h($email) . '</a>';
  }

  $from_lines = ['<strong>' . $h($sender_company) . '</strong>'];
  if ($sender_name !== '' && $sender_name !== $sender_company) {
    $from_lines[] = $h($sender_name);
  }
  foreach (array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $sender_address))) as $line) {
    $from_lines[] = $h($line);
  }
  if ($sender_phone !== '') {
    $from_lines[] = $h($sender_phone);
  }
  if ($sender_email !== '') {
    $from_lines[] = '<a href="mailto:' . $h($sender_email) . '">' . $h($sender_email) . '</a>';
  }

  $grouped = ['labor' => [], 'inventory' => []];
  foreach ($items as $item) {
    $group = (string)($item['item_group'] ?? 'inventory');
    if (!isset($grouped[$group])) {
      $group = 'inventory';
    }
    $grouped[$group][] = $item;
  }

  $build_rows = static function (array $rows, bool $with_image = false) use ($h, $base_url): string {
    if (!$rows) {
      return '<tr><td colspan="5" style="padding:12px;text-align:center;color:#64748b;">No line items.</td></tr>';
    }
    $out = '';
    foreach ($rows as $row) {
      $thumb_html = '';
      if ($with_image && trim((string)($row['image_filename'] ?? '')) !== '') {
        $safe_name = preg_replace('/[^A-Za-z0-9_\-.]/', '', basename((string)$row['image_filename']));
        if ($safe_name !== '') {
          $img_src = ($base_url !== '' ? $base_url . '/' : '') . 'uploads/inventory/' . rawurlencode($safe_name);
          $thumb_html = '<img src="' . $h($img_src) . '" width="48" height="48" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:8px;display:inline-block;">';
        }
      }
      $out .= '<tr>'
        . '<td style="padding:10px;border-top:1px solid #e2e8f0;">' . $thumb_html . $h(trim((string)($row['description'] ?? ''))) . '</td>'
        . '<td style="padding:10px;border-top:1px solid #e2e8f0;text-align:right;">' . $h(qq_money((float)($row['quantity'] ?? 0))) . '</td>'
        . '<td style="padding:10px;border-top:1px solid #e2e8f0;text-align:right;">$' . $h(qq_money((float)($row['unit_price'] ?? 0))) . '</td>'
        . '<td style="padding:10px;border-top:1px solid #e2e8f0;text-align:center;">' . (((int)($row['is_taxable'] ?? 0) === 1) ? 'Yes' : 'No') . '</td>'
        . '<td style="padding:10px;border-top:1px solid #e2e8f0;text-align:right;">$' . $h(qq_money((float)($row['line_total'] ?? 0))) . '</td>'
        . '</tr>';
    }
    return $out;
  };

  $subtotal = (float)($quote['subtotal_amount'] ?? 0);
  $tax_rate = (float)($quote['tax_rate'] ?? 0);
  $tax_amount = (float)($quote['tax_amount'] ?? 0);
  $grand = $subtotal + $tax_amount;

  $footer_parts = [];
  if ($sender_company !== '') $footer_parts[] = $h($sender_company);
  if ($sender_phone !== '') $footer_parts[] = $h($sender_phone);
  if ($sender_email !== '') $footer_parts[] = '<a href="mailto:' . $h($sender_email) . '">' . $h($sender_email) . '</a>';

  return '<style>'
    . '.qq-doc{max-width:1040px;margin:0 auto;background:#fff;border:1px solid #dbe2ea;border-radius:16px;overflow:hidden;box-shadow:0 12px 30px rgba(15,23,42,.08);}'
    . '.qq-notice{background:#ecfeff;color:#0f766e;padding:10px 16px;border-bottom:1px solid #99f6e4;font-weight:700;text-align:center;font-size:14px;}'
    . '.qq-head{background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff;padding:24px 28px;display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;}'
    . '.qq-head h2{margin:0;font-size:26px;letter-spacing:.3px;}.qq-head p{margin:4px 0 0;opacity:.9;}'
    . '.qq-meta{background:#f8fafc;border:1px solid #cbd5e1;border-radius:12px;padding:12px 14px;min-width:230px;}'
    . '.qq-body{padding:22px 24px 8px;}.qq-cols{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;}'
    . '.qq-card{border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;background:#fff;}'
    . '.qq-card h4{margin:0 0 8px;font-size:13px;color:#475569;text-transform:uppercase;letter-spacing:.4px;}'
    . '.qq-card p{margin:0;line-height:1.55;font-size:14px;color:#0f172a;}'
    . '.qq-table-wrap{margin:14px 0 18px;}.qq-label{margin:0 0 8px;font-size:15px;color:#0f172a;}'
    . '.qq-table{width:100%;border-collapse:collapse;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;}'
    . '.qq-table th{background:#f8fafc;font-size:12px;letter-spacing:.3px;text-transform:uppercase;color:#475569;padding:10px;text-align:left;}'
    . '.qq-totals{margin-left:auto;width:320px;max-width:100%;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:12px 14px;}'
    . '.qq-totals div{display:flex;justify-content:space-between;padding:4px 0;color:#1e293b;}.qq-totals strong{font-size:17px;}'
    . '.qq-notes{margin-top:16px;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;background:#fff;}'
    . '.qq-foot{margin-top:20px;background:#0f172a;color:#cbd5e1;padding:12px 18px;font-size:12px;text-align:center;}'
    . '.qq-doc a{color:#1d4ed8;text-decoration:none;} .qq-foot a{color:#93c5fd;} @media print{.no-print{display:none!important;} .qq-doc{box-shadow:none;border:1px solid #cbd5e1;} body{background:#fff!important;}}'
    . '@media (max-width:780px){.qq-cols{grid-template-columns:1fr;}.qq-head{padding:18px;}.qq-body{padding:16px;}}'
    . '</style>'
    . '<div class="qq-doc">'
    . '<div class="qq-notice">This is exactly what the customer will receive via email.</div>'
    . '<div class="qq-head">'
    . '<div><h2>Quote</h2><p>' . $h($sender_company) . '</p></div>'
    . '<div class="qq-meta">'
    . '<div><strong>Quote #</strong> ' . $h((string)$quote_id) . '</div>'
    . '<div style="margin-top:6px;"><strong>Date</strong> ' . $h($quote_date) . '</div>'
    . '</div></div>'
    . '<div class="qq-body">'
    . '<div class="qq-cols">'
    . '<div class="qq-card"><h4>Customer Information</h4><p>' . implode('<br>', $bill_to_lines ?: ['Not provided']) . '</p></div>'
    . '<div class="qq-card"><h4>Company Information</h4><p>' . implode('<br>', $from_lines ?: ['Not provided']) . '</p></div>'
    . '</div>'
    . '<div class="qq-table-wrap"><h3 class="qq-label">Labor / Services</h3><table class="qq-table"><thead><tr><th>Description</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Unit</th><th style="text-align:center;">Taxable</th><th style="text-align:right;">Line Total</th></tr></thead><tbody>' . $build_rows($grouped['labor']) . '</tbody></table></div>'
    . '<div class="qq-table-wrap"><h3 class="qq-label">Inventory / Parts</h3><table class="qq-table"><thead><tr><th>Description</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Unit</th><th style="text-align:center;">Taxable</th><th style="text-align:right;">Line Total</th></tr></thead><tbody>' . $build_rows($grouped['inventory'], true) . '</tbody></table></div>'
    . '<div class="qq-totals">'
    . '<div><span>Subtotal</span><span>$' . $h(qq_money($subtotal)) . '</span></div>'
    . '<div><span>Tax (' . $h(qq_money($tax_rate)) . '%)</span><span>$' . $h(qq_money($tax_amount)) . '</span></div>'
    . '<div style="border-top:1px solid #cbd5e1;margin-top:6px;padding-top:8px;"><strong>Grand Total</strong><strong>$' . $h(qq_money($grand)) . '</strong></div>'
    . '</div>'
    . (trim((string)($quote['notes'] ?? '')) !== '' ? '<div class="qq-notes"><h4 style="margin:0 0 6px;font-size:13px;color:#475569;text-transform:uppercase;">Notes</h4><div style="font-size:14px;color:#0f172a;white-space:pre-wrap;">' . $h(trim((string)$quote['notes'])) . '</div></div>' : '')
    . '</div>'
    . '<div class="qq-foot">' . ($footer_parts ? implode(' &nbsp; • &nbsp; ', $footer_parts) : '&copy; ' . date('Y') . ' ' . $h($sender_company)) . '</div>'
    . '</div>';
}

function qq_send_quote_email(PDO $pdo, array $quote, array $items, array $sender, ?string &$error = null): bool {
  $to = trim((string)($quote['email'] ?? ''));
  if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $error = 'Quote customer email is missing or invalid.';
    return false;
  }

  $smtp_host = qq_env_value('SMTP_HOST');
  $smtp_port = (int)qq_env_value('SMTP_PORT');
  $smtp_username = qq_env_value('SMTP_USERNAME');
  $smtp_password = qq_env_value('SMTP_PASSWORD');
  $smtp_from_email = qq_env_value('SMTP_FROM_EMAIL');
  $smtp_from_name = trim(str_replace(["\r", "\n"], ' ', qq_env_value('SMTP_FROM_NAME')));

  if ($smtp_host === '' || $smtp_port <= 0 || $smtp_username === '' || $smtp_password === '' || $smtp_from_email === '' || !filter_var($smtp_from_email, FILTER_VALIDATE_EMAIL)) {
    $error = 'SMTP is not configured.';
    return false;
  }

  $sender_company = trim((string)($sender['company_name'] ?? ''));
  if ($sender_company === '') {
    $sender_company = $smtp_from_name !== '' ? $smtp_from_name : 'Our Company';
  }

  $subject = $sender_company . ' - Quote #' . (int)$quote['id'];

  $email_base_url = rtrim(qq_env_value('APP_URL'), '/');
  if ($email_base_url === '') {
    $fwd_proto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if (!in_array($fwd_proto, ['http', 'https'], true)) {
      $fwd_proto = '';
    }
    $https_on = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $fwd_proto !== '' ? $fwd_proto : ($https_on ? 'https' : 'http');
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($host !== '') {
      $script_dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
      if ($script_dir === '.' || $script_dir === '/') {
        $script_dir = '';
      }
      $email_base_url = $scheme . '://' . $host . rtrim($script_dir, '/');
    }
  }

  $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#f1f5f9;">' . qq_document_html($quote, $items, $sender, $email_base_url) . '</body></html>';
  $text = "Quote #" . (int)$quote['id'] . "\nTotal: $" . qq_money((float)($quote['subtotal_amount'] ?? 0) + (float)($quote['tax_amount'] ?? 0));

  try {
    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->isSMTP();
    $mailer->Host = $smtp_host;
    $mailer->Port = $smtp_port;
    $mailer->SMTPAuth = true;
    $mailer->Username = $smtp_username;
    $mailer->Password = $smtp_password;
    if ($smtp_port === 465) {
      $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($smtp_port === 587) {
      $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mailer->setFrom($smtp_from_email, $smtp_from_name !== '' ? $smtp_from_name : $sender_company);
    $mailer->addAddress($to, trim((string)($quote['customer_name'] ?? '')) ?: null);
    $mailer->isHTML(true);
    $mailer->Subject = $subject;
    $mailer->Body = $html;
    $mailer->AltBody = $text;

    if (!$mailer->send()) {
      $error = trim((string)$mailer->ErrorInfo);
      return false;
    }

    return true;
  } catch (Throwable $e) {
    $error = $e->getMessage();
    return false;
  }
}

qq_ensure_quote_item_columns($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['customer_search'])) {
  header('Content-Type: application/json; charset=utf-8');
  $csrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  if ($csrf === '' || !hash_equals((string)$_SESSION['quick_quote_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode([]);
    exit;
  }

  $query = trim((string)($_GET['q'] ?? ''));
  if ($query === '') {
    echo json_encode([]);
    exit;
  }

  $like = '%' . qq_escape_like($query) . '%';
  $stmt = $pdo->prepare(
    "SELECT id,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', NULLIF(first_name, ''), NULLIF(last_name, ''))), ''), NULLIF(company, ''), NULLIF(email, ''), '') AS customer_name,
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
  if ($csrf === '' || !hash_equals((string)$_SESSION['quick_quote_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode([]);
    exit;
  }

  $query = trim((string)($_GET['q'] ?? ''));
  if ($query === '') {
    echo json_encode([]);
    exit;
  }

  $like = '%' . qq_escape_like($query) . '%';
  try {
    $stmt = $pdo->prepare(
      "SELECT id, service_name, pricing_type, hourly_rate, typical_hours
       FROM labor_items
       WHERE service_name LIKE ? ESCAPE '\\\\' OR description LIKE ? ESCAPE '\\\\'
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
  if ($csrf === '' || !hash_equals((string)$_SESSION['quick_quote_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode([]);
    exit;
  }

  $query = trim((string)($_GET['q'] ?? ''));
  if ($query === '') {
    echo json_encode([]);
    exit;
  }

  $like = '%' . qq_escape_like($query) . '%';
  try {
    $stmt = $pdo->prepare(
      "SELECT id, item_name, cost_price, markup_percent, image_stored_name
       FROM inventory_items
       WHERE item_name LIKE ? ESCAPE '\\\\' OR part_number LIKE ? ESCAPE '\\\\'
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
$today = qq_today_ymd();
$form = [
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
  'tax_rate' => '0.00',
  'notes' => '',
];

$view_id = (($_GET['view'] ?? '') === 'id') ? (int)($_GET['id'] ?? 0) : 0;
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';
$emailed = isset($_GET['emailed']) && $_GET['emailed'] === '1';
$approval_sent = isset($_GET['approval_sent']) && $_GET['approval_sent'] === '1';
$approval_approved = isset($_GET['approval_approved']) && $_GET['approval_approved'] === '1';

if ($saved) $messages[] = 'Quote saved successfully.';
if ($emailed) $messages[] = 'Quote emailed successfully.';
if ($approval_sent) $messages[] = 'Quote sent for approval.';
if ($approval_approved) $messages[] = 'Quote approved.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = trim((string)($_POST['csrf_token'] ?? ''));
  if ($csrf === '' || !hash_equals((string)$_SESSION['quick_quote_csrf'], $csrf)) {
    $errors[] = 'Invalid security token. Please refresh and try again.';
  } else {
    $action = trim((string)($_POST['action'] ?? 'save_quote'));

    if ($action === 'send_quote') {
      $send_id = (int)($_POST['quote_id'] ?? 0);
      if ($send_id <= 0) {
        $errors[] = 'Invalid quote selected for email.';
      } else {
        $q = $pdo->prepare("SELECT * FROM quotes WHERE id = ? LIMIT 1");
        $q->execute([$send_id]);
        $quote = $q->fetch(PDO::FETCH_ASSOC);
        if (!$quote) {
          $errors[] = 'Quote not found.';
        } else {
          $i = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC");
          $i->execute([$send_id]);
          $items = $i->fetchAll(PDO::FETCH_ASSOC);
          if (!$items) {
            $errors[] = 'Cannot email a quote with no line items.';
          } else {
            $sender = qq_sender_profile($pdo, $quote);
            $email_error = null;
            if (!qq_send_quote_email($pdo, $quote, $items, $sender, $email_error)) {
              $errors[] = 'Unable to send quote email: ' . ($email_error ?: 'Unknown error.');
            } else {
              $pdo->prepare("UPDATE quotes SET status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END WHERE id = ?")->execute([$send_id]);
              header('Location: quick_quote.php?view=id&id=' . $send_id . '&emailed=1');
              exit;
            }
          }
        }
      }
    } elseif ($action === 'send_for_approval') {
      $row_id = (int)($_POST['quote_id'] ?? 0);
      if ($row_id <= 0) {
        $errors[] = 'Invalid quote selected for approval.';
      } else {
        if (!qq_quote_exists($pdo, $row_id)) {
          $errors[] = 'Quote not found.';
        } else {
          $pdo->prepare("UPDATE quotes SET approval_status = 'pending_approval' WHERE id = ?")->execute([$row_id]);
          header('Location: quick_quote.php?view=id&id=' . $row_id . '&approval_sent=1');
          exit;
        }
      }
    } elseif ($action === 'approve_quote') {
      $row_id = (int)($_POST['quote_id'] ?? 0);
      if (!is_admin()) {
        $errors[] = 'Only admins can approve quotes.';
      } elseif ($row_id <= 0) {
        $errors[] = 'Invalid quote selected for approval.';
      } else {
        if (!qq_quote_exists($pdo, $row_id)) {
          $errors[] = 'Quote not found.';
        } else {
          $pdo->prepare("UPDATE quotes SET approval_status = 'approved' WHERE id = ?")->execute([$row_id]);
          try {
            $pdo->prepare("UPDATE approval_alerts SET is_read = 1 WHERE entity_type = 'quote' AND entity_id = ?")->execute([$row_id]);
          } catch (Throwable $e) {
            error_log('Quick quote approval alert update failed: ' . $e->getMessage());
          }
          header('Location: quick_quote.php?view=id&id=' . $row_id . '&approval_approved=1');
          exit;
        }
      }
    } elseif ($action === 'convert_invoice') {
      $row_id = (int)($_POST['quote_id'] ?? 0);
      if ($row_id <= 0) {
        $errors[] = 'Invalid quote selected for invoice conversion.';
      } else {
        if (!qq_quote_exists($pdo, $row_id)) {
          $errors[] = 'Quote not found.';
        } else {
          $inv_no = qq_format_invoice_number($row_id);
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
            header('Location: invoice_form.php?id=' . $row_id . '&invoice_converted=1');
            exit;
          }
        }
      }
    } else {
      foreach (array_keys($form) as $key) {
        $form[$key] = trim((string)($_POST[$key] ?? ''));
      }

      if ($form['customer_name'] === '') $errors[] = 'Customer name is required.';
      if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
      if ($form['notes'] !== '' && strlen($form['notes']) > QQ_MAX_NOTES_LENGTH) $errors[] = 'Notes are too long.';
      if ($form['customer_id'] !== '' && (int)$form['customer_id'] <= 0) $errors[] = 'Invalid customer selected.';

      $customer_id = ($form['customer_id'] !== '' && (int)$form['customer_id'] > 0) ? (int)$form['customer_id'] : null;

      $build_rows = static function (
        array $desc,
        array $qty,
        array $cost,
        array $markup,
        array $taxable,
        string $group,
        array &$errors,
        array $image_filenames = []
      ): array {
        if (count($desc) !== count($qty) || count($desc) !== count($cost) || count($desc) !== count($markup) || count($desc) !== count($taxable)) {
          $errors[] = ucfirst($group) . ' line item data is malformed. Please reload and try again.';
          return [];
        }
        $rows = [];
        $limit = min(count($desc), count($qty), count($cost), count($markup), count($taxable), QQ_MAX_LINE_ITEMS);
        for ($i = 0; $i < $limit; $i++) {
          $d = trim((string)($desc[$i] ?? ''));
          $q = trim((string)($qty[$i] ?? ''));
          $c = trim((string)($cost[$i] ?? ''));
          $m = trim((string)($markup[$i] ?? ''));

          if ($d === '' && $q === '' && $c === '' && $m === '') {
            continue;
          }

          if ($d === '') {
            $errors[] = ucfirst($group) . ' line item description is required.';
            continue;
          }
          if (!is_numeric($q) || (float)$q <= 0) {
            $errors[] = ucfirst($group) . ' line item quantity must be greater than 0.';
            continue;
          }
          if (!is_numeric($c) || (float)$c < 0) {
            $errors[] = ucfirst($group) . ' line item cost must be 0 or greater.';
            continue;
          }
          if (!is_numeric($m) || (float)$m < 0) {
            $errors[] = ucfirst($group) . ' line item markup must be 0 or greater.';
            continue;
          }

          $qty_val = round((float)$q, 2);
          $cost_val = round((float)$c, 2);
          $markup_val = round((float)$m, 2);
          $unit = ($group === 'labor') ? $cost_val : round($cost_val * (1 + $markup_val / 100), 2);
          $line = round($qty_val * $unit, 2);

          $img = trim((string)($image_filenames[$i] ?? ''));
          $rows[] = [
            'item_group' => $group,
            'description' => $d,
            'quantity' => $qty_val,
            'cost' => $cost_val,
            'markup_percent' => $markup_val,
            'unit_price' => $unit,
            'line_total' => $line,
            'is_taxable' => ((int)($taxable[$i] ?? 0) === 1) ? 1 : 0,
            'image_filename' => $img !== '' ? $img : null,
          ];
        }
        return $rows;
      };

      $labor_rows = $build_rows(
        is_array($_POST['labor_desc'] ?? null) ? $_POST['labor_desc'] : [],
        is_array($_POST['labor_qty'] ?? null) ? $_POST['labor_qty'] : [],
        is_array($_POST['labor_cost'] ?? null) ? $_POST['labor_cost'] : [],
        is_array($_POST['labor_markup'] ?? null) ? $_POST['labor_markup'] : [],
        is_array($_POST['labor_taxable'] ?? null) ? $_POST['labor_taxable'] : [],
        'labor',
        $errors
      );

      $inventory_rows = $build_rows(
        is_array($_POST['inv_desc'] ?? null) ? $_POST['inv_desc'] : [],
        is_array($_POST['inv_qty'] ?? null) ? $_POST['inv_qty'] : [],
        is_array($_POST['inv_cost'] ?? null) ? $_POST['inv_cost'] : [],
        is_array($_POST['inv_markup'] ?? null) ? $_POST['inv_markup'] : [],
        is_array($_POST['inv_taxable'] ?? null) ? $_POST['inv_taxable'] : [],
        'inventory',
        $errors,
        is_array($_POST['inv_image_filename'] ?? null) ? $_POST['inv_image_filename'] : []
      );

      $line_items = array_merge($labor_rows, $inventory_rows);
      if (!$line_items) {
        $errors[] = 'Add at least one labor or inventory line item.';
      }

      if (!$errors) {
        $tax_rate = max(0.0, min(100.0, round((float)($form['tax_rate'] !== '' ? $form['tax_rate'] : '0'), 2)));
        $form['tax_rate'] = number_format($tax_rate, 2, '.', '');

        $subtotal = 0.0;
        $taxable_total = 0.0;
        foreach ($line_items as $row) {
          $subtotal += (float)$row['line_total'];
          if ((int)$row['is_taxable'] === 1) {
            $taxable_total += (float)$row['line_total'];
          }
        }
        $subtotal = round($subtotal, 2);
        $tax_amount = round($taxable_total * $tax_rate / 100, 2);

        $pdo->beginTransaction();
        try {
          $created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
          if ($created_by !== null && $created_by <= 0) $created_by = null;

          $ins = $pdo->prepare(
            "INSERT INTO quotes
               (customer_id, customer_name, company_name, phone_number, email, billing_street, billing_city, billing_state, billing_zip, quote_date, notes, subtotal_amount, tax_rate, tax_amount, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
          );
          $ins->execute([
            $customer_id,
            $form['customer_name'],
            $form['company_name'] !== '' ? $form['company_name'] : null,
            $form['phone_number'] !== '' ? $form['phone_number'] : null,
            $form['email'] !== '' ? $form['email'] : null,
            $form['billing_street'] !== '' ? $form['billing_street'] : null,
            $form['billing_city'] !== '' ? $form['billing_city'] : null,
            $form['billing_state'] !== '' ? $form['billing_state'] : null,
            $form['billing_zip'] !== '' ? $form['billing_zip'] : null,
            $form['quote_date'] !== '' ? $form['quote_date'] : $today,
            $form['notes'] !== '' ? $form['notes'] : null,
            $subtotal,
            $tax_rate,
            $tax_amount,
            $created_by,
          ]);

          $quote_id = (int)$pdo->lastInsertId();
          $item_ins = $pdo->prepare(
            "INSERT INTO quote_items
               (quote_id, line_position, item_group, description, quantity, cost, markup_percent, unit_price, line_total, is_taxable, image_filename)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
          );

          $position = 1;
          foreach ($line_items as $row) {
            $item_ins->execute([
              $quote_id,
              $position,
              $row['item_group'],
              $row['description'],
              $row['quantity'],
              $row['cost'],
              $row['markup_percent'],
              $row['unit_price'],
              $row['line_total'],
              $row['is_taxable'],
              $row['image_filename'] ?? null,
            ]);
            $position++;
          }

          $pdo->commit();
          $_SESSION['quick_quote_csrf'] = bin2hex(random_bytes(24));
          header('Location: quick_quote.php?view=id&id=' . $quote_id . '&saved=1');
          exit;
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }
          $errors[] = 'Unable to save quote: ' . $e->getMessage();
        }
      }
    }
  }
}

$detail_quote = null;
$detail_items = [];
$detail_sender = [];

if ($view_id > 0) {
  $stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = ? LIMIT 1");
  $stmt->execute([$view_id]);
  $detail_quote = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($detail_quote) {
    $item_stmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC");
    $item_stmt->execute([$view_id]);
    $detail_items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
    $detail_sender = qq_sender_profile($pdo, $detail_quote);
  } else {
    $errors[] = 'Quote not found.';
  }
}

render_header('Quick Quote');
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1>Quick Quote</h1>
    <p class="muted">Fast quoting with separated labor/services and inventory/parts.</p>
  </div>
  <div class="actions">
    <?php if ($view_id > 0): ?>
      <a class="btn" href="quick_quote.php">New Quick Quote</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0;padding-left:18px;">
      <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php foreach ($messages as $message): ?>
  <div class="alert" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534;"><?= h($message) ?></div>
<?php endforeach; ?>

<?php if ($detail_quote): ?>
  <div class="card no-print" style="margin-bottom:14px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
    <div class="muted">Quote #<?= (int)$detail_quote['id'] ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <a class="btn" href="quotes.php?view=all">Back to Quotes</a>
      <a class="btn" href="quotes.php?edit=<?= (int)$detail_quote['id'] ?>">Edit Quote</a>
      <button type="button" class="btn" onclick="window.print()">Print Quote</button>
      <form method="post" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quick_quote_csrf']) ?>">
        <input type="hidden" name="action" value="send_quote">
        <input type="hidden" name="quote_id" value="<?= (int)$detail_quote['id'] ?>">
        <button type="submit" class="btn primary">Email Quote</button>
      </form>
      <form method="post" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quick_quote_csrf']) ?>">
        <input type="hidden" name="action" value="send_for_approval">
        <input type="hidden" name="quote_id" value="<?= (int)$detail_quote['id'] ?>">
        <button type="submit" class="btn">Send for Approval</button>
      </form>
      <?php if (is_admin()): ?>
        <form method="post" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quick_quote_csrf']) ?>">
          <input type="hidden" name="action" value="approve_quote">
          <input type="hidden" name="quote_id" value="<?= (int)$detail_quote['id'] ?>">
          <button type="submit" class="btn primary">Approve</button>
        </form>
      <?php endif; ?>
      <form method="post" style="margin:0;" onsubmit="return confirm('Convert this quote to invoice?');">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quick_quote_csrf']) ?>">
        <input type="hidden" name="action" value="convert_invoice">
        <input type="hidden" name="quote_id" value="<?= (int)$detail_quote['id'] ?>">
        <button type="submit" class="btn primary">Convert to Invoice</button>
      </form>
    </div>
  </div>
  <?= qq_document_html($detail_quote, $detail_items, $detail_sender) ?>

<?php else: ?>

  <form class="card" method="post" style="padding:18px;">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quick_quote_csrf']) ?>">
    <input type="hidden" name="action" value="save_quote">

    <h2 style="margin:0 0 14px;">Create Quick Quote</h2>

    <div class="form-grid" style="position:relative;">
      <div style="position:relative;">
        <label for="customer_name">Customer Name <span style="color:#dc2626;">*</span></label>
        <input id="customer_name" type="text" name="customer_name" maxlength="255" required autocomplete="off" value="<?= h($form['customer_name']) ?>">
        <input id="customer_id" type="hidden" name="customer_id" value="<?= h($form['customer_id']) ?>">
        <div id="customerSuggestions" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:40;background:#fff;border:1px solid #d1d5db;border-radius:10px;box-shadow:0 12px 24px rgba(2,6,23,.12);margin-top:6px;max-height:220px;overflow:auto;"></div>
      </div>
      <div>
        <label for="company_name">Company</label>
        <input id="company_name" type="text" name="company_name" maxlength="255" value="<?= h($form['company_name']) ?>">
      </div>
      <div>
        <label for="phone_number">Phone</label>
        <input id="phone_number" type="text" name="phone_number" maxlength="100" value="<?= h($form['phone_number']) ?>">
      </div>
      <div>
        <label for="email">Email</label>
        <input id="email" type="email" name="email" maxlength="255" value="<?= h($form['email']) ?>">
      </div>
      <div>
        <label for="quote_date">Quote Date</label>
        <input id="quote_date" type="date" name="quote_date" value="<?= h($form['quote_date']) ?>">
      </div>
    </div>

    <div class="form-grid" style="margin-top:14px;">
      <div>
        <label for="billing_street">Billing Street Address</label>
        <input id="billing_street" type="text" name="billing_street" maxlength="255" value="<?= h($form['billing_street']) ?>">
      </div>
      <div>
        <label for="billing_city">City</label>
        <input id="billing_city" type="text" name="billing_city" maxlength="100" value="<?= h($form['billing_city']) ?>">
      </div>
      <div>
        <label for="billing_state">State</label>
        <input id="billing_state" type="text" name="billing_state" maxlength="100" value="<?= h($form['billing_state']) ?>">
      </div>
      <div>
        <label for="billing_zip">ZIP</label>
        <input id="billing_zip" type="text" name="billing_zip" maxlength="20" value="<?= h($form['billing_zip']) ?>">
      </div>
    </div>

    <div style="margin-top:20px;">
      <h3 style="margin:0 0 10px;">Labor / Services</h3>
      <div style="overflow-x:auto;">
        <table style="min-width:780px;" id="laborItemsTable">
          <thead>
            <tr>
              <th>Description</th>
              <th style="width:100px;">Qty</th>
              <th style="width:130px;">Rate</th>
              <th style="width:150px;">Line Total</th>
              <th style="width:80px;text-align:center;">Taxable</th>
              <th style="width:90px;">Remove</th>
            </tr>
          </thead>
          <tbody id="laborItemsBody">
            <tr class="labor-row">
              <td>
                <input type="text" class="item-desc labor-desc" name="labor_desc[]" maxlength="500" autocomplete="off" placeholder="Search labor / service…">
                <input type="hidden" name="labor_markup[]" value="0.00">
              </td>
              <td><input type="number" step="0.01" min="0.01" class="labor-qty" name="labor_qty[]" value="1"></td>
              <td><input type="number" step="0.01" min="0" class="labor-cost" name="labor_cost[]" value="0.00"></td>
              <td class="labor-line-total">$0.00</td>
              <td style="text-align:center;">
                <input type="hidden" class="taxable-hidden" name="labor_taxable[]" value="0">
                <input type="checkbox" class="taxable-check" style="width:18px;height:18px;cursor:pointer;">
              </td>
              <td><button type="button" class="btn remove-labor-row">×</button></td>
            </tr>
          </tbody>
        </table>
        <div style="margin-top:10px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;">
          <button type="button" class="btn" id="addLaborRow">+ Add Labor Item</button>
          <div><strong>Labor Subtotal: $<span id="laborSubtotal">0.00</span></strong></div>
        </div>
      </div>
    </div>

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
              <th style="width:80px;text-align:center;">Taxable</th>
              <th style="width:90px;">Remove</th>
            </tr>
          </thead>
          <tbody id="inventoryItemsBody">
            <tr class="inv-row">
              <td><input type="text" class="item-desc inv-desc" name="inv_desc[]" maxlength="500" autocomplete="off" placeholder="Search inventory / part…"><input type="hidden" class="inv-image-filename" name="inv_image_filename[]" value=""></td>
              <td><input type="number" step="0.01" min="0.01" class="inv-qty" name="inv_qty[]" value="1"></td>
              <td><input type="number" step="0.01" min="0" class="inv-cost" name="inv_cost[]" value="0.00"></td>
              <td><input type="number" step="0.01" min="0" class="inv-markup" name="inv_markup[]" value="20.00"></td>
              <td><input type="number" step="0.01" min="0" class="inv-price" value="0.00" readonly style="background:#f8fafc;color:#64748b;"></td>
              <td class="inv-line-total">$0.00</td>
              <td style="text-align:center;">
                <input type="hidden" class="taxable-hidden" name="inv_taxable[]" value="0">
                <input type="checkbox" class="taxable-check" style="width:18px;height:18px;cursor:pointer;">
              </td>
              <td><button type="button" class="btn remove-inv-row">×</button></td>
            </tr>
          </tbody>
        </table>
        <div style="margin-top:10px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;">
          <button type="button" class="btn" id="addInventoryRow">+ Add Inventory Item</button>
          <div><strong>Parts Subtotal: $<span id="partsSubtotal">0.00</span></strong></div>
        </div>
      </div>
    </div>

    <div style="margin-top:14px;display:flex;justify-content:flex-end;align-items:flex-start;gap:14px;flex-wrap:wrap;">
      <div style="text-align:right;">
        <label for="tax_rate" style="display:block;margin-bottom:4px;font-weight:600;">Tax Rate (%)</label>
        <input id="tax_rate" type="number" name="tax_rate" step="0.01" min="0" max="100" value="<?= h($form['tax_rate']) ?>" style="width:120px;text-align:right;">
      </div>
      <div style="font-size:1.05em;padding-top:28px;line-height:1.8;">
        <div>Subtotal: $<span id="quoteSubtotalDisplay">0.00</span></div>
        <div>Tax: $<span id="quoteTaxAmount">0.00</span></div>
        <div><strong>Grand Total: $<span id="quoteGrandTotal">0.00</span></strong></div>
      </div>
    </div>

    <div style="margin-top:14px;">
      <label for="notes">Notes</label>
      <textarea id="notes" name="notes" rows="4" maxlength="<?= QQ_MAX_NOTES_LENGTH ?>"><?= h($form['notes']) ?></textarea>
    </div>

    <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
      <button type="submit" class="btn primary" style="font-size:18px;padding:14px 22px;">Save Quote</button>
    </div>
  </form>

  <script>
    (() => {
      const csrfToken = <?= json_encode($_SESSION['quick_quote_csrf']) ?>;
      const customerNameInput = document.getElementById('customer_name');
      const customerIdInput = document.getElementById('customer_id');
      const companyInput = document.getElementById('company_name');
      const phoneInput = document.getElementById('phone_number');
      const emailInput = document.getElementById('email');
      const streetInput = document.getElementById('billing_street');
      const cityInput = document.getElementById('billing_city');
      const stateInput = document.getElementById('billing_state');
      const zipInput = document.getElementById('billing_zip');
      const customerSugg = document.getElementById('customerSuggestions');
      let customerTimer = null;

      function hideCustomerSugg() {
        customerSugg.style.display = 'none';
        customerSugg.innerHTML = '';
      }

      function renderCustomerSugg(rows) {
        customerSugg.innerHTML = '';
        if (!rows.length) {
          hideCustomerSugg();
          return;
        }

        rows.forEach((row) => {
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
          meta.textContent = (row.company_name || '—') + ' • ' + (row.phone || '—') + ' • ' + (row.email || '—');
          btn.appendChild(meta);

          btn.addEventListener('click', () => {
            customerIdInput.value = row.id || '';
            customerNameInput.value = row.customer_name || '';
            companyInput.value = row.company_name || '';
            phoneInput.value = row.phone || '';
            emailInput.value = row.email || '';
            streetInput.value = row.address || '';
            cityInput.value = row.city || '';
            stateInput.value = row.state || '';
            zipInput.value = row.zip || '';
            hideCustomerSugg();
          });

          customerSugg.appendChild(btn);
        });

        customerSugg.style.display = 'block';
      }

      customerNameInput.addEventListener('input', () => {
        customerIdInput.value = '';
        const q = customerNameInput.value.trim();
        if (customerTimer) clearTimeout(customerTimer);
        if (q.length < 1) {
          hideCustomerSugg();
          return;
        }

        customerTimer = setTimeout(() => {
          fetch('quick_quote.php?customer_search=1&q=' + encodeURIComponent(q), {
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrfToken }
          })
            .then((r) => (r.ok ? r.json() : []))
            .then((rows) => renderCustomerSugg(Array.isArray(rows) ? rows : []))
            .catch(() => hideCustomerSugg());
        }, 180);
      });

      customerNameInput.addEventListener('blur', () => setTimeout(hideCustomerSugg, 180));

      const globalSuggestBox = document.createElement('div');
      globalSuggestBox.style.cssText = 'display:none;position:fixed;z-index:1000;background:#fff;border:1px solid #d1d5db;border-radius:10px;box-shadow:0 12px 24px rgba(2,6,23,.12);max-height:260px;overflow:auto;';
      document.body.appendChild(globalSuggestBox);

      function hideSuggestBox() {
        globalSuggestBox.style.display = 'none';
        globalSuggestBox.innerHTML = '';
      }

      function positionSuggestBox(input) {
        const rect = input.getBoundingClientRect();
        globalSuggestBox.style.left = Math.max(8, rect.left) + 'px';
        globalSuggestBox.style.top = (rect.bottom + 6) + 'px';
        globalSuggestBox.style.width = rect.width + 'px';
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

      const parseNum = (v) => {
        const n = parseFloat(v);
        return Number.isFinite(n) ? n : 0;
      };

      const laborBody = document.getElementById('laborItemsBody');
      const invBody = document.getElementById('inventoryItemsBody');
      const laborSubtotalNode = document.getElementById('laborSubtotal');
      const partsSubtotalNode = document.getElementById('partsSubtotal');
      const subtotalNode = document.getElementById('quoteSubtotalDisplay');
      const taxNode = document.getElementById('quoteTaxAmount');
      const grandNode = document.getElementById('quoteGrandTotal');
      const taxRateInput = document.getElementById('tax_rate');

      let laborTaxable = 0;
      let invTaxable = 0;

      function updateGrandTotal() {
        const labor = parseNum(laborSubtotalNode.textContent);
        const parts = parseNum(partsSubtotalNode.textContent);
        const subtotal = labor + parts;
        const taxRate = Math.max(0, parseNum(taxRateInput.value));
        const taxable = laborTaxable + invTaxable;
        const tax = taxable * taxRate / 100;
        subtotalNode.textContent = subtotal.toFixed(2);
        taxNode.textContent = tax.toFixed(2);
        grandNode.textContent = (subtotal + tax).toFixed(2);
      }

      function computeLaborTotals() {
        let total = 0;
        let taxable = 0;
        laborBody.querySelectorAll('tr.labor-row').forEach((row) => {
          const qty = parseNum(row.querySelector('.labor-qty')?.value);
          const cost = parseNum(row.querySelector('.labor-cost')?.value);
          const line = qty * cost;
          row.querySelector('.labor-line-total').textContent = '$' + line.toFixed(2);
          total += line;
          if (row.querySelector('.taxable-hidden')?.value === '1') taxable += line;
        });
        laborSubtotalNode.textContent = total.toFixed(2);
        laborTaxable = taxable;
        updateGrandTotal();
      }

      function computeInvTotals() {
        let total = 0;
        let taxable = 0;
        invBody.querySelectorAll('tr.inv-row').forEach((row) => {
          const qty = parseNum(row.querySelector('.inv-qty')?.value);
          const cost = parseNum(row.querySelector('.inv-cost')?.value);
          const markup = parseNum(row.querySelector('.inv-markup')?.value);
          const price = cost * (1 + markup / 100);
          row.querySelector('.inv-price').value = price.toFixed(2);
          const line = qty * price;
          row.querySelector('.inv-line-total').textContent = '$' + line.toFixed(2);
          total += line;
          if (row.querySelector('.taxable-hidden')?.value === '1') taxable += line;
        });
        partsSubtotalNode.textContent = total.toFixed(2);
        invTaxable = taxable;
        updateGrandTotal();
      }

      function setupTaxToggle(row, recompute) {
        const check = row.querySelector('.taxable-check');
        const hidden = row.querySelector('.taxable-hidden');
        if (!check || !hidden) return;
        check.addEventListener('change', () => {
          hidden.value = check.checked ? '1' : '0';
          recompute();
        });
      }

      function setupLaborSearch(row) {
        const descInput = row.querySelector('.labor-desc');
        const costInput = row.querySelector('.labor-cost');
        const qtyInput = row.querySelector('.labor-qty');
        let timer = null;

        descInput.addEventListener('input', () => {
          const q = descInput.value.trim();
          if (timer) clearTimeout(timer);
          if (q.length < 1) {
            hideSuggestBox();
            return;
          }

          timer = setTimeout(() => {
            fetch('quick_quote.php?labor_search=1&q=' + encodeURIComponent(q), {
              credentials: 'same-origin',
              headers: { 'X-CSRF-Token': csrfToken }
            })
              .then((r) => (r.ok ? r.json() : []))
              .then((items) => {
                globalSuggestBox.innerHTML = '';
                if (!Array.isArray(items) || !items.length) {
                  hideSuggestBox();
                  return;
                }

                items.forEach((item) => {
                  const rate = item.hourly_rate != null ? '$' + parseFloat(item.hourly_rate || 0).toFixed(2) : '';
                  const sub = (item.pricing_type || '') + (rate ? ' • ' + rate : '');
                  const btn = buildSuggestBtn(item.service_name || '', sub);
                  btn.addEventListener('click', () => {
                    descInput.value = item.service_name || '';
                    costInput.value = item.hourly_rate != null ? parseFloat(item.hourly_rate || 0).toFixed(2) : '0.00';
                    if (item.typical_hours && parseFloat(item.typical_hours) > 0) {
                      qtyInput.value = parseFloat(item.typical_hours).toFixed(2);
                    }
                    hideSuggestBox();
                    computeLaborTotals();
                  });
                  globalSuggestBox.appendChild(btn);
                });

                positionSuggestBox(descInput);
                globalSuggestBox.style.display = 'block';
              })
              .catch(() => hideSuggestBox());
          }, 180);
        });

        descInput.addEventListener('blur', () => setTimeout(hideSuggestBox, 180));
      }

      function setupInvSearch(row) {
        const descInput = row.querySelector('.inv-desc');
        const costInput = row.querySelector('.inv-cost');
        const markupInput = row.querySelector('.inv-markup');
        const imgInput = row.querySelector('.inv-image-filename');
        let timer = null;

        descInput.addEventListener('input', () => {
          const q = descInput.value.trim();
          if (timer) clearTimeout(timer);
          if (imgInput) imgInput.value = '';
          if (q.length < 1) {
            hideSuggestBox();
            return;
          }

          timer = setTimeout(() => {
            fetch('quick_quote.php?inventory_search=1&q=' + encodeURIComponent(q), {
              credentials: 'same-origin',
              headers: { 'X-CSRF-Token': csrfToken }
            })
              .then((r) => (r.ok ? r.json() : []))
              .then((items) => {
                globalSuggestBox.innerHTML = '';
                if (!Array.isArray(items) || !items.length) {
                  hideSuggestBox();
                  return;
                }

                items.forEach((item) => {
                  const costText = item.cost_price != null ? '$' + parseFloat(item.cost_price || 0).toFixed(2) : '';
                  const markupText = item.markup_percent != null ? parseFloat(item.markup_percent || 0).toFixed(0) + '%' : '20%';
                  const sub = (costText ? 'Cost: ' + costText + ' • ' : '') + 'Markup: ' + markupText;
                  const btn = buildSuggestBtn(item.item_name || '', sub);
                  btn.addEventListener('click', () => {
                    descInput.value = item.item_name || '';
                    costInput.value = item.cost_price != null ? parseFloat(item.cost_price || 0).toFixed(2) : '0.00';
                    markupInput.value = item.markup_percent != null ? parseFloat(item.markup_percent || 0).toFixed(2) : '20.00';
                    if (imgInput) imgInput.value = item.image_stored_name || '';
                    hideSuggestBox();
                    computeInvTotals();
                  });
                  globalSuggestBox.appendChild(btn);
                });

                positionSuggestBox(descInput);
                globalSuggestBox.style.display = 'block';
              })
              .catch(() => hideSuggestBox());
          }, 180);
        });

        descInput.addEventListener('blur', () => setTimeout(hideSuggestBox, 180));
      }

      function bindLaborRow(row) {
        setupLaborSearch(row);
        row.querySelector('.labor-qty')?.addEventListener('input', computeLaborTotals);
        row.querySelector('.labor-cost')?.addEventListener('input', computeLaborTotals);
        setupTaxToggle(row, computeLaborTotals);
        row.querySelector('.remove-labor-row')?.addEventListener('click', () => {
          if (laborBody.querySelectorAll('tr.labor-row').length <= 1) {
            row.querySelector('.labor-desc').value = '';
            row.querySelector('.labor-qty').value = '1';
            row.querySelector('.labor-cost').value = '0.00';
            row.querySelector('.taxable-hidden').value = '0';
            row.querySelector('.taxable-check').checked = false;
          } else {
            row.remove();
          }
          computeLaborTotals();
        });
      }

      function bindInvRow(row) {
        setupInvSearch(row);
        row.querySelector('.inv-qty')?.addEventListener('input', computeInvTotals);
        row.querySelector('.inv-cost')?.addEventListener('input', computeInvTotals);
        row.querySelector('.inv-markup')?.addEventListener('input', computeInvTotals);
        setupTaxToggle(row, computeInvTotals);
        row.querySelector('.remove-inv-row')?.addEventListener('click', () => {
          if (invBody.querySelectorAll('tr.inv-row').length <= 1) {
            row.querySelector('.inv-desc').value = '';
            row.querySelector('.inv-qty').value = '1';
            row.querySelector('.inv-cost').value = '0.00';
            row.querySelector('.inv-markup').value = '20.00';
            row.querySelector('.inv-price').value = '0.00';
            row.querySelector('.taxable-hidden').value = '0';
            row.querySelector('.taxable-check').checked = false;
            const imgHidden = row.querySelector('.inv-image-filename');
            if (imgHidden) imgHidden.value = '';
          } else {
            row.remove();
          }
          computeInvTotals();
        });
      }

      document.getElementById('addLaborRow').addEventListener('click', () => {
        const tr = document.createElement('tr');
        tr.className = 'labor-row';
        tr.innerHTML = '<td><input type="text" class="item-desc labor-desc" name="labor_desc[]" maxlength="500" autocomplete="off" placeholder="Search labor / service…"><input type="hidden" name="labor_markup[]" value="0.00"></td>'
          + '<td><input type="number" step="0.01" min="0.01" class="labor-qty" name="labor_qty[]" value="1"></td>'
          + '<td><input type="number" step="0.01" min="0" class="labor-cost" name="labor_cost[]" value="0.00"></td>'
          + '<td class="labor-line-total">$0.00</td>'
          + '<td style="text-align:center;"><input type="hidden" class="taxable-hidden" name="labor_taxable[]" value="0"><input type="checkbox" class="taxable-check" style="width:18px;height:18px;cursor:pointer;"></td>'
          + '<td><button type="button" class="btn remove-labor-row">×</button></td>';
        laborBody.appendChild(tr);
        bindLaborRow(tr);
      });

      document.getElementById('addInventoryRow').addEventListener('click', () => {
        const tr = document.createElement('tr');
        tr.className = 'inv-row';
        tr.innerHTML = '<td><input type="text" class="item-desc inv-desc" name="inv_desc[]" maxlength="500" autocomplete="off" placeholder="Search inventory / part…"><input type="hidden" class="inv-image-filename" name="inv_image_filename[]" value=""></td>'
          + '<td><input type="number" step="0.01" min="0.01" class="inv-qty" name="inv_qty[]" value="1"></td>'
          + '<td><input type="number" step="0.01" min="0" class="inv-cost" name="inv_cost[]" value="0.00"></td>'
          + '<td><input type="number" step="0.01" min="0" class="inv-markup" name="inv_markup[]" value="20.00"></td>'
          + '<td><input type="number" step="0.01" min="0" class="inv-price" value="0.00" readonly style="background:#f8fafc;color:#64748b;"></td>'
          + '<td class="inv-line-total">$0.00</td>'
          + '<td style="text-align:center;"><input type="hidden" class="taxable-hidden" name="inv_taxable[]" value="0"><input type="checkbox" class="taxable-check" style="width:18px;height:18px;cursor:pointer;"></td>'
          + '<td><button type="button" class="btn remove-inv-row">×</button></td>';
        invBody.appendChild(tr);
        bindInvRow(tr);
      });

      laborBody.querySelectorAll('tr.labor-row').forEach(bindLaborRow);
      invBody.querySelectorAll('tr.inv-row').forEach(bindInvRow);
      taxRateInput.addEventListener('input', updateGrandTotal);
      document.addEventListener('click', (event) => {
        if (!globalSuggestBox.contains(event.target) && !event.target.closest('.item-desc')) {
          hideSuggestBox();
        }
      });

      computeLaborTotals();
      computeInvTotals();
    })();
  </script>

<?php endif; ?>

<?php render_footer(); ?>
