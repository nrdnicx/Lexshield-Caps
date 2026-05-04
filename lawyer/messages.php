<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('lawyer');
$pdo = lex_pdo();
lex_messages_table_ensure();
lex_message_deletions_table_ensure();
$lawyerId = lex_user_lawyer_id((int) $user['id']);
$adminRow = lex_recent("SELECT id, full_name, email FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1");
$adminUser = $adminRow[0] ?? ['id' => 0, 'full_name' => 'Admin', 'email' => ''];
$currentUserLabel = $user['full_name'] ?? 'You';
$currentUserRole = lex_message_role_label('lawyer');

$cases = lex_recent(
        'SELECT c.id AS case_id, c.case_number, c.title, c.status, c.priority, cl.user_id AS client_user_id, cu.full_name AS client_name, cu.email AS client_email
     FROM cases c
     JOIN clients cl ON cl.id = c.client_id
     JOIN users cu ON cu.id = cl.user_id
     WHERE c.lawyer_id = :id
     ORDER BY c.id DESC',
    ['id' => $lawyerId]
);

$activeClients = lex_recent(
    'SELECT cl.id AS client_id, cl.user_id AS client_user_id, u.full_name AS client_name, u.email AS client_email,
            latest_case.case_id, latest_case.case_number, latest_case.case_title
     FROM clients cl
     JOIN users u ON u.id = cl.user_id
     LEFT JOIN (
         SELECT c1.client_id, c1.id AS case_id, c1.case_number, c1.title AS case_title
         FROM cases c1
         INNER JOIN (
             SELECT client_id, MAX(id) AS case_id
             FROM cases
             WHERE lawyer_id = :lawyer_id
             GROUP BY client_id
         ) latest ON latest.case_id = c1.id
     ) latest_case ON latest_case.client_id = cl.id
     WHERE u.is_active = 1
     ORDER BY u.full_name ASC',
    ['lawyer_id' => $lawyerId]
);

$caseMap = [];
$recipientMap = [];
$recipientById = [];
foreach ($cases as $case) {
    $caseMap[(int) $case['case_id']] = $case;
}
foreach ($activeClients as $client) {
    $clientRecipient = [
        'id' => (int) $client['client_user_id'],
        'name' => $client['client_name'],
        'email' => $client['client_email'],
        'role' => 'Client',
        'case_id' => (int) ($client['case_id'] ?? 0),
        'case_number' => (string) ($client['case_number'] ?? ''),
        'case_title' => (string) ($client['case_title'] ?? ''),
    ];
    $recipientMap['client:' . (int) $client['client_user_id']] = $clientRecipient;
    $recipientById[(int) $client['client_user_id']] = $clientRecipient;
}

if ((int) $adminUser['id'] > 0) {
    $adminRecipient = [
        'id' => (int) $adminUser['id'],
        'name' => $adminUser['full_name'] ?: 'Admin',
        'role' => 'Admin',
    ];
    $recipientMap['admin:' . (int) $adminUser['id']] = $adminRecipient;
    $recipientById[(int) $adminUser['id']] = $adminRecipient;
}
$availableRecipients = array_values($recipientMap);

$threadInput = (string) ($_GET['thread'] ?? '');
$selectedKind = 'client';
$selectedCaseId = isset($cases[0]) ? (int) $cases[0]['case_id'] : 0;
if (preg_match('/^(client|admin):(\d+)$/', $threadInput, $matches)) {
    $selectedKind = $matches[1];
    $selectedCaseId = (int) $matches[2];
    if (!isset($caseMap[$selectedCaseId])) {
        $selectedCaseId = isset($cases[0]) ? (int) $cases[0]['case_id'] : 0;
    }
}

$message = '';
$error = '';
$partnerId = 0;
$partnerName = '';
$partnerRole = '';
$caseInfo = $selectedCaseId && isset($caseMap[$selectedCaseId]) ? $caseMap[$selectedCaseId] : null;
$activeCaseNumber = $caseInfo['case_number'] ?? 'No case selected';

$profileName = $selectedKind === 'admin'
    ? (($adminUser['full_name'] ?? '') !== '' ? $adminUser['full_name'] : 'Admin')
    : ($partnerName !== '' ? $partnerName : 'Client');
$profileRole = $selectedKind === 'admin' ? 'Admin' : 'Client';
$profileStatus = $selectedKind === 'admin' ? 'Online' : 'Available';
$profileEmail = $selectedKind === 'admin'
    ? (string) ($adminUser['email'] ?? '')
    : (string) ($caseInfo['client_email'] ?? '');
$profileCase = $activeCaseNumber;
$profileNote = $selectedKind === 'admin'
    ? 'Use this thread for admin coordination and approvals.'
    : 'Use this thread for legal guidance and case updates.';
$profileInitials = strtoupper(substr(preg_replace('/\s+/', '', $profileName) ?: 'PR', 0, 2));

$deleteMessageAttachment = static function (array $message): void {
    if (!empty($message['attachment_stored_name'])) {
        $path = lex_messages_attachment_path((string) $message['attachment_stored_name']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
};

if ($selectedCaseId && $caseInfo) {
    if ($selectedKind === 'admin') {
        $partnerId = (int) $adminUser['id'];
        $partnerName = $adminUser['full_name'] ?: 'Admin';
        $partnerRole = 'admin';
    } else {
        $partnerId = (int) $caseInfo['client_user_id'];
        $partnerName = $caseInfo['client_name'];
        $partnerRole = 'client';
        $selectedKind = 'client';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    if (!empty($_POST['delete_conversation_submit'])) {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        $postThreadKind = (string) ($_POST['thread_kind'] ?? 'client');
        if ($postCaseId && isset($caseMap[$postCaseId])) {
            $caseRow = $caseMap[$postCaseId];
            $partnerUserId = $postThreadKind === 'admin'
                ? (int) $adminUser['id']
                : (int) $caseRow['client_user_id'];
            if ($partnerUserId > 0) {
                $deletedCount = lex_mark_conversation_deleted_for_user($postCaseId, (int) $user['id'], $partnerUserId);
                if ($deletedCount > 0) {
                    lex_audit('delete_conversation', 'messages', (string) $postCaseId);
                    lex_flash_set('success', 'Conversation deleted for your view.');
                } else {
                    lex_flash_set('error', 'No matching conversation was found to delete.');
                }
            }
        }
        header('Location: ' . lex_app_url('lawyer/messages.php'));
        exit;
    }
    if (!empty($_POST['delete_message_submit'])) {
        $messageId = lex_sanitize_int($_POST['message_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT id, sender_id, attachment_stored_name
             FROM messages
             WHERE id = :id
               AND case_id = :case_id
               AND sender_id = :sender_id
               AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :viewer_id)
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $messageId,
            'case_id' => $selectedCaseId,
            'sender_id' => (int) $user['id'],
            'viewer_id' => (int) $user['id'],
        ]);
        $messageRow = $stmt->fetch();
        if ($messageRow) {
            lex_mark_message_deleted_for_user($messageId, (int) $user['id']);
            lex_audit('delete_message', 'messages', (string) $messageId);
            lex_flash_set('success', 'Message deleted for your view.');
            header('Location: ' . lex_app_url('lawyer/messages.php?thread=' . urlencode($threadInput)));
            exit;
        }
        $error = 'Message not found.';
    }
    if (!empty($_POST['new_message_submit'])) {
        $postRecipientId = lex_sanitize_int($_POST['new_receiver_id'] ?? 0);
        $postCaseId = lex_sanitize_int($_POST['new_case_id'] ?? 0);
        $text = lex_sanitize_text($_POST['new_message_text'] ?? '');
        $recipientRole = (string) ($_POST['new_recipient_role'] ?? '');
        try {
            $attachment = lex_store_message_attachment($_FILES['new_attachment'] ?? []);
        } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
                $attachment = null;
        }
        $payload = trim($text);
        $recipientKey = $recipientRole . ':' . $postRecipientId;
        $recipientInfo = $recipientMap[$recipientKey] ?? null;
        if (!$recipientInfo && $postRecipientId > 0) {
            $recipientInfo = $recipientById[$postRecipientId] ?? null;
            if ($recipientInfo) {
                $recipientRole = strtolower((string) ($recipientInfo['role'] ?? ''));
            }
        }

        if (!$postRecipientId) {
            $error = $error ?: 'Select a recipient first.';
        } elseif (!$recipientInfo) {
            $error = $error ?: 'That recipient is not available.';
        } elseif ($payload === '' && !$attachment) {
            $error = $error ?: 'Type a message or attach a file.';
        } else {
            $expectedCaseId = 0;
            $caseRow = null;
            $pdo->beginTransaction();
            try {
                if ($recipientRole === 'admin') {
                    $caseRow = $caseMap[$postCaseId] ?? null;
                    if (!$caseRow) {
                        throw new RuntimeException('That case is not assigned to you.');
                    }
                    $expectedCaseId = $postCaseId;
                    if ((int) $adminUser['id'] !== $postRecipientId) {
                        throw new RuntimeException('That recipient is not available.');
                    }
                } else {
                    $clientCaseId = 0;
                    if ($postCaseId && isset($caseMap[$postCaseId]) && (int) $caseMap[$postCaseId]['client_user_id'] === $postRecipientId) {
                        $caseRow = $caseMap[$postCaseId];
                        $clientCaseId = $postCaseId;
                    }
                    if (!$caseRow) {
                        $stmt = $pdo->prepare(
                            'SELECT c.id AS case_id, c.case_number, c.title, c.status, c.priority, cl.user_id AS client_user_id, cu.full_name AS client_name, cu.email AS client_email
                             FROM cases c
                             JOIN clients cl ON cl.id = c.client_id
                             JOIN users cu ON cu.id = cl.user_id
                             WHERE c.lawyer_id = :lawyer_id
                               AND cu.id = :client_user_id
                             ORDER BY CASE WHEN c.status IN ("open", "ongoing") THEN 0 ELSE 1 END, c.id DESC
                             LIMIT 1'
                        );
                        $stmt->execute([
                            'lawyer_id' => $lawyerId,
                            'client_user_id' => $postRecipientId,
                        ]);
                        $caseRow = $stmt->fetch() ?: null;
                        $clientCaseId = (int) ($caseRow['case_id'] ?? 0);
                    }
                    if (!$caseRow) {
                        $recipientClientId = lex_user_client_id($postRecipientId);
                        if ($recipientClientId <= 0) {
                            throw new RuntimeException('That recipient is not available.');
                        }
                        do {
                            $caseNumber = 'INTAKE-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
                            $stmt = $pdo->prepare('SELECT 1 FROM cases WHERE case_number = :case_number LIMIT 1');
                            $stmt->execute(['case_number' => $caseNumber]);
                        } while ($stmt->fetchColumn());

                        $pdo->prepare(
                            'INSERT INTO cases (case_number, title, description, lawyer_id, client_id, status, priority, filed_date, closed_date)
                             VALUES (:case_number, :title, :description, :lawyer_id, :client_id, "open", "normal", CURDATE(), NULL)'
                        )->execute([
                            'case_number' => $caseNumber,
                            'title' => 'Client Intake Consultation',
                            'description' => 'Client conversation created automatically from the lawyer message composer.',
                            'lawyer_id' => $lawyerId,
                            'client_id' => $recipientClientId,
                        ]);

                        $clientCaseId = (int) $pdo->lastInsertId();
                        $caseRow = [
                            'case_id' => $clientCaseId,
                            'case_number' => $caseNumber,
                            'client_user_id' => $postRecipientId,
                            'client_name' => (string) ($recipientInfo['name'] ?? 'Client'),
                            'client_email' => (string) ($recipientInfo['email'] ?? ''),
                        ];
                    }
                    $expectedCaseId = (int) ($caseRow['case_id'] ?? $clientCaseId ?? 0);
                    if ($expectedCaseId <= 0) {
                        throw new RuntimeException('That case is not assigned to you.');
                    }
                    $postCaseId = $expectedCaseId;
                    if ((int) ($caseRow['client_user_id'] ?? $postRecipientId) !== $postRecipientId) {
                        throw new RuntimeException('That recipient is not available.');
                    }
                }
                if ($payload === '' && $attachment) {
                    $payload = 'Attachment: ' . $attachment['original_name'];
                }
                $encrypted = lex_encrypt_string($payload);
                $stmt = $pdo->prepare(
                    'INSERT INTO messages (sender_id, receiver_id, case_id, message_text, is_encrypted, sent_at, is_read, attachment_original_name, attachment_stored_name, attachment_mime_type, attachment_size)
                     VALUES (:sender_id, :receiver_id, :case_id, :message_text, 1, NOW(), 0, :attachment_original_name, :attachment_stored_name, :attachment_mime_type, :attachment_size)'
                );
                $stmt->execute([
                    'sender_id' => (int) $user['id'],
                    'receiver_id' => $postRecipientId,
                    'case_id' => $postCaseId,
                    'message_text' => $encrypted,
                    'attachment_original_name' => $attachment['original_name'] ?? null,
                    'attachment_stored_name' => $attachment['stored_name'] ?? null,
                    'attachment_mime_type' => $attachment['mime_type'] ?? null,
                    'attachment_size' => $attachment['size'] ?? null,
                ]);
                $messageId = (string) $pdo->lastInsertId();
                lex_audit('send_message', 'messages', $messageId);
                $pdo->commit();
                lex_notify($postRecipientId, 'message', 'You received a secure message in LEXSHIELD.');
                $threadInput = ($recipientRole === 'admin' ? 'admin' : 'client') . ':' . $postCaseId;
                header('Location: ' . lex_app_url('lawyer/messages.php?thread=' . urlencode($threadInput)));
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (!empty($attachment['path']) && is_file($attachment['path'])) {
                    @unlink($attachment['path']);
                }
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Unable to send the message right now.';
            }
        }
    } else {
        $postKind = (string) ($_POST['thread_kind'] ?? 'client');
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        $postReceiverId = lex_sanitize_int($_POST['receiver_id'] ?? 0);
        $text = lex_sanitize_text($_POST['message_text'] ?? '');
        try {
            $attachment = lex_store_message_attachment($_FILES['message_attachment'] ?? []);
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
            $attachment = null;
        }
        $payload = trim($text);

        if (!$postCaseId || !$postReceiverId) {
            $error = $error ?: 'Select a conversation first.';
        } elseif ($payload === '' && !$attachment) {
            $error = $error ?: 'Type a message or attach a file.';
        } elseif (!isset($caseMap[$postCaseId])) {
            $error = 'That case is not assigned to you.';
        } else {
            $caseRow = $caseMap[$postCaseId];
            $expectedReceiver = $postKind === 'admin' ? (int) $adminUser['id'] : (int) $caseRow['client_user_id'];
            if ($expectedReceiver === 0 || $expectedReceiver !== $postReceiverId) {
                $error = 'That conversation is not available.';
            } else {
                if ($payload === '' && $attachment) {
                    $payload = 'Attachment: ' . $attachment['original_name'];
                }
                $encrypted = lex_encrypt_string($payload);
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO messages (sender_id, receiver_id, case_id, message_text, is_encrypted, sent_at, is_read, attachment_original_name, attachment_stored_name, attachment_mime_type, attachment_size)
                         VALUES (:sender_id, :receiver_id, :case_id, :message_text, 1, NOW(), 0, :attachment_original_name, :attachment_stored_name, :attachment_mime_type, :attachment_size)'
                    );
                    $stmt->execute([
                        'sender_id' => (int) $user['id'],
                        'receiver_id' => $postReceiverId,
                        'case_id' => $postCaseId,
                        'message_text' => $encrypted,
                        'attachment_original_name' => $attachment['original_name'] ?? null,
                        'attachment_stored_name' => $attachment['stored_name'] ?? null,
                        'attachment_mime_type' => $attachment['mime_type'] ?? null,
                        'attachment_size' => $attachment['size'] ?? null,
                    ]);
                    lex_audit('send_message', 'messages', (string) $pdo->lastInsertId());
                    lex_notify($postReceiverId, 'message', 'You received a secure message in LEXSHIELD.');
                    $threadInput = $postKind . ':' . $postCaseId;
                    header('Location: ' . lex_app_url('lawyer/messages.php?thread=' . urlencode($threadInput)));
                    exit;
                } catch (Throwable $exception) {
                    if (!empty($attachment['path']) && is_file($attachment['path'])) {
                        @unlink($attachment['path']);
                    }
                    $error = 'Unable to send the message right now.';
                }
            }
        }
    }
}

$sidebarGroups = [
    'Clients' => [],
    'Admin' => [],
];

foreach ($cases as $case) {
    $caseId = (int) $case['case_id'];
    $clientUnread = lex_stats(
        'SELECT COUNT(*) FROM messages WHERE case_id = :case_id AND sender_id = :sender_id AND receiver_id = :receiver_id AND is_read = 0 AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :viewer_id)',
        ['case_id' => $caseId, 'sender_id' => (int) $case['client_user_id'], 'receiver_id' => (int) $user['id'], 'viewer_id' => (int) $user['id']]
    );
    $clientLast = lex_recent(
        'SELECT m.message_text, m.is_encrypted, m.sent_at FROM messages m
         WHERE m.case_id = ? AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
           AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = ?)
         ORDER BY m.sent_at DESC LIMIT 1',
        [$caseId, (int) $user['id'], (int) $case['client_user_id'], (int) $case['client_user_id'], (int) $user['id'], (int) $user['id']]
    );
    $clientLastRow = $clientLast[0] ?? null;
    $clientPreview = $clientLastRow ? lex_message_excerpt(lex_message_display_text($clientLastRow)) : 'No messages yet';
    $clientTime = $clientLastRow ? lex_message_timestamp((string) $clientLastRow['sent_at']) : '';
    if ($clientLastRow || $clientUnread > 0) {
        $sidebarGroups['Clients'][] = [
            'key' => 'client:' . $caseId,
            'title' => $case['client_name'],
            'subtitle' => $case['case_number'] . ' &middot; ' . $case['title'],
            'role' => 'Client',
            'unread' => $clientUnread,
            'preview' => $clientPreview,
            'time' => $clientTime,
            'important' => $case['priority'] === 'urgent' ? '1' : '0',
            'case_id' => $caseId,
            'thread_kind' => 'client',
        ];
    }

    if ((int) $adminUser['id'] > 0) {
        $adminUnread = lex_stats(
            'SELECT COUNT(*) FROM messages WHERE case_id = :case_id AND sender_id = :sender_id AND receiver_id = :receiver_id AND is_read = 0 AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :viewer_id)',
            ['case_id' => $caseId, 'sender_id' => (int) $adminUser['id'], 'receiver_id' => (int) $user['id'], 'viewer_id' => (int) $user['id']]
        );
        $adminLast = lex_recent(
            'SELECT m.message_text, m.is_encrypted, m.sent_at FROM messages m
             WHERE m.case_id = ? AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
               AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = ?)
             ORDER BY m.sent_at DESC LIMIT 1',
            [$caseId, (int) $user['id'], (int) $adminUser['id'], (int) $adminUser['id'], (int) $user['id'], (int) $user['id']]
        );
        $adminLastRow = $adminLast[0] ?? null;
        $adminPreview = $adminLastRow ? lex_message_excerpt(lex_message_display_text($adminLastRow)) : 'No messages yet';
        $adminTime = $adminLastRow ? lex_message_timestamp((string) $adminLastRow['sent_at']) : '';
        if ($adminLastRow || $adminUnread > 0) {
            $sidebarGroups['Admin'][] = [
                'key' => 'admin:' . $caseId,
                'title' => 'Admin Desk',
                'subtitle' => $case['case_number'] . ' &middot; ' . $case['title'],
                'role' => 'Admin',
                'unread' => $adminUnread,
                'preview' => $adminPreview,
                'time' => $adminTime,
                'important' => $case['priority'] === 'urgent' ? '1' : '0',
                'case_id' => $caseId,
                'thread_kind' => 'admin',
            ];
        }
    }
}

if (!$selectedCaseId && !empty($sidebarGroups['Clients'][0])) {
    $selectedCaseId = (int) $sidebarGroups['Clients'][0]['case_id'];
    $selectedKind = 'client';
    $caseInfo = $caseMap[$selectedCaseId];
    $partnerId = (int) $caseInfo['client_user_id'];
    $partnerName = $caseInfo['client_name'];
    $partnerRole = 'client';
    $activeCaseNumber = $caseInfo['case_number'];
}

if ($selectedCaseId && $partnerId) {
    $pdo->prepare(
        'UPDATE messages
         SET is_read = 1
         WHERE case_id = :case_id AND receiver_id = :receiver_id AND sender_id = :sender_id AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :viewer_id)'
    )->execute([
        'case_id' => $selectedCaseId,
        'receiver_id' => (int) $user['id'],
        'sender_id' => $partnerId,
        'viewer_id' => (int) $user['id'],
    ]);
}

$threadMessages = [];
if ($selectedCaseId && $partnerId) {
    $stmt = $pdo->prepare(
        'SELECT m.*, su.full_name AS sender_name, su.role AS sender_role, ru.full_name AS receiver_name, ru.role AS receiver_role
         FROM messages m
         JOIN users su ON su.id = m.sender_id
         JOIN users ru ON ru.id = m.receiver_id
         WHERE m.case_id = ?
           AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = ?)
           AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
         ORDER BY m.sent_at ASC'
    );
    $stmt->execute([
        $selectedCaseId,
        (int) $user['id'],
        (int) $user['id'],
        $partnerId,
        $partnerId,
        (int) $user['id'],
    ]);
    $threadMessages = $stmt->fetchAll();
}

$activeThread = $selectedKind . ':' . $selectedCaseId;
$threadLabel = $selectedKind === 'admin' ? 'Admin Desk' : ($partnerName ?: 'Client');
$onlineLabel = $selectedKind === 'admin' ? 'Online' : 'Available';
$caseStatus = $caseInfo['status'] ?? 'open';
$casePriority = $caseInfo['priority'] ?? 'normal';
$sendDisabled = !$selectedCaseId || !$partnerId;

lex_page_header('Messages', 'messages', $user);
?>
<section class="messages-page" data-chat-shell data-partner-name="<?= lex_e($threadLabel) ?>">
  <div class="messages-layout">
    <aside class="chat-sidebar">
      <div class="card-head" style="margin-bottom:0.8rem;">
        <h2>Conversations</h2>
        <span class="pill"><?= number_format(count($cases)) ?> cases</span>
      </div>
      <input class="conversation-search" type="search" placeholder="Search users or conversations" data-conversation-search>
      <div class="filter-tabs">
        <button class="filter-tab is-active" type="button" data-filter-tab="all">All</button>
        <button class="filter-tab" type="button" data-filter-tab="unread">Unread</button>
        <button class="filter-tab" type="button" data-filter-tab="important">Important</button>
      </div>
      <?php foreach ($sidebarGroups as $groupName => $items): ?>
        <div class="conversation-group">
          <h3><?= lex_e($groupName) ?></h3>
          <?php foreach ($items as $item): ?>
            <div class="conversation-item<?= $activeThread === $item['key'] ? ' is-active' : '' ?>" data-conversation-item data-unread="<?= (int) ($item['unread'] > 0 ? 1 : 0) ?>" data-important="<?= lex_e($item['important']) ?>">
              <a class="conversation-item-link" href="?thread=<?= urlencode($item['key']) ?>">
              <div class="conversation-item-top">
                <div class="conversation-item-title">
                  <strong><?= lex_e($item['title']) ?></strong>
                  <span class="conversation-role"><?= lex_e($item['role']) ?></span>
                </div>
                <?php if ((int) $item['unread'] > 0): ?><span class="badge"><?= (int) $item['unread'] ?></span><?php endif; ?>
              </div>
              <div class="conversation-item-bottom">
                <span class="muted"><?= lex_e($item['subtitle']) ?></span>
                <small><?= lex_e($item['time']) ?></small>
              </div>
              <small><?= lex_e($item['preview']) ?></small>
              </a>
              <form method="post" class="conversation-delete-form" data-no-loading>
                <?= lex_csrf_field() ?>
                <input type="hidden" name="delete_conversation_submit" value="1">
                <input type="hidden" name="case_id" value="<?= (int) $item['case_id'] ?>">
                <input type="hidden" name="thread_kind" value="<?= lex_e((string) $item['thread_kind']) ?>">
                <button class="conversation-delete-button" type="submit" aria-label="Delete conversation" data-confirm="Delete this conversation?">&times;</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </aside>

    <section class="chat-panel">
      <div class="chat-header">
        <div>
          <h2><?= lex_e($threadLabel) ?></h2>
          <div class="status-line"><?= lex_e($activeCaseNumber) ?> &middot; <?= lex_e($onlineLabel) ?></div>
        </div>
        <div class="header-actions">
          <div class="role-badge"><?= lex_e(lex_message_role_label($selectedKind === 'admin' ? 'admin' : 'client')) ?></div>
          <button
            class="icon-chip"
            type="button"
            data-modal-open="conversationInfoModal"
            data-info-name="<?= lex_e($threadLabel) ?>"
            data-info-role="<?= lex_e($selectedKind === 'admin' ? 'Admin' : 'Client') ?>"
            data-info-status="<?= lex_e($onlineLabel) ?>"
            data-info-case="<?= lex_e($activeCaseNumber) ?>"
            data-info-id="MSG-<?= (int) $selectedCaseId ?>"
            data-info-created="<?= lex_e(date('M j, Y')) ?>"
            data-info-activity="<?= lex_e($threadMessages ? lex_message_timestamp((string) end($threadMessages)['sent_at']) : 'No activity yet') ?>"
            aria-label="Conversation Info"
          >Info</button>
        </div>
      </div>

      <div class="chat-feed" data-chat-scroll>
        <?php if (!$selectedCaseId || !$partnerId): ?>
          <div class="info-card">
            <strong>No messages yet</strong>
            <p class="muted">Select a conversation to start chatting.</p>
          </div>
        <?php else: ?>
          <?php foreach ($threadMessages as $msg): ?>
            <?php
              $plain = lex_message_display_text($msg);
              $bubbleClass = lex_message_bubble_class((int) $msg['sender_id'], (int) $user['id']);
              $statusText = (int) $msg['sender_id'] === (int) $user['id'] ? ((int) $msg['is_read'] === 1 ? 'Seen' : 'Delivered') : 'Received';
            ?>
            <div class="message-row <?= lex_e($bubbleClass) ?>">
              <article class="chat-bubble <?= lex_e($bubbleClass) ?> role-<?= lex_e((string) $msg['sender_role']) ?>">
                <?php if ((int) $msg['sender_id'] === (int) $user['id']): ?>
                  <form method="post" class="message-delete-form" data-no-loading>
                    <?= lex_csrf_field() ?>
                    <input type="hidden" name="delete_message_submit" value="1">
                    <input type="hidden" name="message_id" value="<?= (int) $msg['id'] ?>">
                    <button class="message-delete-button" type="submit" aria-label="Delete message" data-confirm="Delete this message?">&times;</button>
                  </form>
                <?php endif; ?>
                <div class="bubble-meta">
                  <div class="bubble-meta" style="gap:0.45rem;">
                    <strong><?= lex_e($msg['sender_name']) ?></strong>
                    <span class="sender-pill"><?= lex_e(lex_message_role_label((string) $msg['sender_role'])) ?></span>
                  </div>
                  <span><?= lex_e(lex_message_timestamp((string) $msg['sent_at'])) ?></span>
                </div>
                <p class="bubble-text"><?= lex_e($plain) ?></p>
                <?php if (!empty($msg['attachment_stored_name'])): ?>
                  <div class="attachment-card">
                    <strong><?= lex_e((string) ($msg['attachment_original_name'] ?? $msg['attachment_stored_name'])) ?></strong>
                    <span class="muted"><?= lex_e(lex_human_file_size((int) ($msg['attachment_size'] ?? 0))) ?> · Downloadable file</span>
                    <a class="button button-secondary" href="<?= lex_e(lex_app_url('message_attachment.php?id=' . (int) $msg['id'])) ?>">Download</a>
                  </div>
                <?php endif; ?>
                <div class="bubble-meta">
                  <span class="bubble-status"><?= lex_e($statusText) ?></span>
                  <span class="bubble-status"><?= lex_e(ucfirst((string) $msg['sender_role'])) ?></span>
                </div>
              </article>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="conversation-actions">
        <button
          class="button button-secondary"
          type="button"
          data-modal-open="newMessageModal"
          data-default-case="<?= (int) $selectedCaseId ?>"
          data-default-recipient="<?= (int) $partnerId ?>"
          data-default-role="<?= lex_e($selectedKind === 'admin' ? 'admin' : 'client') ?>"
        >Create New Message</button>
      </div>

      <div class="chat-composer">
        <?php if ($message): ?><div class="alert alert-success"><?= lex_e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
        <form method="post" class="stack-form" data-chat-composer enctype="multipart/form-data">
          <?= lex_csrf_field() ?>
          <input type="hidden" name="thread_kind" value="<?= lex_e($selectedKind) ?>">
          <input type="hidden" name="case_id" value="<?= (int) $selectedCaseId ?>">
          <input type="hidden" name="receiver_id" value="<?= (int) $partnerId ?>">
          <div class="composer-row">
            <div class="composer-tools">
              <label class="icon-button ghost" style="display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;">
                &#128206;
                <input type="file" hidden accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.gif,.webp,.txt,.rtf,.csv,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.7z" data-attachment-input name="message_attachment">
              </label>
            </div>
            <textarea class="composer-input" name="message_text" rows="3" placeholder="<?= $sendDisabled ? 'Select a conversation first' : 'Write a secure message...' ?>" <?= $sendDisabled ? 'disabled' : '' ?> data-chat-input></textarea>
            <button class="button button-primary" type="submit" <?= $sendDisabled ? 'disabled' : '' ?>>Send</button>
          </div>
          <div class="attachment-preview" data-attachment-preview hidden></div>
        </form>
      </div>
    </section>

  </div>
</section>

<div class="modal-overlay" id="newMessageModal" data-modal aria-hidden="true">
  <div class="modal-card wide" role="dialog" aria-modal="true" aria-labelledby="newMessageTitle">
    <div class="modal-header">
      <h2 id="newMessageTitle">Create New Message</h2>
      <button class="close-button" type="button" data-modal-close aria-label="Close" onclick="document.getElementById('newMessageModal')?.classList.remove('is-open');document.getElementById('newMessageModal')?.setAttribute('aria-hidden','true');">&times;</button>
    </div>
    <div class="modal-body">
      
      <div class="error-text" data-modal-errors></div>
      <form method="post" class="modal-grid" data-new-message-form data-no-loading enctype="multipart/form-data">
        <?= lex_csrf_field() ?>
        <input type="hidden" name="new_message_submit" value="1">
        <input type="hidden" name="new_case_id" value="<?= (int) $selectedCaseId ?>" data-default-case-input>
        <label>Recipient Name
          <select class="modal-select" name="new_receiver_id" data-recipient-select required>
            <option value="">Select a recipient</option>
            <optgroup label="Clients">
              <?php foreach ($availableRecipients as $recipient): ?>
                <?php if ($recipient['role'] === 'Client'): ?>
                  <option
                    value="<?= (int) $recipient['id'] ?>"
                    data-role="client"
                    data-case-id="<?= (int) ($recipient['case_id'] ?? 0) ?>"
                    <?= $partnerRole === 'client' && (int) $recipient['id'] === (int) $partnerId ? 'selected' : '' ?>
                  ><?= lex_e($recipient['name']) ?><?= !empty($recipient['email']) ? ' - ' . lex_e((string) $recipient['email']) : '' ?></option>
                <?php endif; ?>
              <?php endforeach; ?>
            </optgroup>
            <?php if ((int) $adminUser['id'] > 0): ?>
              <optgroup label="Admin">
                <option value="<?= (int) $adminUser['id'] ?>" data-role="admin" <?= $partnerRole === 'admin' ? 'selected' : '' ?>><?= lex_e($adminUser['full_name'] ?: 'Admin') ?></option>
              </optgroup>
            <?php endif; ?>
          </select>
        </label>
        <input type="hidden" name="new_recipient_role" value="<?= lex_e($selectedKind === 'admin' ? 'admin' : 'client') ?>" data-recipient-role>
        <label>Sender
          <select class="modal-select" data-sender-select disabled>
            <option selected><?= lex_e($currentUserLabel) ?> (<?= lex_e($currentUserRole) ?>)</option>
          </select>
        </label>
        <label>Message <span class="muted">(optional if attaching a file)</span>
          <textarea class="modal-textarea" name="new_message_text" rows="6" data-message-input placeholder="Type your message here..."></textarea>
        </label>
        <div>
          <label class="button button-secondary file-button" data-modal-attachment-button for="lawyerNewMessageAttachment">Attach File</label>
          <input id="lawyerNewMessageAttachment" type="file" hidden accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.gif,.webp,.txt,.rtf,.csv,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.7z" data-modal-attachment-input name="new_attachment">
          <div class="attachment-preview" data-modal-attachment-name>No file selected</div>
        </div>
        <div class="modal-actions">
          <button class="button button-secondary" type="button" data-modal-close onclick="document.getElementById('newMessageModal')?.classList.remove('is-open');document.getElementById('newMessageModal')?.setAttribute('aria-hidden','true');">Cancel</button>
          <button class="button button-primary" type="submit">Send Message</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal-overlay" id="conversationInfoModal" data-modal aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="conversationInfoTitle">
    <div class="modal-header">
      <h2 id="conversationInfoTitle">Conversation Info</h2>
      <button class="close-button" type="button" data-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="modal-grid two-col">
        <div class="info-card">
          <div style="display:flex;align-items:center;gap:0.9rem;">
            <div class="avatar-placeholder" data-info-avatar>LS</div>
            <div>
              <strong data-info-name><?= lex_e($threadLabel) ?></strong>
              <div class="modal-note"><span data-info-role><?= lex_e($selectedKind === 'admin' ? 'Admin' : 'Client') ?></span> &middot; <span data-info-status><?= lex_e($onlineLabel) ?></span></div>
            </div>
          </div>
          <div class="info-row">
            <span>Case Name</span>
            <strong data-info-case><?= lex_e($activeCaseNumber) ?></strong>
          </div>
          <div class="info-row">
            <span>Conversation ID</span>
            <strong data-info-id>MSG-<?= (int) $selectedCaseId ?></strong>
          </div>
          <div class="info-row">
            <span>Created Date</span>
            <strong data-info-created><?= lex_e(date('M j, Y')) ?></strong>
          </div>
          <div class="info-row">
            <span>Last Activity</span>
            <strong data-info-activity><?= lex_e($threadMessages ? lex_message_timestamp((string) end($threadMessages)['sent_at']) : 'No activity yet') ?></strong>
          </div>
        </div>
        <div class="info-card">
          <h3 style="margin:0 0 0.4rem;">Shared Files</h3>
          <ul class="modal-list">
            <li><span>&#128196; NDA Agreement.pdf</span><span class="file-meta">240 KB</span></li>
            <li><span>&#128196; Evidence Notes.docx</span><span class="file-meta">112 KB</span></li>
          </ul>
        </div>
      </div>
      <div class="modal-actions">
        <button class="button button-secondary" type="button">Mark as Important</button>
        <button class="button button-secondary" type="button">Mute Notifications</button>
      </div>
    </div>
  </div>
</div>

<?php lex_page_footer(); ?>
