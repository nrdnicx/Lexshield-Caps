<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$pdo = lex_pdo();
$clientId = lex_user_client_id((int) $user['id']);

$profile = lex_recent(
    'SELECT c.contact_number, c.address, c.risk_level, u.full_name, u.email, u.password_hash, u.avatar_stored_name, u.created_at
     FROM clients c
     JOIN users u ON u.id = c.user_id
     WHERE c.id = :id
     LIMIT 1',
    ['id' => $clientId]
);
$profile = $profile[0] ?? [
    'contact_number' => '',
    'address' => '',
    'risk_level' => 'low',
    'full_name' => $user['full_name'] ?? 'Client',
    'email' => $user['email'] ?? '',
    'password_hash' => '',
    'avatar_stored_name' => '',
    'created_at' => '',
];

$error = '';

$fullName = (string) ($profile['full_name'] ?? '');
$email = (string) ($profile['email'] ?? '');
$contactNumber = (string) ($profile['contact_number'] ?? '');
$address = (string) ($profile['address'] ?? '');
$avatarStoredName = (string) ($profile['avatar_stored_name'] ?? '');
$avatarUrl = lex_profile_avatar_url($avatarStoredName);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid CSRF token.';
    } else {
        $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
        $email = lex_sanitize_email($_POST['email'] ?? '');
        $contactNumber = lex_sanitize_text($_POST['contact_number'] ?? '');
        $address = lex_sanitize_text($_POST['address'] ?? '');
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $newAvatar = null;

        if ($fullName === '' || $email === '' || $contactNumber === '') {
            $error = 'Please complete the required profile fields.';
        } elseif (($newPassword !== '' || $confirmPassword !== '' || $email !== (string) ($profile['email'] ?? '')) && $currentPassword === '') {
            $error = 'Enter your current password to change your email or password.';
        } elseif ($newPassword !== '' && strlen($newPassword) < 10) {
            $error = 'New password must be at least 10 characters.';
        } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
            $error = 'New password and confirmation do not match.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $stmt->execute(['email' => $email, 'id' => (int) $user['id']]);
            if ($stmt->fetchColumn()) {
                $error = 'That email address is already in use.';
            } elseif (($newPassword !== '' || $email !== (string) ($profile['email'] ?? '')) && !password_verify($currentPassword, (string) ($profile['password_hash'] ?? ''))) {
                $error = 'Current password is incorrect.';
            } else {
                try {
                    $newAvatar = lex_store_profile_avatar($_FILES['avatar_image'] ?? []);
                } catch (RuntimeException $exception) {
                    $error = $exception->getMessage();
                }

                if ($error === '') {
                    $oldAvatar = $avatarStoredName;
                    $pdo->beginTransaction();
                    try {
                        $updateUsers = 'UPDATE users SET full_name = :full_name, email = :email';
                        $params = [
                            'full_name' => $fullName,
                            'email' => $email,
                            'id' => (int) $user['id'],
                        ];
                        if ($newPassword !== '') {
                            $updateUsers .= ', password_hash = :password_hash';
                            $params['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
                        }
                        if ($newAvatar) {
                            $updateUsers .= ', avatar_stored_name = :avatar_stored_name';
                            $params['avatar_stored_name'] = $newAvatar['stored_name'];
                        }
                        $updateUsers .= ' WHERE id = :id';
                        $pdo->prepare($updateUsers)->execute($params);

                        $pdo->prepare(
                            'UPDATE clients
                             SET contact_number = :contact_number, address = :address
                             WHERE id = :id'
                        )->execute([
                            'contact_number' => $contactNumber,
                            'address' => $address,
                            'id' => $clientId,
                        ]);

                        lex_audit('update_client_profile', 'clients', (string) $clientId);
                        $pdo->commit();
                        if ($newAvatar && $oldAvatar !== '' && $oldAvatar !== $newAvatar['stored_name']) {
                            lex_profile_avatar_remove($oldAvatar);
                        }
                        lex_flash_set('success', 'Client profile updated successfully.');
                        header('Location: ' . lex_app_url('client/profile.php'));
                        exit;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        if ($newAvatar && is_file($newAvatar['path'])) {
                            @unlink($newAvatar['path']);
                        }
                        $error = 'Unable to update the profile right now.';
                    }
                }
            }
        }
    }
}

$profileInitials = strtoupper(substr(preg_replace('/\s+/', '', (string) ($fullName ?: 'CL')) ?: 'CL', 0, 2));

lex_page_header('Client Profile', 'profile', $user);
?>
<style>
  .client-profile-layout {
    display: grid !important;
    place-items: start center !important;
    padding: 0.35rem 0 0 !important;
  }

  .client-profile-layout .client-profile-card {
    width: min(100%, 560px) !important;
    max-width: 560px !important;
    margin-inline: auto !important;
    display: grid !important;
    gap: 0.8rem !important;
    padding: 1.2rem !important;
    border-radius: 24px !important;
    border: 1px solid var(--border) !important;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(247, 249, 253, 0.99)) !important;
    box-shadow: var(--shadow) !important;
  }

  html[data-theme="dark"] .client-profile-layout .client-profile-card {
    background: linear-gradient(180deg, rgba(12, 20, 34, 0.98), rgba(10, 16, 28, 0.99)) !important;
  }

  .client-profile-layout .profile-card-demo__avatar-wrap {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
  }

  .client-profile-layout img.profile-card-demo__avatar,
  .client-profile-layout .profile-card-demo__avatar {
    width: 104px !important;
    height: 104px !important;
    min-width: 104px !important;
    max-width: 104px !important;
    border-radius: 26px !important;
    object-fit: cover !important;
    display: block !important;
    margin-inline: auto !important;
  }

  .client-profile-layout .profile-card-demo__avatar:not(img) {
    display: grid !important;
    place-items: center !important;
    background: linear-gradient(135deg, #dbeafe, #bfdbfe) !important;
    color: #1d4ed8 !important;
    font-size: 1.5rem !important;
    font-weight: 800 !important;
    letter-spacing: 0.06em !important;
  }

  .client-profile-layout .profile-card-demo__header {
    display: grid !important;
    justify-items: center !important;
    text-align: center !important;
    gap: 0.4rem !important;
  }

  .client-profile-layout .profile-card-demo__name {
    margin: 0 !important;
    font-size: clamp(1.55rem, 2vw, 2rem) !important;
    line-height: 1.08 !important;
  }

  .client-profile-layout .profile-card-demo__title {
    margin: 0 !important;
    text-align: center !important;
    padding: 0.38rem 0.78rem !important;
    border-radius: 999px !important;
    background: rgba(10, 22, 40, 0.05) !important;
    font-size: 0.84rem !important;
    letter-spacing: 0.06em !important;
    text-transform: uppercase !important;
  }

  .client-profile-layout .profile-card-demo__bio {
    margin: 0 !important;
    text-align: center !important;
    padding: 0.95rem 1rem !important;
    border-radius: 18px !important;
    background: rgba(10, 22, 40, 0.03) !important;
    line-height: 1.6 !important;
  }

  .client-profile-layout .profile-card-demo__badge {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: fit-content !important;
    min-height: 26px !important;
    padding: 0.22rem 0.62rem !important;
    border-radius: 999px !important;
    font-size: 0.72rem !important;
    line-height: 1 !important;
  }

  .client-profile-layout .profile-card-demo__background,
  .client-profile-layout .profile-card-demo__row {
    display: grid !important;
    gap: 0.35rem !important;
    padding: 0.95rem 1rem !important;
    border: 1px solid var(--border) !important;
    border-radius: 18px !important;
    background: rgba(255,255,255,0.72) !important;
  }

  html[data-theme="dark"] .client-profile-layout .profile-card-demo__background,
  html[data-theme="dark"] .client-profile-layout .profile-card-demo__row {
    background: rgba(255,255,255,0.03) !important;
  }

  .client-profile-layout .profile-card-demo__info {
    display: grid !important;
    gap: 0.75rem !important;
  }

  .client-profile-layout .profile-card-demo__row {
    grid-template-columns: 1.2rem minmax(0, 1fr) !important;
    align-items: center !important;
    gap: 0.85rem !important;
  }

  .client-profile-layout svg.profile-card-demo__icon {
    width: 1.2rem !important;
    height: 1.2rem !important;
    min-width: 1.2rem !important;
    max-width: 1.2rem !important;
    display: block !important;
  }

  .client-profile-layout .profile-card-demo__row-body {
    display: grid !important;
    gap: 0.18rem !important;
    min-width: 0 !important;
  }

  .client-profile-layout .profile-card-demo__actions {
    display: flex !important;
    justify-content: center !important;
    gap: 0.7rem !important;
    flex-wrap: wrap !important;
    margin-top: 0.1rem !important;
  }

  .client-profile-layout .profile-card-demo__actions .button {
    min-width: 180px !important;
  }

  @media (max-width: 480px) {
    .client-profile-layout .client-profile-card {
      padding: 1rem !important;
    }

    .client-profile-layout img.profile-card-demo__avatar,
    .client-profile-layout .profile-card-demo__avatar {
      width: 92px !important;
      height: 92px !important;
      min-width: 92px !important;
      max-width: 92px !important;
      border-radius: 24px !important;
    }

    .client-profile-layout .profile-card-demo__actions .button {
      width: 100% !important;
      min-width: 0 !important;
    }
  }
</style>
<section class="profile-card-layout client-profile-layout">
  <article class="profile-card-demo profile-card-live profile-card-compact client-profile-card">
    <div class="profile-card-demo__avatar-wrap">
      <?php if ($avatarUrl !== ''): ?>
        <img class="profile-card-demo__avatar" src="<?= lex_e($avatarUrl) ?>" alt="Avatar for <?= lex_e($fullName) ?>">
      <?php else: ?>
        <div class="profile-card-demo__avatar"><?= lex_e($profileInitials) ?></div>
      <?php endif; ?>
    </div>
    <header class="profile-card-demo__header">
      <h2 class="profile-card-demo__name"><?= lex_e($fullName) ?></h2>
      <p class="profile-card-demo__title">Client</p>
      <span class="profile-card-demo__badge">Risk: <?= lex_e((string) ($profile['risk_level'] ?? 'low')) ?></span>
    </header>
    <?php if ($address !== ''): ?>
      <div class="profile-card-demo__background client-profile-card__address">
        <span class="profile-card-demo__row-label">Address</span>
        <strong><?= lex_e($address) ?></strong>
      </div>
    <?php endif; ?>
    <div class="profile-card-demo__divider" aria-hidden="true"></div>
    <section class="profile-card-demo__info" aria-label="Client details">
      <div class="profile-card-demo__row">
        <svg class="profile-card-demo__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M4 6h16v12H4z"></path>
          <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="m4 7 8 6 8-6"></path>
        </svg>
        <div class="profile-card-demo__row-body">
          <span class="profile-card-demo__row-label">Email</span>
          <strong class="profile-card-demo__row-value"><?= lex_e($email) ?></strong>
        </div>
      </div>
      <div class="profile-card-demo__row">
        <svg class="profile-card-demo__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-3 2c1.5 3 3.5 5 6 6l2-3 5 2v4c0 1-1 2-2 2C9 22 2 15 2 6c0-1 1-2 2-2h1z"></path>
        </svg>
        <div class="profile-card-demo__row-body">
          <span class="profile-card-demo__row-label">Phone</span>
          <strong class="profile-card-demo__row-value"><?= lex_e($contactNumber !== '' ? $contactNumber : 'Not provided') ?></strong>
        </div>
      </div>
    </section>
    <div class="profile-card-demo__actions client-profile-card__actions">
      <button class="button button-primary" type="button" data-profile-edit-open>Edit Profile</button>
    </div>
  </article>
</section>

<div class="modal-overlay" id="profileEditModal" data-modal aria-hidden="true">
  <div class="modal-card wide profile-edit-modal client-profile-edit-modal" role="dialog" aria-modal="true" aria-labelledby="profileEditTitle">
    <div class="modal-header client-profile-edit-modal__header">
      <div>
        <h2 id="profileEditTitle">Edit Client Profile</h2>
        <p class="modal-note">Update your account details and login credentials. Current password is required for email or password changes.</p>
      </div>
      <button class="close-button" type="button" data-profile-edit-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
      <form method="post" class="form-grid client-profile-edit-form" enctype="multipart/form-data">
        <?= lex_csrf_field() ?>
        <section class="client-profile-edit-section full">
          <div class="client-profile-edit-section__head">
            <h3>Profile Photo</h3>
            <p>Use a clear image for easier identification across messages, billing, and appointments.</p>
          </div>
          <label class="full">Profile avatar
            <div class="profile-upload-row client-profile-upload-row">
              <div class="profile-upload-thumb client-profile-upload-thumb" data-profile-upload-thumb>
                <?php if ($avatarUrl !== ''): ?>
                  <img src="<?= lex_e($avatarUrl) ?>" alt="Current avatar for <?= lex_e($fullName) ?>" data-profile-upload-image>
                <?php else: ?>
                  <span data-profile-upload-fallback><?= lex_e($profileInitials) ?></span>
                <?php endif; ?>
              </div>
              <div class="profile-upload-field client-profile-upload-field">
                <strong class="client-profile-upload-name"><?= lex_e($fullName) ?></strong>
                <p class="client-profile-upload-copy" data-profile-upload-copy>Accepted formats: JPG, PNG, GIF, WEBP. Maximum file size: 5 MB.</p>
                <input type="file" name="avatar_image" accept="image/png,image/jpeg,image/gif,image/webp" data-profile-upload-input>
              </div>
            </div>
          </label>
        </section>

        <section class="client-profile-edit-section full">
          <div class="client-profile-edit-section__head">
            <h3>Account Information</h3>
          </div>
          <div class="client-profile-edit-grid">
            <label>Full name
              <input type="text" name="full_name" required value="<?= lex_e($fullName) ?>">
            </label>
            <label>Email
              <input type="email" name="email" required value="<?= lex_e($email) ?>">
            </label>
            <label>Contact number
              <input type="text" name="contact_number" required value="<?= lex_e($contactNumber) ?>">
            </label>
            <label class="full">Address
              <textarea name="address" rows="4" placeholder="Add your address for billing and records."><?= lex_e($address) ?></textarea>
            </label>
          </div>
        </section>

        <section class="client-profile-edit-section full">
          <div class="client-profile-edit-section__head">
            <h3>Security</h3>
            <p>Current password is required before changing your email address or password.</p>
          </div>
          <div class="client-profile-edit-grid">
            <label>Current password
              <input type="password" name="current_password" autocomplete="current-password" placeholder="Required for email or password changes">
            </label>
            <label>New password
              <input type="password" name="new_password" minlength="10" autocomplete="new-password" placeholder="Leave blank to keep the same">
            </label>
            <label>Confirm new password
              <input type="password" name="confirm_password" autocomplete="new-password" placeholder="Repeat new password">
            </label>
          </div>
        </section>

        <div class="profile-card-demo__actions full client-profile-edit-actions">
          <button class="button button-primary" type="submit">Save changes</button>
          <button class="button button-secondary" type="button" data-profile-edit-close>Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('profileEditModal');
  if (!modal) return;
  const openButtons = document.querySelectorAll('[data-profile-edit-open]');
  const closeButtons = modal.querySelectorAll('[data-profile-edit-close]');
  const uploadInput = modal.querySelector('[data-profile-upload-input]');
  const uploadThumb = modal.querySelector('[data-profile-upload-thumb]');
  const uploadImage = modal.querySelector('[data-profile-upload-image]');
  const uploadFallback = modal.querySelector('[data-profile-upload-fallback]');
  const uploadCopy = modal.querySelector('[data-profile-upload-copy]');
  const defaultCopy = uploadCopy ? uploadCopy.textContent : '';
  let previewUrl = null;
  const open = () => {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  };
  const close = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  };
  openButtons.forEach((button) => button.addEventListener('click', open));
  closeButtons.forEach((button) => button.addEventListener('click', close));
  modal.addEventListener('click', (event) => {
    if (event.target === modal) close();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) close();
  });
  if (window.location.hash === '#edit-profile') {
    open();
  }
  if (uploadInput && uploadThumb) {
    uploadInput.addEventListener('change', () => {
      const file = uploadInput.files && uploadInput.files[0] ? uploadInput.files[0] : null;
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl);
        previewUrl = null;
      }
      if (!file) {
        if (uploadCopy && defaultCopy) uploadCopy.textContent = defaultCopy;
        return;
      }
      previewUrl = URL.createObjectURL(file);
      let imageNode = uploadThumb.querySelector('[data-profile-upload-image]');
      if (!imageNode) {
        imageNode = document.createElement('img');
        imageNode.setAttribute('data-profile-upload-image', '');
        imageNode.alt = 'Selected avatar preview';
        uploadThumb.innerHTML = '';
        uploadThumb.appendChild(imageNode);
      }
      imageNode.src = previewUrl;
      imageNode.alt = 'Selected avatar preview';
      if (uploadFallback) {
        uploadFallback.remove();
      }
      if (uploadCopy) {
        uploadCopy.textContent = file.name + ' selected';
      }
    });
  }
  <?php if ($error !== ''): ?>
  open();
  <?php endif; ?>
})();
</script>
<?php lex_page_footer(); ?>
