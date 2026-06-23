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

define('QR_SIZE_PX', 116);
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
      background: #e8e4f0;
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
      border-radius: 14px;
      box-shadow: 0 6px 24px rgba(80,40,120,0.18);
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

    /* Cute soft rounded frame around QR */
    .qr-frame {
      background: linear-gradient(135deg, #f3eeff 0%, #fff0fa 100%);
      border-radius: 16px;
      padding: 6px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 0 0 1.5px rgba(160,100,220,0.18), inset 0 1px 3px rgba(255,255,255,0.8);
    }

    #qrcode canvas {
      width: <?= (int)QR_SIZE_PX ?>px !important;
      height: <?= (int)QR_SIZE_PX ?>px !important;
      display: block;
    }

    /* Ghost logo centred on QR */
    .ghost-overlay {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: #fff;
      border-radius: 50%;
      padding: 3px;
      line-height: 0;
      pointer-events: none;
      box-shadow: 0 1px 5px rgba(80,40,120,0.20);
    }

    .ghost-overlay img {
      width: <?= (int)GHOST_OVERLAY_PX ?>px !important;
      height: <?= (int)GHOST_OVERLAY_PX ?>px !important;
      display: block !important;
      border-radius: 50%;
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
      .qr-frame {
        box-shadow: none;
      }
    }
  </style>
</head>
<body>

  <div class="label">
    <div class="brand">Ghost Laser</div>

    <div class="qr-wrap">
      <div class="qr-frame">
        <div id="qrcode"></div>
      </div>
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
    /* ── Rounded-rect helper ── */
    function glRoundedRect(ctx, x, y, w, h, r) {
      r = Math.min(r, w / 2, h / 2);
      ctx.beginPath();
      ctx.moveTo(x + r, y);
      ctx.lineTo(x + w - r, y);
      ctx.arc(x + w - r, y + r,     r, -Math.PI / 2, 0);
      ctx.lineTo(x + w, y + h - r);
      ctx.arc(x + w - r, y + h - r, r,  0,           Math.PI / 2);
      ctx.lineTo(x + r, y + h);
      ctx.arc(x + r,     y + h - r, r,  Math.PI / 2, Math.PI);
      ctx.lineTo(x, y + r);
      ctx.arc(x + r,     y + r,     r,  Math.PI,     Math.PI * 3 / 2);
      ctx.closePath();
    }

    /* ── Draw cute rounded QR on canvas ── */
    function glDrawCuteQR(canvas, qrData, size) {
      var n    = qrData.getModuleCount();
      var cell = size / n;
      var ctx  = canvas.getContext('2d');
      var dark = '#1a1a2e';

      /* White background */
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, size, size);

      /* Zones occupied by finder patterns + their 1-module separators */
      function isFinderZone(r, c) {
        if (r <= 7 && c <= 7)         return true; /* top-left  */
        if (r <= 7 && c >= n - 8)     return true; /* top-right */
        if (r >= n - 8 && c <= 7)     return true; /* bot-left  */
        return false;
      }

      /* Data modules → circles */
      ctx.fillStyle = dark;
      for (var r = 0; r < n; r++) {
        for (var c = 0; c < n; c++) {
          if (isFinderZone(r, c)) continue;
          if (qrData.isDark(r, c)) {
            ctx.beginPath();
            ctx.arc((c + 0.5) * cell, (r + 0.5) * cell, cell * 0.42, 0, Math.PI * 2);
            ctx.fill();
          }
        }
      }

      /* Finder patterns → rounded "eye" shapes */
      function drawFinder(startRow, startCol) {
        var px = startCol * cell;
        var py = startRow * cell;
        var s  = 7 * cell;

        /* Outer rounded square */
        ctx.fillStyle = dark;
        glRoundedRect(ctx, px, py, s, s, cell * 1.1);
        ctx.fill();

        /* White ring */
        ctx.fillStyle = '#ffffff';
        glRoundedRect(ctx, px + cell, py + cell, 5 * cell, 5 * cell, cell * 0.8);
        ctx.fill();

        /* Inner dark dot */
        ctx.fillStyle = dark;
        glRoundedRect(ctx, px + 2 * cell, py + 2 * cell, 3 * cell, 3 * cell, cell * 0.65);
        ctx.fill();
      }

      drawFinder(0,     0);      /* top-left  */
      drawFinder(0,     n - 7);  /* top-right */
      drawFinder(n - 7, 0);      /* bot-left  */
    }

    /* ── Boot ── */
    window.addEventListener('DOMContentLoaded', function () {
      var qrTarget = document.getElementById('qrcode');
      if (!qrTarget || typeof QRCode === 'undefined') {
        if (qrTarget) qrTarget.textContent = 'QR library unavailable.';
        return;
      }

      var SIZE = <?= (int)QR_SIZE_PX ?>;
      var url  = <?= json_encode($qr_url) ?>;

      /* Generate QR data via hidden off-screen element */
      var hiddenDiv = document.createElement('div');
      hiddenDiv.style.cssText = 'position:fixed;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;';
      document.body.appendChild(hiddenDiv);

      var qrInstance = new QRCode(hiddenDiv, {
        text:         url,
        width:        SIZE,
        height:       SIZE,
        colorDark:    '#000000',
        colorLight:   '#ffffff',
        correctLevel: QRCode.CorrectLevel.H
      });

      var qrData = qrInstance._oQRCode;
      document.body.removeChild(hiddenDiv);

      if (!qrData) {
        qrTarget.textContent = 'QR generation failed.';
        return;
      }

      /* Render cute rounded QR to a visible canvas */
      var canvas = document.createElement('canvas');
      canvas.width  = SIZE;
      canvas.height = SIZE;
      qrTarget.appendChild(canvas);
      glDrawCuteQR(canvas, qrData, SIZE);
    });
  </script>
</body>
</html>
