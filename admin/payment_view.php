<?php
require_once __DIR__ . '/../config/bootstrap.php';

$admin = lex_require_role('admin');
$pdo = lex_pdo();
$paymentId = lex_sanitize_int($_GET['id'] ?? $_POST['id'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(404);
    exit('Payment not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $decision = trim((string) ($_POST['decision'] ?? ''));
    $adminNotes = trim(lex_sanitize_text($_POST['admin_notes'] ?? ''));

    if (!in_array($decision, ['verified', 'rejected'], true)) {
        lex_flash_set('error', 'Choose a valid payment decision.');
        header('Location: ' . lex_app_url('admin/payment_view.php?id=' . $paymentId));
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT mp.id, mp.status, mp.payment_for, cu.id AS client_user_id, cu.full_name AS client_name
         FROM manual_payments mp
         JOIN clients c ON c.id = mp.client_id
         JOIN users cu ON cu.id = c.user_id
         WHERE mp.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $paymentId]);
    $target = $stmt->fetch();

    if (!$target) {
        lex_flash_set('error', 'Payment not found.');
        header('Location: ' . lex_app_url('admin/payments.php'));
        exit;
    }

    $pdo->prepare(
        'UPDATE manual_payments
         SET status = :status,
             admin_notes = :admin_notes,
             reviewed_by_user_id = :reviewed_by_user_id,
             reviewed_at = NOW()
         WHERE id = :id'
    )->execute([
        'status' => $decision,
        'admin_notes' => $adminNotes,
        'reviewed_by_user_id' => (int) $admin['id'],
        'id' => $paymentId,
    ]);

    lex_audit($decision === 'verified' ? 'verify_manual_payment' : 'reject_manual_payment', 'manual_payments', (string) $paymentId);
    $note = $adminNotes !== '' ? ' Note: ' . $adminNotes : '';
    lex_notify((int) $target['client_user_id'], 'payment', 'Your payment for "' . (string) $target['payment_for'] . '" was marked ' . $decision . '.' . $note);
    lex_flash_set('success', $decision === 'verified' ? 'Payment approved.' : 'Payment rejected.');
    header('Location: ' . lex_app_url('admin/payment_view.php?id=' . $paymentId));
    exit;
}

$stmt = $pdo->prepare(
    'SELECT mp.*, cu.full_name AS client_name, cu.email AS client_email, c.contact_number AS client_contact,
            ru.full_name AS reviewed_by_name
     FROM manual_payments mp
     JOIN clients c ON c.id = mp.client_id
     JOIN users cu ON cu.id = c.user_id
     LEFT JOIN users ru ON ru.id = mp.reviewed_by_user_id
     WHERE mp.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    http_response_code(404);
    exit('Payment not found.');
}

$isPreviewableImage = str_starts_with((string) ($payment['proof_mime_type'] ?? ''), 'image/');

lex_page_header('Payments', 'payments');
?>
<section class="card payment-page-card">
  <div class="card-head">
    <div>
      <h2>Payment Review #<?= (int) $payment['id'] ?></h2>
      <p class="muted payment-section-copy">Inspect the uploaded proof, check the details, then decide whether to verify or reject the submission.</p>
    </div>
    <a class="button button-secondary" href="<?= lex_e(lex_app_url('admin/payments.php')) ?>">Back to queue</a>
  </div>

  <div class="payment-review-grid">
    <article class="payment-panel">
      <h3>Payment details</h3>
      <dl class="payment-account-list">
        <div><dt>Client</dt><dd><?= lex_e((string) $payment['client_name']) ?></dd></div>
        <div><dt>Email</dt><dd><?= lex_e((string) $payment['client_email']) ?></dd></div>
        <div><dt>Contact</dt><dd><?= lex_e((string) ($payment['client_contact'] ?? '')) ?></dd></div>
        <div><dt>Payment for</dt><dd><?= lex_e((string) $payment['payment_for']) ?></dd></div>
        <div><dt>Amount</dt><dd>PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></dd></div>
        <div><dt>Reference</dt><dd><?= (string) ($payment['reference_number'] ?? '') !== '' ? lex_e((string) $payment['reference_number']) : 'None' ?></dd></div>
        <div><dt>Status</dt><dd><span class="pill payment-status-pill payment-status-<?= lex_e((string) $payment['status']) ?>"><?= lex_e(ucfirst((string) $payment['status'])) ?></span></dd></div>
        <div><dt>Submitted</dt><dd><?= lex_e(date('M j, Y g:i A', strtotime((string) $payment['created_at']))) ?></dd></div>
        <div><dt>Reviewed by</dt><dd><?= !empty($payment['reviewed_by_name']) ? lex_e((string) $payment['reviewed_by_name']) : 'Not reviewed yet' ?></dd></div>
      </dl>
      <?php if (!empty($payment['notes'])): ?>
        <div class="payment-notes-box">
          <strong>Client notes</strong>
          <p><?= nl2br(lex_e((string) $payment['notes'])) ?></p>
        </div>
      <?php endif; ?>
    </article>

    <article class="payment-panel">
      <h3>Uploaded proof</h3>
      <?php if ($isPreviewableImage): ?>
        <a class="payment-proof-preview-link" href="<?= lex_e(lex_app_url('payment_proof.php?id=' . (int) $payment['id'])) ?>" target="_blank" rel="noopener">
          <img class="payment-proof-preview" src="<?= lex_e(lex_app_url('payment_proof.php?id=' . (int) $payment['id'])) ?>" alt="Uploaded payment proof">
        </a>
      <?php else: ?>
        <div class="payment-proof-file-box">
          <strong><?= lex_e((string) $payment['proof_original_name']) ?></strong>
          <p class="muted">Preview is unavailable for this file type. Download it to inspect the proof.</p>
        </div>
      <?php endif; ?>
      <div class="landing-hero__actions">
        <a class="button button-secondary" href="<?= lex_e(lex_app_url('payment_proof.php?id=' . (int) $payment['id'] . '&download=1')) ?>">Download proof</a>
      </div>
    </article>
  </div>
</section>

<section class="card payment-page-card">
  <div class="card-head">
    <div>
      <h2>Admin Decision</h2>
      <p class="muted payment-section-copy">Leave a short note when rejecting or when you want the client to have a clear audit trail.</p>
    </div>
  </div>

  <form method="post" class="form-grid payment-form">
    <?= lex_csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $payment['id'] ?>">
    <label>Decision
      <select name="decision" required>
        <option value="verified"<?= ($payment['status'] ?? '') === 'verified' ? ' selected' : '' ?>>Verify payment</option>
        <option value="rejected"<?= ($payment['status'] ?? '') === 'rejected' ? ' selected' : '' ?>>Reject payment</option>
      </select>
    </label>
    <label class="full">Admin notes
      <textarea name="admin_notes" rows="4" placeholder="Optional message for the client or audit trail"><?= lex_e((string) ($payment['admin_notes'] ?? '')) ?></textarea>
    </label>
    <button class="button button-primary" type="submit">Save Decision</button>
  </form>
</section>
<?php lex_page_footer(); ?>
