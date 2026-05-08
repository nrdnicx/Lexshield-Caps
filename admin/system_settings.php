<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');
$pdo = lex_pdo();
$currentSettings = [];
$rows = $pdo->query('SELECT setting_key, setting_value FROM site_settings ORDER BY setting_key')->fetchAll();
foreach ($rows as $row) {
    $currentSettings[$row['setting_key']] = $row['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $qrUpload = null;
    try {
        $qrUpload = lex_store_payment_qr($_FILES['gcash_qr_file'] ?? []);
        $settings = [
            'site_name' => lex_sanitize_text($_POST['site_name'] ?? ''),
            'session_timeout' => lex_sanitize_text($_POST['session_timeout'] ?? ''),
            'smtp_host' => lex_sanitize_text($_POST['smtp_host'] ?? ''),
            'smtp_port' => lex_sanitize_text($_POST['smtp_port'] ?? ''),
            'smtp_user' => lex_sanitize_email($_POST['smtp_user'] ?? ''),
            'smtp_pass' => trim((string) ($_POST['smtp_pass'] ?? '')) !== ''
                ? (string) ($_POST['smtp_pass'] ?? '')
                : (string) ($currentSettings['smtp_pass'] ?? ''),
            'gcash_account_name' => lex_sanitize_text($_POST['gcash_account_name'] ?? ''),
            'gcash_number' => preg_replace('/[^0-9+]/', '', (string) ($_POST['gcash_number'] ?? '')),
            'gcash_instructions' => trim((string) ($_POST['gcash_instructions'] ?? '')),
            'gcash_qr_stored_name' => (string) ($qrUpload['stored_name'] ?? ($currentSettings['gcash_qr_stored_name'] ?? '')),
        ];
        $stmt = $pdo->prepare('REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, NOW())');
        foreach ($settings as $key => $value) {
            $stmt->execute(['setting_key' => $key, 'setting_value' => (string) $value]);
        }
        lex_audit('update_settings', 'site_settings', 'global');
        lex_flash_set('success', 'System settings updated.');
        header('Location: ' . lex_app_url('admin/system_settings.php'));
        exit;
    } catch (Throwable $e) {
        if (!empty($qrUpload['path']) && is_file((string) $qrUpload['path'])) {
            @unlink((string) $qrUpload['path']);
        }
        lex_flash_set('error', 'Could not save settings.');
        header('Location: ' . lex_app_url('admin/system_settings.php'));
        exit;
    }
}

$settings = [];
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

lex_page_header('System Settings', 'settings');
?>
<section class="card admin-settings-card" data-system-settings-page>
  <div class="card-head"><h2>Platform Settings</h2></div>
  <form method="post" enctype="multipart/form-data" class="form-grid admin-settings-form">
    <?= lex_csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <label>Site name <input type="text" name="site_name" value="<?= lex_e($settings['site_name'] ?? 'LEXSHIELD') ?>"></label>
    <label>Session timeout (seconds) <input type="number" name="session_timeout" value="<?= lex_e($settings['session_timeout'] ?? '1800') ?>"></label>
    <label>SMTP host <input type="text" name="smtp_host" value="<?= lex_e($settings['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com"></label>
    <label>SMTP port <input type="number" name="smtp_port" value="<?= lex_e($settings['smtp_port'] ?? '587') ?>"></label>
    <label>SMTP user <input type="email" name="smtp_user" value="<?= lex_e($settings['smtp_user'] ?? '') ?>" placeholder="no-reply@example.com"></label>
    <label>SMTP password <input type="password" name="smtp_pass" value="" placeholder="Leave blank to keep existing value" autocomplete="new-password"></label>
    <label>GCash account name <input type="text" name="gcash_account_name" value="<?= lex_e($settings['gcash_account_name'] ?? '') ?>" placeholder="Juan Dela Cruz"></label>
    <label>GCash mobile number <input type="text" name="gcash_number" value="<?= lex_e($settings['gcash_number'] ?? '') ?>" placeholder="09XXXXXXXXX"></label>
    <label class="full">GCash instructions
      <textarea name="gcash_instructions" rows="4" placeholder="Add any reminders for clients before they upload proof."><?= lex_e($settings['gcash_instructions'] ?? '') ?></textarea>
    </label>
    <label class="full">GCash QR image
      <input type="file" name="gcash_qr_file" accept="image/png,image/jpeg,image/webp,image/gif">
    </label>
    <?php if (!empty($settings['gcash_qr_stored_name'])): ?>
      <div class="full profile-upload-row">
        <div class="profile-upload-thumb">
          <img src="<?= lex_e(lex_app_url('payment_qr_image.php')) ?>" alt="Current GCash QR">
        </div>
        <div class="profile-upload-field">
          <strong>Current GCash QR is active</strong>
          <p class="profile-upload-copy">Upload a new image only when you want to replace the current code.</p>
        </div>
      </div>
    <?php endif; ?>
    <button class="button button-primary admin-settings-submit" type="submit">Save Settings</button>
  </form>
</section>
<?php lex_page_footer(); ?>
