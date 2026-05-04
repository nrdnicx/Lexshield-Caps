<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$clientId = lex_user_client_id((int) $user['id']);

$activeCases = lex_stats("SELECT COUNT(*) FROM cases WHERE client_id = :id AND status IN ('open','ongoing')", ['id' => $clientId]);
$upcomingAppointments = lex_stats("SELECT COUNT(*) FROM appointments WHERE client_id = :id AND scheduled_at >= NOW() AND status IN ('pending','confirmed') AND status <> 'deleted'", ['id' => $clientId]);
$unreadMessages = lex_stats('SELECT COUNT(*) FROM messages WHERE receiver_id = :id AND is_read = 0', ['id' => (int) $user['id']]);
$totalCases = lex_stats('SELECT COUNT(*) FROM cases WHERE client_id = :id', ['id' => $clientId]);

$clientProfile = lex_recent(
    'SELECT c.contact_number, c.address, c.risk_level, u.full_name, u.email, u.created_at
     FROM clients c
     JOIN users u ON u.id = c.user_id
     WHERE c.id = :id
     LIMIT 1',
    ['id' => $clientId]
);
$clientProfile = $clientProfile[0] ?? [
    'contact_number' => '',
    'address' => '',
    'risk_level' => 'low',
    'full_name' => $user['full_name'] ?? 'Client',
    'email' => $user['email'] ?? '',
    'created_at' => '',
];

$assignedLawyer = lex_recent(
    'SELECT u.full_name, u.email, c.case_number, c.title, c.status
     FROM cases c
     JOIN lawyers l ON l.id = c.lawyer_id
     JOIN users u ON u.id = l.user_id
     WHERE c.client_id = :id
     ORDER BY FIELD(c.status, "ongoing", "open", "closed", "archived"), c.id DESC
     LIMIT 1',
    ['id' => $clientId]
);
$assignedLawyer = $assignedLawyer[0] ?? null;

$nextAppointment = lex_recent(
    'SELECT a.scheduled_at, a.status, COALESCE(NULLIF(c.title, ""), "Appointment Request") AS case_title
     FROM appointments a
     JOIN cases c ON c.id = a.case_id
     WHERE a.client_id = :id AND a.status <> "deleted"
     ORDER BY a.scheduled_at ASC
     LIMIT 1',
    ['id' => $clientId]
);
$nextAppointment = $nextAppointment[0] ?? null;

$appointments = lex_recent(
    'SELECT a.scheduled_at, a.status, COALESCE(NULLIF(c.title, ""), "Appointment Request") AS case_title
     FROM appointments a
     JOIN cases c ON c.id = a.case_id
     WHERE a.client_id = :id
       AND a.status <> "deleted"
     ORDER BY a.scheduled_at DESC
     LIMIT 5',
    ['id' => $clientId]
);

lex_page_header('Client Dashboard', 'dashboard', $user);
?>
<div class="dashboard-compact">
<section class="kpi-grid">
  <article class="kpi-card"><span>Active Cases</span><strong><?= number_format($activeCases) ?></strong></article>
  <article class="kpi-card"><span>Upcoming Appointments</span><strong><?= number_format($upcomingAppointments) ?></strong></article>
  <article class="kpi-card"><span>Unread Messages</span><strong><?= number_format($unreadMessages) ?></strong></article>
  <article class="kpi-card"><span>Total Cases</span><strong><?= number_format($totalCases) ?></strong></article>
</section>

<section class="content-grid two-col">
  <article class="card client-assigned-lawyer-card">
    <div class="card-head">
      <h2>Assigned Lawyer</h2>
      <a class="button button-secondary" href="<?= lex_e(lex_app_url('client/lawyers.php')) ?>">View lawyers</a>
    </div>
    <?php if ($assignedLawyer): ?>
      <div class="profile-summary client-assigned-lawyer-summary">
        <div class="profile-summary-row client-assigned-lawyer-head">
          <div>
            <span class="profile-kicker">Assigned lawyer</span>
            <strong><?= lex_e((string) $assignedLawyer['full_name']) ?></strong>
            <small><?= lex_e((string) $assignedLawyer['email']) ?></small>
          </div>
          <span class="pill"><?= lex_e((string) $assignedLawyer['status']) ?></span>
        </div>
        <div class="profile-summary-row">
          <span>Case</span>
          <strong><?= lex_e((string) $assignedLawyer['case_number']) ?></strong>
        </div>
        <div class="profile-summary-row">
          <span>Case title</span>
          <strong><?= lex_e((string) $assignedLawyer['title']) ?></strong>
        </div>
      </div>
    <?php else: ?>
      <p class="muted">No lawyer is linked to this account yet.</p>
      <a class="button button-primary" href="<?= lex_e(lex_app_url('client/lawyers.php')) ?>">Browse lawyers</a>
    <?php endif; ?>
  </article>

  <article class="card client-profile-details-card">
    <div class="card-head"><h2>Profile Details</h2><a class="button button-secondary" href="<?= lex_e(lex_app_url('client/profile.php')) ?>">View profile</a></div>
    <div class="profile-summary client-profile-details-summary">
      <div class="profile-summary-row">
        <span>Email</span>
        <strong><?= lex_e((string) ($clientProfile['email'] ?? '')) ?></strong>
      </div>
      <div class="profile-summary-row">
        <span>Phone</span>
        <strong><?= lex_e((string) ($clientProfile['contact_number'] ?? '')) ?></strong>
      </div>
      <div class="profile-summary-row">
        <span>Member since</span>
        <strong><?= lex_e((string) ($clientProfile['created_at'] ?? '')) ?></strong>
      </div>
    </div>
  </article>
</section>

<section class="content-grid two-col">
  <article class="card client-appointments-card">
    <div class="card-head">
      <h2>Recent Appointments</h2>
      <a class="button button-secondary" href="<?= lex_e(lex_app_url('client/appointment.php')) ?>">Open appointments</a>
    </div>
    <div class="profile-summary client-appointments-summary">
      <?php foreach ($appointments as $appointment): ?>
        <div class="profile-summary-row client-appointment-row">
          <div class="client-appointment-main">
            <strong><?= lex_e((string) $appointment['case_title']) ?></strong>
            <small><?= lex_e((string) $appointment['status']) ?></small>
          </div>
          <strong><?= lex_e((string) $appointment['scheduled_at']) ?></strong>
        </div>
      <?php endforeach; ?>
      <?php if (!$appointments): ?>
        <div class="profile-summary-row">
          <span>No appointments yet.</span>
          <strong>Schedule your first one</strong>
        </div>
      <?php endif; ?>
    </div>
  </article>

  <article class="card client-next-appointment-card">
    <div class="card-head">
      <h2>Next Appointment</h2>
      <a class="button button-primary" href="<?= lex_e(lex_app_url('client/lawyers.php')) ?>">Lawyers</a>
    </div>
    <?php if ($nextAppointment): ?>
      <div class="profile-summary client-next-appointment-summary">
        <div class="profile-summary-row">
          <span>Case</span>
          <strong><?= lex_e((string) $nextAppointment['case_title']) ?></strong>
        </div>
        <div class="profile-summary-row">
          <span>Scheduled</span>
          <strong><?= lex_e((string) $nextAppointment['scheduled_at']) ?></strong>
        </div>
        <div class="profile-summary-row">
          <span>Status</span>
          <strong><?= lex_e((string) $nextAppointment['status']) ?></strong>
        </div>
      </div>
    <?php else: ?>
      <p class="muted">No upcoming appointments.</p>
    <?php endif; ?>
  </article>
</section>
</div>
<?php lex_page_footer(); ?>
