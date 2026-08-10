<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

function expense_import_find_col(array $row, array $needles): ?int {
  foreach ($needles as $needle) {
    $needle = strtolower($needle);
    foreach ($row as $index => $column) {
      if (str_contains(strtolower(trim((string)$column)), $needle)) {
        return $index;
      }
    }
  }
  return null;
}

function expense_import_parse_date(string $raw): ?DateTime {
  $raw = trim($raw);
  if ($raw === '') {
    return null;
  }
  foreach (['m/d/Y', 'n/j/Y', 'm/d/y', 'n/j/y', 'Y-m-d'] as $fmt) {
    $dt = DateTime::createFromFormat($fmt, $raw);
    if ($dt instanceof DateTime) {
      return $dt;
    }
  }
  $ts = strtotime($raw);
  if ($ts === false) {
    return null;
  }
  $dt = new DateTime('@' . $ts);
  $dt->setTimezone(new DateTimeZone(APP_TZ));
  return $dt;
}

if (empty($_SESSION['expense_import_csrf'])) {
  $_SESSION['expense_import_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$summary = null;

$categoryRows = $pdo->query("SELECT id, code, name, group_type FROM expense_categories ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$categoriesByCode = [];
foreach ($categoryRows as $catRow) {
  $categoriesByCode[(string)$catRow['code']] = [
    'id' => (int)$catRow['id'],
    'group_type' => (string)($catRow['group_type'] ?? 'opex'),
  ];
}

$getCategoryMeta = function (string $categoryCode, string $categoryName) use ($pdo, &$categoriesByCode): array {
  $categoryCode = trim($categoryCode);
  if ($categoryCode === '') {
    $categoryCode = 'uncategorized';
  }

  if (isset($categoriesByCode[$categoryCode])) {
    return $categoriesByCode[$categoryCode];
  }

  $insert = $pdo->prepare(
    "INSERT INTO expense_categories (code, name, group_type, sort_order, is_active)
     VALUES (?, ?, 'opex', 999, 1)
     ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1"
  );
  $label = trim($categoryName) !== '' ? trim($categoryName) : ucwords(str_replace('_', ' ', $categoryCode));
  $insert->execute([$categoryCode, mb_substr($label, 0, 150)]);

  $fetch = $pdo->prepare("SELECT id, group_type FROM expense_categories WHERE code = ? LIMIT 1");
  $fetch->execute([$categoryCode]);
  $row = $fetch->fetch(PDO::FETCH_ASSOC) ?: [];
  $id = (int)($row['id'] ?? 0);
  if ($id <= 0) {
    throw new RuntimeException('Unable to resolve expense category ID for code: ' . $categoryCode);
  }

  $categoriesByCode[$categoryCode] = [
    'id' => $id,
    'group_type' => (string)($row['group_type'] ?? 'opex'),
  ];
  return $categoriesByCode[$categoryCode];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['expense_import_csrf']) || !hash_equals((string)$_SESSION['expense_import_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } elseif (empty($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
    $errors[] = 'Please choose a CSV file to import.';
  } else {
    $_SESSION['expense_import_csrf'] = bin2hex(random_bytes(24));
    $upload = $_FILES['csv_file'];
    $tmpName = (string)($upload['tmp_name'] ?? '');
    $originalName = trim((string)($upload['name'] ?? 'rocket-money.csv'));
    $errorCode = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
      $errors[] = 'Upload failed. Please try again with a valid CSV file.';
    } else {
      $handle = fopen($tmpName, 'rb');
      if ($handle === false) {
        $errors[] = 'Could not read the uploaded file.';
      } else {
        $dateIndex = null;
        $descriptionIndex = null;
        $merchantIndex = null;
        $amountIndex = null;
        $categoryIndex = null;
        $foundHeader = false;

        while (($headerRow = fgetcsv($handle)) !== false) {
          if (!is_array($headerRow)) {
            continue;
          }
          $d = expense_import_find_col($headerRow, ['date']);
          $desc = expense_import_find_col($headerRow, ['description', 'name']);
          $merchant = expense_import_find_col($headerRow, ['merchant']);
          $amt = expense_import_find_col($headerRow, ['amount']);
          $cat = expense_import_find_col($headerRow, ['category']);

          if ($d !== null && $amt !== null && ($desc !== null || $merchant !== null)) {
            $dateIndex = $d;
            $descriptionIndex = $desc;
            $merchantIndex = $merchant;
            $amountIndex = $amt;
            $categoryIndex = $cat;
            $foundHeader = true;
            break;
          }
        }

        if (!$foundHeader) {
          $errors[] = 'The CSV must include Date, Amount, and Description or Merchant columns.';
        } else {
          $inserted = 0;
          $duplicates = 0;
          $invalid = 0;
          $processed = 0;
          $previewRows = [];
          $invalidRows = [];

          $insertStmt = $pdo->prepare(
            "INSERT INTO expenses (
                expense_date,
                amount,
                category_id,
                group_type,
                description,
                vendor_name,
                transaction_hash,
                source,
                source_filename,
                source_line_number,
                raw_row_json,
                created_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
          );

          $lineNumber = 1;
          while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;
            if (!is_array($row)) {
              continue;
            }

            $dateRaw = trim((string)($row[$dateIndex] ?? ''));
            $description = trim((string)($row[$descriptionIndex] ?? ''));
            $merchant = $merchantIndex !== null ? trim((string)($row[$merchantIndex] ?? '')) : '';
            $amountRaw = trim((string)($row[$amountIndex] ?? ''));
            $categoryRaw = $categoryIndex !== null ? trim((string)($row[$categoryIndex] ?? '')) : '';

            if ($dateRaw === '' && $description === '' && $merchant === '' && $amountRaw === '' && $categoryRaw === '') {
              continue;
            }

            $processed++;

            $parsedDate = expense_import_parse_date($dateRaw);
            $parsedAmount = expense_parse_money($amountRaw);
            $finalDescription = $description !== '' ? $description : $merchant;

            if (!$parsedDate || $parsedAmount === null || $finalDescription === '') {
              $invalid++;
              if (count($invalidRows) < 5) {
                $invalidRows[] = 'Line ' . $lineNumber . ': invalid date, description, or amount.';
              }
              continue;
            }

            $expenseAmount = abs($parsedAmount);
            if ($expenseAmount <= 0) {
              $invalid++;
              if (count($invalidRows) < 5) {
                $invalidRows[] = 'Line ' . $lineNumber . ': amount must be greater than zero.';
              }
              continue;
            }

            $dateYmd = $parsedDate->format('Y-m-d');
            $categoryCode = expense_category_guess_code($categoryRaw, $finalDescription);
            $categoryMeta = $getCategoryMeta($categoryCode, $categoryRaw);
            $categoryId = (int)($categoryMeta['id'] ?? 0);
            $groupType = (string)($categoryMeta['group_type'] ?? 'opex');
            $hash = expense_hash($dateYmd, $finalDescription, $expenseAmount);
            $rawRowJson = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            try {
              $insertStmt->execute([
                $dateYmd,
                expense_amount_string($expenseAmount),
                $categoryId,
                $groupType,
                $finalDescription,
                $merchant !== '' ? $merchant : null,
                $hash,
                'rocket_money_csv',
                $originalName !== '' ? $originalName : null,
                $lineNumber,
                $rawRowJson,
                ((int)($_SESSION['user_id'] ?? 0)) ?: null,
              ]);

              $inserted++;
              if (count($previewRows) < 8) {
                $previewRows[] = [
                  'expense_date' => $dateYmd,
                  'description' => $finalDescription,
                  'category' => $categoryCode,
                  'amount' => $expenseAmount,
                ];
              }
            } catch (PDOException $e) {
              $isDuplicate = isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062;
              if ($isDuplicate) {
                $duplicates++;
                continue;
              }
              throw $e;
            }
          }

          $summary = [
            'file_name' => $originalName,
            'processed' => $processed,
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
            'invalid_rows' => $invalidRows,
            'preview_rows' => $previewRows,
          ];
        }

        fclose($handle);
      }
    }
  }
}

$recentStmt = $pdo->query(
  "SELECT e.id, e.expense_date, e.description, e.amount, ec.name AS category_name, e.created_at
   FROM expenses e
   INNER JOIN expense_categories ec ON ec.id = e.category_id
   ORDER BY e.created_at DESC, e.id DESC
   LIMIT 20"
);
$recentExpenses = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

render_header('Expense Import');
?>

<?php foreach ($errors as $err): ?>
  <div class="alert" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;"><?= h($err) ?></div>
<?php endforeach; ?>

<?php if ($summary): ?>
  <div class="alert" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534;">
    Imported <?= (int)$summary['inserted'] ?> new expenses from <?= h((string)$summary['file_name']) ?>.
    Skipped <?= (int)$summary['duplicates'] ?> duplicates and <?= (int)$summary['invalid'] ?> invalid row(s).
  </div>
  <?php if (!empty($summary['invalid_rows'])): ?>
    <div class="alert" style="border-color:#fde68a;background:#fffbeb;color:#92400e;">
      <?= h(implode(' ', $summary['invalid_rows'])) ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="card laser-rfq-hero page-header">
  <div class="laser-rfq-hero-beams" aria-hidden="true">
    <span class="laser-rfq-beam laser-rfq-beam-1"></span>
    <span class="laser-rfq-beam laser-rfq-beam-2"></span>
    <span class="laser-rfq-beam laser-rfq-beam-3"></span>
  </div>
  <div class="laser-rfq-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body laser-rfq-hero-body">
    <span class="laser-rfq-hero-tag">Accounting Import</span>
    <h1>Rocket Money Expense Import</h1>
    <p class="muted">Upload Rocket Money CSV exports to build a duplicate-safe expenses ledger for tax reporting.</p>
    <ul class="laser-rfq-hero-pills" aria-label="Import highlights">
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🧾</span> CSV upload</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🔁</span> Duplicate-safe</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🏷️</span> Category mapping</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">📊</span> P&amp;L ready</li>
    </ul>
  </div>
  <div class="laser-rfq-hero-actions">
    <a class="btn" href="expenses.php">View Expenses</a>
  </div>
</div>

<div class="card">
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['expense_import_csrf']) ?>" />

    <div style="grid-column:1/-1;">
      <label for="csv_file">Rocket Money CSV File</label>
      <input id="csv_file" type="file" name="csv_file" accept=".csv,text/csv" required />
      <div class="muted" style="margin-top:8px;">Expected columns include Date, Amount, Category, and Description or Merchant.</div>
    </div>

    <div style="grid-column:1/-1; display:flex; gap:8px; flex-wrap:wrap;">
      <button type="submit" class="btn primary">Import CSV</button>
      <a class="btn" href="expenses.php">Open Expenses</a>
    </div>
  </form>
</div>

<?php if ($summary && !empty($summary['preview_rows'])): ?>
  <div class="card">
    <h2 style="margin-top:0;">Newly Imported Preview</h2>
    <div class="table-wrap" style="overflow-x:auto;">
      <table class="table-auto" style="min-width:700px;">
        <thead>
          <tr>
            <th>Date</th>
            <th>Description</th>
            <th>Category</th>
            <th>Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summary['preview_rows'] as $row): ?>
            <tr>
              <td><?= h(fmt_date_mdY((string)$row['expense_date'])) ?></td>
              <td><?= h((string)$row['description']) ?></td>
              <td><?= h((string)$row['category']) ?></td>
              <td><strong>$<?= h(number_format((float)$row['amount'], 2)) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;">Recent Imported Expenses</h2>
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:760px;">
      <thead>
        <tr>
          <th>#</th>
          <th>Date</th>
          <th>Description</th>
          <th>Category</th>
          <th>Amount</th>
          <th>Imported</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$recentExpenses): ?>
          <tr><td colspan="6" class="muted">No expenses have been imported yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recentExpenses as $expense): ?>
          <tr>
            <td class="muted"><?= (int)$expense['id'] ?></td>
            <td><?= h(fmt_date_mdY((string)$expense['expense_date'])) ?></td>
            <td><?= h((string)$expense['description']) ?></td>
            <td><?= h((string)$expense['category_name']) ?></td>
            <td><strong>$<?= h(number_format((float)$expense['amount'], 2)) ?></strong></td>
            <td><?= h((string)$expense['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
