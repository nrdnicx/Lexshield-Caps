<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
lex_page_header('Payments', 'payments', $user);
?>
<section class="card">
  <div class="card-head">
    <h2>Payments Coming Soon</h2>
  </div>
  <p class="muted">
    We are preparing a secure online payment experience for consultations, retainers, and legal fees.
    Please check back soon.
  </p>
  <div class="landing-hero__actions">
    <a class="button button-primary" href="<?= lex_e(lex_app_url('client/index.php')) ?>">Back to dashboard</a>
    <a class="button button-secondary" href="<?= lex_e(lex_app_url('client/billing.php')) ?>">View billing</a>
  </div>
</section>
<?php lex_page_footer(); ?>
