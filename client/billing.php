<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
lex_page_header('Billing', 'billing', $user);
?>
<section class="card">
  <div class="card-head">
    <h2>Billing Coming Soon</h2>
  </div>
  <p class="muted">
    Billing tools and online invoice payments will be available soon.
  </p>
  <div class="landing-hero__actions">
    <a class="button button-primary" href="<?= lex_e(lex_app_url('client/index.php')) ?>">Back to dashboard</a>
    <a class="button button-secondary" href="<?= lex_e(lex_app_url('client/payments.php')) ?>">Payments</a>
  </div>
</section>
<?php lex_page_footer(); ?>
