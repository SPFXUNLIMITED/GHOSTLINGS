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

define('QR_SIZE_COMPACT_LABEL_PX', 140);
$qr_url = 'https://ghostlaser.com/project/inventory_form.php?id=' . (int)$id . '&view=qr';
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
      width: 2in;
      height: 2in;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #ddd;
      border-radius: 8px;
      padding: 6px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 3px;
      overflow: hidden;
      box-shadow: 0 2px 10px rgba(0,0,0,0.12);
    }
    .qr-frame {
      border: 1.5px solid #d0d0d0;
      border-radius: 6px;
      padding: 2px;
      background: #fff;
      line-height: 0;
      flex: 0 0 auto;
    }
    #qrcode {
      width: <?= (int)QR_SIZE_COMPACT_LABEL_PX ?>px;
      height: <?= (int)QR_SIZE_COMPACT_LABEL_PX ?>px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 auto;
      position: relative;
    }
    #qrcode img,
    #qrcode canvas {
      width: <?= (int)QR_SIZE_COMPACT_LABEL_PX ?>px !important;
      height: <?= (int)QR_SIZE_COMPACT_LABEL_PX ?>px !important;
      display: block;
      margin: 0 auto;
    }
    .qr-ghost-overlay {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: #fff;
      padding: 3px;
      line-height: 0;
      border-radius: 2px;
      pointer-events: none;
    }
    .qr-ghost-overlay img {
      width: 36px !important;
      height: 36px !important;
      display: block !important;
      margin: 0 !important;
    }
    .brand-text {
      font-size: 11px;
      font-weight: 900;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: #111;
      margin: 0;
    }
    .item-name {
      width: 100%;
      font-size: 10px;
      font-weight: 600;
      margin: 0;
      color: #333;
      line-height: 1.2;
      word-break: break-word;
      text-align: center;
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
        box-shadow: none;
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
    <div class="brand-text">Ghost Laser</div>
    <div class="qr-frame">
      <div id="qrcode"></div>
    </div>
    <div class="item-name"><?= h((string)$item['item_name']) ?></div>
  </div>
  <button class="print-btn" onclick="window.print()">Print This Label</button>
  <div class="print-help">If sizing looks off, set print scale to 100% and margins to None in the print dialog.</div>

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
        width: <?= (int)QR_SIZE_COMPACT_LABEL_PX ?>,
        height: <?= (int)QR_SIZE_COMPACT_LABEL_PX ?>,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H
      });

      // Place ghost logo as a CSS-positioned overlay so it always appears centered
      var overlay = document.createElement('div');
      overlay.className = 'qr-ghost-overlay';
      var ghostImg = document.createElement('img');
      ghostImg.src = '/project/ghost-logo2-32x32.png';
      ghostImg.alt = 'Ghost Laser';
      overlay.appendChild(ghostImg);
      qrTarget.appendChild(overlay);
    });
  </script>
</body>
</html>
