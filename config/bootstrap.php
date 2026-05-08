<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../security/csrf.php';
require_once __DIR__ . '/../security/input_sanitizer.php';
require_once __DIR__ . '/../security/session_guard.php';

lex_start_secure_session();

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self' https: data:; script-src 'self' https://cdn.jsdelivr.net https://cdn.jsdelivr.net/npm https://cdnjs.cloudflare.com 'unsafe-inline'; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https:; connect-src 'self' http://127.0.0.1:3001 https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");

function lex_pdo(): PDO
{
    global $pdo, $lexDbPingChecked;

    if (!$pdo instanceof PDO) {
        return lex_reset_pdo();
    }

    if (!$lexDbPingChecked) {
        $lexDbPingChecked = true;
        try {
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            if (lex_db_is_connection_lost($e)) {
                return lex_reset_pdo();
            }
            throw $e;
        }
    }

    return $pdo;
}

function lex_db_retry(callable $callback, mixed $fallback = null): mixed
{
    try {
        return $callback();
    } catch (Throwable $e) {
        if (!lex_db_is_connection_lost($e)) {
            throw $e;
        }

        error_log('[DB] Lost connection detected, reconnecting: ' . $e->getMessage());
        try {
            lex_reset_pdo();
        } catch (Throwable $reconnectError) {
            error_log('[DB] Reconnect failed: ' . $reconnectError->getMessage());
            return $fallback;
        }

        try {
            return $callback();
        } catch (Throwable $retry) {
            error_log('[DB] Retry after reconnect failed: ' . $retry->getMessage());
            return $fallback;
        }
    }
}

function lex_app_url(string $path = ''): string
{
    global $lexAppUrl;
    return rtrim($lexAppUrl, '/') . '/' . ltrim($path, '/');
}

function lex_api_url(string $path = ''): string
{
    global $lexApiUrl;
    return rtrim($lexApiUrl, '/') . '/' . ltrim($path, '/');
}

function lex_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function lex_flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function lex_flash_get(): array
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

function lex_site_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    try {
        $rows = lex_pdo()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        if (!lex_db_is_connection_lost($e)) {
            $settings = [];
            return $settings;
        }

        $settings = lex_db_retry(static function (): array {
            $rows = lex_pdo()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['setting_key']] = $row['setting_value'];
            }
            return $result;
        }, []);
    }

    return $settings;
}

function lex_site_setting(string $key, string $default = ''): string
{
    $settings = lex_site_settings();
    return (string) ($settings[$key] ?? $default);
}

function lex_mail_error(?string $message = null): ?string
{
    if ($message !== null) {
        $GLOBALS['lexLastMailError'] = $message;
    }
    return $GLOBALS['lexLastMailError'] ?? null;
}

function lex_smtp_send(string $to, string $subject, string $body): bool
{
    $host = lex_site_setting('smtp_host');
    $port = (int) lex_site_setting('smtp_port', '587');
    $user = lex_site_setting('smtp_user');
    $pass = lex_site_setting('smtp_pass');
    if ($host === '') {
        lex_mail_error('SMTP host is missing.');
        return false;
    }

    $secure = $port === 465 ? 'ssl://' : '';
    $remote = $secure . $host . ':' . $port;
    $stream = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$stream) {
        lex_mail_error('Unable to connect to the SMTP server: ' . ($errstr ?: 'connection failed') . '.');
        return false;
    }
    stream_set_timeout($stream, 20);

    $read = static function ($stream): string {
        $data = '';
        while (($line = fgets($stream, 515)) !== false) {
            $data .= $line;
            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }
        return $data;
    };
    $write = static function ($stream, string $command): void {
        fwrite($stream, $command . "\r\n");
    };
    $expect = static function (string $response, array $codes): bool {
        foreach ($codes as $code) {
            if (str_starts_with($response, (string) $code)) {
                return true;
            }
        }
        return false;
    };
    $formatResponse = static function (string $response): string {
        $response = trim(preg_replace('/\s+/', ' ', $response));
        return $response !== '' ? $response : 'no response';
    };

    $greeting = $read($stream);
    if (!$expect($greeting, [220])) {
        lex_mail_error('SMTP server did not return a valid greeting.');
        fclose($stream);
        return false;
    }

    $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $write($stream, "EHLO {$hostname}");
    $response = $read($stream);
    if (!$expect($response, [250])) {
        $write($stream, "HELO {$hostname}");
        $response = $read($stream);
        if (!$expect($response, [250])) {
            lex_mail_error('SMTP handshake failed.');
            fclose($stream);
            return false;
        }
    }

    if ($port !== 465 && str_contains($response, 'STARTTLS')) {
        $write($stream, 'STARTTLS');
        $response = $read($stream);
        if (!$expect($response, [220])) {
            lex_mail_error('SMTP server rejected STARTTLS.');
            fclose($stream);
            return false;
        }
        if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            lex_mail_error('Could not establish a TLS connection to the SMTP server.');
            fclose($stream);
            return false;
        }
        $write($stream, "EHLO {$hostname}");
        $response = $read($stream);
        if (!$expect($response, [250])) {
            lex_mail_error('SMTP server rejected the secure handshake.');
            fclose($stream);
            return false;
        }
    }

    if ($user !== '') {
        $write($stream, 'AUTH LOGIN');
        $response = $read($stream);
        if (!$expect($response, [334])) {
            lex_mail_error('SMTP server did not accept AUTH LOGIN.');
            error_log('[SMTP] AUTH LOGIN rejected: ' . $formatResponse($response));
            fclose($stream);
            return false;
        }
        $write($stream, base64_encode($user));
        $response = $read($stream);
        if (!$expect($response, [334])) {
            $message = 'SMTP username was rejected by the server. Server replied: ' . $formatResponse($response) . '.';
            lex_mail_error($message);
            error_log('[SMTP] Username rejected: ' . $formatResponse($response));
            fclose($stream);
            return false;
        }
        $write($stream, base64_encode($pass));
        $response = $read($stream);
        if (!$expect($response, [235])) {
            $message = 'SMTP password was rejected by the server. Server replied: ' . $formatResponse($response) . '.';
            lex_mail_error($message);
            error_log('[SMTP] Password rejected: ' . $formatResponse($response));
            fclose($stream);
            return false;
        }
    }

    $from = $user !== '' ? $user : ('no-reply@' . ($hostname ?: 'localhost'));
    $fromName = lex_site_setting('site_name', LEX_APP_NAME);
    $messageId = sprintf('<%s@%s>', bin2hex(random_bytes(8)), preg_replace('/[^A-Za-z0-9\.\-]/', '', $hostname) ?: 'localhost');
    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s O'),
        'From: ' . $fromName . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . $subject,
        'Message-ID: ' . $messageId,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $write($stream, 'MAIL FROM:<' . $from . '>');
    if (!$expect($read($stream), [250])) {
        lex_mail_error('SMTP server rejected the sender address.');
        fclose($stream);
        return false;
    }
    $write($stream, 'RCPT TO:<' . $to . '>');
    if (!$expect($read($stream), [250, 251])) {
        lex_mail_error('SMTP server rejected the recipient address.');
        fclose($stream);
        return false;
    }
    $write($stream, 'DATA');
    if (!$expect($read($stream), [354])) {
        lex_mail_error('SMTP server did not accept the message body.');
        fclose($stream);
        return false;
    }

    $message = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace("/\r?\n/", "\r\n", $body);
    $message = preg_replace('/^\./m', '..', $message);
    $write($stream, $message . "\r\n.");
    if (!$expect($read($stream), [250])) {
        lex_mail_error('SMTP server rejected the email after DATA.');
        fclose($stream);
        return false;
    }

    $write($stream, 'QUIT');
    fclose($stream);
    return true;
}

function lex_send_email(string $to, string $subject, string $body): bool
{
    lex_mail_error(null);
    if (lex_smtp_send($to, $subject, $body)) {
        return true;
    }
    $from = lex_site_setting('smtp_user');
    $headers = [
        'From: ' . ($from !== '' ? $from : (LEX_APP_NAME . ' <no-reply@localhost>')),
        'Reply-To: ' . ($from !== '' ? $from : 'no-reply@localhost'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if (@mail($to, $subject, $body, implode("\r\n", $headers))) {
        return true;
    }
    if (!lex_mail_error()) {
        lex_mail_error('The local mail function failed.');
    }
    return false;
}

function lex_current_user(): ?array
{
    static $user = null;
    if ($user !== null) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $user = lex_db_retry(static function (): ?array {
        $stmt = lex_pdo()->prepare('SELECT id, full_name, email, role, is_active, last_login FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    }, null);
    if (!$user) {
        lex_logout_session();
    }
    return $user;
}

function lex_require_login(): array
{
    $user = lex_current_user();
    if (!$user) {
        header('Location: ' . lex_app_url('auth/login.php'));
        exit;
    }
    if (($user['is_active'] ?? 0) != 1) {
        lex_logout_session();
        header('Location: ' . lex_app_url('auth/login.php'));
        exit;
    }
    return $user;
}

function lex_require_role(string|array $roles): array
{
    $user = lex_require_login();
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($user['role'], $allowed, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
    return $user;
}

function lex_activity_label(string $label): string
{
    return ucwords(str_replace(['_', '-'], ' ', $label));
}

function lex_page_header(string $title, string $active = '', ?array $user = null): void
{
    $user = $user ?? lex_current_user();
    $role = $user['role'] ?? '';
    $isLoggedIn = (bool) $user;
    $flash = lex_flash_get();
    $links = [
        'admin' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '../admin/index.php'],
            ['key' => 'messages', 'label' => 'Messages', 'href' => 'messages.php'],
            ['key' => 'lawyers', 'label' => 'Lawyers', 'href' => 'manage_lawyers.php'],
            ['key' => 'clients', 'label' => 'Clients', 'href' => 'manage_clients.php'],
            ['key' => 'payments', 'label' => 'Payments', 'href' => 'payments.php'],
            ['key' => 'audit', 'label' => 'Audit Logs', 'href' => 'audit_logs.php'],
            ['key' => 'settings', 'label' => 'Settings', 'href' => 'system_settings.php'],
        ],
        'lawyer' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => lex_app_url('lawyer/index.php')],
            ['key' => 'profile', 'label' => 'Profile', 'href' => lex_app_url('lawyer/profile.php')],
            ['key' => 'case-files', 'label' => 'Case Files', 'href' => lex_app_url('case_files.php')],
            ['key' => 'appointments', 'label' => 'Appointments', 'href' => lex_app_url('lawyer/appointment.php')],
            ['key' => 'messages', 'label' => 'Messages', 'href' => lex_app_url('lawyer/messages.php')],
        ],
        'client' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => lex_app_url('client/index.php')],
            ['key' => 'lawyers', 'label' => 'Lawyers', 'href' => lex_app_url('client/lawyers.php')],
            ['key' => 'payments', 'label' => 'Payments', 'href' => lex_app_url('client/payments.php')],
            ['key' => 'billing', 'label' => 'Billing', 'href' => lex_app_url('client/billing.php')],
            ['key' => 'profile', 'label' => 'Profile', 'href' => lex_app_url('client/profile.php')],
            ['key' => 'case-files', 'label' => 'Case Files', 'href' => lex_app_url('case_files.php')],
            ['key' => 'messages', 'label' => 'Messages', 'href' => lex_app_url('client/messages.php')],
            ['key' => 'appointments', 'label' => 'Appointments', 'href' => lex_app_url('client/appointment.php')],
        ],
    ];
    $nav = $links[$role] ?? [];
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . lex_e($title) . ' | ' . lex_e(LEX_APP_NAME) . '</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="' . lex_e(lex_asset_url('public/css/style.css')) . '">';
    echo '<script defer src="' . lex_e(lex_asset_url('public/js/main.js')) . '"></script>';
    echo '</head><body data-api-base="' . lex_e(lex_api_url()) . '" data-role="' . lex_e($role) . '">';
    echo '<a class="skip-link" href="#main">Skip to content</a>';
    echo '<div class="app-shell">';
    if ($isLoggedIn && $role) {
        echo '<aside class="sidebar" id="sidebar" aria-label="Primary">';
        echo '<div class="brand-block"><div class="brand-mark">L</div><div><strong>' . lex_e(LEX_APP_NAME) . '</strong><span>Secure legal compliance</span></div></div>';
        echo '<nav class="sidebar-nav">';
        foreach ($nav as $item) {
            $activeClass = $active === $item['key'] ? ' active' : '';
            echo '<a class="nav-link' . $activeClass . '" href="' . lex_e($item['href']) . '">' . lex_e($item['label']) . '</a>';
        }
        echo '</nav>';
        echo '<div class="sidebar-footer"><a class="nav-link" href="' . lex_e(lex_app_url('auth/logout.php')) . '">Logout</a></div>';
        echo '</aside>';
    }
    echo '<main class="main-content" id="main">';
    $topbarClass = 'topbar';
    if ($title === 'Lawyer Profile' || $title === 'Client Profile') {
        $topbarClass .= ' is-lawyer-profile';
    }
    echo '<header class="' . $topbarClass . '">';
    echo '<button class="icon-button" id="sidebarToggle" aria-label="Toggle navigation">☰</button>';
    echo '<div class="topbar-title"><h1>' . lex_e($title) . '</h1></div>';
    echo '<div class="topbar-actions">';
    echo '<button class="icon-button" id="themeToggle" aria-label="Toggle theme">◐</button>';
    if ($user) {
        echo '<div class="user-chip"><strong>' . lex_e($user['full_name'] ?? '') . '</strong><span>' . lex_e(ucfirst($role)) . '</span></div>';
    } else {
        echo '<a class="button button-primary" href="' . lex_e(lex_app_url('auth/login.php')) . '">Login</a>';
    }
    echo '</div></header>';
    if ($flash) {
        echo '<div class="toast-stack" aria-live="polite" aria-atomic="true">';
        foreach ($flash as $item) {
            echo '<div class="toast toast-' . lex_e($item['type']) . '" role="status">';
            $label = (string) $item['message'];
            if (($item['type'] ?? '') === 'success' && stripos($label, 'approved') !== false) {
                $label = 'Approved';
            } elseif (($item['type'] ?? '') === 'success' && stripos($label, 'added') !== false) {
                $label = 'Added';
            } elseif (($item['type'] ?? '') === 'success' && stripos($label, 'updated') !== false) {
                $label = 'Updated';
            } elseif (($item['type'] ?? '') === 'success' && stripos($label, 'cancelled') !== false) {
                $label = 'Cancelled';
            } elseif (($item['type'] ?? '') === 'success' && stripos($label, 'removed') !== false) {
                $label = 'Removed';
            } elseif (($item['type'] ?? '') === 'success' && stripos($label, 'deleted') !== false) {
                $label = 'Deleted';
            }
            echo '<span class="toast-icon" aria-hidden="true">✓</span>';
            echo '<span class="toast-message">' . lex_e($label) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }
}

function lex_page_footer(): void
{
    echo '</main></div></body></html>';
}

function lex_auth_page_header(string $title): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . lex_e($title) . ' | ' . lex_e(LEX_APP_NAME) . '</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="' . lex_e(lex_asset_url('public/css/style.css')) . '">';
    echo '<script defer src="' . lex_e(lex_asset_url('public/js/main.js')) . '"></script>';
    echo '</head><body class="auth-page" data-api-base="' . lex_e(lex_api_url()) . '">';
    echo '<main class="auth-shell">';
}

function lex_auth_page_footer(): void
{
    echo '</main></body></html>';
}

function lex_asset_url(string $path): string
{
    $url = lex_app_url($path);
    $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $version = is_file($filePath) ? (string) filemtime($filePath) : '';
    if ($version === '') {
        return $url;
    }

    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
}

function lex_stats(string $query, array $params = []): int
{
    $result = lex_db_retry(static function () use ($query, $params): int {
        $stmt = lex_pdo()->prepare($query);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }, 0);

    return (int) $result;
}

function lex_recent(string $query, array $params = []): array
{
    $result = lex_db_retry(static function () use ($query, $params): array {
        $stmt = lex_pdo()->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }, []);

    return is_array($result) ? $result : [];
}

function lex_audit(string $action, string $table, ?string $targetId = null, ?int $userId = null): void
{
    try {
        $userId = $userId ?? (lex_current_user()['id'] ?? null);
        $stmt = lex_pdo()->prepare('INSERT INTO audit_logs (user_id, action, target_table, target_id, ip_address, user_agent, performed_at) VALUES (:user_id, :action, :target_table, :target_id, :ip_address, :user_agent, NOW())');
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'target_table' => $table,
            'target_id' => $targetId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 250),
        ]);
    } catch (Throwable $e) {
        error_log(sprintf('[AUDIT] %s on %s failed: %s', $action, $table, $e->getMessage()));
    }
}

function lex_notify(int $userId, string $type, string $message): void
{
    try {
        $stmt = lex_pdo()->prepare('INSERT INTO notifications (user_id, type, message, is_read, created_at) VALUES (:user_id, :type, :message, 0, NOW())');
        $stmt->execute([
            'user_id' => $userId,
            'type' => $type,
            'message' => $message,
        ]);
    } catch (Throwable $e) {
        error_log(sprintf('[NOTIFY] %s for user %d failed: %s', $type, $userId, $e->getMessage()));
    }
}

function lex_message_role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Admin',
        'lawyer' => 'Lawyer',
        'client' => 'Client',
        default => ucfirst($role),
    };
}

function lex_message_excerpt(string $value, int $limit = 72): string
{
    $text = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($text === '') {
        return 'No message yet';
    }
    if (strlen($text) <= $limit) {
        return $text;
    }
    return rtrim(substr($text, 0, $limit - 1)) . '…';
}

function lex_message_display_text(array $message): string
{
    $isEncrypted = !empty($message['is_encrypted']);
    $plain = $isEncrypted
        ? lex_decrypt_string((string) ($message['message_text'] ?? ''))
        : (string) ($message['message_text'] ?? '');
    $plain = trim($plain);
    if ($plain === '') {
        if (!empty($message['attachment_original_name'])) {
            $plain = 'Attachment: ' . (string) $message['attachment_original_name'];
        } elseif (!empty($message['attachment_stored_name'])) {
            $plain = 'Attachment: ' . (string) $message['attachment_stored_name'];
        }
    }
    return $plain !== '' ? $plain : (string) ($message['message_text'] ?? '');
}

function lex_message_timestamp(string $value): string
{
    $time = strtotime($value);
    if ($time === false) {
        return $value;
    }
    return date('M j, g:i A', $time);
}

function lex_message_bubble_class(int $senderId, int $currentUserId): string
{
    return $senderId === $currentUserId ? 'sent' : 'received';
}

function lex_case_count_for_role(array $user): int
{
    return lex_db_retry(static function () use ($user): int {
        if ($user['role'] === 'admin') {
            return lex_stats('SELECT COUNT(*) FROM cases');
        }
        if ($user['role'] === 'lawyer') {
            $stmt = lex_pdo()->prepare('SELECT id FROM lawyers WHERE user_id = :uid');
            $stmt->execute(['uid' => $user['id']]);
            $lawyerId = (int) ($stmt->fetchColumn() ?: 0);
            return $lawyerId ? lex_stats('SELECT COUNT(*) FROM cases WHERE lawyer_id = :lid', ['lid' => $lawyerId]) : 0;
        }
        $stmt = lex_pdo()->prepare('SELECT id FROM clients WHERE user_id = :uid');
        $stmt->execute(['uid' => $user['id']]);
        $clientId = (int) ($stmt->fetchColumn() ?: 0);
        return $clientId ? lex_stats('SELECT COUNT(*) FROM cases WHERE client_id = :cid', ['cid' => $clientId]) : 0;
    }, 0);
}

function lex_crypto_key(): string
{
    global $lexEncryptionKey;
    return substr(hash('sha256', $lexEncryptionKey, true), 0, 32);
}

function lex_encrypt_string(string $plaintext): string
{
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', lex_crypto_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function lex_decrypt_string(string $payload): string
{
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 17) {
        return '';
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', lex_crypto_key(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

function lex_user_lawyer_id(int $userId): int
{
    return (int) lex_db_retry(static function () use ($userId): int {
        $stmt = lex_pdo()->prepare('SELECT id FROM lawyers WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }, 0);
}

function lex_user_client_id(int $userId): int
{
    return (int) lex_db_retry(static function () use ($userId): int {
        $stmt = lex_pdo()->prepare('SELECT id FROM clients WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }, 0);
}

function lex_storage_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'documents';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function lex_profile_avatars_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'avatars';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function lex_profile_avatar_url(?string $storedName): string
{
    $storedName = trim((string) $storedName);
    if ($storedName === '') {
        return '';
    }
    return lex_app_url('storage/avatars/' . rawurlencode($storedName));
}

function lex_profile_avatar_remove(?string $storedName): void
{
    $storedName = trim((string) $storedName);
    if ($storedName === '') {
        return;
    }
    $path = lex_profile_avatars_dir() . DIRECTORY_SEPARATOR . $storedName;
    if (is_file($path)) {
        @unlink($path);
    }
}

function lex_case_files_base_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'case_files';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function lex_case_files_slug(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9]+/', '_', trim($value)) ?? '';
    $value = trim($value, '_');
    return strtoupper($value !== '' ? $value : 'CASE');
}

function lex_case_files_folder_name(string $fullName, string $caseFileTitle, string $identifier = ''): string
{
    $parts = ['CF', date('YmdHis'), lex_case_files_slug($fullName), lex_case_files_slug($caseFileTitle)];
    if ($identifier !== '') {
        $parts[] = lex_case_files_slug($identifier);
    }
    return implode('_', $parts);
}

function lex_case_files_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        lex_pdo()->exec(
            "CREATE TABLE IF NOT EXISTS `case_files` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `full_name` VARCHAR(150) NOT NULL,
              `case_identifier` VARCHAR(80) NOT NULL,
              `case_file_title` VARCHAR(180) NOT NULL,
              `description` TEXT NULL,
              `date_created` DATE NOT NULL,
              `client_user_id` INT UNSIGNED NOT NULL,
              `assigned_lawyer_user_id` INT UNSIGNED DEFAULT NULL,
              `status` ENUM('open','ongoing','closed') NOT NULL DEFAULT 'open',
              `folder_name` VARCHAR(180) NOT NULL,
              `attachments_json` LONGTEXT NULL,
              `created_by_user_id` INT UNSIGNED NOT NULL,
              `updated_by_user_id` INT UNSIGNED DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_case_files_identifier` (`case_identifier`),
              UNIQUE KEY `uq_case_files_folder` (`folder_name`),
              KEY `idx_case_files_fullname` (`full_name`),
              KEY `idx_case_files_title` (`case_file_title`),
              KEY `idx_case_files_client` (`client_user_id`),
              KEY `idx_case_files_lawyer` (`assigned_lawyer_user_id`),
              CONSTRAINT `fk_case_files_client_user` FOREIGN KEY (`client_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_case_files_assigned_lawyer_user` FOREIGN KEY (`assigned_lawyer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `fk_case_files_created_by_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
              CONSTRAINT `fk_case_files_updated_by_user` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    });
}

function lex_case_file_vault_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_case_files_table_ensure();
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `case_file_folders` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `case_file_id` INT UNSIGNED NOT NULL,
              `parent_folder_id` INT UNSIGNED DEFAULT NULL,
              `name` VARCHAR(150) NOT NULL,
              `slug` VARCHAR(170) NOT NULL,
              `created_by_user_id` INT UNSIGNED NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_case_file_folder_slug` (`case_file_id`, `slug`),
              KEY `idx_case_file_folders_case` (`case_file_id`),
              KEY `idx_case_file_folders_parent` (`parent_folder_id`),
              CONSTRAINT `fk_case_file_folders_case` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_case_file_folders_parent` FOREIGN KEY (`parent_folder_id`) REFERENCES `case_file_folders` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_case_file_folders_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `case_file_documents` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `case_file_id` INT UNSIGNED NOT NULL,
              `folder_id` INT UNSIGNED NOT NULL,
              `original_name` VARCHAR(255) NOT NULL,
              `stored_name` VARCHAR(255) NOT NULL,
              `mime_type` VARCHAR(120) DEFAULT NULL,
              `file_size` INT UNSIGNED DEFAULT NULL,
              `file_hash` CHAR(64) DEFAULT NULL,
              `upload_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
              `uploaded_by_user_id` INT UNSIGNED NOT NULL,
              `approved_by_user_id` INT UNSIGNED DEFAULT NULL,
              `approved_at` DATETIME DEFAULT NULL,
              `rejection_reason` TEXT DEFAULT NULL,
              `is_confidential` TINYINT(1) NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_case_file_documents_stored` (`case_file_id`, `stored_name`),
              KEY `idx_case_file_documents_case` (`case_file_id`, `upload_status`),
              KEY `idx_case_file_documents_folder` (`folder_id`),
              KEY `idx_case_file_documents_uploaded_by` (`uploaded_by_user_id`),
              CONSTRAINT `fk_case_file_documents_case` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_case_file_documents_folder` FOREIGN KEY (`folder_id`) REFERENCES `case_file_folders` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_case_file_documents_uploaded_by` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
              CONSTRAINT `fk_case_file_documents_approved_by` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    });
}

function lex_case_files_folder_path(string $folderName): string
{
    return lex_case_files_base_dir() . DIRECTORY_SEPARATOR . $folderName;
}

function lex_case_files_metadata_path(string $folderName): string
{
    return lex_case_files_folder_path($folderName) . DIRECTORY_SEPARATOR . 'metadata.json';
}

function lex_case_files_ensure_folders(string $folderName): void
{
    $root = lex_case_files_folder_path($folderName);
    $subfolders = ['DOCUMENTS', 'PHOTOS', 'EVIDENCE', 'COURT_FILINGS', 'CORRESPONDENCE', 'CLIENT_UPLOADS'];
    if (!is_dir($root)) {
        @mkdir($root, 0775, true);
    }
    foreach ($subfolders as $subfolder) {
        $path = $root . DIRECTORY_SEPARATOR . $subfolder;
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }
}

function lex_case_file_vault_default_folders(): array
{
    return [
        'Documents',
        'Evidence',
        'Court Filings',
        'Photos',
        'Correspondence',
        'Client Uploads',
    ];
}

function lex_case_file_vault_slug(string $value): string
{
    $slug = preg_replace('/[^A-Za-z0-9]+/', '_', trim($value)) ?? '';
    $slug = trim($slug, '_');
    return strtoupper($slug !== '' ? $slug : 'FOLDER');
}

function lex_case_file_vault_folder_dir(array $caseFile, array $folder): string
{
    $slug = lex_case_file_vault_slug((string) ($folder['slug'] ?? $folder['name'] ?? 'DOCUMENTS'));
    return lex_case_files_folder_path((string) $caseFile['folder_name']) . DIRECTORY_SEPARATOR . $slug;
}

function lex_case_file_vault_ensure_defaults(int $caseFileId, int $createdByUserId = 1): void
{
    lex_case_file_vault_table_ensure();
    $pdo = lex_pdo();
    $stmt = $pdo->prepare('SELECT folder_name FROM case_files WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $caseFileId]);
    $folderName = (string) ($stmt->fetchColumn() ?: '');
    if ($folderName === '') {
        return;
    }
    lex_case_files_ensure_folders($folderName);
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO case_file_folders (case_file_id, parent_folder_id, name, slug, created_by_user_id)
         VALUES (:case_file_id, NULL, :name, :slug, :created_by_user_id)'
    );
    $exists = $pdo->prepare('SELECT id FROM case_file_folders WHERE case_file_id = :case_file_id AND slug = :slug LIMIT 1');
    foreach (lex_case_file_vault_default_folders() as $name) {
        $slug = lex_case_file_vault_slug($name);
        $path = lex_case_files_folder_path($folderName) . DIRECTORY_SEPARATOR . $slug;
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        $exists->execute(['case_file_id' => $caseFileId, 'slug' => $slug]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $insert->execute([
            'case_file_id' => $caseFileId,
            'name' => $name,
            'slug' => $slug,
            'created_by_user_id' => $createdByUserId,
        ]);
    }
}

function lex_case_file_vault_fetch(int $caseFileId, array $viewer): array
{
    lex_case_file_vault_ensure_defaults($caseFileId, (int) ($viewer['id'] ?? 1));
    $pdo = lex_pdo();
    $folders = lex_recent(
        'SELECT f.*,
                (SELECT COUNT(*) FROM case_file_documents ad WHERE ad.folder_id = f.id AND ad.upload_status = "approved") AS approved_count,
                (SELECT COUNT(*) FROM case_file_documents pd WHERE pd.folder_id = f.id AND pd.upload_status = "pending") AS pending_count
         FROM case_file_folders f
         WHERE f.case_file_id = :case_file_id
         ORDER BY f.parent_folder_id IS NOT NULL, f.name ASC',
        ['case_file_id' => $caseFileId]
    );
    $statusClause = ((string) ($viewer['role'] ?? '') === 'lawyer') ? '1 = 1' : 'd.upload_status = "approved"';
    $documents = lex_recent(
        'SELECT d.*, f.name AS folder_name, f.slug AS folder_slug, u.full_name AS uploaded_by_name, au.full_name AS approved_by_name
         FROM case_file_documents d
         JOIN case_file_folders f ON f.id = d.folder_id
         JOIN users u ON u.id = d.uploaded_by_user_id
         LEFT JOIN users au ON au.id = d.approved_by_user_id
         WHERE d.case_file_id = :case_file_id AND ' . $statusClause . '
         ORDER BY d.upload_status = "pending" DESC, d.created_at DESC',
        ['case_file_id' => $caseFileId]
    );
    return ['folders' => $folders, 'documents' => $documents];
}

function lex_case_file_vault_access(array $caseFile, array $user): string
{
    $role = (string) ($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);
    if ($role === 'lawyer' && ($userId === (int) ($caseFile['created_by_user_id'] ?? 0) || $userId === (int) ($caseFile['assigned_lawyer_user_id'] ?? 0))) {
        return 'manage';
    }
    if ($role === 'client' && $userId === (int) ($caseFile['client_user_id'] ?? 0)) {
        return 'client';
    }
    return 'none';
}

function lex_case_file_vault_store_document(array $caseFile, int $folderId, array $file, array $user, string $status): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Select a document to upload.');
    }
    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException('Unable to upload document.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('Document is empty.');
    }
    if ($size > 25 * 1024 * 1024) {
        throw new RuntimeException('Document is too large. The limit is 25 MB.');
    }
    $folderStmt = lex_pdo()->prepare('SELECT * FROM case_file_folders WHERE id = :id AND case_file_id = :case_file_id LIMIT 1');
    $folderStmt->execute(['id' => $folderId, 'case_file_id' => (int) $caseFile['id']]);
    $folder = $folderStmt->fetch();
    if (!$folder) {
        throw new RuntimeException('Select a valid vault folder.');
    }
    $originalName = trim((string) ($file['name'] ?? 'document'));
    $originalName = $originalName !== '' ? $originalName : 'document';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $blockedExtensions = ['php', 'phtml', 'phar', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'sh', 'bat', 'cmd', 'exe', 'dll', 'com', 'scr'];
    if ($extension !== '' && in_array($extension, $blockedExtensions, true)) {
        throw new RuntimeException('Unsupported document type.');
    }
    $storedName = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
    $targetDir = lex_case_file_vault_folder_dir($caseFile, $folder);
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0775, true);
    }
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Unable to save document.');
    }
    $mime = 'application/octet-stream';
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($targetPath);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    } elseif (!empty($file['type'])) {
        $mime = (string) $file['type'];
    }
    $hash = hash_file('sha256', $targetPath) ?: null;
    $approvedBy = $status === 'approved' ? (int) $user['id'] : null;
    $approvedAt = $status === 'approved' ? date('Y-m-d H:i:s') : null;
    lex_pdo()->prepare(
        'INSERT INTO case_file_documents
            (case_file_id, folder_id, original_name, stored_name, mime_type, file_size, file_hash, upload_status, uploaded_by_user_id, approved_by_user_id, approved_at)
         VALUES
            (:case_file_id, :folder_id, :original_name, :stored_name, :mime_type, :file_size, :file_hash, :upload_status, :uploaded_by_user_id, :approved_by_user_id, :approved_at)'
    )->execute([
        'case_file_id' => (int) $caseFile['id'],
        'folder_id' => $folderId,
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'mime_type' => $mime,
        'file_size' => $size,
        'file_hash' => $hash,
        'upload_status' => $status,
        'uploaded_by_user_id' => (int) $user['id'],
        'approved_by_user_id' => $approvedBy,
        'approved_at' => $approvedAt,
    ]);
    return [
        'id' => (int) lex_pdo()->lastInsertId(),
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'path' => $targetPath,
        'mime_type' => $mime,
        'size' => $size,
    ];
}

function lex_case_files_write_metadata(array $record): void
{
    if (empty($record['folder_name'])) {
        return;
    }
    lex_case_files_ensure_folders((string) $record['folder_name']);
    $payload = [
        'FULLNAME' => $record['full_name'] ?? '',
        'CASE_IDENTIFIER' => $record['case_identifier'] ?? '',
        'CASE_FILE' => $record['case_file_title'] ?? '',
        'DESCRIPTION' => $record['description'] ?? '',
        'DATE_CREATED' => $record['date_created'] ?? '',
        'ASSIGNED_LAWYER' => [
            'id' => (int) ($record['assigned_lawyer_user_id'] ?? 0),
            'name' => $record['assigned_lawyer_name'] ?? '',
        ],
        'STATUS' => strtoupper((string) ($record['status'] ?? 'open')),
        'CLIENT_USER_ID' => (int) ($record['client_user_id'] ?? 0),
        'ATTACHMENTS' => json_decode((string) ($record['attachments_json'] ?? '[]'), true) ?: [],
        'UPDATED_AT' => $record['updated_at'] ?? date('c'),
    ];
    file_put_contents(lex_case_files_metadata_path((string) $record['folder_name']), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function lex_case_files_recursive_delete(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

function lex_messages_base_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'messages';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function lex_messages_attachment_path(string $storedName): string
{
    return lex_messages_base_dir() . DIRECTORY_SEPARATOR . $storedName;
}

function lex_human_file_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float) $bytes;
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return $unit === 0 ? sprintf('%d %s', (int) $size, $units[$unit]) : sprintf('%.1f %s', $size, $units[$unit]);
}

function lex_messages_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM messages');
        if ($stmt) {
            $columns = array_map(static fn ($row) => (string) $row['Field'], $stmt->fetchAll());
        }
        $adds = [
            'attachment_original_name' => "ALTER TABLE `messages` ADD COLUMN `attachment_original_name` VARCHAR(255) DEFAULT NULL AFTER `message_text`",
            'attachment_stored_name' => "ALTER TABLE `messages` ADD COLUMN `attachment_stored_name` VARCHAR(255) DEFAULT NULL AFTER `attachment_original_name`",
            'attachment_mime_type' => "ALTER TABLE `messages` ADD COLUMN `attachment_mime_type` VARCHAR(120) DEFAULT NULL AFTER `attachment_stored_name`",
            'attachment_size' => "ALTER TABLE `messages` ADD COLUMN `attachment_size` INT UNSIGNED DEFAULT NULL AFTER `attachment_mime_type`",
            'is_deleted' => "ALTER TABLE `messages` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_read`",
            'deleted_at' => "ALTER TABLE `messages` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_deleted`",
        ];
        foreach ($adds as $column => $sql) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec($sql);
            }
        }
        $done = true;
    });
}

function lex_message_deletions_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'message_deletions'");
        $exists = $stmt ? (bool) $stmt->fetchColumn() : false;
        if ($exists) {
            $done = true;
            return;
        }
        $pdo->exec(
            'CREATE TABLE `message_deletions` (
                `message_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `deleted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`message_id`, `user_id`),
                KEY `idx_message_deletions_user` (`user_id`),
                CONSTRAINT `fk_message_deletions_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_message_deletions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
        $done = true;
    });
}

function lex_message_visibility_clause(string $alias, string $userPlaceholder = ':viewer_id'): string
{
    return 'NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = ' . $alias . '.id AND md.user_id = ' . $userPlaceholder . ')';
}

function lex_mark_message_deleted_for_user(int $messageId, int $userId): void
{
    $stmt = lex_pdo()->prepare(
        'INSERT IGNORE INTO message_deletions (message_id, user_id)
         VALUES (:message_id, :user_id)'
    );
    $stmt->execute([
        'message_id' => $messageId,
        'user_id' => $userId,
    ]);
}

function lex_mark_conversation_deleted_for_user(int $caseId, int $currentUserId, int $partnerUserId): int
{
    $pdo = lex_pdo();
    $stmt = $pdo->prepare(
        'SELECT id
         FROM messages
         WHERE case_id = :case_id
           AND ((sender_id = :me1 AND receiver_id = :partner1) OR (sender_id = :partner2 AND receiver_id = :me2))'
    );
    $stmt->execute([
        'case_id' => $caseId,
        'me1' => $currentUserId,
        'partner1' => $partnerUserId,
        'partner2' => $partnerUserId,
        'me2' => $currentUserId,
    ]);
    $ids = array_map(static fn ($row) => (int) $row['id'], $stmt->fetchAll());
    if (!$ids) {
        return 0;
    }
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO message_deletions (message_id, user_id)
         VALUES (:message_id, :user_id)'
    );
    foreach ($ids as $messageId) {
        $insert->execute([
            'message_id' => $messageId,
            'user_id' => $currentUserId,
        ]);
    }
    return count($ids);
}

function lex_users_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM users');
        if ($stmt) {
            $columns = array_map(static fn ($row) => (string) $row['Field'], $stmt->fetchAll());
        }
        if (!in_array('avatar_stored_name', $columns, true)) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `avatar_stored_name` VARCHAR(255) DEFAULT NULL AFTER `last_login`");
        }
        $done = true;
    });
}

function lex_lawyers_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM lawyers');
        if ($stmt) {
            $columns = array_map(static fn ($row) => (string) $row['Field'], $stmt->fetchAll());
        }
        if (!in_array('background', $columns, true)) {
            $pdo->exec("ALTER TABLE `lawyers` ADD COLUMN `background` TEXT DEFAULT NULL AFTER `bio`");
        }
        $done = true;
    });
}

function lex_lawyer_reviews_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'lawyer_reviews'");
        $exists = $stmt ? (bool) $stmt->fetchColumn() : false;
        if ($exists) {
            $done = true;
            return;
        }

        $pdo->exec(
            "CREATE TABLE `lawyer_reviews` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `lawyer_id` INT UNSIGNED NOT NULL,
                `client_id` INT UNSIGNED NOT NULL,
                `rating` TINYINT UNSIGNED NOT NULL,
                `comment` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_lawyer_reviews_pair` (`lawyer_id`, `client_id`),
                KEY `idx_lawyer_reviews_lawyer` (`lawyer_id`),
                KEY `idx_lawyer_reviews_client` (`client_id`),
                CONSTRAINT `fk_lawyer_reviews_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_lawyer_reviews_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    });
}

function lex_store_profile_avatar(array $file): ?array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException('Unable to upload the avatar.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('Avatar image is empty.');
    }
    if ($size > 5 * 1024 * 1024) {
        throw new RuntimeException('Avatar image is too large. The limit is 5 MB.');
    }
    $tmpPath = (string) $file['tmp_name'];
    $imageType = function_exists('exif_imagetype') ? @exif_imagetype($tmpPath) : false;
    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    if (!isset($allowed[$imageType])) {
        throw new RuntimeException('Avatar must be a JPG, PNG, GIF, or WEBP image.');
    }
    $extension = $allowed[$imageType];
    $storedName = 'avatar_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = lex_profile_avatars_dir() . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Unable to save the avatar image.');
    }
    return [
        'stored_name' => $storedName,
        'path' => $targetPath,
        'mime_type' => image_type_to_mime_type($imageType),
        'size' => $size,
        'original_name' => (string) ($file['name'] ?? 'avatar'),
    ];
}

function lex_store_message_attachment(array $file): ?array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException('Unable to upload attachment.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('Attachment is empty.');
    }
    if ($size > 25 * 1024 * 1024) {
        throw new RuntimeException('Attachment is too large. The limit is 25 MB.');
    }
    $originalName = trim((string) ($file['name'] ?? 'attachment'));
    $originalName = $originalName !== '' ? $originalName : 'attachment';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $blockedExtensions = ['php', 'phtml', 'phar', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'sh', 'bat', 'cmd', 'exe', 'dll'];
    if ($extension !== '' && in_array($extension, $blockedExtensions, true)) {
        throw new RuntimeException('Unsupported attachment type.');
    }
    $storedName = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
    $targetPath = lex_messages_attachment_path($storedName);
    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Unable to save attachment.');
    }
    $mime = 'application/octet-stream';
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($targetPath);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    } elseif (!empty($file['type'])) {
        $mime = (string) $file['type'];
    }
    return [
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'mime_type' => $mime,
        'size' => $size,
        'path' => $targetPath,
    ];
}

function lex_payment_proofs_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'payment_proofs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function lex_payment_qr_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'payment_qr';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function lex_payment_proof_path(string $storedName): string
{
    return lex_payment_proofs_dir() . DIRECTORY_SEPARATOR . basename($storedName);
}

function lex_payment_qr_path(string $storedName): string
{
    return lex_payment_qr_dir() . DIRECTORY_SEPARATOR . basename($storedName);
}

function lex_store_payment_proof(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Upload your payment screenshot or PDF proof.');
    }
    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException('Unable to upload payment proof.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('Payment proof is empty.');
    }
    if ($size > 8 * 1024 * 1024) {
        throw new RuntimeException('Payment proof is too large. The limit is 8 MB.');
    }

    $tmpPath = (string) $file['tmp_name'];
    $originalName = trim((string) ($file['name'] ?? 'payment-proof'));
    $originalName = $originalName !== '' ? $originalName : 'payment-proof';

    $mime = '';
    $extension = '';
    $imageType = function_exists('exif_imagetype') ? @exif_imagetype($tmpPath) : false;
    $allowedImages = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    if (isset($allowedImages[$imageType])) {
        [$extension, $mime] = $allowedImages[$imageType];
    } else {
        $detectedMime = function_exists('mime_content_type') ? (string) @mime_content_type($tmpPath) : '';
        if ($detectedMime === 'application/pdf') {
            $extension = 'pdf';
            $mime = 'application/pdf';
        }
    }

    if ($extension === '' || $mime === '') {
        throw new RuntimeException('Payment proof must be a JPG, PNG, GIF, WEBP, or PDF file.');
    }

    $storedName = 'proof_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = lex_payment_proof_path($storedName);
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Unable to save payment proof.');
    }

    return [
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'mime_type' => $mime,
        'size' => $size,
        'path' => $targetPath,
    ];
}

function lex_store_payment_qr(array $file): ?array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException('Unable to upload the GCash QR image.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('GCash QR image is empty.');
    }
    if ($size > 5 * 1024 * 1024) {
        throw new RuntimeException('GCash QR image is too large. The limit is 5 MB.');
    }

    $tmpPath = (string) $file['tmp_name'];
    $imageType = function_exists('exif_imagetype') ? @exif_imagetype($tmpPath) : false;
    $allowed = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];
    if (!isset($allowed[$imageType])) {
        throw new RuntimeException('GCash QR must be a JPG, PNG, GIF, or WEBP image.');
    }

    [$extension, $mime] = $allowed[$imageType];
    $storedName = 'gcash_qr_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = lex_payment_qr_path($storedName);
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Unable to save the GCash QR image.');
    }

    return [
        'original_name' => trim((string) ($file['name'] ?? 'gcash-qr')),
        'stored_name' => $storedName,
        'mime_type' => $mime,
        'size' => $size,
        'path' => $targetPath,
    ];
}

function lex_case_files_sync_record(array $record): void
{
    if (empty($record['folder_name'])) {
        return;
    }
    lex_case_files_write_metadata($record);
}

function lex_manual_payments_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'manual_payments'");
        $exists = $stmt ? (bool) $stmt->fetchColumn() : false;
        if (!$exists) {
            $pdo->exec(
                "CREATE TABLE `manual_payments` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `client_id` INT UNSIGNED NOT NULL,
                    `payment_channel` ENUM('gcash') NOT NULL DEFAULT 'gcash',
                    `payment_for` VARCHAR(180) NOT NULL,
                    `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `currency` VARCHAR(10) NOT NULL DEFAULT 'PHP',
                    `payer_name` VARCHAR(150) NOT NULL,
                    `payer_contact` VARCHAR(40) DEFAULT NULL,
                    `reference_number` VARCHAR(120) DEFAULT NULL,
                    `notes` TEXT DEFAULT NULL,
                    `proof_original_name` VARCHAR(255) NOT NULL,
                    `proof_stored_name` VARCHAR(255) NOT NULL,
                    `proof_mime_type` VARCHAR(120) DEFAULT NULL,
                    `proof_size` INT UNSIGNED DEFAULT NULL,
                    `status` ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
                    `admin_notes` TEXT DEFAULT NULL,
                    `reviewed_by_user_id` INT UNSIGNED DEFAULT NULL,
                    `reviewed_at` DATETIME DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_manual_payments_client` (`client_id`, `status`, `created_at`),
                    KEY `idx_manual_payments_status` (`status`, `created_at`),
                    KEY `idx_manual_payments_reviewer` (`reviewed_by_user_id`),
                    CONSTRAINT `fk_manual_payments_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_manual_payments_reviewer` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $done = true;
    });
}

function lex_encrypt_file_contents(string $contents): string
{
    return lex_encrypt_string($contents);
}

function lex_decrypt_file_contents(string $payload): string
{
    return lex_decrypt_string($payload);
}

lex_users_table_ensure();
lex_lawyers_table_ensure();
lex_lawyer_reviews_table_ensure();
lex_manual_payments_table_ensure();
