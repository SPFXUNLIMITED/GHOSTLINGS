<?php
require __DIR__ . '/layout.php';

render_header('SMS Opt-In');
?>
<div class="card" style="max-width:760px; margin:24px auto; padding:28px;">
  <h1 style="margin-top:0;">SMS Opt-In Consent</h1>
  <p style="font-size:16px; line-height:1.6; margin-bottom:10px;">
    We will send you appointment confirmations, updates about your laser repair, and occasional promotions via text message.
  </p>
  <p style="font-size:16px; line-height:1.6; margin:10px 0;">
    Message and data rates may apply.
  </p>
  <p style="font-size:16px; line-height:1.6; margin:10px 0 24px;">
    You can reply STOP to opt out at any time.
  </p>
  <p class="muted" style="margin:0 0 24px;">
    By clicking I Agree and Continue, you consent to receive SMS messages from Ghost Laser at the mobile number you provide in the service request form.
  </p>

  <a href="service_request_form.php" class="btn primary" style="padding:10px 20px; font-size:16px;">I Agree and Continue</a>
</div>
<?php render_footer(); ?>
