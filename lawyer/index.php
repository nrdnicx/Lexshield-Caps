<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('lawyer');
$pdo = lex_pdo();
$lawyerId = lex_user_lawyer_id((int) $user['id']);

$assignedCases = lex_stats('SELECT COUNT(*) FROM cases WHERE lawyer_id = :id', ['id' => $lawyerId]);
$upcomingAppointments = lex_stats("SELECT COUNT(*) FROM appointments WHERE lawyer_id = :id AND scheduled_at >= NOW() AND status IN ('pending','confirmed') AND status <> 'deleted'", ['id' => $lawyerId]);
$unreadMessages = lex_stats('SELECT COUNT(*) FROM messages WHERE receiver_id = :uid AND is_read = 0', ['uid' => (int) $user['id']]);
$totalClients = lex_stats('SELECT COUNT(DISTINCT client_id) FROM cases WHERE lawyer_id = :id', ['id' => $lawyerId]);

$lawyerProfile = lex_recent(
    'SELECT l.bar_number, l.specialization, l.status, l.bio, l.background, u.full_name, u.email, u.created_at
     FROM lawyers l
     JOIN users u ON u.id = l.user_id
     WHERE l.id = :id
     LIMIT 1',
    ['id' => $lawyerId]
);
$lawyerProfile = $lawyerProfile[0] ?? [
    'bar_number' => '',
    'specialization' => '',
    'status' => 'active',
    'bio' => '',
    'background' => '',
    'full_name' => $user['full_name'] ?? 'Lawyer',
    'email' => $user['email'] ?? '',
    'created_at' => '',
];

$cases = lex_recent(
    'SELECT c.case_number, c.title, c.status, c.priority, u.full_name AS client_name
     FROM cases c
     JOIN clients cl ON cl.id = c.client_id
     JOIN users u ON u.id = cl.user_id
     WHERE c.lawyer_id = :id
     ORDER BY c.id DESC
     LIMIT 5',
    ['id' => $lawyerId]
);

$appointments = lex_recent(
    'SELECT a.scheduled_at, a.status, u.full_name AS client_name, c.case_number
     FROM appointments a
     JOIN clients cl ON cl.id = a.client_id
     JOIN users u ON u.id = cl.user_id
     JOIN cases c ON c.id = a.case_id
     WHERE a.lawyer_id = :id
       AND a.status <> "deleted"
     ORDER BY a.scheduled_at ASC
     LIMIT 5',
    ['id' => $lawyerId]
);

$profileInitials = strtoupper(substr(preg_replace('/\s+/', '', (string) ($lawyerProfile['full_name'] ?? 'LW')) ?: 'LW', 0, 2));

lex_page_header('Lawyer Dashboard', 'dashboard', $user);
?>
<div class="dashboard-compact">
<section class="kpi-grid">
  <article class="kpi-card"><span>Assigned Cases</span><strong><?= number_format($assignedCases) ?></strong></article>
  <article class="kpi-card"><span>Upcoming Appointments</span><strong><?= number_format($upcomingAppointments) ?></strong></article>
  <article class="kpi-card"><span>Unread Messages</span><strong><?= number_format($unreadMessages) ?></strong></article>
  <article class="kpi-card"><span>Unique Clients</span><strong><?= number_format($totalClients) ?></strong></article>
</section>

<section class="content-grid two-col">
  <article class="card lawyer-profile-details-card">
    <div class="card-head">
      <h2>Profile Details</h2>
      <a class="button button-secondary" href="<?= lex_e(lex_app_url('lawyer/profile.php')) ?>">View profile</a>
    </div>
    <div class="profile-summary lawyer-profile-summary">
      <div class="sidebar-profile-top lawyer-profile-top">
        <div class="profile-avatar"><?= lex_e($profileInitials) ?></div>
        <div>
          <span class="profile-kicker">Lawyer profile</span>
          <h3 class="lawyer-profile-name"><?= lex_e((string) ($lawyerProfile['full_name'] ?? $user['full_name'] ?? 'Lawyer')) ?></h3>
          <div class="profile-badges lawyer-profile-badges">
            <span class="pill"><?= lex_e((string) ($lawyerProfile['status'] ?? 'active')) ?></span>
            <span class="pill"><?= lex_e((string) ($lawyerProfile['specialization'] ?? 'Specialization pending')) ?></span>
          </div>
        </div>
      </div>
      <div class="profile-summary-row">
        <span>Email</span>
        <strong><?= lex_e((string) ($lawyerProfile['email'] ?? '')) ?></strong>
      </div>
      <div class="profile-summary-row">
        <span>Bar number</span>
        <strong><?= lex_e((string) ($lawyerProfile['bar_number'] ?? '')) ?></strong>
      </div>
      <div class="profile-summary-row">
        <span>Member since</span>
        <strong><?= lex_e((string) ($lawyerProfile['created_at'] ?? '')) ?></strong>
      </div>
      <?php if (!empty($lawyerProfile['bio'])): ?>
        <div class="profile-summary-row profile-summary-bio">
          <span>Bio</span>
          <strong><?= lex_e((string) $lawyerProfile['bio']) ?></strong>
        </div>
      <?php endif; ?>
      <?php if (!empty($lawyerProfile['background'])): ?>
        <div class="profile-summary-row profile-summary-bio">
          <span>Background</span>
          <strong><?= lex_e((string) $lawyerProfile['background']) ?></strong>
        </div>
      <?php endif; ?>
    </div>
  </article>

  <article class="card lawyer-appointments-card">
    <div class="card-head">
      <h2>Upcoming Appointments</h2>
      <a class="button button-secondary" href="appointment.php">Open appointments</a>
    </div>
    <div class="profile-summary lawyer-appointments-summary">
      <?php foreach ($appointments as $appointment): ?>
        <div class="profile-summary-row lawyer-appointment-row">
          <div class="lawyer-appointment-main">
            <strong><?= lex_e((string) $appointment['case_number']) ?></strong>
            <span><?= lex_e((string) $appointment['client_name']) ?></span>
          </div>
          <div class="lawyer-appointment-meta">
            <span><?= lex_e((string) $appointment['scheduled_at']) ?></span>
            <span class="pill"><?= lex_e((string) $appointment['status']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$appointments): ?>
        <div class="profile-summary-row">
          <span>No upcoming appointments.</span>
          <strong>Clear schedule</strong>
        </div>
      <?php endif; ?>
    </div>
  </article>
</section>

<section class="card lawyer-assigned-cases-card">
  <div class="card-head">
    <h2>Assigned Cases</h2>
    <a class="button button-secondary" href="<?= lex_e(lex_app_url('case_files.php')) ?>">Open cases</a>
  </div>
  <div class="profile-summary lawyer-cases-summary">
    <?php foreach ($cases as $case): ?>
      <div class="profile-summary-row lawyer-case-row">
        <div class="lawyer-case-main">
          <strong><?= lex_e((string) $case['case_number']) ?></strong>
          <span><?= lex_e((string) $case['title']) ?></span>
          <small><?= lex_e((string) $case['client_name']) ?></small>
        </div>
        <div class="lawyer-case-meta">
          <span class="pill"><?= lex_e((string) $case['status']) ?></span>
          <strong><?= lex_e((string) $case['priority']) ?></strong>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$cases): ?>
      <div class="profile-summary-row">
        <span>No assigned cases yet.</span>
        <strong>Clear workload</strong>
      </div>
    <?php endif; ?>
  </div>
</section>

</div>
<?php lex_page_footer(); ?>
