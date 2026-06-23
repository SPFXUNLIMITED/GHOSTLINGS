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

define('QR_SIZE_PX', 128);
define('GHOST_OVERLAY_PX', 24);
$qr_url = 'https://ghostlaser.com/project/inventory_form.php?id=' . (int)$id . '&view=qr';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>QR Label – <?= h((string)$item['part_number']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;900&display=swap" rel="stylesheet">
  <script src="js/qrcode.min.js"></script>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      background: #ececec;
      font-family: 'Nunito', Arial, sans-serif;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 24px 16px 20px;
      min-height: 100vh;
    }

    /* ── 2×2 inch label ── */
    .label {
      width: 2in;
      height: 2in;
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 4px 18px rgba(0,0,0,0.15);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: space-between;
      padding: 7px 6px 8px;
      overflow: hidden;
    }

    /* Brand header */
    .brand {
      font-size: 13px;
      font-weight: 900;
      letter-spacing: 1.8px;
      text-transform: uppercase;
      color: #1a1a2e;
      line-height: 1;
    }

    /* QR wrapper */
    .qr-wrap {
      position: relative;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 1 1 auto;
    }

    #qrcode img,
    #qrcode canvas {
      width: <?= (int)QR_SIZE_PX ?>px !important;
      height: <?= (int)QR_SIZE_PX ?>px !important;
      display: block;
    }

    /* Ghost logo centered on QR */
    .ghost-overlay {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: #fff;
      border-radius: 3px;
      padding: 2px;
      line-height: 0;
      pointer-events: none;
    }

    .ghost-overlay img {
      width: <?= (int)GHOST_OVERLAY_PX ?>px !important;
      height: <?= (int)GHOST_OVERLAY_PX ?>px !important;
      display: block !important;
    }

    /* Item name footer */
    .item-name {
      font-size: 9.5px;
      font-weight: 700;
      color: #333;
      text-align: center;
      line-height: 1.25;
      word-break: break-word;
      width: 100%;
      max-height: 30px;
      overflow: hidden;
    }

    /* ── Controls (hidden on print) ── */
    .controls {
      margin-top: 18px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
    }

    .print-btn {
      background: #1a1a2e;
      color: #fff;
      border: none;
      padding: 10px 22px;
      font-size: 14px;
      font-weight: 700;
      font-family: 'Nunito', Arial, sans-serif;
      border-radius: 8px;
      cursor: pointer;
      letter-spacing: 0.5px;
    }

    .print-btn:hover { background: #2d2d6b; }

    .print-hint {
      font-size: 11px;
      color: #777;
      text-align: center;
      line-height: 1.4;
    }

    /* ── Print styles ── */
    @media print {
      @page { size: 2in 2in; margin: 0; }
      html, body { width: 2in; height: 2in; background: #fff; padding: 0; }
      .controls { display: none; }
      .label {
        border-radius: 0;
        box-shadow: none;
        width: 2in;
        height: 2in;
        padding: 7px 6px 8px;
      }
    }
  </style>
</head>
<body>

  <div class="label">
    <div class="brand">Ghost Laser</div>

    <div class="qr-wrap">
      <div id="qrcode"></div>
      <div class="ghost-overlay">
        <img src="/project/ghost-logo2-32x32.png" alt="Ghost Laser">
      </div>
    </div>

    <div class="item-name"><?= h((string)$item['item_name']) ?></div>
  </div>

  <div class="controls">
    <button class="print-btn" onclick="window.print()">🖨️ Print Label</button>
    <div class="print-hint">Set scale to 100% and margins to None in the print dialog.</div>
  </div>

  <script>
    window.addEventListener('DOMContentLoaded', function () {
      var qrTarget = document.getElementById('qrcode');
      if (!qrTarget || typeof QRCode === 'undefined') {
        if (qrTarget) qrTarget.textContent = 'QR library unavailable.';
        return;
      }

      new QRCode(qrTarget, {
        text: <?= json_encode($qr_url) ?>,
        width: <?= (int)QR_SIZE_PX ?>,
        height: <?= (int)QR_SIZE_PX ?>,
        colorDark:  '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H
      });
    });
  </script>
</body>
</html>
