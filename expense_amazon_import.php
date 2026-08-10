<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

// ── helpers ────────────────────────────────────────────────────────────────

function amazon_find_col(array $row, array $needles): ?int {
  foreach ($needles as $needle) {
    $needle = strtolower($needle);
    foreach ($row as $index => $cell) {
      if (str_contains(strtolower(trim((string)$cell)), $needle)) {
        return $index;
      }
    }
  }
  return null;
}

function amazon_parse_date(string $raw): ?DateTime {
  $raw = trim($raw);
  if ($raw === '') {
    return null;
  }
  foreach (['m/d/Y', 'n/j/Y', 'm/d/y', 'n/j/y', 'Y-m-d', 'M d, Y', 'F j, Y'] as $fmt) {
    $dt = DateTime::createFromFormat($fmt, $raw);
    if ($dt instanceof DateTime) {
      $dt->setTime(0, 0, 0);
      return $dt;
    }
  }
  $ts = strtotime($raw);
  if ($ts !== false) {
    $dt = new DateTime('@' . $ts);
    $dt->setTimezone(new DateTimeZone(APP_TZ));
    $dt->setTime(0, 0, 0);
    return $dt;
  }
  return null;
}

// ── CSRF ──────────────────────────────────────────────────────────────────

if (empty($_SESSION['amazon_import_csrf'])) {
  $_SESSION['amazon_import_csrf'] = bin2hex(random_bytes(24));
}

$errors  = [];
$summary = null;

// ── category helpers ───────────────────────────────────────────────────────

$categoryRows = $pdo->query(
  "SELECT id, code, name, group_type FROM expense_categories ORDER BY sort_order ASC, name ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$categoriesByCode = [];
foreach ($categoryRows as $catRow) {
  $categoriesByCode[(string)$catRow['code']] = [
    'id'         => (int)$catRow['id'],
    'group_type' => (string)($catRow['group_type'] ?? 'opex'),
  ];
}

$getOrCreateCategory = function (string $code, string $label) use ($pdo, &$categoriesByCode): array {
  $code = trim($code) !== '' ? $code : 'uncategorized';
  if (isset($categoriesByCode[$code])) {
    return $categoriesByCode[$code];
  }
  $stmt = $pdo->prepare(
    "INSERT INTO expense_categories (code, name, group_type, sort_order, is_active)
     VALUES (?, ?, 'opex', 999, 1)
     ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1"
  );
  $label = trim($label) !== '' ? trim($label) : ucwords(str_replace('_', ' ', $code));
  $stmt->execute([$code, mb_substr($label, 0, 150)]);
  $fetch = $pdo->prepare("SELECT id, group_type FROM expense_categories WHERE code = ? LIMIT 1");
  $fetch->execute([$code]);
  $row = $fetch->fetch(PDO::FETCH_ASSOC) ?: [];
  $id  = (int)($row['id'] ?? 0);
  if ($id <= 0) {
    throw new RuntimeException('Unable to resolve category for code: ' . $code);
  }
  $meta = ['id' => $id, 'group_type' => (string)($row['group_type'] ?? 'opex')];
  $categoriesByCode[$code] = $meta;
  return $meta;
};

// "excluded" category used for new unmatched Amazon rows
$excludedMeta = $getOrCreateCategory('excluded', 'Excluded');

// ── AJAX: confirm matched/review rows ─────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_review') {
  header('Content-Type: application/json');

  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['amazon_import_csrf']) || !hash_equals((string)$_SESSION['amazon_import_csrf'], $csrf)) {
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']);
    exit;
  }

  $rows        = json_decode((string)($_POST['review_rows'] ?? '[]'), true);
  $decisions   = json_decode((string)($_POST['decisions']   ?? '[]'), true);

  if (!is_array($rows) || !is_array($decisions)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid payload.']);
    exit;
  }

  $matched = 0;
  $created = 0;
  $skipped = 0;

  $updateDesc = $pdo->prepare(
    "UPDATE expenses SET description = ?, amazon_order_id = ?, updated_at = NOW() WHERE id = ? LIMIT 1"
  );
  $insertNew = $pdo->prepare(
    "INSERT INTO expenses
       (expense_date, amount, category_id, group_type, description, amazon_order_id,
        transaction_hash, source, source_filename, source_line_number, raw_row_json, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'amazon_csv', ?, ?, ?, ?)"
  );

  foreach ($rows as $i => $row) {
    $decision = (string)($decisions[$i] ?? 'skip');

    if ($decision === 'skip') {
      $skipped++;
      continue;
    }

    $dateYmd    = (string)($row['expense_date']  ?? '');
    $amount     = (float)($row['amount']         ?? 0);
    $description= (string)($row['description']   ?? '');
    $orderId    = (string)($row['order_id']      ?? '');
    $lineNumber = (int)($row['line_number']      ?? 0);
    $filename   = (string)($row['filename']      ?? '');
    $rawJson    = (string)($row['raw_json']      ?? '');
    $expenseId  = (int)($row['expense_id']       ?? 0);
    $userId     = ((int)($_SESSION['user_id'] ?? 0)) ?: null;

    if ($decision === 'match' && $expenseId > 0) {
      // Update existing expense with Amazon product description
      $updateDesc->execute([$description, $orderId !== '' ? $orderId : null, $expenseId]);
      $matched++;
    } elseif ($decision === 'create') {
      $hash = expense_hash($dateYmd, $description, $amount);
      try {
        $insertNew->execute([
          $dateYmd,
          expense_amount_string($amount),
          $excludedMeta['id'],
          'excluded',
          $description,
          $orderId !== '' ? $orderId : null,
          $hash,
          $filename !== '' ? $filename : null,
          $lineNumber > 0 ? $lineNumber : null,
          $rawJson,
          $userId,
        ]);
        $created++;
      } catch (PDOException $e) {
        if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
          $skipped++; // already exists
        } else {
          throw $e;
        }
      }
    }
  }

  // Regenerate CSRF
  $_SESSION['amazon_import_csrf'] = bin2hex(random_bytes(24));

  echo json_encode([
    'ok'      => true,
    'matched' => $matched,
    'created' => $created,
    'skipped' => $skipped,
    'new_csrf'=> $_SESSION['amazon_import_csrf'],
  ]);
  exit;
}

// ── POST: CSV upload + parse ───────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['expense_import_csrf']) && empty($_SESSION['amazon_import_csrf'])) {
    $errors[] = 'Security token missing.';
  } elseif (
    !hash_equals((string)$_SESSION['amazon_import_csrf'], $submitted_csrf)
  ) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } elseif (empty($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
    $errors[] = 'Please choose a CSV file to import.';
  } else {
    $_SESSION['amazon_import_csrf'] = bin2hex(random_bytes(24));
    $upload       = $_FILES['csv_file'];
    $tmpName      = (string)($upload['tmp_name'] ?? '');
    $originalName = trim((string)($upload['name'] ?? 'amazon-orders.csv'));
    $errorCode    = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
      $errors[] = 'Upload failed. Please try again with a valid CSV file.';
    } else {
      $handle = fopen($tmpName, 'rb');
      if ($handle === false) {
        $errors[] = 'Could not read the uploaded file.';
      } else {
        // ── detect header row ──────────────────────────────────────────────
        $dateIndex     = null;
        $productIndex  = null;
        $qtyIndex      = null;
        $unitPriceIndex= null;
        $itemTotalIndex= null;
        $orderIdIndex  = null;
        $foundHeader   = false;

        while (($headerRow = fgetcsv($handle)) !== false) {
          if (!is_array($headerRow)) {
            continue;
          }
          $d   = amazon_find_col($headerRow, ['order date', 'date']);
          $p   = amazon_find_col($headerRow, ['title', 'product name', 'product', 'item']);
          $q   = amazon_find_col($headerRow, ['quantity', 'qty']);
          $up  = amazon_find_col($headerRow, ['unit price', 'price per unit']);
          $tot = amazon_find_col($headerRow, ['item total', 'item subtotal', 'subtotal', 'total']);
          $oid = amazon_find_col($headerRow, ['order id', 'order #', 'orderid']);

          if ($d !== null && $p !== null && $tot !== null) {
            $dateIndex      = $d;
            $productIndex   = $p;
            $qtyIndex       = $q;
            $unitPriceIndex = $up;
            $itemTotalIndex = $tot;
            $orderIdIndex   = $oid;
            $foundHeader    = true;
            break;
          }
        }

        if (!$foundHeader) {
          $errors[] = 'The CSV must include Order Date, Product/Title, and Item Total columns.';
        } else {
          // ── load existing expenses for matching ────────────────────────────
          // Fetch all expenses within a reasonable window (no outer range filter;
          // we'll match per-row in PHP using a ±2-day window).
          $existingStmt = $pdo->query(
            "SELECT id, expense_date, amount FROM expenses ORDER BY expense_date ASC"
          );
          $existingExpenses = $existingStmt->fetchAll(PDO::FETCH_ASSOC);

          // Index by date string → array of rows for faster lookup
          $expenseByDate = [];
          foreach ($existingExpenses as $exp) {
            $expenseByDate[$exp['expense_date']][] = $exp;
          }

          $autoMatched  = [];  // confident single matches
          $reviewRows   = [];  // ambiguous (multiple candidates) or close
          $createdRows  = [];  // no match found → will create as Excluded
          $invalidCount = 0;
          $lineNumber   = 1;

          $DATE_WINDOW = 2; // days

          while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;
            if (!is_array($row)) {
              continue;
            }

            $dateRaw     = trim((string)($row[$dateIndex]      ?? ''));
            $product     = trim((string)($row[$productIndex]   ?? ''));
            $qtyRaw      = $qtyIndex !== null ? trim((string)($row[$qtyIndex] ?? '')) : '';
            $unitPriceRaw= $unitPriceIndex !== null ? trim((string)($row[$unitPriceIndex] ?? '')) : '';
            $totalRaw    = trim((string)($row[$itemTotalIndex]  ?? ''));
            $orderId     = $orderIdIndex !== null ? trim((string)($row[$orderIdIndex] ?? '')) : '';

            // Skip blank rows
            if ($dateRaw === '' && $product === '' && $totalRaw === '') {
              continue;
            }

            $parsedDate = amazon_parse_date($dateRaw);
            $parsedTotal= expense_parse_money($totalRaw);

            if (!$parsedDate || $parsedTotal === null || $product === '') {
              $invalidCount++;
              continue;
            }

            $amount  = abs($parsedTotal);
            if ($amount <= 0) {
              $invalidCount++;
              continue;
            }

            $dateYmd = $parsedDate->format('Y-m-d');

            // Build description from product + qty
            $qty = (int)$qtyRaw;
            if ($qty > 1) {
              $description = $product . ' (×' . $qty . ')';
            } else {
              $description = $product;
            }

            $rawJson = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // ── match against existing expenses (±2-day window, same amount) ─
            $candidates = [];
            $dt = clone $parsedDate;
            for ($d = -$DATE_WINDOW; $d <= $DATE_WINDOW; $d++) {
              $checkDate = (clone $parsedDate)->modify("{$d} days")->format('Y-m-d');
              if (!isset($expenseByDate[$checkDate])) {
                continue;
              }
              foreach ($expenseByDate[$checkDate] as $exp) {
                if (abs((float)$exp['amount'] - $amount) < 0.005) {
                  $candidates[] = $exp;
                }
              }
            }

            $rowData = [
              'line_number'  => $lineNumber,
              'filename'     => $originalName,
              'expense_date' => $dateYmd,
              'amount'       => $amount,
              'description'  => $description,
              'order_id'     => $orderId,
              'raw_json'     => $rawJson,
            ];

            if (count($candidates) === 1) {
              // Confident match – queue for auto-confirm (user still sees summary)
              $rowData['expense_id'] = (int)$candidates[0]['id'];
              $rowData['match_date'] = (string)$candidates[0]['expense_date'];
              $autoMatched[] = $rowData;
            } elseif (count($candidates) > 1) {
              // Ambiguous – show to user for manual review
              $rowData['candidates'] = $candidates;
              $reviewRows[] = $rowData;
            } else {
              // No match – will create as Excluded
              $createdRows[] = $rowData;
            }
          }

          fclose($handle);
          $handle = null;

          $summary = [
            'file_name'     => $originalName,
            'auto_matched'  => $autoMatched,
            'review_rows'   => $reviewRows,
            'created_rows'  => $createdRows,
            'invalid_count' => $invalidCount,
          ];
        }

        if ($handle !== null) {
          fclose($handle);
        }
      }
    }
  }
}

// ── render ────────────────────────────────────────────────────────────────

render_header('Amazon Import');
?>

<?php foreach ($errors as $err): ?>
  <div class="alert" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;"><?= h($err) ?></div>
<?php endforeach; ?>

<div class="card laser-rfq-hero page-header">
  <div class="laser-rfq-hero-beams" aria-hidden="true">
    <span class="laser-rfq-beam laser-rfq-beam-1"></span>
    <span class="laser-rfq-beam laser-rfq-beam-2"></span>
    <span class="laser-rfq-beam laser-rfq-beam-3"></span>
  </div>
  <div class="laser-rfq-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body laser-rfq-hero-body">
    <span class="laser-rfq-hero-tag">Amazon Import</span>
    <h1>Amazon Order History Import</h1>
    <p class="muted">Upload your Amazon order history CSV to match purchases against existing bank expenses or create new Excluded entries for review.</p>
    <ul class="laser-rfq-hero-pills" aria-label="Import highlights">
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">📦</span> Amazon CSV</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🔍</span> ±2-day matching</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🕵️</span> Manual review</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🚫</span> Auto-excluded</li>
    </ul>
  </div>
  <div class="laser-rfq-hero-actions">
    <a class="btn" href="expenses.php">View Expenses</a>
    <a class="btn" href="expense_import.php">Bank Import</a>
  </div>
</div>

<?php if (!$summary): ?>
<div class="card">
  <h2 style="margin-top:0;">Upload Amazon Order History CSV</h2>
  <p class="muted" style="margin-top:0;">
    Export your orders from <strong>Amazon → Account → Order History Reports</strong>.
    The CSV must contain columns for <em>Order Date</em>, <em>Title</em> (product name), and <em>Item Total</em>.
    Optional: Order ID, Quantity, Unit Price.
  </p>
  <form method="post" enctype="multipart/form-data" class="form-grid" id="amazon-upload-form">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['amazon_import_csrf']) ?>" />
    <div style="grid-column:1/-1;">
      <label for="csv_file">Amazon Order History CSV</label>
      <input id="csv_file" type="file" name="csv_file" accept=".csv,text/csv" required />
    </div>
    <div style="grid-column:1/-1; display:flex; gap:8px; flex-wrap:wrap;">
      <button type="submit" class="btn primary">Parse CSV</button>
      <a class="btn" href="expenses.php">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($summary): ?>

<?php
  $totalRows   = count($summary['auto_matched']) + count($summary['review_rows']) + count($summary['created_rows']);
  $reviewCount = count($summary['review_rows']);
  $autoCount   = count($summary['auto_matched']);
  $newCount    = count($summary['created_rows']);
?>

<div class="alert" style="border-color:#bfdbfe;background:#eff6ff;color:#1e40af;">
  <strong>Parse complete:</strong> <?= $totalRows ?> Amazon line item(s) found in
  <em><?= h($summary['file_name']) ?></em>.
  <strong><?= $autoCount ?></strong> auto-matched &nbsp;·&nbsp;
  <strong><?= $reviewCount ?></strong> need review &nbsp;·&nbsp;
  <strong><?= $newCount ?></strong> new (will be Excluded) &nbsp;·&nbsp;
  <strong><?= (int)$summary['invalid_count'] ?></strong> skipped (invalid).
</div>

<form id="confirm-form" method="post">
  <input type="hidden" name="action" value="confirm_review" />
  <input type="hidden" name="csrf_token" id="confirm-csrf" value="<?= h($_SESSION['amazon_import_csrf']) ?>" />
  <input type="hidden" name="review_rows" id="review-rows-json" value="" />
  <input type="hidden" name="decisions" id="decisions-json" value="" />

  <?php if ($autoCount > 0): ?>
  <div class="card">
    <h2 style="margin-top:0;">✅ Auto-Matched (<?= $autoCount ?>)</h2>
    <p class="muted" style="margin-top:0;">These Amazon items matched exactly one expense by amount within a ±2-day window. The existing expense description will be updated with the product name.</p>
    <div class="table-wrap" style="overflow-x:auto;">
      <table class="table-auto" style="min-width:800px;">
        <thead>
          <tr>
            <th>Amazon Date</th>
            <th>Product</th>
            <th>Amount</th>
            <th>Matched Expense Date</th>
            <th>Expense&nbsp;ID</th>
            <th style="width:80px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summary['auto_matched'] as $idx => $row): ?>
          <tr>
            <td><?= h(fmt_date_mdY($row['expense_date'])) ?></td>
            <td><?= h($row['description']) ?></td>
            <td><strong>$<?= h(number_format($row['amount'], 2)) ?></strong></td>
            <td><?= h(fmt_date_mdY($row['match_date'])) ?></td>
            <td class="muted">#<?= (int)$row['expense_id'] ?></td>
            <td>
              <select class="auto-decision" data-idx="<?= $idx ?>" style="font-size:13px;">
                <option value="match" selected>Update</option>
                <option value="skip">Skip</option>
              </select>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($reviewCount > 0): ?>
  <div class="card">
    <h2 style="margin-top:0;">🕵️ Needs Review (<?= $reviewCount ?>)</h2>
    <p class="muted" style="margin-top:0;">Multiple existing expenses match by amount and date window. Choose which expense to update, or create a new Excluded entry, or skip.</p>
    <div class="table-wrap" style="overflow-x:auto;">
      <table class="table-auto" style="min-width:900px;">
        <thead>
          <tr>
            <th>Amazon Date</th>
            <th>Product</th>
            <th>Amount</th>
            <th>Candidates</th>
            <th style="width:200px;">Decision</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summary['review_rows'] as $idx => $row): ?>
          <tr>
            <td><?= h(fmt_date_mdY($row['expense_date'])) ?></td>
            <td><?= h($row['description']) ?></td>
            <td><strong>$<?= h(number_format($row['amount'], 2)) ?></strong></td>
            <td>
              <?php foreach ($row['candidates'] as $cand): ?>
                <div class="muted" style="font-size:12px;">#<?= (int)$cand['id'] ?> — <?= h(fmt_date_mdY($cand['expense_date'])) ?></div>
              <?php endforeach; ?>
            </td>
            <td>
              <select class="review-decision" data-idx="<?= $idx ?>" data-candidates="<?= h(json_encode(array_column($row['candidates'], 'id'))) ?>" style="font-size:13px;">
                <option value="create">New (Excluded)</option>
                <?php foreach ($row['candidates'] as $cand): ?>
                  <option value="match_<?= (int)$cand['id'] ?>">Update #<?= (int)$cand['id'] ?> (<?= h(fmt_date_mdY($cand['expense_date'])) ?>)</option>
                <?php endforeach; ?>
                <option value="skip">Skip</option>
              </select>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($newCount > 0): ?>
  <div class="card">
    <h2 style="margin-top:0;">🆕 New Entries — Excluded (<?= $newCount ?>)</h2>
    <p class="muted" style="margin-top:0;">No matching expense found. These will be created as <strong>Excluded</strong> so you can categorize them later. Uncheck any you want to skip.</p>
    <div class="table-wrap" style="overflow-x:auto;">
      <table class="table-auto" style="min-width:700px;">
        <thead>
          <tr>
            <th style="width:36px;"></th>
            <th>Date</th>
            <th>Product</th>
            <th>Amount</th>
            <th>Order ID</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summary['created_rows'] as $idx => $row): ?>
          <tr>
            <td><input type="checkbox" class="new-decision" data-idx="<?= $idx ?>" checked /></td>
            <td><?= h(fmt_date_mdY($row['expense_date'])) ?></td>
            <td><?= h($row['description']) ?></td>
            <td><strong>$<?= h(number_format($row['amount'], 2)) ?></strong></td>
            <td class="muted"><?= h($row['order_id']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:24px;">
    <button type="button" id="confirm-btn" class="btn primary">Apply Changes</button>
    <a class="btn" href="expense_amazon_import.php">Start Over</a>
    <a class="btn" href="expenses.php">Cancel</a>
  </div>
</form>

<div id="confirm-result" style="display:none;"></div>

<script>
(function () {
  // Serialise all row data as JSON for the hidden fields
  var autoRows    = <?= json_encode($summary['auto_matched'],  JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;
  var reviewRows  = <?= json_encode($summary['review_rows'],   JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;
  var newRows     = <?= json_encode($summary['created_rows'],  JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;

  document.getElementById('confirm-btn').addEventListener('click', function () {
    var allRows      = [];
    var allDecisions = [];

    // ── auto-matched ──────────────────────────────────────────────────────
    document.querySelectorAll('.auto-decision').forEach(function (sel) {
      var idx = parseInt(sel.dataset.idx, 10);
      var row = Object.assign({}, autoRows[idx]);
      allRows.push(row);
      allDecisions.push(sel.value); // 'match' or 'skip'
    });

    // ── review rows ───────────────────────────────────────────────────────
    document.querySelectorAll('.review-decision').forEach(function (sel) {
      var idx = parseInt(sel.dataset.idx, 10);
      var row = Object.assign({}, reviewRows[idx]);
      var val = sel.value;
      if (val.startsWith('match_')) {
        row.expense_id = parseInt(val.substring(6), 10);
        allRows.push(row);
        allDecisions.push('match');
      } else if (val === 'create') {
        delete row.candidates;
        allRows.push(row);
        allDecisions.push('create');
      } else {
        allRows.push(row);
        allDecisions.push('skip');
      }
    });

    // ── new rows ──────────────────────────────────────────────────────────
    document.querySelectorAll('.new-decision').forEach(function (cb) {
      var idx = parseInt(cb.dataset.idx, 10);
      var row = Object.assign({}, newRows[idx]);
      allRows.push(row);
      allDecisions.push(cb.checked ? 'create' : 'skip');
    });

    document.getElementById('review-rows-json').value = JSON.stringify(allRows);
    document.getElementById('decisions-json').value    = JSON.stringify(allDecisions);

    var form    = document.getElementById('confirm-form');
    var btn     = document.getElementById('confirm-btn');
    var result  = document.getElementById('confirm-result');

    btn.disabled    = true;
    btn.textContent = 'Applying…';
    result.style.display = 'none';

    var fd = new FormData(form);
    fetch('expense_amazon_import.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        btn.textContent = 'Apply Changes';
        if (data.ok) {
          document.getElementById('confirm-csrf').value = data.new_csrf;
          result.style.cssText = 'display:block;padding:12px 16px;border-radius:6px;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;margin-bottom:16px;';
          result.innerHTML = '✅ Done — <strong>' + data.matched + '</strong> matched, <strong>' +
            data.created + '</strong> created, <strong>' + data.skipped + '</strong> skipped. ' +
            '<a href="expenses.php">View Expenses</a>';
          form.querySelectorAll('button, select, input[type=checkbox]').forEach(function(el){ el.disabled = true; });
        } else {
          result.style.cssText = 'display:block;padding:12px 16px;border-radius:6px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;margin-bottom:16px;';
          result.textContent = 'Error: ' + (data.error || 'Unknown error');
        }
      })
      .catch(function (err) {
        btn.disabled = false;
        btn.textContent = 'Apply Changes';
        result.style.cssText = 'display:block;padding:12px 16px;border-radius:6px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;margin-bottom:16px;';
        result.textContent = 'Network error: ' + err;
      });
  });
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
