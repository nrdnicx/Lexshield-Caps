<?php
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = lex_pdo();
$error = '';
$success = '';
$fullName = '';
$email = '';
$contactNumber = '';
$address = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid CSRF token.';
    } else {
        $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
        $email = lex_sanitize_email($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $contactNumber = lex_sanitize_text($_POST['contact_number'] ?? '');
        $address = lex_sanitize_text($_POST['address'] ?? '');

        if (strlen($password) < 10) {
            $error = 'Password must be at least 10 characters.';
        } elseif ($fullName === '' || $email === '' || $contactNumber === '') {
            $error = 'Please complete all required fields.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            if ($stmt->fetchColumn()) {
                $error = 'An account already exists with that email.';
            } else {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active, created_at) VALUES (:full_name, :email, :password_hash, "client", 1, NOW())');
                    $stmt->execute([
                        'full_name' => $fullName,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    ]);
                    $userId = (int) $pdo->lastInsertId();
                    $stmt = $pdo->prepare('INSERT INTO clients (user_id, contact_number, address, risk_level) VALUES (:user_id, :contact_number, :address, "low")');
                    $stmt->execute([
                        'user_id' => $userId,
                        'contact_number' => $contactNumber,
                        'address' => $address,
                    ]);
                    lex_audit('register', 'users', (string) $userId, $userId);
                    $pdo->commit();
                    $success = 'Your client account has been created. You can now sign in.';
                    $fullName = '';
                    $email = '';
                    $contactNumber = '';
                    $address = '';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Registration failed. Please try again.';
                }
            }
        }
    }
}

lex_auth_page_header('Client Registration');
?>
<section class="auth-card auth-card-single">
  <div class="auth-copy auth-copy-light">
    <div class="hero-badge">Client Onboarding</div>
    <h1>Join the secure client portal.</h1>
  </div>
  <div class="auth-panel auth-panel-form">
    <h2>Create client account</h2>
    <p class="muted auth-subtitle">Fill out the form below to create your account.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= lex_e($success) ?></div><?php endif; ?>
    <form method="post" class="stack-form">
      <?= lex_csrf_field() ?>
      <label>Full name
        <input type="text" name="full_name" required value="<?= lex_e($fullName) ?>">
      </label>
      <label>Email
        <input type="email" name="email" required value="<?= lex_e($email) ?>">
      </label>
      <label>Contact number
        <input type="text" name="contact_number" required value="<?= lex_e($contactNumber) ?>">
      </label>
      <label>Address
        <textarea name="address" rows="3"><?= lex_e($address) ?></textarea>
      </label>
      <label>Password
        <div class="password-field" data-password-toggle>
          <input type="password" name="password" required minlength="10">
          <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
        </div>
      </label>
      <button class="button button-primary auth-submit" type="submit">Create account</button>
    </form>
    <p class="muted auth-footnote">Already have access? <a href="login.php">Sign in</a></p>
  </div>
</section>
<?php lex_auth_page_footer(); ?>
