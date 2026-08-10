<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const EXPENSE_UPLOAD_MAX_BYTES = 20 * 1024 * 1024;
const EXPENSES_LIST_LIMIT = 300;
const EXPENSE_ATTACHMENTS_PREVIEW_LIMIT = 4;
const EXPENSE_UPLOAD_ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip'];
const EXPENSE_UPLOAD_ALLOWED_MIMES = [
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.ms-excel',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'text/csv',
  'text/plain',
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp',
  'application/zip',
];

function expenses_escape_like(string $value): string {
  return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

function expenses_sort_link(string $column, string $label, string $currentSort, string $currentDir): string {
  $params = $_GET;
  $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
  $params['sort'] = $column;
  $params['dir'] = $nextDir;
  $arrow = '';
  if ($currentSort === $column) {
    $arrow = $currentDir === 'asc' ? ' ↑' : ' ↓';
  }
  return '<a href="?' . h(http_build_query($params)) . '">' . h($label . $arrow) . '</a>';
}

function expenses_is_ajax_request(): bool {
  return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function expenses_fetch_rows(PDO $pdo, array $whereParts, array $params, string $orderBy, ?int $limit = null): array {
  $stmt = $pdo->prepare(
    "SELECT e.id, e.expense_date, e.description, e.amount, e.payment_source, e.source,
            ec.name AS category_name, ec.code AS category_code, COALESCE(e.group_type, ec.group_type) AS group_type,
            COALESCE(att.attachment_count, 0) AS attachment_count,
            COALESCE(il.invoice_count, 0) AS invoice_count,
            il.invoice_labels
     FROM expenses e
     INNER JOIN expense_categories ec ON ec.id = e.category_id
     LEFT JOIN (
       SELECT expense_id, COUNT(*) AS attachment_count
       FROM expense_attachments
       GROUP BY expense_id
     ) att ON att.expense_id = e.id
     LEFT JOIN (
       SELECT eil.expense_id,
              COUNT(*) AS invoice_count,
              GROUP_CONCAT(CONCAT(eil.id, '::', q.id, '::', COALESCE(NULLIF(q.converted_invoice_no, ''), CONCAT('#', q.id))) ORDER BY q.id SEPARATOR '||') AS invoice_labels
       FROM expense_invoice_links eil
       INNER JOIN quotes q ON q.id = eil.quote_id
       GROUP BY eil.expense_id
     ) il ON il.expense_id = e.id
     WHERE " . implode(' AND ', $whereParts) . "
     ORDER BY {$orderBy}" . ($limit !== null ? ' LIMIT ' . max(1, $limit) : '')
  );

  foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
  }
  $stmt->execute();
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function expenses_load_attachments_by_expense(PDO $pdo, array $expenseIds): array {
  $attachmentsByExpense = [];
  $expenseIds = array_values(array_filter(array_map('intval', $expenseIds), static fn(int $id): bool => $id > 0));
  if (!$expenseIds) {
    return $attachmentsByExpense;
  }

  $placeholders = implode(',', array_fill(0, count($expenseIds), '?'));
  $attachmentsStmt = $pdo->prepare(
    "SELECT id, expense_id, original_name
     FROM expense_attachments
     WHERE expense_id IN ($placeholders)
     ORDER BY created_at DESC, id DESC"
  );
  $attachmentsStmt->execute($expenseIds);
  foreach ($attachmentsStmt->fetchAll(PDO::FETCH_ASSOC) as $attachment) {
    $expenseId = (int)($attachment['expense_id'] ?? 0);
    if ($expenseId <= 0) {
      continue;
    }
    if (!isset($attachmentsByExpense[$expenseId])) {
      $attachmentsByExpense[$expenseId] = [];
    }
    if (count($attachmentsByExpense[$expenseId]) >= EXPENSE_ATTACHMENTS_PREVIEW_LIMIT) {
      continue;
    }
    $attachmentsByExpense[$expenseId][] = $attachment;
  }

  return $attachmentsByExpense;
}

function expenses_render_row(array $expense, array $attachments, string $csrfToken): string {
  $expenseId = (int)($expense['id'] ?? 0);
  $groupType = (string)($expense['group_type'] ?? 'opex');
  if ($groupType === 'cogs') {
    [$groupBg, $groupFg] = ['#fee2e2', '#991b1b'];
  } elseif ($groupType === 'excluded') {
    [$groupBg, $groupFg] = ['#f3f4f6', '#374151'];
  } else {
    [$groupBg, $groupFg] = ['#dbeafe', '#1e3a8a'];
  }
  $invoiceRaw = trim((string)($expense['invoice_labels'] ?? ''));
  $invoiceLinks = $invoiceRaw !== '' ? explode('||', $invoiceRaw) : [];

  ob_start();
  ?>
  <tr id="expense-row-<?= $expenseId ?>" data-expense-id="<?= $expenseId ?>">
    <td><?= h(fmt_date_mdY((string)$expense['expense_date'])) ?></td>
    <td>
      <strong>#<?= $expenseId ?></strong><br>
      <?= h((string)$expense['description']) ?>
      <div class="muted" style="font-size:.82em;">Source: <?= h((string)$expense['source']) ?></div>
    </td>
    <td><?= h((string)$expense['category_name']) ?></td>
    <td>
      <select
        class="expenses-group-select js-expense-group-select"
        data-expense-id="<?= $expenseId ?>"
        data-group-value="<?= h($groupType) ?>"
        aria-label="Group for expense #<?= $expenseId ?>"
        style="min-width:110px;background:<?= h($groupBg) ?>;color:<?= h($groupFg) ?>;border-color:<?= h($groupFg) ?>;font-weight:600;"
      >
        <option value="opex" <?= $groupType === 'opex' ? 'selected' : '' ?>>OPEX</option>
        <option value="cogs" <?= $groupType === 'cogs' ? 'selected' : '' ?>>COGS</option>
        <option value="excluded" <?= $groupType === 'excluded' ? 'selected' : '' ?>>Excluded</option>
      </select>
    </td>
    <td><strong>$<?= h(number_format((float)$expense['amount'], 2)) ?></strong></td>
    <td>
      <strong><?= (int)$expense['attachment_count'] ?></strong>
      <?php if ((int)$expense['attachment_count'] > 0): ?>
        <div><a class="btn" href="#expense-<?= $expenseId ?>">Open</a></div>
      <?php endif; ?>
    </td>
    <td>
      <strong><?= (int)$expense['invoice_count'] ?></strong>
      <?php if ($invoiceLinks): ?>
        <div style="margin-top:6px;display:flex;flex-direction:column;gap:4px;">
          <?php foreach ($invoiceLinks as $invoiceLink): ?>
            <?php
              $parts = explode('::', $invoiceLink);
              $linkId = (int)($parts[0] ?? 0);
              $quoteId = (int)($parts[1] ?? 0);
              $label = (string)($parts[2] ?? ('#' . $quoteId));
            ?>
            <?php if ($quoteId > 0): ?>
              <div class="expenses-actions">
                <a class="btn" href="quotes.php?view=id&id=<?= $quoteId ?>"><?= h($label) ?></a>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
                  <input type="hidden" name="action" value="unlink_invoice" />
                  <input type="hidden" name="link_id" value="<?= $linkId ?>" />
                  <button type="submit" class="btn">Unlink</button>
                </form>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </td>
    <td id="expense-<?= $expenseId ?>">
      <form method="post" enctype="multipart/form-data" class="expenses-inline-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
        <input type="hidden" name="action" value="upload_attachment" />
        <input type="hidden" name="expense_id" value="<?= $expenseId ?>" />
        <input type="file" name="attachment" required />
        <button type="submit" class="btn">Upload Receipt</button>
      </form>

      <form method="post" class="expenses-inline-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
        <input type="hidden" name="action" value="link_invoice" />
        <input type="hidden" name="expense_id" value="<?= $expenseId ?>" />
        <input type="number" name="quote_id" min="1" step="1" placeholder="Invoice ID" required />
        <input type="number" name="allocated_amount" min="0" step="0.01" placeholder="Allocated $ (optional)" />
        <button type="submit" class="btn">Link Invoice</button>
      </form>

      <?php if ($attachments): ?>
        <div class="muted" style="margin-top:6px;font-size:.82em;">Latest receipts:</div>
        <div style="display:flex;flex-direction:column;gap:4px;margin-top:4px;">
          <?php foreach ($attachments as $attachment): ?>
            <a class="btn" href="expense_attachment_file.php?id=<?= (int)$attachment['id'] ?>" target="_blank" rel="noopener noreferrer"><?= h((string)$attachment['original_name']) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div style="margin-top:10px;">
        <button
          type="button"
          class="btn js-expense-delete-btn"
          data-expense-id="<?= $expenseId ?>"
          style="color:#b91c1c;border-color:#b91c1c;"
        >Delete</button>
      </div>
    </td>
  </tr>
  <?php
  return trim((string)ob_get_clean());
}

if (empty($_SESSION['expenses_csrf'])) {
  $_SESSION['expenses_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['action'] ?? ''));
  $isAjaxGroupUpdate = $action === 'update_group' && expenses_is_ajax_request();
  $isAjaxDelete = $action === 'delete_expense' && expenses_is_ajax_request();
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['expenses_csrf']) || !hash_equals((string)$_SESSION['expenses_csrf'], $submitted_csrf)) {
    if ($isAjaxGroupUpdate || $isAjaxDelete) {
      header('Content-Type: application/json; charset=UTF-8');
      header('X-Content-Type-Options: nosniff');
      http_response_code(403);
      echo json_encode(['ok' => false, 'error' => 'Security token mismatch. Please refresh and try again.']);
      exit;
    }
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    if ($action === 'update_group' && !$isAjaxGroupUpdate) {
      $errors[] = 'Invalid group update request.';
    } elseif ($action === 'delete_expense' && !$isAjaxDelete) {
      $errors[] = 'Invalid delete request.';
    } elseif ($action !== 'update_group' && $action !== 'delete_expense') {
      $_SESSION['expenses_csrf'] = bin2hex(random_bytes(24));
    }
    if ($action === 'update_group' && $isAjaxGroupUpdate) {
      header('Content-Type: application/json; charset=UTF-8');
      header('X-Content-Type-Options: nosniff');

      $expenseId = (int)($_POST['expense_id'] ?? 0);
      $groupType = strtolower(trim((string)($_POST['group_type'] ?? '')));

      if ($expenseId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid expense selected.']);
        exit;
      }
      if (!in_array($groupType, ['opex', 'cogs', 'excluded'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid group selected.']);
        exit;
      }

      try {
        $expenseStmt = $pdo->prepare("SELECT id, category_id FROM expenses WHERE id = ? LIMIT 1");
        $expenseStmt->execute([$expenseId]);
        $expenseRow = $expenseStmt->fetch(PDO::FETCH_ASSOC);
        if (!$expenseRow) {
          http_response_code(404);
          echo json_encode(['ok' => false, 'error' => 'Expense not found.']);
          exit;
        }

        $updateStmt = $pdo->prepare("UPDATE expenses SET group_type = ? WHERE id = ?");
        $updateStmt->execute([$groupType, $expenseId]);

        $_SESSION['expenses_csrf'] = bin2hex(random_bytes(24));

        $rows = expenses_fetch_rows($pdo, ['e.id = :expense_id'], [':expense_id' => $expenseId], 'e.id DESC', 1);
        if (!$rows) {
          http_response_code(404);
          echo json_encode(['ok' => false, 'error' => 'Expense not found.']);
          exit;
        }

        $attachmentsByExpense = expenses_load_attachments_by_expense($pdo, [$expenseId]);
        echo json_encode([
          'ok' => true,
          'new_csrf' => (string)$_SESSION['expenses_csrf'],
          'rowHtml' => expenses_render_row($rows[0], $attachmentsByExpense[$expenseId] ?? [], (string)$_SESSION['expenses_csrf']),
        ]);
        exit;
      } catch (Throwable $e) {
        error_log('Expense group update failed for expense ' . $expenseId . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to update the group right now.']);
        exit;
      }
    } elseif ($action === 'upload_attachment') {
      $expenseId = (int)($_POST['expense_id'] ?? 0);
      if ($expenseId <= 0) {
        $errors[] = 'Invalid expense selected for attachment upload.';
      } elseif (!isset($_FILES['attachment']) || !is_array($_FILES['attachment'])) {
        $errors[] = 'Please choose a file to upload.';
      } else {
        $expenseCheck = $pdo->prepare("SELECT id FROM expenses WHERE id = ? LIMIT 1");
        $expenseCheck->execute([$expenseId]);
        if (!$expenseCheck->fetch()) {
          $errors[] = 'Expense not found.';
        } else {
          $file = $_FILES['attachment'];
          $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
          if ($errorCode !== UPLOAD_ERR_OK) {
            $errors[] = 'Attachment upload failed (code ' . $errorCode . ').';
          } else {
            $tmpPath = (string)($file['tmp_name'] ?? '');
            $sizeBytes = (int)($file['size'] ?? 0);
            $originalName = trim((string)($file['name'] ?? 'attachment'));

            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
              $errors[] = 'Invalid uploaded file.';
            } elseif ($sizeBytes > EXPENSE_UPLOAD_MAX_BYTES) {
              $errors[] = 'Attachment exceeds maximum size of ' . (EXPENSE_UPLOAD_MAX_BYTES / 1024 / 1024) . ' MB.';
            } else {
              $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
              if ($ext === '' || !in_array($ext, EXPENSE_UPLOAD_ALLOWED_EXTENSIONS, true)) {
                $errors[] = 'File extension not allowed.';
              } else {
                $mime = null;
                if (function_exists('finfo_open')) {
                  $fi = finfo_open(FILEINFO_MIME_TYPE);
                  if ($fi) {
                    $mime = finfo_file($fi, $tmpPath) ?: null;
                    finfo_close($fi);
                  }
                }

                if ($mime === null) {
                  $errors[] = 'Could not validate attachment content type.';
                } elseif (!in_array($mime, EXPENSE_UPLOAD_ALLOWED_MIMES, true)) {
                  $errors[] = 'File content type not allowed.';
                } else {
                  $uploadsDir = __DIR__ . '/uploads';
                  if (!is_dir($uploadsDir)) {
                    @mkdir($uploadsDir, 0755, true);
                  }
                  if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
                    $errors[] = 'uploads/ directory is missing or not writable.';
                  } else {
                    $storedName = 'exp' . $expenseId . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
                    $destPath = $uploadsDir . '/' . $storedName;
                    if (!move_uploaded_file($tmpPath, $destPath)) {
                      $errors[] = 'Failed to move uploaded attachment.';
                    } else {
                      $stmt = $pdo->prepare(
                        "INSERT INTO expense_attachments (expense_id, original_name, stored_name, mime_type, size_bytes)
                         VALUES (?, ?, ?, ?, ?)"
                      );
                      $stmt->execute([$expenseId, $originalName !== '' ? $originalName : 'attachment', $storedName, $mime, $sizeBytes]);
                      $success = 'Attachment uploaded successfully.';
                    }
                  }
                }
              }
            }
          }
        }
      }
    } elseif ($action === 'link_invoice') {
      $expenseId = (int)($_POST['expense_id'] ?? 0);
      $quoteId = (int)($_POST['quote_id'] ?? 0);
      $allocatedAmountRaw = trim((string)($_POST['allocated_amount'] ?? ''));
      $allocatedAmount = null;
      if ($allocatedAmountRaw !== '') {
        if (!is_numeric($allocatedAmountRaw) || (float)$allocatedAmountRaw < 0) {
          $errors[] = 'Allocated amount must be blank or a non-negative number.';
        } else {
          $allocatedAmount = expense_amount_string((float)$allocatedAmountRaw);
        }
      }

      if ($expenseId <= 0 || $quoteId <= 0) {
        $errors[] = 'Expense ID and Invoice ID are required.';
      } else {
        $expenseCheck = $pdo->prepare("SELECT id FROM expenses WHERE id = ? LIMIT 1");
        $expenseCheck->execute([$expenseId]);
        $quoteCheck = $pdo->prepare("SELECT id FROM quotes WHERE id = ? LIMIT 1");
        $quoteCheck->execute([$quoteId]);

        if (!$expenseCheck->fetch()) {
          $errors[] = 'Expense not found.';
        } elseif (!$quoteCheck->fetch()) {
          $errors[] = 'Invoice (quote) not found.';
        } else {
          $stmt = $pdo->prepare(
            "INSERT INTO expense_invoice_links (expense_id, quote_id, allocated_amount)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE allocated_amount = VALUES(allocated_amount)"
          );
          $stmt->execute([$expenseId, $quoteId, $allocatedAmount]);
          $success = 'Invoice link saved.';
        }
      }
    } elseif ($action === 'unlink_invoice') {
      $linkId = (int)($_POST['link_id'] ?? 0);
      if ($linkId <= 0) {
        $errors[] = 'Invalid link selected.';
      } else {
        $pdo->prepare("DELETE FROM expense_invoice_links WHERE id = ?")->execute([$linkId]);
        $success = 'Invoice link removed.';
      }
    } elseif ($action === 'delete_expense' && $isAjaxDelete) {
      header('Content-Type: application/json; charset=UTF-8');
      header('X-Content-Type-Options: nosniff');

      $expenseId = (int)($_POST['expense_id'] ?? 0);

      if ($expenseId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid expense selected.']);
        exit;
      }

      try {
        $expenseCheck = $pdo->prepare("SELECT id FROM expenses WHERE id = ? LIMIT 1");
        $expenseCheck->execute([$expenseId]);
        if (!$expenseCheck->fetch()) {
          http_response_code(404);
          echo json_encode(['ok' => false, 'error' => 'Expense not found.']);
          exit;
        }

        db_delete_expense($pdo, $expenseId);

        $_SESSION['expenses_csrf'] = bin2hex(random_bytes(24));

        echo json_encode(['ok' => true, 'new_csrf' => (string)$_SESSION['expenses_csrf']]);
        exit;
      } catch (Throwable $e) {
        error_log('Expense delete failed for expense ' . $expenseId . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to delete the expense right now.']);
        exit;
      }
    }
  }

  if (empty($errors) && $success !== '') {
    $query = $_GET ? http_build_query($_GET) : '';
    $redirect = 'expenses.php?saved=1';
    if ($query !== '') {
      $redirect .= '&' . $query;
    }
    header('Location: ' . $redirect);
    exit;
  }
}

if (($_GET['saved'] ?? '') === '1' && !$errors) {
  $success = 'Changes saved.';
}

$search = trim((string)($_GET['q'] ?? ''));
$categoryFilter = trim((string)($_GET['category_id'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$minAmountRaw = trim((string)($_GET['min_amount'] ?? ''));
$maxAmountRaw = trim((string)($_GET['max_amount'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'date'));
$dir = strtolower(trim((string)($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';

$categoryOptions = $pdo->query(
  "SELECT id, code, name, group_type
   FROM expense_categories
   WHERE is_active = 1
   ORDER BY sort_order ASC, name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$categoryOptionIds = [];
foreach ($categoryOptions as $cat) {
  $categoryOptionIds[(int)$cat['id']] = true;
}

$sortMap = [
  'date' => 'e.expense_date',
  'description' => 'e.description',
  'category' => 'ec.name',
  'group' => 'COALESCE(e.group_type, ec.group_type)',
  'amount' => 'e.amount',
];
if (!isset($sortMap[$sort])) {
  $sort = 'date';
}

$where = ['1=1'];
$params = [];

if ($search !== '') {
  $where[] = "(e.description LIKE :q ESCAPE '\\\\'
               OR COALESCE(ec.name, '') LIKE :q ESCAPE '\\\\')";
  $params[':q'] = '%' . expenses_escape_like($search) . '%';
}

$categoryIdInt = (int)$categoryFilter;
if ($categoryFilter !== '' && isset($categoryOptionIds[$categoryIdInt])) {
  $where[] = 'e.category_id = :category_id';
  $params[':category_id'] = $categoryIdInt;
}

if ($dateFrom !== '') {
  $where[] = 'e.expense_date >= :date_from';
  $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
  $where[] = 'e.expense_date <= :date_to';
  $params[':date_to'] = $dateTo;
}
if ($minAmountRaw !== '' && is_numeric($minAmountRaw)) {
  $where[] = 'e.amount >= :min_amount';
  $params[':min_amount'] = expense_amount_string((float)$minAmountRaw);
}
if ($maxAmountRaw !== '' && is_numeric($maxAmountRaw)) {
  $where[] = 'e.amount <= :max_amount';
  $params[':max_amount'] = expense_amount_string((float)$maxAmountRaw);
}

$expenses = expenses_fetch_rows($pdo, $where, $params, "{$sortMap[$sort]} {$dir}, e.id DESC", EXPENSES_LIST_LIMIT);
$limitHit = count($expenses) >= EXPENSES_LIST_LIMIT;

$expenseIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $expenses);
$attachmentsByExpense = expenses_load_attachments_by_expense($pdo, $expenseIds);

$heroTotal = count($expenses);
$heroAmount = 0.0;
$heroCogs = 0.0;
$heroOpex = 0.0;
foreach ($expenses as $expense) {
  $amount = (float)($expense['amount'] ?? 0);
  $heroAmount += $amount;
  if (($expense['group_type'] ?? '') === 'cogs') {
    $heroCogs += $amount;
  } else {
    $heroOpex += $amount;
  }
}

render_header('Expenses');
?>

<?php foreach ($errors as $err): ?>
  <div class="alert" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;"><?= h($err) ?></div>
<?php endforeach; ?>

<?php if ($success !== ''): ?>
  <div class="alert" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534;"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($limitHit): ?>
  <div class="alert" style="border-color:#bfdbfe;background:#eff6ff;color:#1e40af;">Showing the first <?= (int)EXPENSES_LIST_LIMIT ?> rows for this filter.</div>
<?php endif; ?>

<div class="card laser-rfq-hero page-header">
  <div class="laser-rfq-hero-beams" aria-hidden="true">
    <span class="laser-rfq-beam laser-rfq-beam-1"></span>
    <span class="laser-rfq-beam laser-rfq-beam-2"></span>
    <span class="laser-rfq-beam laser-rfq-beam-3"></span>
  </div>
  <div class="laser-rfq-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body laser-rfq-hero-body">
    <span class="laser-rfq-hero-tag">Accounting Ledger</span>
    <h1>Expenses <span class="laser-rfq-hero-count">(<?= (int)$heroTotal ?>)</span></h1>
    <p class="muted">Review, categorize, and link expense records to receipts and invoices for tax filing support.</p>
    <ul class="laser-rfq-hero-pills" aria-label="Expense highlights">
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🔎</span> Search &amp; filter</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🧾</span> Receipt attachments</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🔗</span> Invoice links</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">📊</span> P&amp;L ready</li>
    </ul>
    <div class="laser-rfq-hero-stats" aria-label="Expense summary">
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$heroTotal ?></strong>
        <span>Visible Rows</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong>$<?= h(number_format($heroAmount, 2)) ?></strong>
        <span>Total Expenses</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong>$<?= h(number_format($heroCogs, 2)) ?></strong>
        <span>COGS</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong>$<?= h(number_format($heroOpex, 2)) ?></strong>
        <span>OpEx</span>
      </div>
    </div>
  </div>
  <div class="laser-rfq-hero-actions">
    <a class="btn primary" href="expense_import.php">Import Rocket CSV</a>
    <a class="btn" href="expense_amazon_import.php">Amazon Import</a>
    <a class="btn" href="profit_loss.php">Profit &amp; Loss</a>
  </div>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <div style="flex:1 1 260px;">
      <label for="exp_q">Search</label>
      <input id="exp_q" type="text" name="q" value="<?= h($search) ?>" placeholder="Description, category..." />
    </div>
    <div style="width:220px;">
      <label for="exp_category">Category</label>
      <select id="exp_category" name="category_id">
        <option value="">All categories</option>
        <?php foreach ($categoryOptions as $cat): ?>
          <?php $catId = (int)$cat['id']; ?>
          <option value="<?= $catId ?>" <?= $categoryIdInt === $catId ? 'selected' : '' ?>>
            <?= h((string)$cat['name']) ?> (<?= h(strtoupper((string)$cat['group_type'])) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:160px;">
      <label for="exp_date_from">From</label>
      <input id="exp_date_from" type="date" name="date_from" value="<?= h($dateFrom) ?>" />
    </div>
    <div style="width:160px;">
      <label for="exp_date_to">To</label>
      <input id="exp_date_to" type="date" name="date_to" value="<?= h($dateTo) ?>" />
    </div>
    <div style="width:140px;">
      <label for="exp_min_amount">Min Amount</label>
      <input id="exp_min_amount" type="number" step="0.01" name="min_amount" value="<?= h($minAmountRaw) ?>" />
    </div>
    <div style="width:140px;">
      <label for="exp_max_amount">Max Amount</label>
      <input id="exp_max_amount" type="number" step="0.01" name="max_amount" value="<?= h($maxAmountRaw) ?>" />
    </div>
    <div class="row">
      <button type="submit" class="btn primary">Filter</button>
      <a class="btn" href="expenses.php">Clear</a>
    </div>
  </form>
</div>

<style>
.expenses-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
.expenses-inline-form{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:6px;}
.expenses-inline-form input[type="number"],
.expenses-inline-form input[type="text"],
.expenses-inline-form input[type="file"]{max-width:180px;}
.expenses-pill{display:inline-flex;align-items:center;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:600;white-space:nowrap;}
.expenses-group-select{padding:4px 10px;border-radius:999px;}
.expenses-row-saving{opacity:.65;}
</style>

<div class="card">
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:1320px;">
      <colgroup>
        <col />
        <col style="width:180px;" />
      </colgroup>
      <thead>
        <tr>
          <th><?= expenses_sort_link('date', 'Date', $sort, $dir) ?></th>
          <th><?= expenses_sort_link('description', 'Description', $sort, $dir) ?></th>
          <th><?= expenses_sort_link('category', 'Category', $sort, $dir) ?></th>
          <th><?= expenses_sort_link('group', 'Group', $sort, $dir) ?></th>
          <th><?= expenses_sort_link('amount', 'Amount', $sort, $dir) ?></th>
          <th>Attachments</th>
          <th>Linked Invoices</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody data-expenses-table-body>
        <?php if (!$expenses): ?>
          <tr><td colspan="8" class="muted">No expenses found for the current filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($expenses as $expense): ?>
          <?= expenses_render_row($expense, $attachmentsByExpense[(int)($expense['id'] ?? 0)] ?? [], (string)$_SESSION['expenses_csrf']) ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(() => {
  const tableBody = document.querySelector('[data-expenses-table-body]');
  if (!tableBody) return;

  tableBody.addEventListener('change', async (event) => {
    const select = event.target.closest('.js-expense-group-select');
    if (!select || select.dataset.saving === '1') return;
    if (tableBody.dataset.groupUpdateInFlight === '1') {
      select.value = select.dataset.groupValue || select.value;
      alert('Please wait for the current group update to finish.');
      return;
    }

    const expenseId = parseInt(select.dataset.expenseId || '0', 10);
    const previousValue = select.dataset.groupValue || '';
    const nextValue = select.value;
    if (!expenseId || !nextValue || nextValue === previousValue) return;

    const row = select.closest('tr');
    const csrfInput = row.querySelector('input[name="csrf_token"]');
    if (!row || !csrfInput) return;

    select.dataset.saving = '1';
    tableBody.dataset.groupUpdateInFlight = '1';
    select.disabled = true;
    row.classList.add('expenses-row-saving');

    try {
      const body = new URLSearchParams({
        action: 'update_group',
        csrf_token: csrfInput.value,
        expense_id: String(expenseId),
        group_type: nextValue
      });

      const response = await fetch('expenses.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body
      });
      const payload = await response.json();

      if (!response.ok || !payload || !payload.ok || typeof payload.rowHtml !== 'string') {
        throw new Error((payload && payload.error) || 'Unable to update the group.');
      }

      if (typeof payload.new_csrf === 'string' && payload.new_csrf !== '') {
        document.querySelectorAll('input[name="csrf_token"]').forEach((input) => {
          input.value = payload.new_csrf;
        });
      }

      const template = document.createElement('template');
      template.innerHTML = payload.rowHtml.trim();
      const nextRow = template.content.firstElementChild;
      if (!nextRow) {
        throw new Error('Unable to refresh the expense row.');
      }

      row.replaceWith(nextRow);
    } catch (error) {
      select.value = previousValue;
      alert(error instanceof Error ? error.message : 'Network error. Please try again.');
    } finally {
      const activeRow = row.isConnected ? row : tableBody.querySelector(`#expense-row-${expenseId}`);
      if (activeRow) {
        activeRow.classList.remove('expenses-row-saving');
        const activeSelect = activeRow.querySelector('.js-expense-group-select');
        if (activeSelect) {
          activeSelect.disabled = false;
          delete activeSelect.dataset.saving;
        }
      }
      delete tableBody.dataset.groupUpdateInFlight;
    }
  });

  tableBody.addEventListener('click', async (event) => {
    const btn = event.target.closest('.js-expense-delete-btn');
    if (!btn || btn.dataset.deleting === '1') return;

    const expenseId = parseInt(btn.dataset.expenseId || '0', 10);
    if (!expenseId) return;

    const row = btn.closest('tr');
    const csrfInput = row && row.querySelector('input[name="csrf_token"]');
    if (!row || !csrfInput) return;

    if (!confirm(`Delete expense #${expenseId}? This action cannot be undone.`)) return;

    btn.dataset.deleting = '1';
    btn.disabled = true;
    row.classList.add('expenses-row-saving');

    try {
      const body = new URLSearchParams({
        action: 'delete_expense',
        csrf_token: csrfInput.value,
        expense_id: String(expenseId)
      });

      const response = await fetch('expenses.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body
      });
      const payload = await response.json();

      if (!response.ok || !payload || !payload.ok) {
        throw new Error((payload && payload.error) || 'Unable to delete the expense.');
      }

      if (typeof payload.new_csrf === 'string' && payload.new_csrf !== '') {
        document.querySelectorAll('input[name="csrf_token"]').forEach((input) => {
          input.value = payload.new_csrf;
        });
      }

      row.remove();
    } catch (error) {
      btn.disabled = false;
      delete btn.dataset.deleting;
      row.classList.remove('expenses-row-saving');
      alert(error instanceof Error ? error.message : 'Network error. Please try again.');
    }
  });
})();
</script>

<?php render_footer(); ?>
