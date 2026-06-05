<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';

require_login();

$sections = [
  'Main Workflows' => [
    ['icon' => '📨', 'term' => 'RFQ', 'description' => 'We send a request to suppliers asking for their pricing.'],
    ['icon' => '💬', 'term' => 'Supplier Quote', 'description' => 'Suppliers reply with their quotes.'],
    ['icon' => '📄', 'term' => 'Purchase Order (PO)', 'description' => 'After we accept a supplier\'s quote, we send them an official Purchase Order.'],
    ['icon' => '🚚', 'term' => 'Shipping Form', 'description' => 'Once the supplier accepts our PO, we enter their shipping information here to track the delivery.'],
  ],
  'Customer Side' => [
    ['icon' => '🧾', 'term' => 'Quote', 'description' => 'We create a price quote for our customers.'],
    ['icon' => '💵', 'term' => 'Invoice', 'description' => 'When a customer accepts our quote, we convert it into an invoice.'],
  ],
  'Other' => [
    ['icon' => '⚡', 'term' => 'Quick Order Form', 'description' => 'Used for fast, simple orders that skip the normal process.'],
  ],
];

render_header('Help Glossary');
?>

<style>
.help-glossary-shell {
  max-width: 1180px;
  margin: 0 auto;
}
.help-glossary-hero {
  position: relative;
  overflow: hidden;
  border: 1px solid #dbeafe;
  border-radius: 24px;
  padding: 36px;
  margin-bottom: 22px;
  background:
    radial-gradient(circle at top right, rgba(96, 165, 250, 0.34), transparent 30%),
    radial-gradient(circle at bottom left, rgba(59, 130, 246, 0.18), transparent 32%),
    linear-gradient(145deg, #0f172a 0%, #1d4ed8 55%, #60a5fa 100%);
  color: #fff;
  box-shadow: 0 22px 48px rgba(37, 99, 235, 0.25);
}
.help-glossary-hero::after {
  content: '';
  position: absolute;
  inset: auto -80px -90px auto;
  width: 240px;
  height: 240px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.12);
  filter: blur(4px);
}
.help-glossary-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.16);
  border: 1px solid rgba(255, 255, 255, 0.18);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.help-glossary-title {
  margin: 16px 0 12px;
  font-size: 38px;
  line-height: 1.15;
  color: #fff;
}
.help-glossary-subtitle {
  margin: 0;
  max-width: 760px;
  font-size: 17px;
  line-height: 1.7;
  color: rgba(255, 255, 255, 0.88);
}
.help-glossary-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 18px;
}
.help-glossary-section {
  position: relative;
  overflow: hidden;
  border: 1px solid #dbe5f0;
  border-radius: 22px;
  padding: 24px;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
  box-shadow: 0 16px 34px rgba(15, 23, 42, 0.08);
}
.help-glossary-section::before {
  content: '';
  position: absolute;
  inset: 0 auto auto 0;
  width: 100%;
  height: 4px;
  background: linear-gradient(90deg, #2563eb 0%, #7c3aed 100%);
}
.help-glossary-section-title {
  margin: 0 0 18px;
  font-size: 22px;
  color: #0f172a;
}
.help-glossary-list {
  display: grid;
  gap: 14px;
}
.help-glossary-item {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 16px;
  border: 1px solid #e2e8f0;
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.88);
}
.help-glossary-icon {
  width: 48px;
  height: 48px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 14px;
  background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
  font-size: 23px;
}
.help-glossary-line {
  margin: 0;
  font-size: 15px;
  line-height: 1.65;
  color: #475569;
}
.help-glossary-line strong {
  color: #0f172a;
}
@media (max-width: 1040px) {
  .help-glossary-grid {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 640px) {
  .help-glossary-hero {
    padding: 26px 22px;
  }
  .help-glossary-title {
    font-size: 30px;
  }
  .help-glossary-item {
    padding: 14px;
  }
}
</style>

<div class="help-glossary-shell">
  <section class="help-glossary-hero" aria-labelledby="help-glossary-title">
    <span class="help-glossary-badge">❓ Help Center</span>
    <h1 id="help-glossary-title" class="help-glossary-title">Workflow Glossary for New Employees</h1>
    <p class="help-glossary-subtitle">Use this quick guide to understand the main terms you will see throughout the app. Each workflow is explained in simple language so you can get familiar fast.</p>
  </section>

  <div class="help-glossary-grid">
    <?php foreach ($sections as $section_title => $items): ?>
      <section class="help-glossary-section" aria-labelledby="<?= h('section-' . strtolower(str_replace(' ', '-', $section_title))) ?>">
        <h2 id="<?= h('section-' . strtolower(str_replace(' ', '-', $section_title))) ?>" class="help-glossary-section-title"><?= h($section_title) ?>:</h2>
        <div class="help-glossary-list">
          <?php foreach ($items as $item): ?>
            <article class="help-glossary-item">
              <span class="help-glossary-icon" aria-hidden="true"><?= h($item['icon']) ?></span>
              <div>
                <p class="help-glossary-line"><strong><?= h($item['term']) ?></strong> — <?= h($item['description']) ?></p>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</div>

<?php render_footer(); ?>
