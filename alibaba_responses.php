<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin_or_moderator();

$responses = [
  [
    'title' => 'Machines - No Prototypes',
    'body' => "Thank you for your message. At this time, we do not purchase prototype machines. We only source production-ready machines from established manufacturers with a proven build history.",
  ],
  [
    'title' => 'Parts - Manufacturer Only',
    'body' => "Thank you. For parts orders, we only purchase directly from the original manufacturer. We are not placing parts orders through trading companies or third-party resellers.",
  ],
  [
    'title' => 'No Custom Voltage / Specs',
    'body' => "Thank you for the quote. We are not requesting custom voltage, custom specifications, or special configuration changes for this order. Please quote your standard production version only.",
  ],
  [
    'title' => 'Stock Items Only',
    'body' => "We are only considering items that are currently in stock and ready for normal lead-time shipment. We are not reviewing made-to-order or future-production availability for this request.",
  ],
  [
    'title' => 'No Price Changes After Quote',
    'body' => "Please note that once a quote is provided, we expect the quoted pricing to remain unchanged throughout the order process. We are not accepting price increases after the quote has been issued.",
  ],
];

render_header('Alibaba Quick Responses');
?>

<style>
.alibaba-header {
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:16px;
  flex-wrap:wrap;
}
.alibaba-grid {
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));
  gap:16px;
}
.alibaba-response-card {
  border:1px solid #dbe4f0;
  border-radius:14px;
  padding:16px;
  background:linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
  box-shadow:0 10px 24px rgba(15, 23, 42, 0.06);
}
.alibaba-response-title {
  margin:0 0 8px;
  font-size:1rem;
}
.alibaba-response-text {
  width:100%;
  min-height:150px;
  resize:vertical;
  font:inherit;
  line-height:1.5;
  background:#fff;
}
.alibaba-response-actions {
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
  margin-top:12px;
}
.alibaba-copy-status {
  color:#166534;
  font-size:13px;
  font-weight:600;
  opacity:0;
  transition:opacity .15s ease;
}
.alibaba-copy-status.show {
  opacity:1;
}
@media (max-width: 640px) {
  .alibaba-response-actions {
    flex-direction:column;
    align-items:stretch;
  }
}
</style>

<div class="card">
  <div class="alibaba-header">
    <div>
      <h1 style="margin:0 0 6px;">Alibaba Quick Responses</h1>
      <p class="muted" style="margin:0;">Ready-to-copy sourcing responses for the team.</p>
    </div>
    <span class="badge new">5 Responses</span>
  </div>
</div>

<div class="alibaba-grid">
  <?php foreach ($responses as $index => $response): ?>
    <?php $field_id = 'alibaba-response-' . ($index + 1); ?>
    <div class="alibaba-response-card">
      <h2 class="alibaba-response-title"><?= h($response['title']) ?></h2>
      <textarea
        id="<?= h($field_id) ?>"
        class="alibaba-response-text"
        readonly
      ><?= h($response['body']) ?></textarea>
      <div class="alibaba-response-actions">
        <span class="muted">One click to copy.</span>
        <div class="actions">
          <span class="alibaba-copy-status" id="<?= h($field_id) ?>-status" aria-live="polite">Copied</span>
          <button type="button" class="btn primary js-copy-response" data-target="<?= h($field_id) ?>">Copy Response</button>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<script>
(() => {
  const buttons = document.querySelectorAll('.js-copy-response');
  if (!buttons.length) return;

  const showStatus = (statusEl) => {
    if (!statusEl) return;
    statusEl.classList.add('show');
    window.setTimeout(() => statusEl.classList.remove('show'), 1400);
  };

  const fallbackCopy = (field) => {
    field.focus();
    field.select();
    document.execCommand('copy');
    field.setSelectionRange(0, 0);
    field.blur();
  };

  buttons.forEach((button) => {
    button.addEventListener('click', async () => {
      const field = document.getElementById(button.dataset.target || '');
      if (!field) return;
      const statusEl = document.getElementById(field.id + '-status');

      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(field.value);
        } else {
          fallbackCopy(field);
        }
        showStatus(statusEl);
      } catch (error) {
        fallbackCopy(field);
        showStatus(statusEl);
      }
    });
  });
})();
</script>

<?php render_footer(); ?>
