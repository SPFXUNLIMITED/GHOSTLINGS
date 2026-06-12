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
      padding: 20px;
      font-family: Arial, sans-serif;
      background: #f5f5f5;
      text-align: center;
    }
    .label-container {
      max-width: 420px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #ddd;
      border-radius: 10px;
      padding: 20px;
    }
    #qrcode {
      width: 280px;
      height: 280px;
      margin: 0 auto 20px auto;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    #qrcode img,
    #qrcode canvas {
      width: 280px !important;
      height: 280px !important;
      display: block;
      margin: 0 auto;
    }
    .part-number {
      font-size: 34px;
      font-weight: 800;
      margin: 0 0 10px 0;
      line-height: 1.1;
    }
    .item-name {
      font-size: 20px;
      margin: 0;
      color: #333;
    }
    .print-btn {
      margin-top: 20px;
      background: #000;
      color: #fff;
      border: none;
      padding: 12px 24px;
      font-size: 18px;
      cursor: pointer;
      border-radius: 6px;
      font-weight: 700;
    }
    @media print {
      body { background: #fff; padding: 0; }
      .print-btn { display: none; }
      .label-container {
        border: 0;
        border-radius: 0;
        width: 100%;
        max-width: none;
      }
    }
  </style>
</head>
<body>
  <div class="label-container">
    <div id="qrcode"></div>
    <div class="part-number"><?= h((string)$item['part_number']) ?></div>
    <div class="item-name"><?= h((string)$item['item_name']) ?></div>
    <button class="print-btn" onclick="window.print()">Print This Label</button>
  </div>

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
        text: <?= json_encode('https://ghostlaser.com/project/inventory_form.php?id=' . (int)$id . '&view=1') ?>,
        width: 280,
        height: 280,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
    });
  </script>
</body>
</html>
