<?php
// collect-signature.php — Customer digital signature collection for invoices
require __DIR__ . '/db.php';

$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($invoice_id <= 0) {
    http_response_code(400);
    exit('Invalid invoice ID.');
}

// Load invoice (quotes table)
$stmt = $pdo->prepare('SELECT id, converted_invoice_no, customer_name, subtotal_amount, tax_amount FROM quotes WHERE id = ? LIMIT 1');
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();
if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}

$invoice_label   = !empty($invoice['converted_invoice_no']) ? $invoice['converted_invoice_no'] : 'INV-' . str_pad((string)$invoice['id'], 5, '0', STR_PAD_LEFT);
$total_amount    = (float)$invoice['subtotal_amount'] + (float)$invoice['tax_amount'];
$total_formatted = '$' . number_format($total_amount, 2);
$customer_name   = htmlspecialchars((string)$invoice['customer_name'], ENT_QUOTES, 'UTF-8');

$error   = null;
$success = false;

define('SIGNATURE_STORAGE_DIR', dirname(__DIR__) . '/protected_signatures');

// Empty canvas submissions produce very small PNG payloads in testing; requiring at least 500 bytes
// filters those out while still accepting normal handwritten signatures from mobile devices.
const SIG_MIN_PNG_BYTES = 500;

// ── Handle POST (signature submission) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_data = trim((string)($_POST['signature_data'] ?? ''));

    // Must be a data URI for a PNG image
    if (!str_starts_with($raw_data, 'data:image/png;base64,')) {
        $error = 'No signature was submitted. Please sign above and try again.';
    } else {
        $base64 = substr($raw_data, strlen('data:image/png;base64,'));
        $binary  = base64_decode($base64, true);

        if ($binary === false || strlen($binary) < SIG_MIN_PNG_BYTES) {
            $error = 'The signature data appears to be empty or invalid. Please sign again.';
        } else {
            if (!is_dir(SIGNATURE_STORAGE_DIR)) {
                mkdir(SIGNATURE_STORAGE_DIR, 0700, true);
            }

            do {
                $filename = 'sig_' . bin2hex(random_bytes(16)) . '.png';
                $filepath = SIGNATURE_STORAGE_DIR . '/' . $filename;
            } while (is_file($filepath));

            if (file_put_contents($filepath, $binary) === false) {
                $error = 'Could not save the signature file. Please try again.';
            } else {
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ins = $pdo->prepare('INSERT INTO invoice_signatures (quote_id, signature_path, ip_address) VALUES (?, ?, ?)');
                $ins->execute([$invoice_id, 'protected_signatures/' . $filename, $ip]);
                $success = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Sign Invoice <?= htmlspecialchars($invoice_label, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; }

  body {
    margin: 0;
    padding: 0;
    font-family: system-ui, -apple-system, Arial, sans-serif;
    background: #f0f4f8;
    color: #111827;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
  }

  .page {
    width: 100%;
    max-width: 600px;
    padding: 24px 16px 40px;
  }

  /* ── Header ── */
  .header {
    background: #1e3a5f;
    color: #fff;
    border-radius: 14px;
    padding: 20px 24px;
    margin-bottom: 20px;
    text-align: center;
  }
  .header-title {
    font-size: 13px;
    letter-spacing: .06em;
    text-transform: uppercase;
    opacity: .7;
    margin: 0 0 6px;
  }
  .header-invoice {
    font-size: 26px;
    font-weight: 700;
    margin: 0 0 4px;
    letter-spacing: -.01em;
  }
  .header-customer {
    font-size: 14px;
    opacity: .8;
    margin: 0 0 14px;
  }
  .header-total-label {
    font-size: 12px;
    opacity: .65;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin: 0;
  }
  .header-total-amount {
    font-size: 36px;
    font-weight: 800;
    margin: 2px 0 0;
    color: #7dd3fc;
  }

  /* ── Card ── */
  .card {
    background: #fff;
    border-radius: 14px;
    padding: 24px 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
    margin-bottom: 16px;
  }

  /* ── Instructions ── */
  .instructions {
    text-align: center;
    font-size: 17px;
    font-weight: 600;
    color: #1e3a5f;
    margin: 0 0 18px;
    line-height: 1.4;
  }

  /* ── Signature pad ── */
  .sig-wrap {
    position: relative;
    width: 100%;
    border: 2.5px solid #2563eb;
    border-radius: 10px;
    background: #fafafa;
    overflow: hidden;
    touch-action: none;
    cursor: crosshair;
  }
  .sig-wrap::after {
    content: 'Sign here';
    position: absolute;
    bottom: 12px;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 13px;
    color: #9ca3af;
    pointer-events: none;
    letter-spacing: .04em;
  }
  #sig-canvas {
    display: block;
    width: 100%;
    height: 240px;
    touch-action: none;
  }
  @media (min-width: 480px) {
    #sig-canvas { height: 280px; }
  }

  .sig-line {
    position: absolute;
    bottom: 36px;
    left: 32px;
    right: 32px;
    height: 1px;
    background: #d1d5db;
    pointer-events: none;
  }

  /* ── Buttons ── */
  .btn-row {
    display: flex;
    gap: 10px;
    margin-top: 16px;
  }
  .btn {
    flex: 1;
    padding: 16px 12px;
    border: none;
    border-radius: 10px;
    font-size: 17px;
    font-weight: 700;
    cursor: pointer;
    letter-spacing: -.01em;
    transition: opacity .15s, transform .1s;
  }
  .btn:active { transform: scale(.97); }
  .btn-clear {
    background: #f3f4f6;
    color: #374151;
    border: 1.5px solid #d1d5db;
  }
  .btn-clear:hover { background: #e5e7eb; }
  .btn-submit {
    background: #2563eb;
    color: #fff;
  }
  .btn-submit:hover { opacity: .92; }
  .btn-submit:disabled { opacity: .5; cursor: not-allowed; }

  /* ── Error ── */
  .error-box {
    background: #fef2f2;
    border: 1.5px solid #fca5a5;
    color: #b91c1c;
    border-radius: 10px;
    padding: 14px 16px;
    font-size: 15px;
    margin-bottom: 16px;
  }

  /* ── Success screen ── */
  .success-screen {
    text-align: center;
    padding: 16px 0 8px;
  }
  .success-icon {
    font-size: 72px;
    line-height: 1;
    margin-bottom: 12px;
  }
  .success-heading {
    font-size: 26px;
    font-weight: 800;
    color: #15803d;
    margin: 0 0 8px;
  }
  .success-sub {
    font-size: 16px;
    color: #6b7280;
    margin: 0 0 24px;
    line-height: 1.5;
  }
  .success-message {
    display: inline-block;
    padding: 14px 28px;
    background: #1e3a5f;
    color: #fff;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
  }
</style>
</head>
<body>
<div class="page">

  <!-- Invoice header -->
  <div class="header">
    <p class="header-title">Invoice</p>
    <p class="header-invoice"><?= htmlspecialchars($invoice_label, ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($customer_name !== ''): ?>
      <p class="header-customer"><?= $customer_name ?></p>
    <?php endif; ?>
    <p class="header-total-label">Total Due</p>
    <p class="header-total-amount"><?= $total_formatted ?></p>
  </div>

  <?php if ($success): ?>

    <!-- ── Success screen ── -->
    <div class="card">
      <div class="success-screen">
        <div class="success-icon">✅</div>
        <p class="success-heading">Thank You!</p>
        <p class="success-sub">Your signature has been saved.<br>Invoice <?= htmlspecialchars($invoice_label, ENT_QUOTES, 'UTF-8') ?> is now signed.</p>
        <span class="success-message">You can close this page now.</span>
      </div>
    </div>

  <?php else: ?>

    <?php if ($error !== null): ?>
      <div class="error-box"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- ── Signature card ── -->
    <div class="card">
      <p class="instructions">Please sign below to confirm you are satisfied with the work</p>

      <div class="sig-wrap">
        <canvas id="sig-canvas"></canvas>
        <div class="sig-line"></div>
      </div>

      <form method="POST" id="sig-form">
        <input type="hidden" name="signature_data" id="signature_data">
        <div class="btn-row">
          <button type="button" class="btn btn-clear" id="btn-clear">Clear</button>
          <button type="submit" class="btn btn-submit" id="btn-submit">Submit Signature</button>
        </div>
      </form>
    </div>

  <?php endif; ?>
</div>

<script>
(function () {
  var canvas  = document.getElementById('sig-canvas');
  var form    = document.getElementById('sig-form');
  var btnClear  = document.getElementById('btn-clear');
  var btnSubmit = document.getElementById('btn-submit');
  var hiddenInput = document.getElementById('signature_data');

  if (!canvas || !form) return;

  var ctx = canvas.getContext('2d');
  var drawing = false;
  var hasMark = false;

  // ── Size canvas to its CSS size (hi-DPI aware) ──
  function resizeCanvas() {
    var dpr  = window.devicePixelRatio || 1;
    var rect = canvas.getBoundingClientRect();
    var prevData = hasMark ? canvas.toDataURL() : null;

    canvas.width  = Math.round(rect.width  * dpr);
    canvas.height = Math.round(rect.height * dpr);
    ctx.scale(dpr, dpr);
    setupCtx();

    if (prevData) {
      var img = new Image();
      img.onload = function () { ctx.drawImage(img, 0, 0, rect.width, rect.height); };
      img.src = prevData;
    }
  }

  function setupCtx() {
    ctx.strokeStyle = '#111827';
    ctx.lineWidth   = 2.4;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';
  }

  // ── Coordinate helpers ──
  function getPos(e) {
    var rect = canvas.getBoundingClientRect();
    if (e.touches) {
      return {
        x: e.touches[0].clientX - rect.left,
        y: e.touches[0].clientY - rect.top
      };
    }
    return {
      x: e.clientX - rect.left,
      y: e.clientY - rect.top
    };
  }

  // ── Drawing events ──
  function onStart(e) {
    e.preventDefault();
    drawing = true;
    hasMark = true;
    var p = getPos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
  }

  function onMove(e) {
    e.preventDefault();
    if (!drawing) return;
    var p = getPos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
  }

  function onEnd(e) {
    e.preventDefault();
    if (!drawing) return;
    drawing = false;
    ctx.beginPath();
  }

  canvas.addEventListener('mousedown',  onStart, { passive: false });
  canvas.addEventListener('mousemove',  onMove,  { passive: false });
  canvas.addEventListener('mouseup',    onEnd,   { passive: false });
  canvas.addEventListener('mouseleave', onEnd,   { passive: false });

  canvas.addEventListener('touchstart', onStart, { passive: false });
  canvas.addEventListener('touchmove',  onMove,  { passive: false });
  canvas.addEventListener('touchend',   onEnd,   { passive: false });
  canvas.addEventListener('touchcancel',onEnd,   { passive: false });

  // ── Clear ──
  if (btnClear) {
    btnClear.addEventListener('click', function () {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      hasMark = false;
    });
  }

  // ── Submit: attach data URI before POST ──
  if (form) {
    form.addEventListener('submit', function (e) {
      if (!hasMark) {
        e.preventDefault();
        alert('Please sign before submitting.');
        return;
      }
      hiddenInput.value = canvas.toDataURL('image/png');
      if (btnSubmit) btnSubmit.disabled = true;
    });
  }

  // ── Initial resize + watch for layout changes ──
  resizeCanvas();
  window.addEventListener('resize', resizeCanvas);
})();
</script>
</body>
</html>
