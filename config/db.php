<?php
declare(strict_types=1);

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = (int) (getenv('DB_PORT') ?: 3306);
$dbPort = $dbPort > 0 ? $dbPort : 3306;
$dbName = getenv('DB_NAME') ?: 'lexsh_db';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbCharset = getenv('DB_CHARSET') ?: 'utf8mb4';

global $lexDbDsn, $lexDbUser, $lexDbPass, $lexDbOptions, $lexDbPingChecked;
$lexDbDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $dbHost, $dbPort, $dbName, $dbCharset);
$lexDbUser = $dbUser;
$lexDbPass = $dbPass;
$lexDbOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$lexDbPingChecked = false;

function lex_db_is_connection_lost(Throwable $e): bool
{
    $code = (int) $e->getCode();
    $message = strtolower($e->getMessage());

    return in_array($code, [2002, 2006, 2013], true)
        || str_contains($message, 'server has gone away')
        || str_contains($message, 'lost connection')
        || str_contains($message, 'error while sending')
        || str_contains($message, 'no connection to the server')
        || str_contains($message, 'connection could not be made')
        || str_contains($message, 'actively refused')
        || str_contains($message, 'connection refused');
}

function lex_make_pdo(): PDO
{
    global $lexDbDsn, $lexDbUser, $lexDbPass, $lexDbOptions;

    return new PDO($lexDbDsn, $lexDbUser, $lexDbPass, $lexDbOptions);
}

function lex_reset_pdo(): PDO
{
    global $pdo, $lexDbPingChecked;

    $pdo = lex_make_pdo();
    $lexDbPingChecked = false;

    return $pdo;
}

try {
    $pdo = lex_make_pdo();
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Check your configuration.');
}
