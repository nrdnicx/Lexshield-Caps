<?php
require_once __DIR__ . '/config/bootstrap.php';

$user = lex_require_login();
$paymentId = lex_sanitize_int($_GET['id'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$stmt = lex_pdo()->prepare(
    'SELECT mp.*, c.user_id AS client_user_id
     FROM manual_payments mp
     JOIN clients c ON c.id = mp.client_id
     WHERE mp.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$currentUserId = (int) ($user['id'] ?? 0);
$role = (string) ($user['role'] ?? '');
$isOwner = $role === 'client' && $currentUserId === (int) ($payment['client_user_id'] ?? 0);
$isAdmin = $role === 'admin';
if (!$isOwner && !$isAdmin) {
    http_response_code(403);
    exit('Access denied.');
}

$storedName = (string) ($payment['proof_stored_name'] ?? '');
if ($storedName === '') {
    http_response_code(404);
    exit('Payment proof not found.');
}

$path = lex_payment_proof_path($storedName);
if (!is_file($path)) {
    http_response_code(404);
    exit('Payment proof file missing.');
}

$mime = (string) ($payment['proof_mime_type'] ?? 'application/octet-stream');
$size = (int) ($payment['proof_size'] ?? filesize($path));
$originalName = (string) ($payment['proof_original_name'] ?? basename($storedName));
$disposition = (isset($_GET['download']) && $_GET['download'] === '1') ? 'attachment' : 'inline';

lex_audit('download_payment_proof', 'manual_payments', (string) $paymentId);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '\\"', $originalName) . '"');
readfile($path);
exit;
