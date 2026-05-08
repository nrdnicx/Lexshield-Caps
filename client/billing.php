<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$clientId = lex_user_client_id((int) $user['id']);

$summary = lex_recent(
    'SELECT
        COUNT(*) AS total_payments,
        SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending_payments,
        SUM(CASE WHEN status = "verified" THEN 1 ELSE 0 END) AS verified_payments,
        SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) AS rejected_payments,
        SUM(CASE WHEN status = "verified" THEN amount ELSE 0 END) AS verified_total
     FROM manual_payments
     WHERE client_id = :client_id',
    ['client_id' => $clientId]
);
$totals = $summary[0] ?? [
    'total_payments' => 0,
    'pending_payments' => 0,
    'verified_payments' => 0,
    'rejected_payments' => 0,
    'verified_total' => 0,
];

$recentPayments = lex_recent(
    'SELECT payment_for, amount, status, created_at, reviewed_at
     FROM manual_payments
     WHERE client_id = :client_id
     ORDER BY created_at DESC
     LIMIT 8',
    ['client_id' => $clientId]
);

lex_page_header('Billing', 'billing', $user);
?>
<section class="card payment-page-card">
  <div class="card-head">
    <div>
      <h2>Billing Overview</h2>
      <p class="muted payment-section-copy">A quick snapshot of your manual payment submissions and verified total.</p>
    </div>
  </div>

  <div class="payment-summary-grid">
    <article class="kpi-card"><span>Total submissions</span><strong><?= (int) ($totals['total_payments'] ?? 0) ?></strong></article>
    <article class="kpi-card"><span>Pending review</span><strong><?= (int) ($totals['pending_payments'] ?? 0) ?></strong></article>
    <article class="kpi-card"><span>Verified</span><strong><?= (int) ($totals['verified_payments'] ?? 0) ?></strong></article>
    <article class="kpi-card"><span>Verified amount</span><strong>PHP <?= lex_e(number_format((float) ($totals['verified_total'] ?? 0), 2)) ?></strong></article>
  </div>
</section>

<section class="card payment-page-card">
  <div class="card-head">
    <div>
      <h2>Recent Billing Activity</h2>
      <p class="muted payment-section-copy">Use the payments page whenever you need to submit another proof file.</p>
    </div>
    <a class="button button-primary" href="<?= lex_e(lex_app_url('client/payments.php')) ?>">Open Payments</a>
  </div>

  <div class="table-wrap">
    <table class="data-table payment-history-table">
      <thead>
        <tr>
          <th>Submitted</th>
          <th>Payment for</th>
          <th>Amount</th>
          <th>Status</th>
          <th>Reviewed</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($recentPayments): ?>
          <?php foreach ($recentPayments as $payment): ?>
            <tr>
              <td><?= lex_e(date('M j, Y', strtotime((string) $payment['created_at']))) ?></td>
              <td><?= lex_e((string) $payment['payment_for']) ?></td>
              <td>PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></td>
              <td><span class="pill payment-status-pill payment-status-<?= lex_e((string) $payment['status']) ?>"><?= lex_e(ucfirst((string) $payment['status'])) ?></span></td>
              <td>
                <?php if (!empty($payment['reviewed_at'])): ?>
                  <?= lex_e(date('M j, Y g:i A', strtotime((string) $payment['reviewed_at']))) ?>
                <?php else: ?>
                  <span class="muted">Pending</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="5" class="muted">No billing records yet.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php lex_page_footer(); ?>
