<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (empty($_SESSION['machine_delete_csrf'])) {
  $_SESSION['machine_delete_csrf'] = bin2hex(random_bytes(24));
}

// Ordered by cutting area descending (largest first); fall back to machine dims; NULLs last
$stmt = $pdo->query("
  SELECT * FROM machines
  ORDER BY
    CASE WHEN cut_width_mm IS NULL OR cut_length_mm IS NULL THEN 1 ELSE 0 END ASC,
    (COALESCE(cut_width_mm, 0) * COALESCE(cut_length_mm, 0)) DESC,
    name ASC
");
$machines = $stmt->fetchAll();

/**
 * Format a decimal inch value as a human-readable imperial string.
 * e.g. 48.0  → "4ft"
 *      50.0  → "4'2\""
 *      6.5   → "6.5\""
 */
function fmt_inches_imperial(?string $inches_val): string {
  if ($inches_val === null || $inches_val === '') return '—';
  $in = (float)$inches_val;
  if ($in <= 0) return '—';
  $ft  = (int)floor($in / 12);
  $rem = round($in - ($ft * 12), 2);
  if ($ft > 0 && $rem == 0) return "{$ft}ft";
  if ($ft === 0) return "{$rem}\"";
  return "{$ft}'{$rem}\"";
}

/**
 * Format a decimal mm value for display.
 * e.g. 1219.2 → "1219mm"
 */
function fmt_mm_display(?string $mm_val): string {
  if ($mm_val === null || $mm_val === '') return '—';
  $mm = (float)$mm_val;
  if ($mm <= 0) return '—';
  return (string)(int)round($mm) . 'mm';
}

render_header('Machines');
?>

<style>
  .machines-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #0c4a6e 100%);
    border-radius: 10px;
    padding: 48px 36px;
    margin-bottom: 0;
    position: relative;
    overflow: hidden;
  }
  .machines-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(14,165,233,0.18) 0%, transparent 60%);
    pointer-events: none;
  }
  .machines-hero-content {
    position: relative;
    z-index: 1;
  }
  .machines-hero h1 {
    font-size: 2.2rem;
    font-weight: 700;
    color: #f1f5f9;
    margin: 0 0 8px;
  }
  .machines-hero p {
    color: #94a3b8;
    margin: 0 0 20px;
    font-size: 1rem;
  }
  .machines-hero-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }

  /* Machine cards */
  .machine-card {
    display: flex;
    gap: 24px;
    align-items: flex-start;
    padding: 20px;
    border-bottom: 1px solid var(--b);
  }
  .machine-card:last-child { border-bottom: 0; }
  .machine-photos {
    display: flex;
    flex-direction: column;
    gap: 10px;
    flex-shrink: 0;
  }
  .machine-photo {
    display: block;
    width: 320px;
    max-width: 100%;
    border-radius: 8px;
    border: 1px solid var(--b);
    object-fit: contain;
    background: #f8fafc;
  }
  .machine-photo-placeholder {
    width: 320px;
    height: 200px;
    border-radius: 8px;
    border: 1px solid var(--b);
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    font-size: 2.5rem;
  }
  .machine-info {
    flex: 1;
    min-width: 0;
  }
  .machine-name {
    font-size: 1.4rem;
    font-weight: 700;
    margin: 0 0 4px;
    color: var(--t);
  }
  .machine-model {
    font-size: 1rem;
    color: #64748b;
    margin: 0 0 10px;
  }
  .machine-size-badge {
    display: inline-block;
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    color: #0369a1;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 0.92rem;
    font-weight: 600;
    margin-bottom: 12px;
    font-family: monospace;
  }
  .machine-desc {
    color: #475569;
    font-size: 0.95rem;
    margin: 0 0 14px;
    white-space: pre-wrap;
  }
  .machine-inactive-badge {
    display: inline-block;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    border-radius: 6px;
    padding: 2px 8px;
    font-size: 0.82rem;
    font-weight: 600;
    margin-bottom: 10px;
  }

  @media (max-width: 700px) {
    .machine-card { flex-direction: column; }
    .machine-photo, .machine-photo-placeholder { width: 100%; }
  }
</style>

<div class="card machines-hero page-header" style="padding:0; overflow:hidden;">
  <div class="machines-hero" style="margin:0; border-radius:0; border:0; width:100%; box-sizing:border-box;">
    <div class="machines-hero-content">
      <h1>⚙️ Machines <span style="font-size:1.2rem; font-weight:400; color:#64748b;">(<?= count($machines) ?>)</span></h1>
      <p>Your full equipment catalog — sizes, photos, and specs in one place.</p>
      <div class="machines-hero-actions">
        <a class="btn primary" href="machine_form.php">+ Add Machine</a>
      </div>
    </div>
  </div>
</div>

<?php if (empty($machines)): ?>
<div class="card">
  <p class="muted">No machines yet. Click <strong>+ Add Machine</strong> to get started.</p>
</div>
<?php else: ?>
<div class="card" style="padding:0; overflow:hidden;">
  <?php foreach ($machines as $m): ?>
  <?php
    $primary_url   = ($m['primary_photo']   !== null && $m['primary_photo']   !== '') ? 'uploads/' . rawurlencode($m['primary_photo'])   : '';
    $secondary_url = ($m['secondary_photo'] !== null && $m['secondary_photo'] !== '') ? 'uploads/' . rawurlencode($m['secondary_photo']) : '';
    $tertiary_url  = (isset($m['tertiary_photo']) && $m['tertiary_photo']  !== null && $m['tertiary_photo']  !== '') ? 'uploads/' . rawurlencode($m['tertiary_photo'])  : '';

    // Cutting area (preferred) or machine dimensions for size badge
    $has_cut  = (isset($m['cut_width_mm']) && $m['cut_width_mm'] !== null) || (isset($m['cut_length_mm']) && $m['cut_length_mm'] !== null);
    $has_mach = ($m['width_mm'] !== null || $m['height_mm'] !== null);

    if ($has_cut) {
      $cw_imp = fmt_inches_imperial($m['cut_width']  ?? null);
      $cl_imp = fmt_inches_imperial($m['cut_length'] ?? null);
      $cw_mm  = fmt_mm_display($m['cut_width_mm']  ?? null);
      $cl_mm  = fmt_mm_display($m['cut_length_mm'] ?? null);
      $size_str = '✂️ ' . $cl_imp . ' × ' . $cw_imp . ' (' . $cl_mm . ' × ' . $cw_mm . ')';
    } elseif ($has_mach) {
      $mw_imp = fmt_inches_imperial($m['width']);
      $ml_imp = fmt_inches_imperial($m['height']);
      $mw_mm  = fmt_mm_display($m['width_mm']);
      $ml_mm  = fmt_mm_display($m['height_mm']);
      $size_str = '📐 ' . $ml_imp . ' × ' . $mw_imp . ' (' . $ml_mm . ' × ' . $mw_mm . ')';
    } else {
      $size_str = '';
    }
  ?>
  <div class="machine-card">
    <div class="machine-photos">
      <?php if ($primary_url !== ''): ?>
        <a href="<?= h($primary_url) ?>" target="_blank" rel="noopener noreferrer" title="View photo 1">
          <img class="machine-photo"
               src="<?= h($primary_url) ?>"
               alt="<?= h($m['name']) ?> — photo 1"
               loading="lazy"
               decoding="async" />
        </a>
      <?php else: ?>
        <div class="machine-photo-placeholder" aria-label="No photo available">📷</div>
      <?php endif; ?>

      <?php if ($secondary_url !== ''): ?>
        <a href="<?= h($secondary_url) ?>" target="_blank" rel="noopener noreferrer" title="View photo 2">
          <img class="machine-photo"
               src="<?= h($secondary_url) ?>"
               alt="<?= h($m['name']) ?> — photo 2"
               loading="lazy"
               decoding="async" />
        </a>
      <?php endif; ?>

      <?php if ($tertiary_url !== ''): ?>
        <a href="<?= h($tertiary_url) ?>" target="_blank" rel="noopener noreferrer" title="View photo 3">
          <img class="machine-photo"
               src="<?= h($tertiary_url) ?>"
               alt="<?= h($m['name']) ?> — photo 3"
               loading="lazy"
               decoding="async" />
        </a>
      <?php endif; ?>
    </div>

    <div class="machine-info">
      <?php if (!$m['is_active']): ?>
        <span class="machine-inactive-badge">Inactive</span>
      <?php endif; ?>
      <?php if (isset($m['is_visible']) && !$m['is_visible']): ?>
        <span class="machine-inactive-badge">Hidden</span>
      <?php endif; ?>
      <?php if (isset($m['is_catalog']) && !$m['is_catalog']): ?>
        <span class="machine-inactive-badge">Off Catalog</span>
      <?php endif; ?>

      <h2 class="machine-name"><?= h($m['name']) ?></h2>

      <?php if ($m['model'] !== null && $m['model'] !== ''): ?>
        <p class="machine-model">Model: <?= h($m['model']) ?></p>
      <?php endif; ?>

      <?php if ($size_str !== ''): ?>
        <div class="machine-size-badge">📐 <?= h($size_str) ?></div>
      <?php endif; ?>

      <?php if ($m['description'] !== null && $m['description'] !== ''): ?>
        <p class="machine-desc"><?= h($m['description']) ?></p>
      <?php endif; ?>

      <div class="actions">
        <a class="btn" href="machine_form.php?id=<?= (int)$m['id'] ?>">Edit</a>
        <?php if (is_admin()): ?>
        <form method="post" action="machine_delete.php" style="display:inline;"
              onsubmit="return confirm('Delete this machine? This cannot be undone.');">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['machine_delete_csrf']) ?>" />
          <input type="hidden" name="id" value="<?= (int)$m['id'] ?>" />
          <button type="submit" class="btn danger">Delete</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
