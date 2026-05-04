<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('admin');
$pdo = lex_pdo();
lex_messages_table_ensure();
lex_message_deletions_table_ensure();
$adminUserId = (int) $user['id'];
$currentUserLabel = $user['full_name'] ?? 'You';
$currentUserRole = lex_message_role_label('admin');

$deleteMessageAttachment = static function (array $message): void {
    if (!empty($message['attachment_stored_name'])) {
        $path = lex_messages_attachment_path((string) $message['attachment_stored_name']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
};

$cases = lex_recent(
    'SELECT c.id AS case_id, c.case_number, c.title, c.status, c.priority, lu.user_id AS lawyer_user_id, uu.full_name AS lawyer_name, uu.email AS lawyer_email
     FROM cases c
     JOIN lawyers lu ON lu.id = c.lawyer_id
     JOIN users uu ON uu.id = lu.user_id
     WHERE EXISTS (
         SELECT 1
         FROM messages m
         WHERE m.case_id = c.id
           AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = :viewer_id)
           AND ((m.sender_id = :admin_id_1 AND m.receiver_id = lu.user_id) OR (m.sender_id = lu.user_id AND m.receiver_id = :admin_id_2))
     )
     ORDER BY c.id DESC'
    ,
    ['admin_id_1' => $adminUserId, 'admin_id_2' => $adminUserId, 'viewer_id' => $adminUserId]
);

$recipientCases = lex_recent(
    'SELECT c.id AS case_id, c.case_number, c.title, lu.user_id AS lawyer_user_id, uu.full_name AS lawyer_name, uu.email AS lawyer_email
     FROM cases c
     JOIN lawyers lu ON lu.id = c.lawyer_id
     JOIN users uu ON uu.id = lu.user_id
     WHERE uu.is_active = 1
     ORDER BY c.id DESC'
);

$activeLawyers = lex_recent(
    'SELECT uu.id AS lawyer_user_id, uu.full_name AS lawyer_name, uu.email AS lawyer_email, lu.specialization AS lawyer_specialization
     FROM users uu
     JOIN lawyers lu ON lu.user_id = uu.id
     WHERE uu.role = :role AND uu.is_active = 1 AND lu.status = :lawyer_status
     ORDER BY uu.full_name ASC',
    ['role' => 'lawyer', 'lawyer_status' => 'active']
);

$caseMap = [];
$recipientMap = [];
$composeCaseMap = [];
$caseByLawyerUserId = [];
foreach ($cases as $case) {
    $caseMap[(int) $case['case_id']] = $case;
    $composeCaseMap[(int) $case['case_id']] = $case;
}
foreach ($recipientCases as $case) {
    $lawyerUserId = (int) $case['lawyer_user_id'];
    $composeCaseMap[(int) $case['case_id']] = $composeCaseMap[(int) $case['case_id']] ?? $case;
    $caseByLawyerUserId[$lawyerUserId] = $caseByLawyerUserId[$lawyerUserId] ?? $case;
}
foreach ($activeLawyers as $lawyer) {
    $lawyerUserId = (int) $lawyer['lawyer_user_id'];
    $caseInfoForLawyer = $caseByLawyerUserId[$lawyerUserId] ?? null;
    $recipientMap['lawyer:' . $lawyerUserId] = [
        'id' => $lawyerUserId,
        'name' => $lawyer['lawyer_name'],
        'role' => 'Lawyer',
        'specialization' => $lawyer['lawyer_specialization'] ?? '',
        'case_id' => (int) ($caseInfoForLawyer['case_id'] ?? 0),
        'case_number' => $caseInfoForLawyer['case_number'] ?? '',
        'case_title' => $caseInfoForLawyer['title'] ?? '',
    ];
}
$availableRecipients = array_values($recipientMap);

$threadInput = (string) ($_GET['thread'] ?? '');
$selectedKind = 'lawyer';
$defaultComposeCase = $cases[0] ?? ($recipientCases[0] ?? null);
$selectedCaseId = $defaultComposeCase ? (int) $defaultComposeCase['case_id'] : 0;
if (preg_match('/^lawyer:(\d+)$/', $threadInput, $matches)) {
    $selectedCaseId = (int) $matches[1];
    if (!isset($caseMap[$selectedCaseId])) {
        $selectedCaseId = $defaultComposeCase ? (int) $defaultComposeCase['case_id'] : 0;
    }
}

$message = '';
$error = '';
$partnerId = 0;
$partnerName = '';
$caseInfo = $selectedCaseId && isset($caseMap[$selectedCaseId]) ? $caseMap[$selectedCaseId] : ($selectedCaseId && isset($composeCaseMap[$selectedCaseId]) ? $composeCaseMap[$selectedCaseId] : null);
$activeCaseNumber = $caseInfo['case_number'] ?? 'No case selected';

if ($selectedCaseId && $caseInfo) {
    $partnerId = (int) $caseInfo['lawyer_user_id'];
    $partnerName = $caseInfo['lawyer_name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    if (!empty($_POST['delete_conversation_submit'])) {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        if ($postCaseId && isset($caseMap[$postCaseId])) {
            $caseRow = $caseMap[$postCaseId];
            $partnerUserId = (int) $caseRow['lawyer_user_id'];
            $deletedCount = lex_mark_conversation_deleted_for_user($postCaseId, (int) $user['id'], $partnerUserId);
            if ($deletedCount > 0) {
                lex_audit('delete_conversation', 'messages', (string) $postCaseId);
                lex_flash_set('success', 'Conversation deleted for your view.');
            } else {
                lex_flash_set('error', 'No matching conversation was found to delete.');
            }
        }
        header('Location: ' . lex_app_url('admin/messages.php'));
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
            header('Location: ' . lex_app_url('admin/messages.php?thread=' . urlencode($threadInput)));
            exit;
        }
        $error = 'Message not found.';
    }
    if (!empty($_POST['new_message_submit'])) {
        $postReceiverId = lex_sanitize_int($_POST['new_receiver_id'] ?? 0);
        $subject = lex_sanitize_text($_POST['new_subject'] ?? '');
        $text = lex_sanitize_text($_POST['new_message_text'] ?? '');
        try {
            $attachment = lex_store_message_attachment($_FILES['new_attachment'] ?? []);
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
            $attachment = null;
        }
        $payload = trim(($subject !== '' ? "Subject: {$subject}\n\n" : '') . $text);
        $resolvedCase = $postReceiverId && isset($caseByLawyerUserId[$postReceiverId]) ? $caseByLawyerUserId[$postReceiverId] : null;
        if (!$postReceiverId || !$resolvedCase || ($payload === '' && !$attachment)) {
            $error = $error ?: 'Complete the case and message before sending.';
        } else {
            $postCaseId = (int) $resolvedCase['case_id'];
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
                header('Location: messages.php?thread=' . urlencode('lawyer:' . $postCaseId));
                exit;
            } catch (Throwable $exception) {
                if (!empty($attachment['path']) && is_file($attachment['path'])) {
                    @unlink($attachment['path']);
                }
                $error = 'Unable to send the message right now.';
            }
        }
    } else {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        $text = lex_sanitize_text($_POST['message_text'] ?? '');
        try {
            $attachment = lex_store_message_attachment($_FILES['message_attachment'] ?? []);
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
            $attachment = null;
        }
        $payload = trim($text);

        if (!$postCaseId || ($payload === '' && !$attachment)) {
            $error = $error ?: 'Complete the case and message before sending.';
        } elseif (!isset($composeCaseMap[$postCaseId])) {
            $error = 'That case is not available.';
        } else {
            $postReceiverId = (int) $composeCaseMap[$postCaseId]['lawyer_user_id'];
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
                header('Location: messages.php?thread=' . urlencode('lawyer:' . $postCaseId));
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

$sidebarGroups = ['Lawyers' => []];
foreach ($cases as $case) {
    $caseId = (int) $case['case_id'];
    $unread = lex_stats(
        'SELECT COUNT(*) FROM messages WHERE case_id = :case_id AND sender_id = :sender_id AND receiver_id = :receiver_id AND is_read = 0 AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :viewer_id)',
        ['case_id' => $caseId, 'sender_id' => (int) $case['lawyer_user_id'], 'receiver_id' => (int) $user['id'], 'viewer_id' => (int) $user['id']]
    );
    $last = lex_recent(
        'SELECT m.message_text, m.is_encrypted, m.sent_at FROM messages m
         WHERE m.case_id = ? AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = ?) AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
         ORDER BY m.sent_at DESC LIMIT 1',
        [$caseId, (int) $user['id'], (int) $user['id'], (int) $case['lawyer_user_id'], (int) $case['lawyer_user_id'], (int) $user['id']]
    );
$lastRow = $last[0] ?? null;
    $preview = $lastRow ? lex_message_excerpt(lex_message_display_text($lastRow)) : 'No messages yet';
    $time = $lastRow ? lex_message_timestamp((string) $lastRow['sent_at']) : '';
    $sidebarGroups['Lawyers'][] = [
        'key' => 'lawyer:' . $caseId,
        'title' => $case['lawyer_name'],
        'subtitle' => $case['case_number'] . ' · ' . $case['title'],
        'role' => 'Lawyer',
        'unread' => $unread,
        'preview' => $preview,
        'time' => $time,
        'important' => $case['priority'] === 'urgent' ? '1' : '0',
        'case_id' => $caseId,
    ];
}

if (!$selectedCaseId && !empty($sidebarGroups['Lawyers'][0])) {
    $selectedCaseId = (int) $sidebarGroups['Lawyers'][0]['case_id'];
    $caseInfo = $caseMap[$selectedCaseId];
    $partnerId = (int) $caseInfo['lawyer_user_id'];
    $partnerName = $caseInfo['lawyer_name'];
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

$activeThread = 'lawyer:' . $selectedCaseId;
$threadLabel = $partnerName ?: 'Lawyer';
$onlineLabel = 'Online';
$caseStatus = $caseInfo['status'] ?? 'open';
$casePriority = $caseInfo['priority'] ?? 'normal';
$sendDisabled = !$selectedCaseId || !$partnerId;

lex_page_header('Messages', 'messages', $user);
?>
<section class="messages-page admin-messages-page" data-chat-shell data-partner-name="<?= lex_e($threadLabel) ?>">
  <div class="messages-layout admin-messages-layout">
    <aside class="chat-sidebar admin-messages-sidebar">
      <div class="card-head admin-messages-head">
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
                <input type="hidden" name="thread_kind" value="lawyer">
                <button class="conversation-delete-button" type="submit" aria-label="Delete conversation" data-confirm="Delete this conversation?">&times;</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </aside>

    <section class="chat-panel admin-messages-panel">
      <div class="chat-header admin-messages-header">
        <div>
          <h2><?= lex_e($threadLabel) ?></h2>
          <div class="status-line"><?= lex_e($activeCaseNumber) ?> · <?= lex_e($onlineLabel) ?></div>
        </div>
        <div class="header-actions">
          <div class="role-badge"><?= lex_e(lex_message_role_label('lawyer')) ?></div>
          <button
            class="icon-chip"
            type="button"
            data-modal-open="conversationInfoModal"
            data-info-name="<?= lex_e($threadLabel) ?>"
            data-info-role="Lawyer"
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
            <p class="muted">Select a lawyer conversation to start chatting.</p>
          </div>
        <?php else: ?>
          <?php foreach ($threadMessages as $msg): ?>
            <?php
              $plain = $msg['is_encrypted'] ? lex_decrypt_string((string) $msg['message_text']) : (string) $msg['message_text'];
              $plain = trim($plain);
              if ($plain === '' && !empty($msg['attachment_original_name'])) {
                $plain = 'Attachment: ' . (string) $msg['attachment_original_name'];
              }
              $plain = $plain !== '' ? $plain : (string) $msg['message_text'];
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
 <span class="muted"><?= lex_e(lex_human_file_size((int) ($msg['attachment_size'] ?? 0))) ?> &middot; Downloadable file</span>
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

      <div class="conversation-actions admin-messages-actions">
        <button
          class="button button-secondary"
          type="button"
          data-modal-open="newMessageModal"
          data-default-case="<?= (int) $selectedCaseId ?>"
          data-default-recipient="<?= (int) $partnerId ?>"
          data-default-role="lawyer"
        >Create New Message</button>
      </div>

      <div class="chat-composer admin-messages-composer">
        <?php if ($message): ?><div class="alert alert-success"><?= lex_e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
        <form method="post" class="stack-form" data-chat-composer enctype="multipart/form-data">
          <?= lex_csrf_field() ?>
          <input type="hidden" name="case_id" value="<?= (int) $selectedCaseId ?>">
          <input type="hidden" name="receiver_id" value="<?= (int) $partnerId ?>">
          <input type="hidden" name="thread_kind" value="lawyer">
          <div class="composer-row">
            <div class="composer-tools">
              <label class="icon-button ghost" style="display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;">
                📎
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
  <div class="modal-card wide admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="newMessageTitle">
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
            <option value="" selected>Select a lawyer</option>
            <?php foreach ($availableRecipients as $recipient): ?>
              <?php $recipientCaseId = (int) ($recipient['case_id'] ?? 0); ?>
              <option
                value="<?= (int) $recipient['id'] ?>"
                data-role="lawyer"
                data-case-id="<?= $recipientCaseId ?>"
                <?= $recipientCaseId === 0 ? 'disabled' : '' ?>
                <?= (int) $recipient['id'] === (int) $partnerId ? 'selected' : '' ?>
              ><?= lex_e($recipient['name'] . (!empty($recipient['specialization']) ? ' · ' . $recipient['specialization'] : '')) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <input type="hidden" name="new_recipient_role" value="lawyer" data-recipient-role>
        <label>Sender
          <select class="modal-select" disabled>
            <option selected><?= lex_e($currentUserLabel) ?> (<?= lex_e($currentUserRole) ?>)</option>
          </select>
        </label>
        <label>Subject
          <input class="modal-input" type="text" name="new_subject" data-subject-input placeholder="Optional subject">
        </label>
        <label>Message <span class="muted">(optional if attaching a file)</span>
          <textarea class="modal-textarea" name="new_message_text" rows="6" data-message-input placeholder="Type your message here..."></textarea>
        </label>
        <div>
          <label class="button button-secondary file-button" data-modal-attachment-button for="adminNewMessageAttachment">Attach File</label>
          <input id="adminNewMessageAttachment" type="file" hidden accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.gif,.webp,.txt,.rtf,.csv,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.7z" data-modal-attachment-input name="new_attachment">
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
  <div class="modal-card admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="conversationInfoTitle">
    <div class="modal-header">
      <h2 id="conversationInfoTitle">Conversation Info</h2>
            <button class="close-button" type="button" data-modal-close aria-label="Close" onclick="document.getElementById('conversationInfoModal')?.classList.remove('is-open');document.getElementById('conversationInfoModal')?.setAttribute('aria-hidden','true');">&times;</button>
    </div>
    <div class="modal-body">
      <div class="modal-grid two-col">
        <div class="info-card">
          <div style="display:flex;align-items:center;gap:0.9rem;">
            <div class="avatar-placeholder" data-info-avatar>LA</div>
            <div>
              <strong data-info-name><?= lex_e($threadLabel) ?></strong>
              <div class="modal-note"><span data-info-role>Lawyer</span> · <span data-info-status><?= lex_e($onlineLabel) ?></span></div>
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
            <li><span>📄 Audit Summary.pdf</span><span class="file-meta">188 KB</span></li>
            <li><span>📄 Lawyer Notes.docx</span><span class="file-meta">90 KB</span></li>
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
