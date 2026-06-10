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

      $upd = $pdo->prepare(
        "UPDATE quotes
            SET customer_name     = ?,
                company_name      = ?,
                phone_number      = ?,
                email             = ?,
                quote_date        = ?,
                notes             = ?,
                subtotal_amount   = ?,
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
            status, notes, subtotal_amount, converted_invoice_no, converted_at, created_by)
         VALUES (?, ?, ?, ?, ?, 'converted', ?, ?, '', NOW(), ?)"
      );
      $ins_q->execute([
        $post_customer_name,
        $post_company_name !== '' ? $post_company_name : null,
        $post_phone_number  !== '' ? $post_phone_number  : null,
        $post_email         !== '' ? $post_email         : null,
        $post_invoice_date,
        $post_notes         !== '' ? $post_notes         : null,
        round($subtotal, 2),
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

    <div style="margin-top:16px; overflow-x:auto;">
      <table style="min-width:900px;" id="lineItemsTable">
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
        <tbody id="lineItemsBody">
          <?php foreach ($line_items as $row): ?>
            <tr class="line-item-row">
              <td><input type="text" name="item_desc[]" maxlength="500" value="<?= h((string)$row['description']) ?>"<?= invoice_field_lock_attrs($is_view_mode) ?> /></td>
              <td><input type="number" step="0.01" min="0.01" name="item_qty[]" value="<?= h((string)$row['quantity']) ?>"<?= invoice_field_lock_attrs($is_view_mode) ?> /></td>
              <td><input type="number" step="0.01" min="0" name="item_cost[]" value="<?= h((string)$row['cost']) ?>"<?= invoice_field_lock_attrs($is_view_mode) ?> /></td>
              <td><input type="number" step="0.01" min="0" name="item_markup[]" value="<?= h((string)$row['markup_percent']) ?>"<?= invoice_field_lock_attrs($is_view_mode) ?> /></td>
              <td><input type="number" step="0.01" min="0" name="item_price[]" value="<?= h((string)$row['unit_price']) ?>"<?= invoice_readonly_attrs() ?> /></td>
              <td class="line-total" style="white-space:nowrap;">$<?= h((string)$row['line_total']) ?></td>
              <td>
                <?php if (!$is_view_mode): ?>
                  <button type="button" class="btn remove-line">×</button>
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
          <button type="button" class="btn" id="addLineItem">+ Add Line Item</button>
        <?php else: ?>
          <span class="muted">Line items are read only in view mode.</span>
        <?php endif; ?>
        <div><strong>Subtotal: $<span id="invoiceSubtotal">0.00</span></strong></div>
      </div>
    </div>

    <div style="margin-top:14px;">
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
  const lineItemsBody = document.getElementById('lineItemsBody');
  const addLineItem = document.getElementById('addLineItem');
  const subtotalNode = document.getElementById('invoiceSubtotal');
  const defaultQty = '<?= h(INVOICE_DEFAULT_QTY) ?>';
  const defaultCost = '<?= h(INVOICE_DEFAULT_COST) ?>';
  const defaultMarkup = '<?= h(INVOICE_DEFAULT_MARKUP) ?>';
  const defaultPrice = '<?= h(INVOICE_DEFAULT_PRICE) ?>';
  const readonlyStyle = '<?= invoice_readonly_style() ?>';

  function parseNumber(value) {
    const n = parseFloat(value);
    return Number.isFinite(n) ? n : 0;
  }

  function computeTotals() {
    let subtotal = 0;
    Array.from(lineItemsBody.querySelectorAll('tr.line-item-row')).forEach((row) => {
      const qtyInput = row.querySelector('input[name="item_qty[]"]');
      const costInput = row.querySelector('input[name="item_cost[]"]');
      const markupInput = row.querySelector('input[name="item_markup[]"]');
      const priceInput = row.querySelector('input[name="item_price[]"]');
      const lineTotalCell = row.querySelector('.line-total');
      const cost = parseNumber(costInput?.value);
      const markup = parseNumber(markupInput?.value);
      const price = cost * (1 + markup / 100);
      if (priceInput) { priceInput.value = price.toFixed(2); }
      const lineTotal = parseNumber(qtyInput?.value) * price;
      subtotal += lineTotal;
      if (lineTotalCell) {
        lineTotalCell.textContent = '$' + lineTotal.toFixed(2);
      }
    });
    subtotalNode.textContent = subtotal.toFixed(2);
  }

  function bindRow(row) {
    row.querySelectorAll('input').forEach((input) => {
      input.addEventListener('input', computeTotals);
    });

    const removeBtn = row.querySelector('.remove-line');
    if (!removeBtn) {
      return;
    }

    removeBtn.addEventListener('click', () => {
      if (lineItemsBody.querySelectorAll('tr.line-item-row').length <= 1) {
        row.querySelector('input[name="item_desc[]"]').value = '';
        row.querySelector('input[name="item_qty[]"]').value = defaultQty;
        row.querySelector('input[name="item_cost[]"]').value = defaultCost;
        row.querySelector('input[name="item_markup[]"]').value = defaultMarkup;
        row.querySelector('input[name="item_price[]"]').value = defaultPrice;
      } else {
        row.remove();
      }
      computeTotals();
    });
  }

  addLineItem.addEventListener('click', () => {
    const tr = document.createElement('tr');
    tr.className = 'line-item-row';
    tr.innerHTML = '<td><input type="text" name="item_desc[]" maxlength="500" /></td>'
      + '<td><input type="number" step="0.01" min="0.01" name="item_qty[]" value="' + defaultQty + '" /></td>'
      + '<td><input type="number" step="0.01" min="0" name="item_cost[]" value="' + defaultCost + '" /></td>'
      + '<td><input type="number" step="0.01" min="0" name="item_markup[]" value="' + defaultMarkup + '" /></td>'
      + '<td><input type="number" step="0.01" min="0" name="item_price[]" value="' + defaultPrice + '" readonly style="' + readonlyStyle + '" /></td>'
      + '<td class="line-total" style="white-space:nowrap;">$0.00</td>'
      + '<td><button type="button" class="btn remove-line">×</button></td>';
    lineItemsBody.appendChild(tr);
    bindRow(tr);
    computeTotals();
  });

  Array.from(lineItemsBody.querySelectorAll('tr.line-item-row')).forEach(bindRow);
  computeTotals();
})();
</script>
<script>
(() => {
  const customerNameInput = document.getElementById('customer_name');
  const companyInput = document.getElementById('company_name');
  const phoneInput = document.getElementById('phone_number');
  const emailInput = document.getElementById('email');
  const suggestions = document.getElementById('customerSuggestions');
  if (!customerNameInput || !companyInput || !phoneInput || !emailInput || !suggestions) return;
  let debounceTimer = null;

  function hideSuggestions() {
    suggestions.style.display = 'none';
    suggestions.innerHTML = '';
  }

  function renderSuggestions(rows) {
    suggestions.innerHTML = '';
    if (!rows.length) { hideSuggestions(); return; }
    rows.forEach((row) => {
      const rowCompany = row.company_name || '';
      const rowPhone = row.phone || '';
      const rowEmail = row.email || '';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn';
      btn.style.display = 'block';
      btn.style.width = '100%';
      btn.style.textAlign = 'left';
      btn.style.borderRadius = '0';
      btn.style.border = '0';
      btn.style.borderBottom = '1px solid #e5e7eb';
      btn.style.background = '#fff';
      btn.style.padding = '10px 12px';
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
        phoneInput.value = rowPhone;
        emailInput.value = rowEmail;
        hideSuggestions();
      });
      suggestions.appendChild(btn);
    });
    suggestions.style.display = 'block';
  }

  customerNameInput.addEventListener('input', () => {
    const q = customerNameInput.value.trim();
    if (debounceTimer) clearTimeout(debounceTimer);
    if (q.length < 1) { hideSuggestions(); return; }
    debounceTimer = setTimeout(() => {
      const searchUrl = 'invoice_form.php?customer_search=1&q=' + encodeURIComponent(q);
      fetch(searchUrl, {
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': '<?= h($_SESSION['invoice_form_csrf']) ?>' }
      })
        .then((res) => res.ok ? res.json() : [])
        .then((rows) => renderSuggestions(Array.isArray(rows) ? rows : []))
        .catch(() => hideSuggestions());
    }, 180);
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('#customerSuggestions') && event.target !== customerNameInput) {
      hideSuggestions();
    }
  });
})();
</script>
<?php else: ?>
<script>
(() => {
  let subtotal = 0;
  document.querySelectorAll('#lineItemsBody .line-total').forEach((cell) => {
    const amount = parseFloat((cell.textContent || '').replace(/[^0-9.-]/g, ''));
    if (Number.isFinite(amount)) {
      subtotal += amount;
    }
  });
  const subtotalNode = document.getElementById('invoiceSubtotal');
  if (subtotalNode) {
    subtotalNode.textContent = subtotal.toFixed(2);
  }
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
