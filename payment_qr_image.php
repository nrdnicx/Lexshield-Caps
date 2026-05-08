<?php
require_once __DIR__ . '/config/bootstrap.php';

lex_require_login();

$storedName = trim(lex_site_setting('gcash_qr_stored_name'));
if ($storedName === '') {
    http_response_code(404);
    exit('GCash QR not configured.');
}

$path = lex_payment_qr_path($storedName);
if (!is_file($path)) {
    http_response_code(404);
    exit('GCash QR file missing.');
}

$mime = function_exists('mime_content_type') ? (string) @mime_content_type($path) : '';
if ($mime === '') {
    $mime = 'image/png';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="gcash-qr.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
readfile($path);
exit;
