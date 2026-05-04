<?php
declare(strict_types=1);

function lex_sanitize_text(?string $value): string
{
    return trim((string) filter_var($value, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH));
}

function lex_sanitize_email(?string $value): string
{
    return strtolower(trim((string) filter_var($value, FILTER_SANITIZE_EMAIL)));
}

function lex_sanitize_int(mixed $value, int $default = 0): int
{
    $filtered = filter_var($value, FILTER_VALIDATE_INT);
    return $filtered === false ? $default : (int) $filtered;
}

function lex_sanitize_bool(mixed $value): int
{
    return filter_var($value, FILTER_VALIDATE_BOOL) ? 1 : 0;
}

function lex_sanitize_filename(string $filename): string
{
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'file';
    return trim($filename, '._') ?: 'file';
}

