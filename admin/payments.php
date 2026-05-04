<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');
lex_page_header('Payments', 'payments');
?>
<section class="card">
  <div class="card-head">
    <h2>Payments Coming Soon</h2>
  </div>
  <p class="muted">
    Admin payment reporting and reconciliation tools will be added later.
  </p>
  <div class="landing-hero__actions">
    <a class="button button-primary" href="<?= lex_e(lex_app_url('admin/index.php')) ?>">Back to dashboard</a>
  </div>
</section>
<?php lex_page_footer(); ?>
