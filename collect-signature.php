<?php
require __DIR__ . '/db.php';

if (!function_exists('collect_signature_invoice_label')) {
    function collect_signature_invoice_label(array $invoice): string {
        return !empty($invoice['converted_invoice_no'])
            ? (string)$invoice['converted_invoice_no']
            : 'INV-' . str_pad((string)$invoice['id'], 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('collect_signature_client_ip')) {
    function collect_signature_client_ip(): ?string {
        $remote_addr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return $remote_addr !== '' && filter_var($remote_addr, FILTER_VALIDATE_IP) ? $remote_addr : null;
    }
}

if (!function_exists('collect_signature_inactive_cleanup')) {
    function collect_signature_inactive_cleanup(PDO $pdo, int $invoice_id): void {
        try {
            $stmt = $pdo->prepare(
                'UPDATE quotes
                    SET waiting_for_signature = 0,
                        signature_access_token_hash = NULL,
                        signature_access_expires_at = NULL
                  WHERE id = ?'
            );
            $stmt->execute([$invoice_id]);
        } catch (Throwable $e) {
            // Best-effort cleanup only.
        }
    }
}

const SIG_MIN_PNG_BYTES = 500;
const MAX_FILENAME_ALLOCATION_ATTEMPTS = 10;
const COLLECT_SIGNATURE_TOKEN_PATTERN = '/^[a-f0-9]{64}$/i';

$success = false;
$error = null;
$page_variant = 'missing';
$invoice = null;
$invoice_label = '';
$total_formatted = '';
$customer_name = '';
$show_signature_form = false;
$request_method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$raw_token = trim((string)($request_method === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '')));
$token_hash = preg_match(COLLECT_SIGNATURE_TOKEN_PATTERN, $raw_token) === 1
    ? invoice_signature_access_token_hash($raw_token)
    : '';

if ($token_hash !== '') {
    $stmt = $pdo->prepare(
        'SELECT id, converted_invoice_no, customer_name, subtotal_amount, tax_amount,
                waiting_for_signature, signature_access_token_hash, signature_access_expires_at
           FROM quotes
          WHERE signature_access_token_hash = ?
          LIMIT 1'
    );
    $stmt->execute([$token_hash]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($invoice) {
        $link_is_active = (int)($invoice['waiting_for_signature'] ?? 0) === 1
            && !invoice_signature_access_is_expired((string)($invoice['signature_access_expires_at'] ?? ''));

        if ($link_is_active) {
            $page_variant = 'active';
            $invoice_label = collect_signature_invoice_label($invoice);
            $total_amount = (float)$invoice['subtotal_amount'] + (float)$invoice['tax_amount'];
            $total_formatted = '$' . number_format($total_amount, 2);
            $customer_name = htmlspecialchars((string)$invoice['customer_name'], ENT_QUOTES, 'UTF-8');
            $show_signature_form = true;
        } else {
            $page_variant = 'inactive';
            http_response_code(410);
            collect_signature_inactive_cleanup($pdo, (int)$invoice['id']);
        }
    }
}

if ($page_variant === 'missing') {
    http_response_code(404);
}

if ($request_method === 'POST' && $page_variant === 'active' && $invoice) {
    $raw_data = trim((string)($_POST['signature_data'] ?? ''));

    if (!str_starts_with($raw_data, 'data:image/png;base64,')) {
        $error = 'No signature was submitted. Please sign above and try again.';
    } else {
        $base64 = substr($raw_data, strlen('data:image/png;base64,'));
        $binary = base64_decode($base64, true);

        if ($binary === false || strlen($binary) < SIG_MIN_PNG_BYTES) {
            $error = 'The signature data appears to be empty or invalid. Please sign again.';
        } else {
            $image_info = @getimagesizefromstring($binary);
            if (!is_array($image_info) || (($image_info['mime'] ?? '') !== 'image/png')) {
                $error = 'The signature data appears to be empty or invalid. Please sign again.';
            }

            $signature_storage_dir = invoice_signature_storage_dir();
            if (!is_dir($signature_storage_dir)) {
                if (!mkdir($signature_storage_dir, 0700, true) && !is_dir($signature_storage_dir)) {
                    $error = 'Could not prepare secure signature storage. Please try again.';
                } else {
                    @chmod($signature_storage_dir, 0700);
                }
            }

            if ($error === null) {
                $filename = '';
                $filepath = '';
                $allocated = false;
                for ($attempt = 0; $attempt < MAX_FILENAME_ALLOCATION_ATTEMPTS; $attempt++) {
                    $filename = 'sig_' . bin2hex(random_bytes(16)) . '.png';
                    $filepath = $signature_storage_dir . '/' . $filename;
                    if (!is_file($filepath)) {
                        $allocated = true;
                        break;
                    }
                }

                if (!$allocated) {
                    $error = 'Could not allocate secure signature storage. Please try again.';
                }
            }

            if ($error === null) {
                $file_written = false;
                try {
                    $pdo->beginTransaction();

                    $lock_stmt = $pdo->prepare(
                        'SELECT waiting_for_signature, signature_access_token_hash, signature_access_expires_at
                           FROM quotes
                          WHERE id = ?
                          LIMIT 1
                          FOR UPDATE'
                    );
                    $lock_stmt->execute([(int)$invoice['id']]);
                    $locked_invoice = $lock_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    $locked_hash = trim((string)($locked_invoice['signature_access_token_hash'] ?? ''));
                    $locked_waiting = (int)($locked_invoice['waiting_for_signature'] ?? 0) === 1;
                    $locked_active = $locked_invoice
                        && $locked_hash !== ''
                        && hash_equals($locked_hash, $token_hash)
                        && $locked_waiting
                        && !invoice_signature_access_is_expired((string)($locked_invoice['signature_access_expires_at'] ?? ''));

                    if (!$locked_active) {
                        throw new RuntimeException('signature_link_inactive');
                    }

                    if (file_put_contents($filepath, $binary) === false) {
                        throw new RuntimeException('signature_file_write_failed');
                    }
                    $file_written = true;

                    $ip = collect_signature_client_ip();
                    $ins = $pdo->prepare('INSERT INTO invoice_signatures (quote_id, signature_path, ip_address) VALUES (?, ?, ?)');
                    $ins->execute([(int)$invoice['id'], invoice_signature_relative_path($filename), $ip]);

                    $deactivate_stmt = $pdo->prepare(
                        'UPDATE quotes
                            SET waiting_for_signature = 0,
                                signature_access_token_hash = NULL,
                                signature_access_expires_at = NULL
                          WHERE id = ?'
                    );
                    $deactivate_stmt->execute([(int)$invoice['id']]);

                    $pdo->commit();
                    $success = true;
                    $show_signature_form = false;
                    $page_variant = 'success';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($file_written && is_file($filepath)) {
                        @unlink($filepath);
                    }
                    if ($e->getMessage() === 'signature_link_inactive') {
                        $error = null;
                        $show_signature_form = false;
                        $page_variant = 'inactive';
                        http_response_code(410);
                        collect_signature_inactive_cleanup($pdo, (int)$invoice['id']);
                    } elseif ($e->getMessage() === 'signature_file_write_failed') {
                        $error = 'Could not save the signature file. Please try again.';
                    } else {
                        $error = 'Could not save the signature. Please try again.';
                    }
                }
            }
        }
    }
}

$page_title = 'Signature Request';
if ($success && $invoice_label !== '') {
    $page_title = 'Invoice Signed';
} elseif ($show_signature_form && $invoice_label !== '') {
    $page_title = 'Sign Invoice ' . $invoice_label;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; }

  :root {
    color-scheme: light;
    --bg: #f3f6fb;
    --card: #ffffff;
    --text: #111827;
    --muted: #64748b;
    --brand: #1e3a5f;
    --brand-strong: #16304f;
    --accent: #2563eb;
    --accent-soft: rgba(37, 99, 235, 0.14);
    --danger-bg: #fef2f2;
    --danger-border: #fecaca;
    --danger-text: #b91c1c;
    --shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
  }

  body {
    margin: 0;
    min-height: 100vh;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background: radial-gradient(circle at top, #ffffff 0, var(--bg) 52%);
    color: var(--text);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
  }

  .page {
    width: 100%;
    max-width: 640px;
  }

  .card {
    background: var(--card);
    border-radius: 24px;
    box-shadow: var(--shadow);
    overflow: hidden;
  }

  .hero {
    padding: 30px 28px;
    background: linear-gradient(135deg, var(--brand) 0%, #24496f 100%);
    color: #fff;
  }

  .hero-title {
    margin: 0;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    opacity: 0.74;
  }

  .hero-heading {
    margin: 10px 0 4px;
    font-size: 28px;
    line-height: 1.1;
    letter-spacing: -0.02em;
  }

  .hero-subtitle {
    margin: 0;
    color: rgba(255, 255, 255, 0.82);
    font-size: 15px;
    line-height: 1.6;
  }

  .hero-meta {
    display: grid;
    gap: 16px;
    margin-top: 22px;
  }

  .hero-meta-block small {
    display: block;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.66;
    margin-bottom: 4px;
  }

  .hero-meta-block strong {
    display: block;
    font-size: 20px;
    letter-spacing: -0.02em;
  }

  .content {
    padding: 28px;
  }

  .instructions {
    text-align: center;
    font-size: 17px;
    font-weight: 600;
    color: var(--brand);
    margin: 0 0 18px;
    line-height: 1.4;
  }

  .sig-wrap {
    position: relative;
    width: 100%;
    border: 2.5px solid var(--accent);
    border-radius: 16px;
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
    .hero-meta { grid-template-columns: repeat(2, minmax(0, 1fr)); }
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

  .btn-row {
    display: flex;
    gap: 10px;
    margin-top: 16px;
  }

  .btn {
    flex: 1;
    padding: 16px 12px;
    border: none;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    letter-spacing: -.01em;
    transition: opacity .15s, transform .1s, background-color .15s;
  }

  .btn:active { transform: scale(.98); }
  .btn-clear {
    background: #f3f4f6;
    color: #374151;
    border: 1.5px solid #d1d5db;
  }

  .btn-clear:hover { background: #e5e7eb; }
  .btn-submit {
    background: var(--accent);
    color: #fff;
  }

  .btn-submit:hover { opacity: .92; }
  .btn-submit:disabled { opacity: .5; cursor: not-allowed; }

  .error-box {
    background: var(--danger-bg);
    border: 1px solid var(--danger-border);
    color: var(--danger-text);
    border-radius: 14px;
    padding: 14px 16px;
    font-size: 15px;
    margin-bottom: 16px;
  }

  .status-shell {
    text-align: center;
    padding: 52px 28px;
  }

  .status-badge {
    width: 72px;
    height: 72px;
    margin: 0 auto 22px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    font-weight: 800;
    color: var(--brand);
    background: var(--accent-soft);
  }

  .status-code {
    margin: 0;
    color: #94a3b8;
    font-size: 13px;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    font-weight: 700;
  }

  .status-title {
    margin: 12px 0 10px;
    font-size: 30px;
    line-height: 1.15;
    letter-spacing: -0.03em;
    color: #0f172a;
  }

  .status-message {
    max-width: 360px;
    margin: 0 auto;
    color: var(--muted);
    font-size: 16px;
    line-height: 1.7;
  }

  .success-screen {
    text-align: center;
    padding: 8px 0 2px;
  }

  .success-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 20px;
    border-radius: 999px;
    background: rgba(22, 163, 74, 0.14);
    color: #15803d;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
  }

  .success-heading {
    font-size: 30px;
    font-weight: 800;
    color: #15803d;
    margin: 0 0 8px;
    letter-spacing: -0.03em;
  }

  .success-sub {
    font-size: 16px;
    color: var(--muted);
    margin: 0 0 24px;
    line-height: 1.6;
  }

  .success-message {
    display: inline-block;
    padding: 14px 28px;
    background: var(--brand);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
  }

  .success-message:hover { background: var(--brand-strong); }
</style>
</head>
<body>
<div class="page">
  <?php if ($show_signature_form || $success): ?>
    <div class="card">
      <div class="hero">
        <p class="hero-title">Invoice Signature</p>
        <h1 class="hero-heading"><?= htmlspecialchars($invoice_label, ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($customer_name !== ''): ?>
          <p class="hero-subtitle"><?= $customer_name ?></p>
        <?php endif; ?>
        <div class="hero-meta">
          <div class="hero-meta-block">
            <small>Total due</small>
            <strong><?= htmlspecialchars($total_formatted, ENT_QUOTES, 'UTF-8') ?></strong>
          </div>
          <div class="hero-meta-block">
            <small>Status</small>
            <strong><?= $success ? 'Signed' : 'Awaiting signature' ?></strong>
          </div>
        </div>
      </div>

      <div class="content">
        <?php if ($success): ?>
          <div class="success-screen">
            <div class="success-icon">✓</div>
            <p class="success-heading">Thank you</p>
            <p class="success-sub">Your signature has been saved successfully.</p>
            <button type="button" class="success-message" onclick="window.close()">You can close this page now.</button>
          </div>
        <?php else: ?>
          <?php if ($error !== null): ?>
            <div class="error-box"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>

          <p class="instructions">Please sign below to complete this invoice signature request.</p>

          <div class="sig-wrap">
            <canvas id="sig-canvas"></canvas>
            <div class="sig-line"></div>
          </div>

          <form method="POST" id="sig-form">
            <input type="hidden" name="token" value="<?= htmlspecialchars($raw_token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="signature_data" id="signature_data">
            <div class="btn-row">
              <button type="button" class="btn btn-clear" id="btn-clear">Clear</button>
              <button type="submit" class="btn btn-submit" id="btn-submit">Submit Signature</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <?php
      $inactive = $page_variant === 'inactive';
      $status_code = $inactive ? '410' : '404';
      $status_title = $inactive ? 'This link is no longer active' : 'Nothing to see here';
      $status_message = $inactive
        ? 'This signature request is no longer available. If you still need to sign, please request a new secure link.'
        : 'The page you requested is unavailable or has moved.';
    ?>
    <div class="card status-shell">
      <div class="status-badge" aria-label="<?= htmlspecialchars($inactive ? 'Inactive signature link' : 'Page not found', ENT_QUOTES, 'UTF-8') ?>"><?= $inactive ? '!' : '?' ?></div>
      <p class="status-code"><?= $status_code ?> · signature request</p>
      <h1 class="status-title"><?= htmlspecialchars($status_title, ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="status-message"><?= htmlspecialchars($status_message, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  <?php endif; ?>
</div>

<?php if ($show_signature_form): ?>
<script>
(function () {
  var canvas = document.getElementById('sig-canvas');
  var form = document.getElementById('sig-form');
  var btnClear = document.getElementById('btn-clear');
  var btnSubmit = document.getElementById('btn-submit');
  var hiddenInput = document.getElementById('signature_data');

  if (!canvas || !form) return;

  var ctx = canvas.getContext('2d');
  var drawing = false;
  var hasMark = false;

  function resizeCanvas() {
    var dpr = window.devicePixelRatio || 1;
    var rect = canvas.getBoundingClientRect();
    var prevData = hasMark ? canvas.toDataURL() : null;

    canvas.width = Math.round(rect.width * dpr);
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
    ctx.lineWidth = 2.4;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
  }

  function clearCanvas() {
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.restore();
  }

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

  canvas.addEventListener('mousedown', onStart, { passive: false });
  canvas.addEventListener('mousemove', onMove, { passive: false });
  canvas.addEventListener('mouseup', onEnd, { passive: false });
  canvas.addEventListener('mouseleave', onEnd, { passive: false });
  canvas.addEventListener('touchstart', onStart, { passive: false });
  canvas.addEventListener('touchmove', onMove, { passive: false });
  canvas.addEventListener('touchend', onEnd, { passive: false });
  canvas.addEventListener('touchcancel', onEnd, { passive: false });

  if (btnClear) {
    btnClear.addEventListener('click', function () {
      clearCanvas();
      hasMark = false;
    });
  }

  form.addEventListener('submit', function (e) {
    if (!hasMark) {
      e.preventDefault();
      alert('Please sign before submitting.');
      return;
    }
    hiddenInput.value = canvas.toDataURL('image/png');
    if (btnSubmit) btnSubmit.disabled = true;
  });

  resizeCanvas();
  window.addEventListener('resize', resizeCanvas);
})();
</script>
<?php endif; ?>
</body>
</html>
