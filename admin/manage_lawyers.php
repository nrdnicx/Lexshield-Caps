<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');
$pdo = lex_pdo();

function lex_lawyer_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'LX';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials !== '' ? $initials : 'LX';
}

function lex_lawyer_summary(array $lawyer): string
{
    $summary = trim((string) ($lawyer['background'] ?: $lawyer['bio'] ?: ''));
    if ($summary === '') {
        return 'Profile details not added yet';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($summary, 0, 70, '...');
    }

    return strlen($summary) > 70 ? substr($summary, 0, 67) . '...' : $summary;
}

function lex_lawyer_specializations(): array
{
    return [
        'Criminal Law',
        'Corporate Law',
        'Cyber Law',
        'Family Law',
        'Civil Litigation',
        'Labor Law',
        'Property Law',
        'Tax Law',
        'Immigration Law',
        'Intellectual Property Law',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_flash_set('error', 'Invalid CSRF token.');
        header('Location: ' . lex_app_url('admin/manage_lawyers.php'));
        exit;
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add') {
                $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
                $email = lex_sanitize_email($_POST['email'] ?? '');
                $barNumber = lex_sanitize_text($_POST['bar_number'] ?? '');
                $specialization = lex_sanitize_text($_POST['specialization'] ?? '');
                $bio = lex_sanitize_text($_POST['bio'] ?? '');
                $background = lex_sanitize_text($_POST['background'] ?? '');
                $password = (string) ($_POST['password'] ?? '');
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active, created_at) VALUES (:full_name, :email, :password_hash, "lawyer", 1, NOW())');
                $stmt->execute(['full_name' => $fullName, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_BCRYPT)]);
                $userId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO lawyers (user_id, bar_number, specialization, status, bio, background) VALUES (:user_id, :bar_number, :specialization, "active", :bio, :background)');
                $stmt->execute(['user_id' => $userId, 'bar_number' => $barNumber, 'specialization' => $specialization, 'bio' => $bio, 'background' => $background]);
                lex_audit('add_lawyer', 'lawyers', (string) $userId);
                $pdo->commit();
                lex_flash_set('success', 'Lawyer added successfully.');
                header('Location: ' . lex_app_url('admin/manage_lawyers.php'));
                exit;
            } elseif ($action === 'toggle') {
                $id = lex_sanitize_int($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT l.id, l.status, l.user_id FROM lawyers l WHERE l.id = :id');
                $stmt->execute(['id' => $id]);
                $lawyer = $stmt->fetch();
                if ($lawyer) {
                    $newStatus = $lawyer['status'] === 'active' ? 'suspended' : 'active';
                    $pdo->prepare('UPDATE lawyers SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $id]);
                    $pdo->prepare('UPDATE users SET is_active = :active WHERE id = :uid')->execute(['active' => $newStatus === 'active' ? 1 : 0, 'uid' => $lawyer['user_id']]);
                    lex_audit('toggle_lawyer', 'lawyers', (string) $id);
                    lex_flash_set('success', 'Lawyer status updated.');
                    header('Location: ' . lex_app_url('admin/manage_lawyers.php'));
                    exit;
                }
            } elseif ($action === 'delete') {
                $id = lex_sanitize_int($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT user_id FROM lawyers WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $userId = (int) ($stmt->fetchColumn() ?: 0);
                if ($userId) {
                    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
                    lex_audit('delete_lawyer', 'lawyers', (string) $id);
                    lex_flash_set('success', 'Lawyer removed.');
                    header('Location: ' . lex_app_url('admin/manage_lawyers.php'));
                    exit;
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            lex_flash_set('error', 'Unable to complete the requested action.');
            header('Location: ' . lex_app_url('admin/manage_lawyers.php'));
            exit;
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'active', 'suspended'], true)) {
    $statusFilter = 'all';
}

$lawyerWhere = [];
$lawyerParams = [];

if ($search !== '') {
    $searchValue = '%' . $search . '%';
    $lawyerWhere[] = '(u.full_name LIKE :search_name OR u.email LIKE :search_email OR l.bar_number LIKE :search_bar OR l.specialization LIKE :search_specialization)';
    $lawyerParams['search_name'] = $searchValue;
    $lawyerParams['search_email'] = $searchValue;
    $lawyerParams['search_bar'] = $searchValue;
    $lawyerParams['search_specialization'] = $searchValue;
}

if ($statusFilter !== 'all') {
    $lawyerWhere[] = 'l.status = :status';
    $lawyerParams['status'] = $statusFilter;
}

$lawyerQuery = 'SELECT l.*, u.full_name, u.email FROM lawyers l JOIN users u ON u.id = l.user_id';
if ($lawyerWhere) {
    $lawyerQuery .= ' WHERE ' . implode(' AND ', $lawyerWhere);
}
$lawyerQuery .= ' ORDER BY l.id DESC';

$lawyers = lex_recent($lawyerQuery, $lawyerParams);
$totalLawyers = lex_stats('SELECT COUNT(*) FROM lawyers');
$activeLawyers = lex_stats("SELECT COUNT(*) FROM lawyers WHERE status = 'active'");
$suspendedLawyers = lex_stats("SELECT COUNT(*) FROM lawyers WHERE status = 'suspended'");
$specializationOptions = lex_lawyer_specializations();

lex_page_header('Manage Lawyers', 'lawyers');
?>
  <section class="admin-lawyers-stats" aria-label="Lawyer summary">
    <article class="admin-lawyers-stat-card">
      <div>
        <span>Total Lawyers</span>
        <strong><?= number_format($totalLawyers) ?></strong>
      </div>
      <div class="admin-lawyers-stat-icon tone-blue" aria-hidden="true">L</div>
    </article>
    <article class="admin-lawyers-stat-card">
      <div>
        <span>Active</span>
        <strong class="tone-success"><?= number_format($activeLawyers) ?></strong>
      </div>
      <div class="admin-lawyers-stat-icon tone-green" aria-hidden="true">&#9989;</div>
    </article>
    <article class="admin-lawyers-stat-card">
      <div>
        <span>Suspended</span>
        <strong class="tone-warning"><?= number_format($suspendedLawyers) ?></strong>
      </div>
      <div class="admin-lawyers-stat-icon tone-amber" aria-hidden="true">&#10060;</div>
    </article>
  </section>

  <section class="card admin-lawyers-directory-card">
    <div class="admin-lawyers-directory-head">
      <div>
        <h2>Lawyers Directory</h2>
      </div>
      <form method="get" class="admin-lawyers-filters">
        <label class="admin-lawyers-search">
          <span class="sr-only">Search lawyers</span>
          <span class="admin-lawyers-field-icon" aria-hidden="true"></span>
          <input type="search" name="q" value="<?= lex_e($search) ?>" placeholder="Search lawyers...">
        </label>
        <label class="admin-lawyers-status-filter">
          <span class="sr-only">Filter by status</span>
          <select name="status" onchange="this.form.submit()">
            <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All Status</option>
            <option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="suspended"<?= $statusFilter === 'suspended' ? ' selected' : '' ?>>Suspended</option>
          </select>
        </label>
        <button class="button admin-lawyers-add-button admin-lawyers-toolbar-button" type="button" data-lawyer-modal-open>
          <span aria-hidden="true">+</span>
          <span>Add Lawyer</span>
        </button>
      </form>
    </div>

    <div class="table-wrap">
      <table class="data-table admin-lawyers-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Bar</th>
            <th>Specialization</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($lawyers as $lawyer): ?>
          <?php
            $summary = lex_lawyer_summary($lawyer);
            $isActive = ($lawyer['status'] ?? '') === 'active';
          ?>
          <tr>
            <td>
              <div class="admin-lawyers-person">
                <div>
                  <strong><?= lex_e($lawyer['full_name']) ?></strong>
                  <span><?= lex_e($summary) ?></span>
                </div>
              </div>
            </td>
            <td>
              <div class="admin-lawyers-inline-meta">
                <span class="admin-lawyers-meta-icon" aria-hidden="true">@</span>
                <span><?= lex_e($lawyer['email']) ?></span>
              </div>
            </td>
            <td>
              <div class="admin-lawyers-inline-meta">
                <span class="admin-lawyers-meta-icon" aria-hidden="true">#</span>
                <span><?= lex_e($lawyer['bar_number']) ?></span>
              </div>
            </td>
            <td>
              <span class="admin-lawyers-specialization-pill"><?= lex_e($lawyer['specialization']) ?></span>
            </td>
            <td>
              <span class="admin-lawyers-status-pill <?= $isActive ? 'is-active' : 'is-suspended' ?>">
                <span class="admin-lawyers-status-dot" aria-hidden="true"></span>
                <?= lex_e(ucfirst((string) $lawyer['status'])) ?>
              </span>
            </td>
            <td>
              <div class="admin-lawyers-actions">
                <form method="post">
                  <?= lex_csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $lawyer['id'] ?>">
                  <button class="button button-secondary admin-lawyers-action-button" type="submit"><?= $isActive ? 'Suspend' : 'Reactivate' ?></button>
                </form>
                <form method="post" onsubmit="return confirm('Remove this lawyer?');">
                  <?= lex_csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $lawyer['id'] ?>">
                  <button class="button button-danger admin-lawyers-action-button" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lawyers): ?>
          <tr>
            <td colspan="6" class="admin-lawyers-empty">No lawyers matched your current filters.</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<div class="modal-overlay admin-lawyer-modal-overlay" id="lawyerCreateModal" aria-hidden="true">
  <div class="modal-card wide admin-lawyer-modal-card" role="dialog" aria-modal="true" aria-labelledby="lawyerCreateTitle">
    <div class="admin-lawyer-modal-banner">
      <div>
        <h2 id="lawyerCreateTitle">Add New Lawyer</h2>
        <p>Fill in the details to add a new lawyer to your team</p>
      </div>
      <button class="close-button admin-lawyer-modal-close" type="button" data-lawyer-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" class="form-grid admin-lawyer-form admin-lawyer-modal-form">
        <?= lex_csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>
          <span>Full Name</span>
          <input type="text" name="full_name" placeholder="John Smith" required>
        </label>
        <label>
          <span>Email Address</span>
          <input type="email" name="email" placeholder="john.smith@gmail.com" required>
        </label>
        <label>
          <span>Bar Number</span>
          <input type="text" name="bar_number" placeholder="ROLL NO. 1234" required>
        </label>
        <label>
          <span>Specialization</span>
          <select name="specialization" required>
            <option value="" selected disabled>Select specialization</option>
            <?php foreach ($specializationOptions as $option): ?>
              <option value="<?= lex_e($option) ?>"><?= lex_e($option) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="full">
          <span>Background</span>
          <textarea name="background" rows="4" placeholder="Short background, experience, or practice history..."></textarea>
        </label>
        <label>
          <span>Password</span>
          <div class="password-field" data-password-toggle>
            <input type="password" name="password" minlength="10" placeholder="Create a secure password" required>
            <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
          </div>
        </label>
        <label class="full">
          <span>Bio</span>
          <textarea name="bio" rows="4" placeholder="Professional biography, achievements, and qualifications..."></textarea>
        </label>
        <div class="admin-lawyer-modal-actions">
          <button class="button button-primary admin-lawyer-submit" type="submit">Create Lawyer</button>
          <button class="button button-secondary admin-lawyer-cancel" type="button" data-lawyer-modal-close>Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('lawyerCreateModal');
  if (!modal) return;

  const openButton = document.querySelector('[data-lawyer-modal-open]');
  const closeButtons = modal.querySelectorAll('[data-lawyer-modal-close]');
  const filterForm = document.querySelector('.admin-lawyers-filters');
  const searchInput = filterForm ? filterForm.querySelector('input[name="q"]') : null;
  let searchTimer = 0;

  const openModal = () => {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    const firstField = modal.querySelector('input, textarea, select, button');
    if (firstField) {
      window.setTimeout(() => firstField.focus(), 40);
    }
  };

  const closeModal = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  };

  if (openButton) {
    openButton.addEventListener('click', openModal);
  }

  closeButtons.forEach((button) => {
    button.addEventListener('click', closeModal);
  });

  modal.addEventListener('click', (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) {
      closeModal();
    }
  });

  if (searchInput && filterForm) {
    searchInput.addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(() => filterForm.submit(), 260);
    });
  }
})();
</script>
<?php lex_page_footer(); ?>
