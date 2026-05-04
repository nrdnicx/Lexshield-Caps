<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');

$pdo = lex_pdo();
$totalCases = lex_stats('SELECT COUNT(*) FROM cases');
$activeLawyers = lex_stats("SELECT COUNT(*) FROM lawyers WHERE status = 'active'");
$openCases = lex_stats("SELECT COUNT(*) FROM cases WHERE status IN ('open','ongoing')");
$riskRows = lex_recent('SELECT risk_level, COUNT(*) AS total FROM clients GROUP BY risk_level');
$failedIps = lex_recent("SELECT ip_address, COUNT(*) AS total FROM audit_logs WHERE action = 'failed_login' GROUP BY ip_address ORDER BY total DESC LIMIT 6");
$failedLoginCount = lex_stats("SELECT COUNT(*) FROM audit_logs WHERE action = 'failed_login'");
$latestFailedLogin = lex_recent("SELECT performed_at FROM audit_logs WHERE action = 'failed_login' ORDER BY performed_at DESC LIMIT 1");
$lockedAccounts = lex_stats("SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()");
$recentAudit = lex_recent('SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.performed_at DESC LIMIT 6');
$recentCases = lex_recent('SELECT c.case_number, c.title, c.status, c.priority, u.full_name AS lawyer_name FROM cases c JOIN lawyers l ON l.id = c.lawyer_id JOIN users u ON u.id = l.user_id ORDER BY c.id DESC LIMIT 5');
$latestFailedLoginTime = $latestFailedLogin[0]['performed_at'] ?? null;

lex_page_header('Admin Dashboard', 'dashboard');
?>
<section class="kpi-grid">
  <article class="kpi-card"><span>Total Cases</span><strong><?= number_format($totalCases) ?></strong></article>
  <article class="kpi-card"><span>Active Lawyers</span><strong><?= number_format($activeLawyers) ?></strong></article>
  <article class="kpi-card"><span>Open Cases</span><strong><?= number_format($openCases) ?></strong></article>
  <article class="kpi-card"><span>Risk Coverage</span><strong><?= number_format(array_sum(array_column($riskRows, 'total'))) ?></strong></article>
</section>

<section class="content-grid two-col">
  <article class="card admin-dashboard-card admin-risk-card">
    <div class="card-head"><h2>Risk Overview</h2><span class="pill">Clients</span></div>
    <canvas id="riskChart" height="220"></canvas>
  </article>
  <article class="card admin-dashboard-card admin-security-card">
    <div class="card-head"><h2>Security Activity</h2><span class="pill">Login Defense</span></div>
    <div class="table-wrap admin-dashboard-table-wrap" style="margin-bottom:0.85rem;">
      <table class="data-table admin-dashboard-table">
        <thead><tr><th>Metric</th><th>Value</th><th>Details</th></tr></thead>
        <tbody>
          <tr>
            <td>Failed logins</td>
            <td><strong><?= number_format($failedLoginCount) ?></strong></td>
            <td class="muted">Recorded login failures</td>
          </tr>
          <tr>
            <td>Locked accounts</td>
            <td><strong><?= number_format($lockedAccounts) ?></strong></td>
            <td class="muted">Accounts currently locked</td>
          </tr>
          <tr>
            <td>Latest failed login</td>
            <td colspan="2"><strong><?= lex_e($latestFailedLoginTime ? lex_message_timestamp((string) $latestFailedLoginTime) : 'None') ?></strong></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="card-head" style="margin-top:0.25rem;"><h3 style="margin:0;font-size:0.92rem;">Top failed IPs</h3><span class="pill">Last 6</span></div>
    <div class="table-wrap admin-dashboard-table-wrap">
      <table class="data-table admin-dashboard-table">
        <thead><tr><th>IP Address</th><th>Attempts</th></tr></thead>
        <tbody>
          <?php foreach ($failedIps as $row): ?>
            <tr>
              <td><?= lex_e($row['ip_address']) ?></td>
              <td><strong><?= number_format((int) $row['total']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$failedIps): ?><tr><td colspan="2" class="muted">No suspicious IPs recorded yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>

<div class="admin-dashboard-compact">
<section class="content-grid two-col">
  <article class="card admin-dashboard-card admin-recent-cases-card">
    <div class="card-head"><h2>Recent Cases</h2></div>
    <div class="table-wrap admin-dashboard-table-wrap">
      <table class="data-table admin-dashboard-table">
        <thead><tr><th>Case</th><th>Title</th><th>Lawyer</th><th>Status</th><th>Priority</th></tr></thead>
        <tbody>
        <?php foreach ($recentCases as $row): ?>
          <tr>
            <td><?= lex_e($row['case_number']) ?></td>
            <td><?= lex_e($row['title']) ?></td>
            <td><?= lex_e($row['lawyer_name']) ?></td>
            <td><span class="pill"><?= lex_e($row['status']) ?></span></td>
            <td><?= lex_e($row['priority']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
  <article class="card admin-dashboard-card admin-audit-feed-card">
    <div class="card-head"><h2>Audit Feed</h2><a class="button button-secondary" href="audit_logs.php">View all</a></div>
    <div class="table-wrap admin-dashboard-table-wrap">
      <table class="data-table admin-dashboard-table">
        <thead><tr><th>Action</th><th>User</th><th>Target</th><th>Time</th></tr></thead>
        <tbody>
          <?php foreach ($recentAudit as $row): ?>
            <tr>
              <td><?= lex_e($row['action']) ?></td>
              <td><?= lex_e($row['full_name'] ?? 'System') ?></td>
              <td><?= lex_e($row['target_table'] . ':' . $row['target_id']) ?></td>
              <td><?= lex_e($row['performed_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recentAudit): ?><tr><td colspan="4" class="muted">No audit entries yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('riskChart');
if (ctx) {
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_column($riskRows, 'risk_level')) ?>,
      datasets: [{
        data: <?= json_encode(array_map('intval', array_column($riskRows, 'total'))) ?>,
        backgroundColor: ['#7fb069','#c9a84c','#c98f4c','#8e3b46']
      }]
    },
    options: {responsive: true, plugins: {legend: {position: 'bottom'}}}
  });
}
</script>
<?php lex_page_footer(); ?>
