<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';
require_admin_or_moderator();

// ---------- Constants ----------
const INVOICE_DEFAULT_QTY              = '1.00';
const INVOICE_DEFAULT_COST             = '0.00';
const INVOICE_DEFAULT_MARKUP           = '20.00';
const INVOICE_DEFAULT_PRICE            = '0.00';
const INVOICE_MIN_QTY                  = 0.01;
const STRIPE_AMOUNT_TOLERANCE          = 0.01;
const STRIPE_API_TIMEOUT_SECONDS       = 20;
const INVOICE_BALANCE_EPSILON          = 0.005;
const INVOICE_PAYMENT_STATUS_UNPAID    = 'unpaid';
const INVOICE_PAYMENT_STATUS_PAID      = 'paid';

// EMAIL PREVIEW HANDLER - Must be at the very top, right after requires
if (isset($_GET['email_preview']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];

    try {
        $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$quote) {
            throw new RuntimeException('Quote not found for email preview.');
        }

        $items_stmt = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC');
        $items_stmt->execute([$id]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

        $error = null;
        $payload = invoice_build_email_message_data($pdo, $quote, $items, false, $error);

        if (!is_array($payload) || empty($payload['html_body'])) {
            $message = trim((string)$error);
            if ($message === '') {
                $message = 'Unable to generate email preview.';
            }
            throw new RuntimeException($message);
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $payload['html_body'];
    } catch (Throwable $e) {
        http_response_code(500);
        $message = trim($e->getMessage());
        $log_message = $message;
        if ($message === '') {
            $message = 'Unknown preview error.';
            $log_message = get_class($e) . ' at ' . $e->getFile() . ':' . $e->getLine();
        }
        error_log('Invoice email preview failed for quote #' . $id . ': ' . $log_message);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h2>Unable to generate email preview.</h2>';
        echo '<pre style="white-space:pre-wrap;color:#b91c1c;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</pre>';
    }

    exit;
}

function invoice_build_email_message_data(PDO $pdo, array $quote, array $items, bool $require_payment_link = true, ?string &$error_message = null): ?array {
  $error_message = null;
  $created_by    = isset($quote['created_by']) && $quote['created_by'] !== null ? (int)$quote['created_by'] : null;
  $sender        = invoice_sender_profile($pdo, $created_by);
  $sender_name   = $sender['sender_name'];
  $smtp_from_name = trim(str_replace(["\r", "\n"], ' ', invoice_env_value('SMTP_FROM_NAME')));
  $sender_company = $sender['company_name'] !== '' ? $sender['company_name'] : $smtp_from_name;
  if ($sender_company === '') $sender_company = 'Our Company';
  $sender_address = $sender['address'];
  $sender_phone   = $sender['phone'];
  $sender_email_env = invoice_env_value('SMTP_FROM_EMAIL');
  $sender_email   = $sender['email'] !== '' ? $sender['email'] : $sender_email_env;

  $inv_no        = trim((string)($quote['converted_invoice_no'] ?? ''));
  $customer_name = trim((string)($quote['customer_name'] ?? ''));
  $customer_company = trim((string)($quote['company_name'] ?? ''));
  $inv_date      = trim((string)($quote['quote_date'] ?? ''));
  $subtotal      = number_format((float)($quote['subtotal_amount'] ?? 0), 2);
  $inv_tax_rate  = (float)($quote['tax_rate'] ?? 0);
  $inv_tax_amount = number_format((float)($quote['tax_amount'] ?? 0), 2);
  $inv_grand_total = number_format((float)($quote['subtotal_amount'] ?? 0) + (float)($quote['tax_amount'] ?? 0), 2);

  // Bill To address
  $bill_street = trim((string)($quote['billing_street'] ?? ''));
  $bill_city   = trim((string)($quote['billing_city']   ?? ''));
  $bill_state  = trim((string)($quote['billing_state']  ?? ''));
  $bill_zip    = trim((string)($quote['billing_zip']    ?? ''));
  $is_paid = invoice_is_paid($quote);
  $payment_link = '';
  error_log(
    'DEBUG: enable_online_payment = ' . (int)($quote['enable_online_payment'] ?? 0)
    . ' | is_paid = ' . ($is_paid ? 'true' : 'false')
    . ' | invoice #' . (int)($quote['id'] ?? 0)
  );
  if (!$is_paid && invoice_online_payment_enabled($quote)) {
    $payment_error = null;
    $payment_link = invoice_checkout_session_url($pdo, $quote, $payment_error);
    if ($payment_link === '') {
      $logged_err = trim((string)$payment_error) !== '' ? trim((string)$payment_error) : 'no error message returned';
      error_log('invoice_checkout_session_url returned empty for invoice #' . (int)($quote['id'] ?? 0) . ': ' . $logged_err);
      if ($require_payment_link) {
        $error_message = trim((string)$payment_error) !== '' ? trim((string)$payment_error) : 'Unable to create Stripe checkout link for this invoice.';
        return null;
      }
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

  // ---- Build Bill To / From HTML blocks ----
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
    . ($is_paid
        ? '<div style="background:#ffffff;padding:6px 32px 0;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
            . '<div style="margin:0 0 4px;padding:4px 10px;border:2px solid #dc2626;border-radius:6px;background:#fee2e2;text-align:center;">'
              . '<span style="display:inline-block;font-size:20px;line-height:1;font-weight:900;letter-spacing:0.12em;color:#b91c1c;text-transform:uppercase;">PAID</span>'
            . '</div>'
          . '</div>'
        : '')

    // ── Document title strip ──
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

      . '<p style="margin:0 0 8px;font-size:15px;color:#1e293b;">Hello' . ($customer_name !== '' ? ', ' . $h($customer_name) : '') . ',</p>'
      . '<p style="margin:0 0 24px;font-size:14px;color:#475569;">Please find your invoice details below. Thank you for your business.</p>'
      . ($payment_link !== ''
          ? '<div style="margin:0 0 24px;padding:16px 18px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;">'
              . '<p style="margin:0 0 10px;font-size:14px;font-weight:600;color:#1d4ed8;">Pay this invoice online</p>'
              . '<p style="margin:0 0 14px;font-size:13px;color:#334155;">Use Stripe\'s secure checkout page to pay this invoice online. Card details are entered directly on Stripe and are not collected on our site.</p>'
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
  $text_body .= str_repeat('-', 40) . "\r\n";
  $text_body .= "Bill To: " . ($customer_company !== '' ? $customer_company . ' / ' : '') . $customer_name . "\r\n";
  if ($bill_street !== '') $text_body .= $bill_street . "\r\n";
  if ($bill_csz !== '') $text_body .= $bill_csz . "\r\n";
  $text_body .= str_repeat('-', 40) . "\r\n\r\n";
  if ($is_paid) {
    $text_body .= "PAID\r\n\r\n";
  }
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
    'subject' => $subject,
    'html_body' => $html_body,
    'text_body' => $text_body,
  ];
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
      <p class="muted">We couldn't find the quote used to pre-fill this invoice.</p>
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

function invoice_is_paid(array $quote): bool {
  return strtolower(trim((string)($quote['payment_status'] ?? INVOICE_PAYMENT_STATUS_UNPAID))) === INVOICE_PAYMENT_STATUS_PAID;
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
  $log_empty_result = static function (string $reason, int $quote_id, array $context = [], bool $failed = false): void {
    $details = [];
    foreach ($context as $key => $value) {
      if ($value === null || $value === '') continue;
      if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
      } elseif (!is_scalar($value)) {
        $encoded = json_encode($value);
        $value = $encoded !== false ? $encoded : '[unserializable]';
      }
      $details[] = $key . '=' . (string)$value;
    }
    $suffix = $details ? ' [' . implode(', ', $details) . ']' : '';
    $prefix = $failed
      ? 'invoice_checkout_session_url failed and returned empty for invoice #'
      : 'invoice_checkout_session_url returning empty for invoice #';
    error_log($prefix . $quote_id . ': ' . $reason . $suffix);
  };
  $log_failure = static function (string $reason, int $quote_id, ?string &$error_message, array $context = []) use ($log_empty_result): string {
    $log_empty_result($reason, $quote_id, $context, true);
    $error_message = $reason;
    return '';
  };

  if (!invoice_online_payment_enabled($quote)) {
    $log_empty_result('Online payment is disabled for this invoice.', (int)($quote['id'] ?? 0), [
      'enable_online_payment' => (int)($quote['enable_online_payment'] ?? 0),
    ]);
    return '';
  }

  $quote_id = (int)($quote['id'] ?? 0);
  if ($quote_id <= 0) {
    return $log_failure('Invoice must be saved before generating an online payment link.', -1, $error_message, ['invoice_state' => 'unsaved']);
  }

  $amount = round((float)($quote['subtotal_amount'] ?? 0), 2);
  if ($amount <= 0) {
    return $log_failure('Online payment requires an invoice total greater than $0.00.', $quote_id, $error_message, ['amount' => $amount]);
  }

  if (invoice_has_valid_checkout_session($quote, $amount)) {
    return trim((string)($quote['stripe_checkout_url'] ?? ''));
  }

  if (!function_exists('curl_init')) {
    return $log_failure('Stripe checkout could not be created because cURL is not available on this server.', $quote_id, $error_message);
  }

  $secret_key = invoice_stripe_secret_key($pdo);
  if ($secret_key === '') {
    return $log_failure('Stripe secret key is not configured. Please save it in Admin > Integrations or set STRIPE_SECRET_KEY.', $quote_id, $error_message);
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
  if ($ch === false) {
    return $log_failure('Failed to initialize cURL session for Stripe checkout.', $quote_id, $error_message);
  }

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
    return $log_failure(
      $curl_error !== '' ? $curl_error : 'Stripe checkout request failed.',
      $quote_id,
      $error_message,
      ['http_code' => $http_code]
    );
  }

  $response = json_decode($response_body, true);
  if (!is_array($response)) {
    return $log_failure(
      'Stripe returned an invalid response.',
      $quote_id,
      $error_message,
      [
        'http_code' => $http_code,
        'response_body_length' => strlen($response_body),
      ]
    );
  }

  if ($http_code >= 400) {
    $stripe_error = trim((string)($response['error']['message'] ?? ''));
    return $log_failure(
      $stripe_error !== '' ? $stripe_error : 'Stripe checkout request failed.',
      $quote_id,
      $error_message,
      [
        'http_code' => $http_code,
        'stripe_error_type' => trim((string)($response['error']['type'] ?? '')),
        'stripe_error_code' => trim((string)($response['error']['code'] ?? '')),
      ]
    );
  }

  $checkout_url = trim((string)($response['url'] ?? ''));
  $session_id = trim((string)($response['id'] ?? ''));
  if ($checkout_url === '' || $session_id === '') {
    return $log_failure(
      'Stripe did not return a hosted checkout link.',
      $quote_id,
      $error_message,
      [
        'http_code' => $http_code,
        'has_url' => $checkout_url !== '',
        'has_session_id' => $session_id !== '',
      ]
    );
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


// ---------- CSRF ----------
if (empty($_SESSION['invoice_form_csrf'])) {
  $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));
}

function invoice_form_approval_label(string $status): string {
  return match ($status) {
    'pending_approval' => 'Pending Approval',
    'approved' => 'Approved',
    default => 'Not Submitted',
  };
}

function invoice_form_approval_colors(string $status): array {
  return match ($status) {
    'pending_approval' => ['#fef3c7', '#92400e'],
    'approved' => ['#dcfce7', '#166534'],
    default => ['#f1f5f9', '#475569'],
  };
}

$view_mode_requested = isset($_GET['mode']) && $_GET['mode'] === 'view';

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

  $email_payload = invoice_build_email_message_data($pdo, $quote, $items, true, $error_message);
  if ($email_payload === null) {
    error_log('Invoice email send failed for quote #' . (int)($quote['id'] ?? 0) . ' — ' . ($error_message ?? 'invoice_build_email_message_data returned null'));
    return false;
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
    $mailer->Subject  = (string)$email_payload['subject'];
    $mailer->isHTML(true);
    $mailer->Body     = (string)$email_payload['html_body'];
    $mailer->AltBody  = (string)$email_payload['text_body'];
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

/**
 * Fetch invoice + items, send to the given email address, then redirect or append to $errors.
 */
function invoice_send_email_to_address(
  PDO $pdo,
  int $row_id,
  string $recipient_email,
  string $bad_email_error,
  string $redirect_param,
  array &$errors
): bool {
  if ($recipient_email === '' || !filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = $bad_email_error;
    return false;
  }
  $stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = ? LIMIT 1");
  $stmt->execute([$row_id]);
  $eq_quote = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$eq_quote) {
    $errors[] = 'Invoice not found.';
    return false;
  }
  $item_stmt = $pdo->prepare("SELECT description, quantity, unit_price, line_total FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC");
  $item_stmt->execute([$row_id]);
  $eq_items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$eq_items) {
    $errors[] = 'Cannot send email: invoice has no line items.';
    return false;
  }
  $eq_quote['email'] = $recipient_email;
  $eq_error = null;
  if (!invoice_send_email_msg($pdo, $eq_quote, $eq_items, $eq_error)) {
    $errors[] = $eq_error !== null && $eq_error !== '' ? $eq_error : 'Email was not sent.';
    return false;
  }
  $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));
  header('Location: invoice_form.php?id=' . $row_id . '&mode=view&' . $redirect_param . '=1');
  exit;
}

function invoice_email_preview_content(string $html): string {
  $html = trim($html);
  if ($html === '' || !class_exists('DOMDocument')) {
    return '';
  }

  $dom = new DOMDocument();
  $libxml_previous = libxml_use_internal_errors(true);
  $loaded = $dom->loadHTML($html, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
  libxml_clear_errors();
  libxml_use_internal_errors($libxml_previous);
  if (!$loaded) {
    return '';
  }

  foreach (['script', 'iframe', 'object', 'embed', 'form', 'link', 'meta', 'base'] as $tag_name) {
    while (($nodes = $dom->getElementsByTagName($tag_name))->length > 0) {
      $node = $nodes->item(0);
      if ($node !== null && $node->parentNode !== null) {
        $node->parentNode->removeChild($node);
      }
    }
  }

  $sanitize_node = static function (DOMNode $node) use (&$sanitize_node): void {
    if ($node instanceof DOMElement && $node->hasAttributes()) {
      $attributes_to_remove = [];
      foreach ($node->attributes as $attribute) {
        $attribute_name = strtolower($attribute->nodeName);
        $attribute_value = trim($attribute->nodeValue);
        if (str_starts_with($attribute_name, 'on')) {
          $attributes_to_remove[] = $attribute->nodeName;
          continue;
        }
        if (in_array($attribute_name, ['href', 'src', 'xlink:href', 'formaction'], true) && preg_match('/^\s*(javascript|data|vbscript):/i', $attribute_value)) {
          $attributes_to_remove[] = $attribute->nodeName;
        }
      }
      foreach ($attributes_to_remove as $attribute_name) {
        $node->removeAttribute($attribute_name);
      }
    }
    foreach ($node->childNodes as $child_node) {
      if ($child_node instanceof DOMNode) {
        $sanitize_node($child_node);
      }
    }
  };
  $sanitize_node($dom);

  $body = $dom->getElementsByTagName('body')->item(0);
  $container = $body instanceof DOMElement ? $body : $dom->documentElement;
  if ($container instanceof DOMNode) {
    $preview_html = '';
    foreach ($container->childNodes as $child_node) {
      if ($child_node instanceof DOMNode) {
        $preview_html .= (string)$dom->saveHTML($child_node);
      }
    }
    return trim($preview_html);
  }

  return '';
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
    $return_to_tracker = trim((string)($_POST['return_to'] ?? '')) === 'tracker';
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
          $pdo->prepare("UPDATE quotes SET invoice_emailed = 1 WHERE id = ?")->execute([$row_id]);
          if ($return_to_tracker) {
            header('Location: invoice_tracker.php?email_sent=' . $row_id);
          } else {
            $mode_param = $view_mode_requested ? '&mode=view' : '';
            header('Location: invoice_form.php?id=' . $row_id . $mode_param . '&email_sent=1');
          }
        } else {
          if ($return_to_tracker) {
            header('Location: invoice_tracker.php?email_error=' . urlencode($eq_error ?? 'Unknown error') . '&email_id=' . $row_id);
          } else {
            $mode_param = $view_mode_requested ? '&mode=view' : '';
            header('Location: invoice_form.php?id=' . $row_id . $mode_param . '&email_error=' . urlencode($eq_error ?? 'Unknown error'));
          }
        }
      } else {
        header('Location: invoice_tracker.php');
      }
    } else {
      header('Location: invoice_tracker.php');
    }
    exit;
  }

  if (trim((string)($_POST['action'] ?? '')) === 'send_email_myself') {
    $row_id = (int)($_POST['row_id'] ?? 0);
    $me_stmt = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $me_stmt->execute([current_user_id()]);
    $me = $me_stmt->fetch();
    $my_email = trim((string)($me['email'] ?? ''));
    $errors = [];
    invoice_send_email_to_address($pdo, $row_id, $my_email, 'Your account does not have a valid email address configured.', 'email_sent_myself', $errors);
    if ($errors) {
      $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));
      header('Location: invoice_form.php?id=' . $row_id . '&mode=view&email_error=' . urlencode($errors[0]));
      exit;
    }
  }

  if (trim((string)($_POST['action'] ?? '')) === 'send_email_admin') {
    $row_id = (int)($_POST['row_id'] ?? 0);
    $zeke_stmt = $pdo->prepare("SELECT email FROM users WHERE username = 'Zeke' LIMIT 1");
    $zeke_stmt->execute();
    $zeke = $zeke_stmt->fetch();
    $zeke_email = trim((string)($zeke['email'] ?? ''));
    $errors = [];
    invoice_send_email_to_address($pdo, $row_id, $zeke_email, 'Admin (Zeke) does not have a valid email address configured.', 'email_sent_admin', $errors);
    if ($errors) {
      $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));
      header('Location: invoice_form.php?id=' . $row_id . '&mode=view&email_error=' . urlencode($errors[0]));
      exit;
    }
  }

  if (trim((string)($_POST['action'] ?? '')) === 'approve_invoice') {
    $row_id = (int)($_POST['row_id'] ?? 0);
    $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));
    if (!is_admin() || $row_id <= 0) {
      header('Location: invoice_tracker.php');
      exit;
    }
    $check = $pdo->prepare("SELECT id FROM quotes WHERE id = ? LIMIT 1");
    $check->execute([$row_id]);
    if (!$check->fetch()) {
      header('Location: invoice_tracker.php');
      exit;
    }
    $pdo->prepare("UPDATE quotes SET approval_status = 'approved' WHERE id = ?")->execute([$row_id]);
    $pdo->prepare("UPDATE approval_alerts SET is_read = 1 WHERE entity_type = 'invoice' AND entity_id = ?")->execute([$row_id]);
    header('Location: invoice_form.php?id=' . $row_id . '&mode=view&approval_approved=1');
    exit;
  }
  
  if (trim((string)($_POST['action'] ?? '')) === 'mark_as_paid') {
    $row_id = (int)($_POST['row_id'] ?? 0);
    $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));
    if ($row_id <= 0) {
      header('Location: invoice_tracker.php');
      exit;
    }
    $check = $pdo->prepare("SELECT id FROM quotes WHERE id = ? LIMIT 1");
    $check->execute([$row_id]);
    if (!$check->fetch()) {
      header('Location: invoice_tracker.php');
      exit;
    }
    $mark_paid_stmt = $pdo->prepare("UPDATE quotes SET payment_status = ?, paid_at = NOW() WHERE id = ? AND payment_status <> ?");
    $mark_paid_stmt->execute([
      INVOICE_PAYMENT_STATUS_PAID,
      $row_id,
      INVOICE_PAYMENT_STATUS_PAID,
    ]);
    $payment_query_flag = $mark_paid_stmt->rowCount() > 0 ? 'payment_marked=1' : 'already_paid=1';
    header('Location: invoice_form.php?id=' . $row_id . '&mode=view&' . $payment_query_flag);
    exit;
  }

  if (trim((string)($_POST['action'] ?? '')) === 'apply_credit_to_invoice') {
    $row_id = (int)($_POST['row_id'] ?? 0);
    $_SESSION['invoice_form_csrf'] = bin2hex(random_bytes(24));
    if ($row_id <= 0) {
      header('Location: invoice_tracker.php');
      exit;
    }
    $inv_stmt = $pdo->prepare("SELECT id, customer_id, subtotal_amount, tax_amount, payment_status FROM quotes WHERE id = ? LIMIT 1");
    $inv_stmt->execute([$row_id]);
    $inv_row = $inv_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv_row || (int)($inv_row['customer_id'] ?? 0) <= 0) {
      header('Location: invoice_form.php?id=' . $row_id . '&mode=view&credit_error=' . urlencode('Invoice not found or has no linked customer.'));
      exit;
    }
    $cust_id_for_credit = (int)$inv_row['customer_id'];
    $inv_total = round((float)$inv_row['subtotal_amount'] + (float)($inv_row['tax_amount'] ?? 0), 2);

    // Outstanding balance = invoice total - sum of already applied credits for this invoice
    $already_applied_stmt = $pdo->prepare("SELECT COALESCE(SUM(applied_amount), 0) AS total_applied FROM invoice_credit_applications WHERE quote_id = ?");
    $already_applied_stmt->execute([$row_id]);
    $already_applied = round((float)$already_applied_stmt->fetchColumn(), 2);
    $outstanding_balance = round($inv_total - $already_applied, 2);

    // Available credit = total paid - total already applied across all invoices for this customer
    $total_paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM customer_payments WHERE customer_id = ?");
    $total_paid_stmt->execute([$cust_id_for_credit]);
    $total_paid_credit = round((float)$total_paid_stmt->fetchColumn(), 2);

    $total_all_applied_stmt = $pdo->prepare("SELECT COALESCE(SUM(applied_amount), 0) AS total FROM invoice_credit_applications WHERE customer_id = ?");
    $total_all_applied_stmt->execute([$cust_id_for_credit]);
    $total_all_applied = round((float)$total_all_applied_stmt->fetchColumn(), 2);

    $available_credit = round($total_paid_credit - $total_all_applied, 2);

    $apply_amount_raw = trim((string)($_POST['apply_amount'] ?? ''));
    $apply_amount = round((float)str_replace(',', '', $apply_amount_raw), 2);
    $apply_notes = trim((string)($_POST['apply_notes'] ?? ''));
    $apply_date = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Y-m-d');

    $credit_errors = [];
    if ($apply_amount <= 0) {
      $credit_errors[] = 'Amount must be greater than zero.';
    }
    if ($apply_amount > $available_credit) {
      $credit_errors[] = 'Amount exceeds available customer credit ($' . number_format($available_credit, 2) . ').';
    }
    if ($apply_amount > $outstanding_balance) {
      $credit_errors[] = 'Amount exceeds the outstanding invoice balance ($' . number_format($outstanding_balance, 2) . ').';
    }

    if ($credit_errors) {
      header('Location: invoice_form.php?id=' . $row_id . '&mode=view&credit_error=' . urlencode(implode(' ', $credit_errors)));
      exit;
    }

    $apply_by = ((int)($_SESSION['user_id'] ?? 0)) ?: null;
    $pdo->prepare(
      "INSERT INTO invoice_credit_applications (quote_id, customer_id, applied_amount, applied_date, notes, applied_by)
       VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$row_id, $cust_id_for_credit, $apply_amount, $apply_date, $apply_notes !== '' ? $apply_notes : null, $apply_by]);

    // If outstanding balance is now fully covered, mark invoice as paid
    $new_balance = round($outstanding_balance - $apply_amount, 2);
    if ($new_balance <= INVOICE_BALANCE_EPSILON && strtolower(trim((string)($inv_row['payment_status'] ?? ''))) !== INVOICE_PAYMENT_STATUS_PAID) {
      $pdo->prepare("UPDATE quotes SET payment_status = ?, paid_at = NOW() WHERE id = ?")->execute([INVOICE_PAYMENT_STATUS_PAID, $row_id]);
    }

    header('Location: invoice_form.php?id=' . $row_id . '&mode=view&credit_applied=1');
    exit;
  }

  if (trim((string)($_POST['action'] ?? '')) === 'remove_credit_from_invoice') {
    $row_id = (int)($_POST['row_id'] ?? 0);
    $credit_app_id = (int)($_POST['credit_app_id'] ?? 0);
    if ($row_id <= 0 || $credit_app_id <= 0) {
      header('Location: invoice_tracker.php');
      exit;
    }

    $inv_stmt = $pdo->prepare("SELECT id, subtotal_amount, tax_amount FROM quotes WHERE id = ? LIMIT 1");
    $inv_stmt->execute([$row_id]);
    $inv_row = $inv_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv_row) {
      header('Location: invoice_tracker.php');
      exit;
    }

    try {
      $pdo->beginTransaction();

      $delete_stmt = $pdo->prepare("DELETE FROM invoice_credit_applications WHERE id = ? AND quote_id = ?");
      $delete_stmt->execute([$credit_app_id, $row_id]);
      if ($delete_stmt->rowCount() !== 1) {
        throw new RuntimeException('Credit application was not found or was already removed.');
      }

      $remaining_stmt = $pdo->prepare("SELECT COALESCE(SUM(applied_amount), 0) FROM invoice_credit_applications WHERE quote_id = ?");
      $remaining_stmt->execute([$row_id]);
      $remaining_applied = round((float)$remaining_stmt->fetchColumn(), 2);
      $invoice_total = round((float)$inv_row['subtotal_amount'] + (float)($inv_row['tax_amount'] ?? 0), 2);
      $outstanding_balance = round($invoice_total - $remaining_applied, 2);

      if ($outstanding_balance > INVOICE_BALANCE_EPSILON) {
        $pdo->prepare("UPDATE quotes SET payment_status = ?, paid_at = NULL WHERE id = ?")->execute([
          INVOICE_PAYMENT_STATUS_UNPAID,
          $row_id,
        ]);
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('Invoice credit removal failed for quote #' . $row_id . ', application #' . $credit_app_id . ': ' . $e->getMessage());
      $credit_error_message = $e instanceof RuntimeException ? trim((string)$e->getMessage()) : '';
      if ($credit_error_message === '') {
        $credit_error_message = 'Unable to remove credit from invoice. Please try again or contact support if the issue persists.';
      }
      header('Location: invoice_form.php?id=' . $row_id . '&mode=view&credit_error=' . urlencode($credit_error_message));
      exit;
    }

    header('Location: invoice_form.php?id=' . $row_id . '&mode=view&credit_removed=1');
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
  $post_billing_street  = trim((string)($_POST['billing_street'] ?? ''));
  $post_billing_city    = trim((string)($_POST['billing_city'] ?? ''));
  $post_billing_state   = trim((string)($_POST['billing_state'] ?? ''));
  $post_billing_zip     = trim((string)($_POST['billing_zip'] ?? ''));
  $post_enable_online_payment = !empty($_POST['enable_online_payment']);
  $post_notes           = trim((string)($_POST['notes'] ?? ''));

  // Validate date; fall back to today
  $tz = new DateTimeZone(APP_TZ);
  $post_invoice_date_obj = DateTime::createFromFormat('Y-m-d', $post_invoice_date, $tz);
  if (!$post_invoice_date_obj) {
    $post_invoice_date = (new DateTime('now', $tz))->format('Y-m-d');
  }

  $item_descs   = (array)($_POST['item_desc']    ?? []);
  $item_qtys    = (array)($_POST['item_qty']     ?? []);
  $item_costs   = (array)($_POST['item_cost']    ?? []);
  $item_markups = (array)($_POST['item_markup']  ?? []);
  $item_taxables = (array)($_POST['item_taxable'] ?? []);

  $post_tax_rate = min(100.0, max(0.0, (float)($_POST['tax_rate'] ?? 0)));

  $subtotal = 0.0;
  $taxable_subtotal = 0.0;
  $line_items_to_save = [];
  $count = count($item_descs);
  for ($i = 0; $i < $count; $i++) {
    $desc      = trim((string)($item_descs[$i]    ?? ''));
    $qty       = max(INVOICE_MIN_QTY, (float)($item_qtys[$i]     ?? 1));
    $cost      = max(0.0,  (float)($item_costs[$i]    ?? 0));
    $markup    = max(0.0,  (float)($item_markups[$i]  ?? 0));
    $is_taxable = (int)($item_taxables[$i] ?? 0) === 1 ? 1 : 0;
    $price     = $cost * (1 + $markup / 100);
    $line_total = $qty * $price;
    $subtotal  += $line_total;
    if ($is_taxable) $taxable_subtotal += $line_total;
    $line_items_to_save[] = [
      'description'    => $desc,
      'quantity'       => $qty,
      'cost'           => $cost,
      'markup_percent' => $markup,
      'unit_price'     => $price,
      'line_total'     => $line_total,
      'is_taxable'     => $is_taxable,
    ];
  }

  $tax_amount = round($taxable_subtotal * $post_tax_rate / 100, 2);

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
                billing_street    = ?,
                billing_city      = ?,
                billing_state     = ?,
                billing_zip       = ?,
                quote_date        = ?,
                notes             = ?,
                subtotal_amount   = ?,
                tax_rate          = ?,
                tax_amount        = ?,
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
        $post_billing_street !== '' ? $post_billing_street : null,
        $post_billing_city   !== '' ? $post_billing_city   : null,
        $post_billing_state  !== '' ? $post_billing_state  : null,
        $post_billing_zip    !== '' ? $post_billing_zip    : null,
        $post_invoice_date,
        $post_notes         !== '' ? $post_notes         : null,
        round($subtotal, 2),
        round($post_tax_rate, 2),
        $tax_amount,
        $post_enable_online_payment ? 1 : 0,
        $inv_no,
        $post_source_quote_id,
      ]);

      // Replace line items
      $pdo->prepare("DELETE FROM quote_items WHERE quote_id = ?")->execute([$post_source_quote_id]);

      $ins = $pdo->prepare(
        "INSERT INTO quote_items
           (quote_id, line_position, description, quantity, cost, markup_percent, unit_price, line_total, is_taxable)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
      );
      foreach ($line_items_to_save as $pos => $item) {
        $ins->execute([
          $post_source_quote_id, $pos + 1,
          $item['description'], $item['quantity'], $item['cost'],
          $item['markup_percent'], $item['unit_price'], $item['line_total'], $item['is_taxable'],
        ]);
      }
    } else {
      // Insert a new quote row representing the standalone invoice
      $ins_q = $pdo->prepare(
        "INSERT INTO quotes
           (customer_name, company_name, phone_number, email, billing_street, billing_city, billing_state, billing_zip, quote_date,
            status, notes, subtotal_amount, tax_rate, tax_amount, enable_online_payment, converted_invoice_no, converted_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'converted', ?, ?, ?, ?, ?, '', NOW(), ?)"
      );
      $ins_q->execute([
        $post_customer_name,
        $post_company_name !== '' ? $post_company_name : null,
        $post_phone_number  !== '' ? $post_phone_number  : null,
        $post_email         !== '' ? $post_email         : null,
        $post_billing_street !== '' ? $post_billing_street : null,
        $post_billing_city   !== '' ? $post_billing_city   : null,
        $post_billing_state  !== '' ? $post_billing_state  : null,
        $post_billing_zip    !== '' ? $post_billing_zip    : null,
        $post_invoice_date,
        $post_notes         !== '' ? $post_notes         : null,
        round($subtotal, 2),
        round($post_tax_rate, 2),
        $tax_amount,
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
           (quote_id, line_position, description, quantity, cost, markup_percent, unit_price, line_total, is_taxable)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
      );
      foreach ($line_items_to_save as $pos => $item) {
        $ins->execute([
          $new_id, $pos + 1,
          $item['description'], $item['quantity'], $item['cost'],
          $item['markup_percent'], $item['unit_price'], $item['line_total'], $item['is_taxable'],
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
  return number_format((float)$value, 2, '.', '');
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
  'billing_street' => (string)($quote['billing_street'] ?? ''),
  'billing_city' => (string)($quote['billing_city'] ?? ''),
  'billing_state' => (string)($quote['billing_state'] ?? ''),
  'billing_zip' => (string)($quote['billing_zip'] ?? ''),
  'invoice_date' => invoice_quote_date_value($quote, $today),
  'enable_online_payment' => $quote && invoice_online_payment_enabled($quote) ? '1' : '0',
  'notes' => (string)($quote['notes'] ?? ''),
  'tax_rate' => number_format((float)($quote['tax_rate'] ?? 0), 2, '.', ''),
];

$line_items = [];
foreach ($rows as $row) {
  $line_items[] = [
    'description' => (string)($row['description'] ?? ''),
    'quantity' => invoice_format_money($row['quantity'] ?? 0),
    'cost' => invoice_format_money($row['cost'] ?? 0),
    'markup_percent' => number_format((float)($row['markup_percent'] ?? 0), 2, '.', ''),
    'unit_price' => invoice_format_money($row['unit_price'] ?? 0),
    'line_total' => invoice_format_money($row['line_total'] ?? 0),
    'is_taxable' => (int)($row['is_taxable'] ?? 0),
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
    'is_taxable' => 0,
  ];
}

$invoice_converted = isset($_GET['invoice_converted']) && $_GET['invoice_converted'] === '1';
$invoice_email_sent  = isset($_GET['email_sent'])  && $_GET['email_sent']  === '1';
$invoice_email_sent_myself = isset($_GET['email_sent_myself']) && $_GET['email_sent_myself'] === '1';
$invoice_email_sent_admin  = isset($_GET['email_sent_admin'])  && $_GET['email_sent_admin']  === '1';
$invoice_email_error = isset($_GET['email_error']) && $_GET['email_error'] !== '' ? trim((string)$_GET['email_error']) : '';
$invoice_approval_approved = isset($_GET['approval_approved']) && $_GET['approval_approved'] === '1';
$invoice_payment_marked = isset($_GET['payment_marked']) && $_GET['payment_marked'] === '1';
$invoice_already_paid = isset($_GET['already_paid']) && $_GET['already_paid'] === '1';
$invoice_credit_applied = isset($_GET['credit_applied']) && $_GET['credit_applied'] === '1';
$invoice_credit_removed = isset($_GET['credit_removed']) && $_GET['credit_removed'] === '1';
$invoice_credit_error = isset($_GET['credit_error']) && $_GET['credit_error'] !== '' ? trim((string)$_GET['credit_error']) : '';
$invoice_approval_status = is_array($quote) ? (string)($quote['approval_status'] ?? 'none') : 'none';
$invoice_is_paid = is_array($quote) && invoice_is_paid($quote);
[$invoice_approval_bg, $invoice_approval_color] = invoice_form_approval_colors($invoice_approval_status);
$invoice_approval_label = invoice_form_approval_label($invoice_approval_status);
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
  .invoice-mark-paid-btn{background:#dc2626;border-color:#b91c1c;color:#fff;font-weight:700;}
</style>

<div class="card page-header">
  <div class="page-header-body">
    <h1><?= h($invoice_heading) ?></h1>
    <p class="muted"><?= h($invoice_subtitle) ?></p>
    <?php if (!$is_view_mode && $quote): ?>
      <span style="display:inline-flex;align-items:center;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:600;background:<?= h($invoice_approval_bg) ?>;color:<?= h($invoice_approval_color) ?>;">Approval: <?= h($invoice_approval_label) ?></span>
    <?php endif; ?>
  </div>
  <div class="actions">
    <?php if (!$is_view_mode): ?>
      <?php if ($quote && trim((string)($quote['email'] ?? '')) !== ''): ?>
        <form method="post" style="margin:0;" action="" onsubmit="return confirm('Are you sure you want to email this invoice to <?= addslashes(h((string)($quote["customer_name"] ?? ""))) ?>? This cannot be undone.');">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
          <input type="hidden" name="action" value="send_email" />
          <input type="hidden" name="row_id" value="<?= (int)$quote_id ?>" />
          <button type="submit" class="btn">Email Invoice</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <a class="btn" href="invoice_tracker.php">Back to Invoices</a>
    <?php if ($quote): ?>
      <a class="btn" href="quotes.php?view=id&id=<?= (int)$quote_id ?>">Back to Quote</a>
    <?php endif; ?>
    <a class="btn" href="quotes.php?view=all">All Quotes</a>
  </div>
</div>

<?php if ($invoice_converted): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Quote converted to invoice successfully.</div>
<?php endif; ?>
<?php if ($invoice_email_sent): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Invoice email sent successfully.</div>
<?php endif; ?>
<?php if ($invoice_email_sent_myself): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Invoice emailed to yourself successfully.</div>
<?php endif; ?>
<?php if ($invoice_email_sent_admin): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Invoice emailed to admin (Zeke) successfully.</div>
<?php endif; ?>
<?php if ($invoice_email_error !== ''): ?>
  <div class="alert" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">Failed to send invoice email: <?= h($invoice_email_error) ?></div>
<?php endif; ?>
<?php if ($invoice_approval_approved): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Invoice approved.</div>
<?php endif; ?>
<?php if ($invoice_payment_marked): ?>
  <div class="alert" style="border-color:#fecaca; background:#fff1f2; color:#9f1239;">Invoice marked as paid.</div>
<?php endif; ?>
<?php if ($invoice_already_paid): ?>
  <div class="alert" style="border-color:#fecaca; background:#fff1f2; color:#9f1239;">Invoice is already marked as paid.</div>
<?php endif; ?>
<?php if ($invoice_credit_applied): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Credit applied to invoice successfully.</div>
<?php endif; ?>
<?php if ($invoice_credit_removed): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">Credit removed from invoice successfully.</div>
<?php endif; ?>
<?php if ($invoice_credit_error !== ''): ?>
  <div class="alert" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;"><?= h($invoice_credit_error) ?></div>
<?php endif; ?>

<?php if ($is_view_mode && $quote): ?>
  <?php
    $inv_paid_bg    = $invoice_is_paid ? '#dcfce7' : '#f1f5f9';
    $inv_paid_color = $invoice_is_paid ? '#166534' : '#475569';
    $inv_paid_label = $invoice_is_paid ? 'Paid' : 'Unpaid';
    $inv_number     = h($fields['invoice_number']);
    $inv_customer   = h((string)($quote['customer_name'] ?? ''));
    $inv_date       = h((string)($quote['invoice_date'] ?? ($quote['quote_date'] ?? '')));

    // ── Credit computation ──────────────────────────────────────────────────
    $inv_cust_id_for_credit = (int)($quote['customer_id'] ?? 0);
    $inv_total_for_credit   = round((float)($quote['subtotal_amount'] ?? 0) + (float)($quote['tax_amount'] ?? 0), 2);

    // Credits already applied to this invoice
    $inv_credit_apps_stmt = $pdo->prepare(
      "SELECT id, applied_amount, applied_date, notes FROM invoice_credit_applications WHERE quote_id = ? ORDER BY applied_date ASC, id ASC"
    );
    $inv_credit_apps_stmt->execute([$quote_id]);
    $inv_credit_apps = $inv_credit_apps_stmt->fetchAll(PDO::FETCH_ASSOC);
    $inv_total_applied_here = round(array_sum(array_column($inv_credit_apps, 'applied_amount')), 2);
    $inv_outstanding_balance = round($inv_total_for_credit - $inv_total_applied_here, 2);
    if ($inv_outstanding_balance < 0) $inv_outstanding_balance = 0.0;

    // Available credit = total customer payments - total applied across ALL invoices for this customer
    $inv_available_credit = 0.0;
    if ($inv_cust_id_for_credit > 0) {
      $inv_tp_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM customer_payments WHERE customer_id = ?");
      $inv_tp_stmt->execute([$inv_cust_id_for_credit]);
      $inv_total_paid_credit = round((float)$inv_tp_stmt->fetchColumn(), 2);

      $inv_ta_stmt = $pdo->prepare("SELECT COALESCE(SUM(applied_amount), 0) AS total FROM invoice_credit_applications WHERE customer_id = ?");
      $inv_ta_stmt->execute([$inv_cust_id_for_credit]);
      $inv_total_all_applied = round((float)$inv_ta_stmt->fetchColumn(), 2);

      $inv_available_credit = round($inv_total_paid_credit - $inv_total_all_applied, 2);
      if ($inv_available_credit < 0) $inv_available_credit = 0.0;
    }

    $inv_max_apply = min($inv_available_credit, $inv_outstanding_balance);
    $inv_can_apply_credit = $inv_cust_id_for_credit > 0 && $inv_available_credit > INVOICE_BALANCE_EPSILON && $inv_outstanding_balance > INVOICE_BALANCE_EPSILON && !$invoice_is_paid;
  ?>
  <div class="card">
    <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
      <div>
        <h2 style="margin:0;">Invoice #<?= $inv_number ?><?= $inv_customer !== '' ? ' — ' . $inv_customer : '' ?></h2>
        <?php if ($inv_date !== ''): ?>
          <p class="muted" style="margin:6px 0 0;">Invoice Date: <?= $inv_date ?></p>
        <?php endif; ?>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <span style="display:inline-flex; align-items:center; border-radius:999px; padding:6px 12px; font-weight:600; background:<?= $inv_paid_bg ?>; color:<?= $inv_paid_color ?>;"><?= $inv_paid_label ?></span>
        <span style="display:inline-flex; align-items:center; border-radius:999px; padding:6px 12px; font-weight:600; background:<?= h($invoice_approval_bg) ?>; color:<?= h($invoice_approval_color) ?>;">Approval: <?= h($invoice_approval_label) ?></span>
      </div>
    </div>
  </div>

  <?php if ($inv_cust_id_for_credit > 0): ?>
  <div class="card" id="inv-credit-card">
    <h3 style="margin:0 0 14px;">Customer Credit &amp; Balance</h3>
    <div style="display:flex; flex-wrap:wrap; gap:16px; margin-bottom:<?= ($inv_credit_apps || $inv_can_apply_credit) ? '16px' : '0' ?>;">
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 20px; min-width:180px;">
        <div style="font-size:0.78em; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px;">Available Customer Credit</div>
        <div style="font-size:1.35em; font-weight:700; color:<?= $inv_available_credit > INVOICE_BALANCE_EPSILON ? '#166534' : '#64748b' ?>;">$<?= h(number_format($inv_available_credit, 2)) ?></div>
      </div>
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 20px; min-width:180px;">
        <div style="font-size:0.78em; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px;">Outstanding Balance</div>
        <div style="font-size:1.35em; font-weight:700; color:<?= $inv_outstanding_balance > INVOICE_BALANCE_EPSILON ? '#991b1b' : '#166534' ?>;">$<?= h(number_format($inv_outstanding_balance, 2)) ?></div>
      </div>
    </div>

    <?php if ($inv_credit_apps): ?>
    <div style="margin-bottom:<?= $inv_can_apply_credit ? '16px' : '0' ?>;">
      <p style="font-size:0.85em; font-weight:600; color:#475569; margin:0 0 8px;">Credit Applied to This Invoice</p>
      <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.88em;">
          <thead>
            <tr style="background:#f1f5f9; text-align:left;">
              <th style="padding:6px 10px; border:1px solid #e2e8f0; white-space:nowrap;">Date</th>
              <th style="padding:6px 10px; border:1px solid #e2e8f0; text-align:right; white-space:nowrap;">Amount</th>
              <th style="padding:6px 10px; border:1px solid #e2e8f0;">Notes</th>
              <th style="padding:6px 10px; border:1px solid #e2e8f0; white-space:nowrap;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($inv_credit_apps as $app): ?>
            <?php
              $formatted_amount = '$' . number_format((float)($app['applied_amount'] ?? 0), 2);
            ?>
            <tr>
              <td style="padding:6px 10px; border:1px solid #e2e8f0; white-space:nowrap;"><?= h((string)($app['applied_date'] ?? '')) ?></td>
              <td style="padding:6px 10px; border:1px solid #e2e8f0; text-align:right; font-weight:600; white-space:nowrap;">$<?= h(number_format((float)$app['applied_amount'], 2)) ?></td>
              <td style="padding:6px 10px; border:1px solid #e2e8f0; color:#64748b;"><?= $app['notes'] !== null && $app['notes'] !== '' ? h((string)$app['notes']) : '<span class="muted">—</span>' ?></td>
              <td style="padding:6px 10px; border:1px solid #e2e8f0; white-space:nowrap;">
                <form method="post" style="margin:0;" action="" onsubmit="return confirm(<?= h(json_encode('Remove this credit application for ' . $formatted_amount . '? This will increase the invoice balance and return the amount to available customer credit.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>);">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
                  <input type="hidden" name="action" value="remove_credit_from_invoice" />
                  <input type="hidden" name="row_id" value="<?= (int)$quote_id ?>" />
                  <input type="hidden" name="credit_app_id" value="<?= (int)($app['id'] ?? 0) ?>" />
                  <button type="submit" class="btn" style="padding:4px 8px; font-size:0.8em;">Remove</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($inv_can_apply_credit): ?>
    <div>
      <button type="button" class="btn primary" id="inv-open-credit-modal">Apply Credit to this Invoice</button>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <p>Customer Email Preview — This is exactly what the customer will receive:</p>
    <iframe src="email_preview.php?id=<?= (int)$quote_id ?>&context=invoice"
        style="width:100%; height:1100px; border:1px solid #e2e8f0; border-radius:8px;"
        title="Invoice Email Preview"></iframe>
  </div>

  <div class="card">
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <a class="btn" href="invoice_tracker.php">Back to Invoices</a>
      <?php if ($quote): ?>
        <a class="btn" href="quotes.php?view=id&id=<?= (int)$quote_id ?>">Back to Quote</a>
      <?php endif; ?>
      <a class="btn primary" href="invoice_form.php?id=<?= (int)$quote_id ?>">Edit Invoice</a>
      <?php if (trim((string)($quote['email'] ?? '')) !== ''): ?>
        <form method="post" style="margin:0;" action="" onsubmit="return confirm('Are you sure you want to email this invoice to <?= addslashes(h((string)($quote["customer_name"] ?? ""))) ?>? This cannot be undone.');">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
          <input type="hidden" name="action" value="send_email" />
          <input type="hidden" name="row_id" value="<?= (int)$quote_id ?>" />
          <button type="submit" class="btn">Email Invoice to Customer</button>
        </form>
      <?php endif; ?>

      <form method="post" style="margin:0;" action="" onsubmit="return confirm('Send a copy of this invoice to yourself?');">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
        <input type="hidden" name="action" value="send_email_myself" />
        <input type="hidden" name="row_id" value="<?= (int)$quote_id ?>" />
        <button type="submit" class="btn">Email Invoice to Myself</button>
      </form>

      <form method="post" style="margin:0;" action="" onsubmit="return confirm('Send a copy of this invoice to admin (Zeke)?');">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
        <input type="hidden" name="action" value="send_email_admin" />
        <input type="hidden" name="row_id" value="<?= (int)$quote_id ?>" />
        <button type="submit" class="btn">Email Invoice to Admin</button>
      </form>

      <?php if (!$invoice_is_paid): ?>
        <form method="post" style="margin:0;" action="">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
          <input type="hidden" name="action" value="mark_as_paid" />
          <input type="hidden" name="row_id" value="<?= (int)$quote_id ?>" />
          <button type="submit" class="btn invoice-mark-paid-btn">Mark as Paid</button>
        </form>
      <?php endif; ?>
      <?php if (is_admin() && $invoice_approval_status !== 'approved'): ?>
        <form method="post" style="margin:0;" action="">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
          <input type="hidden" name="action" value="approve_invoice" />
          <input type="hidden" name="row_id" value="<?= (int)$quote_id ?>" />
          <button type="submit" class="btn primary">Approve</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($inv_can_apply_credit): ?>
  <!-- Apply Credit Modal -->
  <div id="inv-credit-modal" role="dialog" aria-modal="true" aria-labelledby="inv-credit-modal-title" style="position:fixed;inset:0;z-index:9000;display:none;">
    <div id="inv-credit-modal-backdrop" style="position:absolute;inset:0;background:rgba(15,23,42,0.72);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);"></div>
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,calc(100vw - 32px));background:#fff;border-radius:16px;box-shadow:0 32px 80px rgba(0,0,0,.4),0 0 0 1px rgba(0,0,0,.08);overflow:hidden;">
      <div style="padding:20px 24px 14px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:12px;">
        <span aria-hidden="true" style="font-size:1.3em;">💳</span>
        <h2 id="inv-credit-modal-title" style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0;">Apply Credit to Invoice</h2>
        <button type="button" id="inv-credit-modal-close" aria-label="Close" style="margin-left:auto;width:30px;height:30px;border:none;border-radius:50%;background:#f1f5f9;color:#64748b;font-size:18px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;">&times;</button>
      </div>
      <form method="post" action="">
        <div style="padding:20px 24px;">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
          <input type="hidden" name="action" value="apply_credit_to_invoice" />
          <input type="hidden" name="row_id" value="<?= (int)$quote_id ?>" />
          <div style="display:grid;gap:12px;">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;font-size:0.88em;color:#166534;">
              <strong>Available Credit:</strong> $<?= h(number_format($inv_available_credit, 2)) ?> &nbsp;|&nbsp;
              <strong>Outstanding Balance:</strong> $<?= h(number_format($inv_outstanding_balance, 2)) ?>
            </div>
            <div>
              <label for="inv-credit-amount" style="display:block;font-size:0.88em;font-weight:600;margin-bottom:4px;">Amount to Apply ($)</label>
              <input id="inv-credit-amount" type="number" name="apply_amount" min="0.01" max="<?= h(number_format($inv_max_apply, 2, '.', '')) ?>" step="0.01" value="<?= h(number_format($inv_max_apply, 2, '.', '')) ?>" style="width:100%;box-sizing:border-box;" required />
              <p style="font-size:0.8em;color:#64748b;margin:4px 0 0;">Maximum: $<?= h(number_format($inv_max_apply, 2)) ?></p>
            </div>
            <div>
              <label for="inv-credit-notes" style="display:block;font-size:0.88em;font-weight:600;margin-bottom:4px;">Notes <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
              <textarea id="inv-credit-notes" name="apply_notes" rows="2" style="width:100%;box-sizing:border-box;resize:vertical;" placeholder="e.g. Credit from overpayment on payment #42"></textarea>
            </div>
          </div>
        </div>
        <div style="padding:14px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:8px;">
          <button type="button" id="inv-credit-modal-cancel" class="btn">Cancel</button>
          <button type="submit" class="btn primary">Apply Credit</button>
        </div>
      </form>
    </div>
  </div>
  <script>
  (function() {
    var modal = document.getElementById('inv-credit-modal');
    var openBtn = document.getElementById('inv-open-credit-modal');
    var closeBtn = document.getElementById('inv-credit-modal-close');
    var cancelBtn = document.getElementById('inv-credit-modal-cancel');
    var backdrop = document.getElementById('inv-credit-modal-backdrop');
    function openModal() { modal.style.display = 'block'; document.getElementById('inv-credit-amount').focus(); }
    function closeModal() { modal.style.display = 'none'; }
    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', function(e) { if (e.target === backdrop) closeModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && modal.style.display === 'block') closeModal(); });
  })();
  </script>
  <?php endif; ?>

<?php else: ?>
<div class="card">

  <?php if (!$is_view_mode): ?>
  <form method="post" action="">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['invoice_form_csrf']) ?>" />
    <input type="hidden" name="source_quote_id" value="<?= h($fields['source_quote_id']) ?>" />

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

    <?php if (!$is_view_mode): ?>
    <div style="display:grid; gap:14px; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); margin-top:16px;">
      <div style="position:relative;">
        <label for="customer_name">Customer Name</label>
        <input id="customer_name" type="text" name="customer_name" maxlength="255" autocomplete="off" value="<?= h($fields['customer_name']) ?>" />
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
    </div>

    <div style="display:grid; gap:14px; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); margin-top:16px;">
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
    <?php endif; ?>

    <?php if ($is_view_mode && $quote): ?>
    <?php
      $inv_sender = invoice_sender_profile($pdo, isset($quote['created_by']) && $quote['created_by'] !== null ? (int)$quote['created_by'] : null);
      $inv_sender_company = $inv_sender['company_name'] !== '' ? $inv_sender['company_name'] : ($inv_sender['sender_name'] !== '' ? $inv_sender['sender_name'] : 'Our Company');
      $inv_bill_street = $fields['billing_street'];
      $inv_bill_city   = $fields['billing_city'];
      $inv_bill_state  = $fields['billing_state'];
      $inv_bill_zip    = $fields['billing_zip'];
      $inv_addr_csz_parts = array_filter([$inv_bill_city, $inv_bill_state . ($inv_bill_zip !== '' ? ' ' . $inv_bill_zip : '')]);
      $inv_city_state_zip = implode(', ', $inv_addr_csz_parts);
      $inv_sender_addr_lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $inv_sender['address'])));
    ?>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:20px; flex-wrap:wrap;">
      <div style="border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; background:#f8fafc;">
        <p style="margin:0 0 8px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:#64748b;">Bill To</p>
        <?php if ($fields['company_name'] !== ''): ?>
          <p style="margin:0 0 2px; font-weight:700; color:#0f172a;"><?= h($fields['company_name']) ?></p>
        <?php endif; ?>
        <?php if ($fields['customer_name'] !== ''): ?>
          <p style="margin:0 0 2px; color:#1e293b;"><?= h($fields['customer_name']) ?></p>
        <?php endif; ?>
        <?php if ($inv_bill_street !== ''): ?>
          <p style="margin:0 0 2px; color:#475569;"><?= h($inv_bill_street) ?></p>
        <?php endif; ?>
        <?php if ($inv_city_state_zip !== ''): ?>
          <p style="margin:0 0 2px; color:#475569;"><?= h($inv_city_state_zip) ?></p>
        <?php endif; ?>
        <?php if ($fields['phone_number'] !== ''): ?>
          <p style="margin:4px 0 0; color:#64748b; font-size:13px;"><?= h($fields['phone_number']) ?></p>
        <?php endif; ?>
        <?php if ($fields['email'] !== ''): ?>
          <p style="margin:0; color:#64748b; font-size:13px;"><?= h($fields['email']) ?></p>
        <?php endif; ?>
      </div>
      <div style="border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; background:#f8fafc;">
        <p style="margin:0 0 8px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:#64748b;">From</p>
        <p style="margin:0 0 2px; font-weight:700; color:#0f172a;"><?= h($inv_sender_company) ?></p>
        <?php if ($inv_sender['sender_name'] !== '' && $inv_sender['sender_name'] !== $inv_sender_company): ?>
          <p style="margin:0 0 2px; color:#1e293b;"><?= h($inv_sender['sender_name']) ?></p>
        <?php endif; ?>
        <?php foreach ($inv_sender_addr_lines as $addr_line): ?>
          <p style="margin:0 0 2px; color:#475569;"><?= h($addr_line) ?></p>
        <?php endforeach; ?>
        <?php if ($inv_sender['phone'] !== ''): ?>
          <p style="margin:4px 0 0; color:#64748b; font-size:13px;"><?= h($inv_sender['phone']) ?></p>
        <?php endif; ?>
        <?php if ($inv_sender['email'] !== ''): ?>
          <p style="margin:0; color:#64748b; font-size:13px;"><?= h($inv_sender['email']) ?></p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

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
              <td style="text-align:center;">
                <?php if (!$is_view_mode): ?>
                  <input type="hidden" class="taxable-hidden" name="item_taxable[]" value="0" />
                  <input type="checkbox" class="taxable-check" style="width:18px;height:18px;cursor:pointer;" />
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
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
        <table style="min-width:1000px;" id="inventoryItemsTable">
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
                <td style="text-align:center;">
                  <?php if (!$is_view_mode): ?>
                    <input type="hidden" class="taxable-hidden" name="item_taxable[]" value="<?= (int)($row['is_taxable'] ?? 0) === 1 ? '1' : '0' ?>" />
                    <input type="checkbox" class="taxable-check" style="width:18px;height:18px;cursor:pointer;"<?= (int)($row['is_taxable'] ?? 0) === 1 ? ' checked' : '' ?> />
                  <?php else: ?>
                    <?= (int)($row['is_taxable'] ?? 0) === 1 ? '<span style="color:#166534;font-weight:600;">Yes</span>' : '<span class="muted">No</span>' ?>
                  <?php endif; ?>
                </td>
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

    <div style="margin-top:14px; display:flex; justify-content:flex-end; align-items:flex-start; gap:14px; flex-wrap:wrap;">
      <?php if (!$is_view_mode): ?>
        <div style="text-align:right;">
          <label for="tax_rate" style="display:block; margin-bottom:4px; font-weight:600;">Tax Rate (%)</label>
          <input id="tax_rate" type="number" name="tax_rate" step="0.01" min="0" max="100" value="<?= h($fields['tax_rate']) ?>" style="width:120px; text-align:right;" />
        </div>
      <?php endif; ?>
      <div style="font-size:1.05em; padding-top:<?= $is_view_mode ? '0' : '28' ?>px; line-height:1.8;">
        <div>Subtotal: $<span id="invoiceSubtotalDisplay">0.00</span></div>
        <div id="invoiceTaxRow" style="display:none;">Tax (<span id="invoiceTaxRateDisplay">0.00</span>%): $<span id="invoiceTaxAmount">0.00</span></div>
        <div><strong>Grand Total: $<span id="invoiceSubtotal">0.00</span></strong></div>
      </div>
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
        <?php endif; ?>
      </div>
      <label for="notes">Notes</label>
      <textarea id="notes" name="notes" rows="5"<?= invoice_field_lock_attrs($is_view_mode) ?>><?= h($fields['notes']) ?></textarea>
    </div>

    <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
      <?php if (!$is_view_mode): ?>
        <button type="submit" class="btn primary" style="font-size:18px; padding:14px 22px;">Save Invoice</button>
      <?php endif; ?>
      <a class="btn" href="invoice_tracker.php">Back to Invoices</a>
      <?php if ($quote): ?>
        <a class="btn" href="quotes.php?view=id&id=<?= (int)$quote_id ?>">Back to Quote</a>
      <?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$is_view_mode): ?>
<script>
(() => {
  const csrfToken = '<?= h($_SESSION['invoice_form_csrf']) ?>';

  // ── Customer live search ──────────────────────────────────────────
  const customerNameInput = document.getElementById('customer_name');
  const companyInput      = document.getElementById('company_name');
  const phoneInput        = document.getElementById('phone_number');
  const emailInput        = document.getElementById('email');
  const streetInput       = document.getElementById('billing_street');
  const cityInput         = document.getElementById('billing_city');
  const stateInput        = document.getElementById('billing_state');
  const zipInput          = document.getElementById('billing_zip');
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
  const subtotalDisplayNode = document.getElementById('invoiceSubtotalDisplay');
  const taxRateInput = document.getElementById('tax_rate');
  const taxRowNode = document.getElementById('invoiceTaxRow');
  const taxRateDisplayNode = document.getElementById('invoiceTaxRateDisplay');
  const taxAmountNode = document.getElementById('invoiceTaxAmount');
  const grandTotalNode    = document.getElementById('invoiceSubtotal');

  function lineTotalForRow(row, selector) {
    const raw = row?.dataset?.lineTotal;
    if (raw !== undefined) return parseNum(raw);
    const text = row?.querySelector(selector)?.textContent ?? '';
    return parseNum(text.replace(/[^0-9.-]/g, ''));
  }

  function taxableSubtotal() {
    let total = 0;
    document.querySelectorAll('#laborItemsBody tr.labor-row, #inventoryItemsBody tr.inv-row').forEach((row) => {
      const isTaxable = parseNum(row.querySelector('.taxable-hidden')?.value) === 1;
      if (!isTaxable) return;
      const selector = row.classList.contains('labor-row') ? '.labor-line-total' : '.inv-line-total';
      total += lineTotalForRow(row, selector);
    });
    return total;
  }

  function updateGrandTotal() {
    const subtotal = parseNum(laborSubtotalNode.textContent) + parseNum(partsSubtotalNode.textContent);
    const taxRate = Math.max(0, Math.min(100, parseNum(taxRateInput?.value)));
    const taxAmount = taxableSubtotal() * taxRate / 100;
    if (subtotalDisplayNode) subtotalDisplayNode.textContent = subtotal.toFixed(2);
    if (taxRateDisplayNode) taxRateDisplayNode.textContent = taxRate.toFixed(2);
    if (taxAmountNode) taxAmountNode.textContent = taxAmount.toFixed(2);
    if (taxRowNode) taxRowNode.style.display = taxRate > 0 && taxAmount > 0 ? '' : 'none';
    grandTotalNode.textContent = (subtotal + taxAmount).toFixed(2);
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
      row.dataset.lineTotal = lineTotal.toFixed(2);
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
    const taxableHidden = row.querySelector('.taxable-hidden');
    const taxableCheck  = row.querySelector('.taxable-check');
    if (taxableHidden && taxableCheck) {
      const syncTaxable = () => {
        taxableHidden.value = taxableCheck.checked ? '1' : '0';
        updateGrandTotal();
      };
      taxableCheck.checked = parseNum(taxableHidden.value) === 1;
      taxableCheck.addEventListener('change', syncTaxable);
      taxableCheck.addEventListener('input', syncTaxable);
    }
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
        + '<td style="text-align:center;"><input type="hidden" class="taxable-hidden" name="item_taxable[]" value="0" /><input type="checkbox" class="taxable-check" style="width:18px;height:18px;cursor:pointer;" /></td>'
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
      row.dataset.lineTotal = lineTotal.toFixed(2);
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
    const taxableHidden = row.querySelector('.taxable-hidden');
    const taxableCheck  = row.querySelector('.taxable-check');
    if (taxableHidden && taxableCheck) {
      const syncTaxable = () => {
        taxableHidden.value = taxableCheck.checked ? '1' : '0';
        updateGrandTotal();
      };
      taxableCheck.checked = parseNum(taxableHidden.value) === 1;
      taxableCheck.addEventListener('change', syncTaxable);
      taxableCheck.addEventListener('input', syncTaxable);
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
        + '<td style="text-align:center;"><input type="hidden" class="taxable-hidden" name="item_taxable[]" value="0" /><input type="checkbox" class="taxable-check" style="width:18px;height:18px;cursor:pointer;" /></td>'
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
  taxRateInput?.addEventListener('input', updateGrandTotal);
  taxRateInput?.addEventListener('change', updateGrandTotal);
  computeLaborTotals();
  computeInvTotals();
})();
</script>
<?php else: ?>
<script>
(() => {
  // View mode: compute totals from rendered cell text
  const taxRate = <?= json_encode((float)($quote['tax_rate'] ?? 0)) ?>;
  const taxAmount = <?= json_encode(round((float)($quote['tax_amount'] ?? 0), 2)) ?>;
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
  const subtotalNode = document.getElementById('invoiceSubtotalDisplay');
  const taxRowNode = document.getElementById('invoiceTaxRow');
  const taxRateNode = document.getElementById('invoiceTaxRateDisplay');
  const taxAmountNode = document.getElementById('invoiceTaxAmount');
  const grandNode = document.getElementById('invoiceSubtotal');
  const subtotal = laborTotal + partsTotal;
  if (laborNode) laborNode.textContent = laborTotal.toFixed(2);
  if (partsNode) partsNode.textContent = partsTotal.toFixed(2);
  if (subtotalNode) subtotalNode.textContent = subtotal.toFixed(2);
  if (taxRateNode) taxRateNode.textContent = taxRate.toFixed(2);
  if (taxAmountNode) taxAmountNode.textContent = taxAmount.toFixed(2);
  if (taxRowNode) taxRowNode.style.display = (taxRate > 0 && taxAmount > 0) ? '' : 'none';
  if (grandNode) grandNode.textContent = (subtotal + taxAmount).toFixed(2);
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
