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
    try {
        $settings = [
            'site_name' => lex_sanitize_text($_POST['site_name'] ?? ''),
            'session_timeout' => lex_sanitize_text($_POST['session_timeout'] ?? ''),
            'smtp_host' => lex_sanitize_text($_POST['smtp_host'] ?? ''),
            'smtp_port' => lex_sanitize_text($_POST['smtp_port'] ?? ''),
            'smtp_user' => lex_sanitize_email($_POST['smtp_user'] ?? ''),
            'smtp_pass' => trim((string) ($_POST['smtp_pass'] ?? '')) !== ''
                ? (string) ($_POST['smtp_pass'] ?? '')
                : (string) ($currentSettings['smtp_pass'] ?? ''),
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
  <form method="post" class="form-grid admin-settings-form">
    <?= lex_csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <label>Site name <input type="text" name="site_name" value="<?= lex_e($settings['site_name'] ?? 'LEXSHIELD') ?>"></label>
    <label>Session timeout (seconds) <input type="number" name="session_timeout" value="<?= lex_e($settings['session_timeout'] ?? '1800') ?>"></label>
    <label>SMTP host <input type="text" name="smtp_host" value="<?= lex_e($settings['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com"></label>
    <label>SMTP port <input type="number" name="smtp_port" value="<?= lex_e($settings['smtp_port'] ?? '587') ?>"></label>
    <label>SMTP user <input type="email" name="smtp_user" value="<?= lex_e($settings['smtp_user'] ?? '') ?>" placeholder="no-reply@example.com"></label>
    <label>SMTP password <input type="password" name="smtp_pass" value="" placeholder="Leave blank to keep existing value" autocomplete="new-password"></label>
    <button class="button button-primary admin-settings-submit" type="submit">Save Settings</button>
  </form>
</section>
<?php lex_page_footer(); ?>
