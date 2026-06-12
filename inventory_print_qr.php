<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo '<p>Invalid item ID.</p>';
  exit;
}

$stmt = $pdo->prepare("SELECT id, part_number, item_name FROM inventory_items WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$item = $stmt->fetch();
if (!$item) {
  http_response_code(404);
  echo '<p>Inventory item not found.</p>';
  exit;
}

$qr_url = 'https://ghostlaser.com/project/inventory_form.php?id=' . $id . '&view=1';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>QR Label – <?= h((string)$item['part_number']) ?></title>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha256-FcVUR0ndoOygJlQ8hRlHocPKzhw6KpQZIky3gGHwH0c=" crossorigin="anonymous"></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #f1f5f9;
      color: #0f172a;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 24px 16px;
    }

    .print-btn-bar {
      width: 100%;
      max-width: 420px;
      margin-bottom: 20px;
      display: flex;
      gap: 10px;
    }

    .btn-print {
      flex: 1;
      background: #0f172a;
      color: #fff;
      border: none;
      border-radius: 10px;
      padding: 14px 24px;
      font-size: 1.1rem;
      font-weight: 700;
      cursor: pointer;
      letter-spacing: 0.02em;
    }

    .btn-print:hover { background: #1e293b; }

    .btn-back {
      background: #e2e8f0;
      color: #0f172a;
      border: none;
      border-radius: 10px;
      padding: 14px 18px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
    }

    .btn-back:hover { background: #cbd5e1; }

    .label-card {
      background: #ffffff;
      border-radius: 16px;
      box-shadow: 0 4px 24px rgba(0,0,0,0.10);
      padding: 32px 28px 28px;
      width: 100%;
      max-width: 420px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 20px;
    }

    #qrcode {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    #qrcode canvas,
    #qrcode img {
      width: 260px !important;
      height: 260px !important;
      display: block;
    }

    .label-info {
      text-align: center;
      width: 100%;
    }

    .label-part-number {
      font-size: 1.4rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      color: #0f172a;
      margin-bottom: 6px;
      font-family: 'Courier New', Courier, monospace;
    }

    .label-item-name {
      font-size: 1.1rem;
      font-weight: 600;
      color: #334155;
      line-height: 1.35;
    }

    .label-url {
      font-size: 0.72rem;
      color: #94a3b8;
      word-break: break-all;
      margin-top: 8px;
    }

    /* ── Print styles ─────────────────────────────── */
    @media print {
      body {
        background: #fff;
        padding: 0;
        display: block;
      }

      .print-btn-bar { display: none !important; }

      .label-card {
        box-shadow: none;
        border-radius: 0;
        padding: 16px;
        max-width: 100%;
        width: 100%;
        page-break-inside: avoid;
      }

      #qrcode canvas,
      #qrcode img {
        width: 240px !important;
        height: 240px !important;
      }

      .label-part-number { font-size: 1.5rem; }
      .label-item-name   { font-size: 1.15rem; }
      .label-url         { font-size: 0.7rem; }
    }
  </style>
</head>
<body>

  <div class="print-btn-bar">
    <button class="btn-print" onclick="window.print()">🖨️ Print This Label</button>
    <a class="btn-back" href="inventory_list.php">← Back</a>
  </div>

  <div class="label-card">
    <div id="qrcode"></div>
    <div class="label-info">
      <div class="label-part-number"><?= h((string)$item['part_number']) ?></div>
      <div class="label-item-name"><?= h((string)$item['item_name']) ?></div>
      <div class="label-url"><?= h($qr_url) ?></div>
    </div>
  </div>

  <script>
    new QRCode(document.getElementById('qrcode'), {
      text: <?= json_encode($qr_url) ?>,
      width: 260,
      height: 260,
      colorDark: '#000000',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
  </script>
</body>
</html>
