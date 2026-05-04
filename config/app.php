<?php
declare(strict_types=1);

const LEX_APP_NAME = 'LEXSHIELD';
const LEX_DEFAULT_TIMEZONE = 'Asia/Shanghai';

function lex_load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

lex_load_env_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$lexAppUrl = getenv('APP_URL') ?: '';
$lexApiUrl = getenv('API_URL') ?: 'http://127.0.0.1:3001';
$lexSessionTimeout = (int) (getenv('SESSION_TIMEOUT') ?: 1800);
$lexEncryptionKey = getenv('DOCUMENT_ENCRYPTION_KEY') ?: 'change-me-change-me-change-me-change-me-32';

date_default_timezone_set(LEX_DEFAULT_TIMEZONE);

if ($lexAppUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    $lexAppUrl = $scheme . '://' . $host . ($basePath === '' ? '' : $basePath);
}
