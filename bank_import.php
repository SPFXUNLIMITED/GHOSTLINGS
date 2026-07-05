<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

const BANK_IMPORT_DUPLICATE_SQLSTATE = '23000';

if (empty($_SESSION['bank_import_csrf'])) {
  $_SESSION['bank_import_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['bank_import_csrf']) || !hash_equals((string)$_SESSION['bank_import_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } elseif (empty($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
    $errors[] = 'Please choose a CSV file to import.';
  } else {
    $_SESSION['bank_import_csrf'] = bin2hex(random_bytes(24));
    $upload = $_FILES['csv_file'];
    $tmpName = (string)($upload['tmp_name'] ?? '');
    $originalName = trim((string)($upload['name'] ?? 'bank-of-america.csv'));
    $errorCode = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
      $errors[] = 'Upload failed. Please try again with a valid CSV file.';
    } else {
      $handle = fopen($tmpName, 'rb');
      if ($handle === false) {
        $errors[] = 'Could not read the uploaded file.';
      } else {
        $header = fgetcsv($handle);
        if (!is_array($header)) {
          $errors[] = 'The CSV file is empty.';
        } else {
          $headerMap = [];
          foreach ($header as $index => $column) {
            $normalized = strtolower(trim((string)$column));
            if ($normalized !== '') {
              $headerMap[$normalized] = $index;
            }
          }

          $dateIndex = $headerMap['date'] ?? null;
          $descriptionIndex = $headerMap['description'] ?? null;
          $amountIndex = $headerMap['amount'] ?? null;
          $runningBalanceIndex = $headerMap['running bal.'] ?? $headerMap['running bal'] ?? null;

          if ($dateIndex === null || $descriptionIndex === null || $amountIndex === null) {
            $errors[] = 'The CSV must include Date, Description, and Amount columns.';
          } else {
            $inserted = 0;
            $duplicates = 0;
            $invalid = 0;
            $processed = 0;
            $previewRows = [];
            $invalidRows = [];

            $insertStmt = $pdo->prepare(
              "INSERT INTO bank_transactions (
                  transaction_date,
                  description,
                  normalized_description,
                  amount,
                  running_balance,
                  transaction_type,
                  source,
                  reference,
                  customer_name,
                  transaction_hash,
                  source_filename,
                  source_line_number,
                  raw_row_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $lineNumber = 1;
            while (($row = fgetcsv($handle)) !== false) {
              $lineNumber++;
              if (!is_array($row)) {
                continue;
              }

              $dateRaw = trim((string)($row[$dateIndex] ?? ''));
              $description = trim((string)($row[$descriptionIndex] ?? ''));
              $amountRaw = trim((string)($row[$amountIndex] ?? ''));
              $runningBalanceRaw = $runningBalanceIndex !== null ? trim((string)($row[$runningBalanceIndex] ?? '')) : '';

              if ($dateRaw === '' && $description === '' && $amountRaw === '' && $runningBalanceRaw === '') {
                continue;
              }

              $processed++;

              $date = DateTime::createFromFormat('m/d/Y', $dateRaw);
              $amount = bank_tx_parse_money($amountRaw);
              $runningBalance = bank_tx_parse_money($runningBalanceRaw);

              if (!$date || $description === '' || $amount === null) {
                $invalid++;
                if (count($invalidRows) < 5) {
                  $invalidRows[] = 'Line ' . $lineNumber . ': invalid date, description, or amount.';
                }
                continue;
              }

              $dateYmd = $date->format('Y-m-d');
              $normalizedDescription = bank_tx_normalize_description($description);
              $transactionType = bank_tx_detect_type($description, $amount);
              $source = bank_tx_classify_source($description);
              $reference = bank_tx_extract_reference($description);
              $customerName = bank_tx_extract_customer_name($description);
              $transactionHash = bank_tx_hash($dateYmd, $description, $amount);
              $rawRowJson = json_encode([
                'Date' => $dateRaw,
                'Description' => $description,
                'Amount' => $amountRaw,
                'Running Bal.' => $runningBalanceRaw,
              ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

              try {
                $insertStmt->execute([
                  $dateYmd,
                  $description,
                  $normalizedDescription,
                  bank_tx_amount_string($amount),
                  $runningBalance !== null ? bank_tx_amount_string($runningBalance) : null,
                  $transactionType,
                  $source,
                  $reference,
                  $customerName,
                  $transactionHash,
                  $originalName !== '' ? $originalName : null,
                  $lineNumber,
                  $rawRowJson,
                ]);
                $inserted++;
                if (count($previewRows) < 8) {
                  $previewRows[] = [
                    'transaction_date' => $dateYmd,
                    'description' => $description,
                    'amount' => $amount,
                    'source' => $source,
                  ];
                }
              } catch (PDOException $e) {
                if ($e->getCode() === BANK_IMPORT_DUPLICATE_SQLSTATE) {
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
        }
        fclose($handle);
      }
    }
  }
}

$recentStmt = $pdo->query(
  "SELECT id, transaction_date, description, amount, source, imported_at
   FROM bank_transactions
   ORDER BY imported_at DESC, id DESC
   LIMIT 20"
);
$recentTransactions = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

render_header('Bank Import');
?>

<?php foreach ($errors as $err): ?>
  <div class="alert" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;"><?= h($err) ?></div>
<?php endforeach; ?>

<?php if ($summary): ?>
  <div class="alert" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534;">
    Imported <?= (int)$summary['inserted'] ?> new transactions from <?= h((string)$summary['file_name']) ?>.
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
    <span class="laser-rfq-hero-tag">Daily Import</span>
    <h1>Bank of America Import</h1>
    <p class="muted">Upload the raw Bank of America CSV. Each row is hashed from date + description + amount so repeat imports safely skip duplicates.</p>
    <ul class="laser-rfq-hero-pills" aria-label="Import highlights">
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🧾</span> CSV upload</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🔁</span> Duplicate-safe</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">📥</span> Daily imports</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🔗</span> Payment matching</li>
    </ul>
  </div>
  <div class="laser-rfq-hero-actions">
    <a class="btn" href="transactions.php">View Transactions</a>
  </div>
</div>

<div class="card">
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['bank_import_csrf']) ?>" />

    <div style="grid-column:1/-1;">
      <label for="csv_file">Bank of America CSV File</label>
      <input id="csv_file" type="file" name="csv_file" accept=".csv,text/csv" required />
      <div class="muted" style="margin-top:8px;">Expected columns: Date, Description, Amount, Running Bal.</div>
    </div>

    <div style="grid-column:1/-1; display:flex; gap:8px; flex-wrap:wrap;">
      <button type="submit" class="btn primary">Import CSV</button>
      <a class="btn" href="transactions.php">Open Transactions</a>
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
            <th>Source</th>
            <th>Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summary['preview_rows'] as $row): ?>
            <tr>
              <td><?= h(fmt_date_mdY((string)$row['transaction_date'])) ?></td>
              <td><?= h((string)$row['description']) ?></td>
              <td><?= h((string)$row['source']) ?></td>
              <td><strong>$<?= h(number_format((float)$row['amount'], 2)) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;">Recent Imported Transactions</h2>
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:760px;">
      <thead>
        <tr>
          <th>#</th>
          <th>Date</th>
          <th>Description</th>
          <th>Source</th>
          <th>Amount</th>
          <th>Imported</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$recentTransactions): ?>
          <tr><td colspan="6" class="muted">No bank transactions have been imported yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recentTransactions as $tx): ?>
          <tr>
            <td class="muted"><?= (int)$tx['id'] ?></td>
            <td><?= h(fmt_date_mdY((string)$tx['transaction_date'])) ?></td>
            <td><?= h((string)$tx['description']) ?></td>
            <td><?= h((string)$tx['source']) ?></td>
            <td><strong>$<?= h(number_format((float)$tx['amount'], 2)) ?></strong></td>
            <td><?= h((string)$tx['imported_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
