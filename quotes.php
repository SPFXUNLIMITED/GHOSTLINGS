<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
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

  $host = strtolower(trim((string)($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '')));
  if ($host === '') {
    return false;
  }

  $normalized_host = preg_replace('/:\d+$/', '', $host);
  if (is_string($normalized_host) && $normalized_host !== '') {
    $host = $normalized_host;
  }
  if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
    return true;
  }

  $is_dev_host = preg_match('/(\.local|\.test)$/', $host);
  if ($is_dev_host === false) {
    return false;
  }

  return $is_dev_host === 1;
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

function quote_send_email(array $quote, array $items): bool {
  $to = trim((string)($quote['email'] ?? ''));
  if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    return false;
  }

  $subject = 'Quote #' . (int)$quote['id'];
  $lines = [];
  foreach ($items as $item) {
    $lines[] = '- ' . (string)$item['description'] . ' | Qty: ' . quote_format_money($item['quantity']) . ' | Price: $' . quote_format_money($item['unit_price']) . ' | Total: $' . quote_format_money($item['line_total']);
  }

  $body = "Hello,\r\n\r\n"
    . "Here is your quote #" . (int)$quote['id'] . ".\r\n"
    . "Customer: " . (string)$quote['customer_name'] . "\r\n"
    . "Quote Date: " . (string)$quote['quote_date'] . "\r\n\r\n"
    . "Line Items:\r\n"
    . implode("\r\n", $lines) . "\r\n\r\n"
    . "Subtotal: $" . quote_format_money($quote['subtotal_amount']) . "\r\n\r\n"
    . "Thank you.\r\n";

  $mail_domain = quote_mail_domain();
  $headers = "From: no-reply@" . $mail_domain . "\r\n"
    . "Reply-To: no-reply@" . $mail_domain . "\r\n"
    . "X-Mailer: PHP/" . phpversion() . "\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

  $sent = mail($to, $subject, $body, $headers);
  if (!$sent) {
    error_log('Quote email send failed for quote #' . (int)($quote['id'] ?? 0) . ' to ' . $to);
  }
  return (bool)$sent;
}

function quote_send_twilio_sms_placeholder(array $quote): string {
  $phone = trim((string)($quote['phone_number'] ?? ''));
  if ($phone === '') {
    return 'SMS skipped: no customer phone number on this quote.';
  }

  // Twilio placeholder:
  // 1) Install Twilio SDK with Composer.
  // 2) Read TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM_NUMBER from env.
  // 3) Send message body such as: "Quote #123 is ready."
  // 4) Handle API errors and log failures.
  return 'Twilio SMS placeholder executed for ' . $phone . '. Integrate Twilio credentials/API to send real messages.';
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
  ['description' => '', 'quantity' => '1', 'unit_price' => '0.00'],
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

    $item_stmt = $pdo->prepare("SELECT description, quantity, unit_price FROM quote_items WHERE quote_id = ? ORDER BY line_position ASC, id ASC");
    $item_stmt->execute([$edit_id]);
    $rows = $item_stmt->fetchAll();
    if ($rows) {
      $line_items = [];
      foreach ($rows as $row) {
        $line_items[] = [
          'description' => (string)$row['description'],
          'quantity' => quote_format_money($row['quantity']),
          'unit_price' => quote_format_money($row['unit_price']),
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
    if ($action === 'convert_invoice') {
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
          header('Location: quotes.php?view=id&id=' . $row_id . '&invoice_converted=1');
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
        } elseif (!quote_send_email($quote, $items)) {
          $errors[] = 'Email was not sent. Confirm the quote email address is valid and mail is configured.';
        } else {
          $pdo->prepare("UPDATE quotes SET status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END WHERE id = ?")->execute([$row_id]);
          $_SESSION['quotes_csrf'] = bin2hex(random_bytes(24));
          header('Location: quotes.php?view=id&id=' . $row_id . '&email_sent=1');
          exit;
        }
      }
    } elseif ($action === 'send_sms') {
      $row_id = (int)($_POST['row_id'] ?? 0);
      $stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = ? LIMIT 1");
      $stmt->execute([$row_id]);
      $quote = $stmt->fetch();
      if (!$quote) {
        $errors[] = 'Quote not found.';
      } else {
        $messages[] = quote_send_twilio_sms_placeholder($quote);
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
      $posted_price = $_POST['item_price'] ?? [];
      if (!is_array($posted_desc) || !is_array($posted_qty) || !is_array($posted_price)) {
        $errors[] = 'Line item data is invalid.';
      } elseif (count($posted_desc) !== count($posted_qty) || count($posted_desc) !== count($posted_price)) {
        $errors[] = 'Line item data is malformed. Please reload and try again.';
      } else {
        $line_items = [];
        $line_count = min(count($posted_desc), count($posted_qty), count($posted_price), QUOTE_MAX_LINE_ITEMS);
        for ($i = 0; $i < $line_count; $i++) {
          $desc = trim((string)$posted_desc[$i]);
          $qty_raw = trim((string)$posted_qty[$i]);
          $price_raw = trim((string)$posted_price[$i]);

          if ($desc === '' && $qty_raw === '' && $price_raw === '') {
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
          if (!is_numeric($price_raw) || (float)$price_raw < 0) {
            $errors[] = 'Each line item price must be 0 or greater.';
            continue;
          }

          $qty = round((float)$qty_raw, 2);
          $price = round((float)$price_raw, 2);
          $line_items[] = [
            'description' => $desc,
            'quantity' => $qty,
            'unit_price' => $price,
            'line_total' => round($qty * $price, 2),
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
            quote_backfill_customer($pdo, $customer_id, $fields);

            $pdo->prepare("DELETE FROM quote_items WHERE quote_id = ?")->execute([$edit_id]);
            $quote_id = $edit_id;
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
            quote_backfill_customer($pdo, $customer_id, $fields);
            $quote_id = (int)$pdo->lastInsertId();
          }

          $item_ins = $pdo->prepare(
            "INSERT INTO quote_items (quote_id, line_position, description, quantity, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?, ?)"
          );
          $position = 1;
          foreach ($line_items as $row) {
            $item_ins->execute([
              $quote_id,
              $position,
              $row['description'],
              $row['quantity'],
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
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }
          error_log('Failed to save quote: ' . $e->getMessage());
          if ($e instanceof PDOException && quote_is_development()) {
            $errors[] = 'Database error: ' . $e->getMessage();
          } else {
            $errors[] = 'Unable to save quote right now. Please try again.';
          }
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
          <th style="width:140px;">Qty</th>
          <th style="width:160px;">Price</th>
          <th style="width:170px;">Line Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($detail_items as $idx => $item): ?>
          <tr>
            <td><?= (int)$idx + 1 ?></td>
            <td><?= h((string)$item['description']) ?></td>
            <td><?= h(quote_format_money($item['quantity'])) ?></td>
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
        <button type="submit" class="btn">Send Email</button>
      </form>

      <form method="post" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['quotes_csrf']) ?>" />
        <input type="hidden" name="action" value="send_sms" />
        <input type="hidden" name="row_id" value="<?= (int)$detail_quote['id'] ?>" />
        <button type="submit" class="btn">Send SMS (Twilio)</button>
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

    <div style="margin-top:16px; overflow-x:auto;">
      <table style="min-width:760px;" id="lineItemsTable">
        <thead>
          <tr>
            <th>Description</th>
            <th style="width:140px;">Qty</th>
            <th style="width:160px;">Price</th>
            <th style="width:170px;">Line Total</th>
            <th style="width:90px;">Remove</th>
          </tr>
        </thead>
        <tbody id="lineItemsBody">
          <?php foreach ($line_items as $row): ?>
            <tr class="line-item-row">
              <td><input type="text" name="item_desc[]" maxlength="500" value="<?= h((string)$row['description']) ?>" /></td>
              <td><input type="number" step="0.01" min="0.01" name="item_qty[]" value="<?= h((string)$row['quantity']) ?>" /></td>
              <td><input type="number" step="0.01" min="0" name="item_price[]" value="<?= h((string)$row['unit_price']) ?>" /></td>
              <td class="line-total" style="white-space:nowrap;">$0.00</td>
              <td><button type="button" class="btn remove-line">×</button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
        <button type="button" class="btn" id="addLineItem">+ Add Line Item</button>
        <div><strong>Subtotal: $<span id="quoteSubtotal">0.00</span></strong></div>
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
      const customerNameInput = document.getElementById('customer_name');
      const customerIdInput = document.getElementById('customer_id');
      const companyInput = document.getElementById('company_name');
      const phoneInput = document.getElementById('phone_number');
      const emailInput = document.getElementById('email');
      const suggestions = document.getElementById('customerSuggestions');
      let debounceTimer = null;

      function hideSuggestions() {
        suggestions.style.display = 'none';
        suggestions.innerHTML = '';
      }

      function renderSuggestions(rows) {
        suggestions.innerHTML = '';
        if (!rows.length) {
          hideSuggestions();
          return;
        }

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
            customerIdInput.value = row.id || '';
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
        customerIdInput.value = '';
        const q = customerNameInput.value.trim();
        if (debounceTimer) {
          clearTimeout(debounceTimer);
        }
        if (q.length < 1) {
          hideSuggestions();
          return;
        }

        debounceTimer = setTimeout(() => {
          const searchUrl = 'quotes.php?customer_search=1&q=' + encodeURIComponent(q);
          fetch(searchUrl, {
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': '<?= h($_SESSION['quotes_csrf']) ?>' }
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

      const lineItemsBody = document.getElementById('lineItemsBody');
      const addLineItem = document.getElementById('addLineItem');
      const subtotalNode = document.getElementById('quoteSubtotal');

      function parseNumber(value) {
        const n = parseFloat(value);
        return Number.isFinite(n) ? n : 0;
      }

      function computeTotals() {
        let subtotal = 0;
        Array.from(lineItemsBody.querySelectorAll('tr.line-item-row')).forEach((row) => {
          const qtyInput = row.querySelector('input[name="item_qty[]"]');
          const priceInput = row.querySelector('input[name="item_price[]"]');
          const lineTotalCell = row.querySelector('.line-total');
          const lineTotal = parseNumber(qtyInput?.value) * parseNumber(priceInput?.value);
          subtotal += lineTotal;
          lineTotalCell.textContent = '$' + lineTotal.toFixed(2);
        });
        subtotalNode.textContent = subtotal.toFixed(2);
      }

      function bindRow(row) {
        row.querySelectorAll('input').forEach((input) => {
          input.addEventListener('input', computeTotals);
        });

        const removeBtn = row.querySelector('.remove-line');
        removeBtn.addEventListener('click', () => {
          if (lineItemsBody.querySelectorAll('tr.line-item-row').length <= 1) {
            row.querySelector('input[name="item_desc[]"]').value = '';
            row.querySelector('input[name="item_qty[]"]').value = '1';
            row.querySelector('input[name="item_price[]"]').value = '0.00';
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
          + '<td><input type="number" step="0.01" min="0.01" name="item_qty[]" value="1" /></td>'
          + '<td><input type="number" step="0.01" min="0" name="item_price[]" value="0.00" /></td>'
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
<?php endif; ?>

<?php render_footer(); ?>
