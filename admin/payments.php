<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');

$statusFilter = trim(lex_sanitize_text($_GET['status'] ?? 'all'));
if (!in_array($statusFilter, ['all', 'pending', 'verified', 'rejected'], true)) {
    $statusFilter = 'all';
}

$summary = lex_recent(
    'SELECT
        COUNT(*) AS total_payments,
        SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending_payments,
        SUM(CASE WHEN status = "verified" THEN 1 ELSE 0 END) AS verified_payments,
        SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) AS rejected_payments,
        SUM(CASE WHEN status = "verified" THEN amount ELSE 0 END) AS verified_total
     FROM manual_payments'
);
$totals = $summary[0] ?? [
    'total_payments' => 0,
    'pending_payments' => 0,
    'verified_payments' => 0,
    'rejected_payments' => 0,
    'verified_total' => 0,
];

$query = 'SELECT mp.id, mp.payment_for, mp.amount, mp.reference_number, mp.status, mp.created_at, mp.reviewed_at,
                 cu.full_name AS client_name, cu.email AS client_email, ru.full_name AS reviewed_by_name
          FROM manual_payments mp
          JOIN clients c ON c.id = mp.client_id
          JOIN users cu ON cu.id = c.user_id
          LEFT JOIN users ru ON ru.id = mp.reviewed_by_user_id';
$params = [];
if ($statusFilter !== 'all') {
    $query .= ' WHERE mp.status = :status';
    $params['status'] = $statusFilter;
}
$query .= ' ORDER BY mp.status = "pending" DESC, mp.created_at DESC';
$payments = lex_recent($query, $params);

lex_page_header('Payments', 'payments');
?>
<section class="card payment-page-card">
  <div class="card-head">
    <div>
      <h2>Manual Payment Queue</h2>
      <p class="muted payment-section-copy">Review client GCash submissions, inspect proof files, and approve or reject them manually.</p>
    </div>
  </div>

  <div class="payment-summary-grid">
    <article class="kpi-card"><span>Total</span><strong><?= (int) ($totals['total_payments'] ?? 0) ?></strong></article>
    <article class="kpi-card"><span>Pending</span><strong><?= (int) ($totals['pending_payments'] ?? 0) ?></strong></article>
    <article class="kpi-card"><span>Verified</span><strong><?= (int) ($totals['verified_payments'] ?? 0) ?></strong></article>
    <article class="kpi-card"><span>Verified amount</span><strong>PHP <?= lex_e(number_format((float) ($totals['verified_total'] ?? 0), 2)) ?></strong></article>
  </div>
</section>

<section class="card payment-page-card">
  <div class="card-head">
    <div>
      <h2>Review Payments</h2>
      <p class="muted payment-section-copy">Pending items stay at the top so admins can work the queue quickly.</p>
    </div>
  </div>

  <form method="get" class="payment-filter-bar">
    <label>Status
      <select name="status">
        <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All</option>
        <option value="pending"<?= $statusFilter === 'pending' ? ' selected' : '' ?>>Pending</option>
        <option value="verified"<?= $statusFilter === 'verified' ? ' selected' : '' ?>>Verified</option>
        <option value="rejected"<?= $statusFilter === 'rejected' ? ' selected' : '' ?>>Rejected</option>
      </select>
    </label>
    <button class="button button-secondary" type="submit">Apply Filter</button>
  </form>

  <div class="table-wrap">
    <table class="data-table payment-history-table">
      <thead>
        <tr>
          <th>Submitted</th>
          <th>Client</th>
          <th>Payment for</th>
          <th>Amount</th>
          <th>Status</th>
          <th>Reference</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($payments): ?>
          <?php foreach ($payments as $payment): ?>
            <tr>
              <td><?= lex_e(date('M j, Y g:i A', strtotime((string) $payment['created_at']))) ?></td>
              <td>
                <strong><?= lex_e((string) $payment['client_name']) ?></strong><br>
                <span class="muted"><?= lex_e((string) $payment['client_email']) ?></span>
              </td>
              <td><?= lex_e((string) $payment['payment_for']) ?></td>
              <td>PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></td>
              <td><span class="pill payment-status-pill payment-status-<?= lex_e((string) $payment['status']) ?>"><?= lex_e(ucfirst((string) $payment['status'])) ?></span></td>
              <td>
                <?php if ((string) ($payment['reference_number'] ?? '') !== ''): ?>
                  <?= lex_e((string) $payment['reference_number']) ?>
                <?php else: ?>
                  <span class="muted">None</span>
                <?php endif; ?>
              </td>
              <td><a class="button button-secondary" href="<?= lex_e(lex_app_url('admin/payment_view.php?id=' . (int) $payment['id'])) ?>">Review</a></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="7" class="muted">No payments matched this filter.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php lex_page_footer(); ?>
