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

$qr_url = 'https://ghostlaser.com/project/inventory_form.php?id=' . (int)$id . '&view=1';
define('QR_SIZE_2X2_LABEL', 176);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>QR Label – <?= h((string)$item['part_number']) ?></title>
  <script src="js/qrcode.min.js"></script>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 12px;
      font-family: Arial, sans-serif;
      background: #f5f5f5;
      text-align: center;
    }
    .label-container {
      --qr-size: <?= (int)QR_SIZE_2X2_LABEL ?>px;
      width: 2in;
      height: 2in;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #ddd;
      border-radius: 8px;
      padding: 8px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-start;
      gap: 4px;
      overflow: hidden;
    }
    #qrcode {
      width: var(--qr-size);
      height: var(--qr-size);
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 auto;
    }
    #qrcode img,
    #qrcode canvas {
      width: var(--qr-size) !important;
      height: var(--qr-size) !important;
      display: block;
      margin: 0 auto;
    }
    .part-number {
      width: 100%;
      font-size: 15px;
      font-weight: 800;
      margin: 0;
      line-height: 1.15;
      word-break: break-word;
    }
    .item-name {
      width: 100%;
      font-size: 11px;
      margin: 0;
      color: #333;
      line-height: 1.15;
      word-break: break-word;
    }
    .print-btn {
      margin-top: 12px;
      background: #000;
      color: #fff;
      border: none;
      padding: 10px 16px;
      font-size: 14px;
      cursor: pointer;
      border-radius: 6px;
      font-weight: 700;
    }
    .print-help {
      margin-top: 8px;
      font-size: 11px;
      color: #555;
      line-height: 1.3;
    }
    @media print {
      @page { size: 2in 2in; margin: 0; }
      html, body {
        width: 2in;
        height: 2in;
      }
      body {
        background: #fff;
        padding: 0;
      }
      .print-btn,
      .print-help { display: none; }
      .label-container {
        border: 0;
        border-radius: 0;
        width: 2in;
        height: 2in;
        margin: 0;
        padding: 8px;
      }
    }
  </style>
</head>
<body>
  <div class="label-container">
    <div id="qrcode"></div>
    <div class="part-number"><?= h((string)$item['part_number']) ?></div>
    <div class="item-name"><?= h((string)$item['item_name']) ?></div>
  </div>
  <button class="print-btn" onclick="window.print()">Print This Label</button>
  <div class="print-help">For best 2x2 printing, use Chrome or Firefox at 100% scale.</div>

  <script>
    window.addEventListener('DOMContentLoaded', function () {
      var qrTarget = document.getElementById('qrcode');
      if (!qrTarget) {
        return;
      }

      if (typeof QRCode === 'undefined') {
        qrTarget.textContent = 'Unable to load QR code.';
        return;
      }

      qrTarget.innerHTML = '';
      new QRCode(qrTarget, {
        text: <?= json_encode($qr_url) ?>,
        width: <?= (int)QR_SIZE_2X2_LABEL ?>,
        height: <?= (int)QR_SIZE_2X2_LABEL ?>,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
    });
  </script>
</body>
</html>
