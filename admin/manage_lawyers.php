<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');
$pdo = lex_pdo();

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

$lawyers = lex_recent('SELECT l.*, u.full_name, u.email FROM lawyers l JOIN users u ON u.id = l.user_id ORDER BY l.id DESC');

lex_page_header('Manage Lawyers', 'lawyers');
?>
<section class="card admin-lawyer-card">
  <div class="card-head"><h2>Add Lawyer</h2></div>
  <form method="post" class="form-grid admin-lawyer-form">
    <?= lex_csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <label>Full name <input type="text" name="full_name" required></label>
    <label>Email <input type="email" name="email" required></label>
    <label>Bar number <input type="text" name="bar_number" required></label>
    <label>Specialization <input type="text" name="specialization" required></label>
    <label class="full">Background <textarea name="background" rows="3" placeholder="Short background, experience, or practice history."></textarea></label>
    <label>Password
      <div class="password-field" data-password-toggle>
        <input type="password" name="password" minlength="10" required>
        <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
      </div>
    </label>
    <label class="full">Bio <textarea name="bio" rows="3"></textarea></label>
    <button class="button button-primary admin-lawyer-submit" type="submit">Create Lawyer</button>
  </form>
</section>

<section class="card admin-lawyer-card">
  <div class="card-head"><h2>Lawyers</h2></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Email</th><th>Bar</th><th>Specialization</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($lawyers as $lawyer): ?>
        <tr>
          <td><?= lex_e($lawyer['full_name']) ?></td>
          <td><?= lex_e($lawyer['email']) ?></td>
          <td><?= lex_e($lawyer['bar_number']) ?></td>
          <td>
            <strong><?= lex_e($lawyer['specialization']) ?></strong>
            <?php if (!empty($lawyer['background'])): ?>
              <div class="muted"><?= lex_e($lawyer['background']) ?></div>
            <?php elseif (!empty($lawyer['bio'])): ?>
              <div class="muted"><?= lex_e($lawyer['bio']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="pill"><?= lex_e($lawyer['status']) ?></span></td>
          <td class="action-group">
            <form method="post">
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $lawyer['id'] ?>">
              <button class="button button-secondary" type="submit"><?= $lawyer['status'] === 'active' ? 'Suspend' : 'Reactivate' ?></button>
            </form>
            <form method="post" onsubmit="return confirm('Remove this lawyer?');">
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $lawyer['id'] ?>">
              <button class="button button-danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php lex_page_footer(); ?>
