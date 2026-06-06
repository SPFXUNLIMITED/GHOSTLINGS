<?php
require __DIR__ . '/db.php';
$openaiApiKeyDebug = getenv('OPENAI_API_KEY') ? 'OPENAI_API_KEY loaded: YES' : 'OPENAI_API_KEY loaded: NO';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';

require_login();

render_header('Home');
render_alibaba_workflow_banner('create_rfq');
if (is_admin()) {
  echo '<div style="margin: 0 auto 16px; max-width: 1100px; padding: 10px 14px; border: 1px solid #facc15; border-radius: 10px; background: #fffbeb; color: #92400e; font-weight: 600;">' . h($openaiApiKeyDebug) . '</div>';
}
?>

<style>
.home-hero-wrap {
  margin: 0 auto;
  max-width: 1100px;
}
.home-dashboard-card {
  padding: 20px;
}
.home-hero-card {
  border: 1px solid #e5e7eb;
  border-radius: 16px;
  background: linear-gradient(160deg, #ffffff 0%, #f8fbff 100%);
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
  padding: 36px;
  margin-bottom: 22px;
}
.home-hero-title {
  margin: 0 0 10px;
  font-size: 34px;
  line-height: 1.2;
  color: #0f172a;
}
.home-hero-subtitle {
  margin: 0;
  font-size: 17px;
  line-height: 1.6;
  color: #475569;
  max-width: 760px;
}
.home-entry-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 18px;
}
.home-entry-card {
  display: block;
  text-decoration: none;
  border: 1px solid #dbe3ef;
  border-radius: 16px;
  padding: 26px;
  background: #ffffff;
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
  transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
}
.home-entry-card:hover,
.home-entry-card:focus {
  transform: translateY(-3px);
  box-shadow: 0 14px 28px rgba(37, 99, 235, 0.16);
  border-color: #93c5fd;
  outline: none;
}
.home-entry-icon {
  width: 52px;
  height: 52px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  font-weight: 700;
  letter-spacing: .04em;
  margin-bottom: 14px;
  background: #eff6ff;
}
.home-entry-title {
  margin: 0 0 8px;
  font-size: 23px;
  line-height: 1.3;
  color: #0f172a;
}
.home-entry-text {
  margin: 0;
  font-size: 15px;
  line-height: 1.55;
  color: #475569;
}
@media (max-width: 980px) {
  .home-entry-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 768px) {
  .home-entry-grid {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 640px) {
  .home-dashboard-card {
    padding: 14px;
  }
  .home-hero-card {
    padding: 24px;
  }
  .home-hero-title {
    font-size: 28px;
  }
}
</style>

<div class="card home-dashboard-card">
  <div class="home-hero-wrap">
    <section class="home-hero-card" aria-labelledby="home-dashboard-title">
      <h1 id="home-dashboard-title" class="home-hero-title">Team Dashboard</h1>
      <p class="home-hero-subtitle">Choose a workflow to start a new request. Use one of the options below to log customer inquiries, submit sourcing RFQs, or begin new purchase orders.</p>
    </section>

    <section class="home-entry-grid" aria-label="Primary actions">
      <a class="home-entry-card" href="quick_order_form.php">
        <span class="home-entry-icon" aria-hidden="true">CI</span>
        <h2 class="home-entry-title">New Quick Order</h2>
        <p class="home-entry-text">Capture customer details, order requirements, and notes for quick follow-up.</p>
      </a>

      <a class="home-entry-card" href="sourcing_rfq_form.php">
        <span class="home-entry-icon" aria-hidden="true">RFQ</span>
        <h2 class="home-entry-title">New RFQ</h2>
        <p class="home-entry-text">Create a new sourcing request to collect supplier quotes and pricing.</p>
      </a>

      <a class="home-entry-card" href="order_form.php">
        <span class="home-entry-icon" aria-hidden="true">PO</span>
        <h2 class="home-entry-title">New Purchase Order</h2>
        <p class="home-entry-text">Start a purchase order workflow and move it through fulfillment stages.</p>
      </a>
    </section>
  </div>
</div>

<?php render_footer(); ?>
