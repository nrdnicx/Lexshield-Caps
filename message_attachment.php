<?php
require_once __DIR__ . '/config/bootstrap.php';

$user = lex_require_login();
lex_messages_table_ensure();
lex_message_deletions_table_ensure();

$messageId = lex_sanitize_int($_GET['id'] ?? 0);
if (!$messageId) {
    http_response_code(404);
    exit('Attachment not found.');
}

$stmt = lex_pdo()->prepare(
    'SELECT m.id, m.sender_id, m.receiver_id, m.attachment_original_name, m.attachment_stored_name, m.attachment_mime_type, m.attachment_size
     FROM messages m
     WHERE m.id = :id
       AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = :viewer_id)
     LIMIT 1'
);
$stmt->execute(['id' => $messageId, 'viewer_id' => (int) $user['id']]);
$message = $stmt->fetch();

if (!$message) {
    http_response_code(404);
    exit('Attachment not found.');
}

$currentUserId = (int) $user['id'];
if ($currentUserId !== (int) $message['sender_id'] && $currentUserId !== (int) $message['receiver_id']) {
    http_response_code(403);
    exit('Access denied.');
}

$storedName = (string) ($message['attachment_stored_name'] ?? '');
$originalName = (string) ($message['attachment_original_name'] ?? '');
if ($storedName === '') {
    http_response_code(404);
    exit('Attachment not found.');
}

if ($originalName === '') {
    $originalName = basename($storedName);
}

$path = lex_messages_attachment_path($storedName);
if (!is_file($path)) {
    http_response_code(404);
    exit('Attachment file missing.');
}

$mime = (string) ($message['attachment_mime_type'] ?? 'application/octet-stream');
$size = (int) ($message['attachment_size'] ?? filesize($path));

lex_audit('download_message_attachment', 'messages', (string) $messageId);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', $originalName) . '"');
readfile($path);
exit;
