<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/notifications.php';
require_once APP_ROOT . '/includes/mailer.php';
require_once APP_ROOT . '/includes/upload_security.php';

requireLogin();
$user = currentUser();
requirePermission('manage_notifications', $user);
$userId = (int) ($user['id'] ?? 0);
$role = strtolower((string) ($user['role'] ?? ''));
$isClient = $role === 'client';
$isDepartmentAdmin = $role === 'department_admin';
$isDepartmentStaff = in_array($role, ['department_staff', 'staff'], true);

$error = '';
$success = '';
$selectedMessageId = (int) ($_GET['message_id'] ?? 0);
$uploadDirectory = APP_ROOT . '/uploads/message_attachments';
$allowedImageMimes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

function sendMessageEmailNotifications(array $recipients, string $subject, string $message, ?array $attachmentData, string $uploadDirectory): array
{
    $uniqueRecipients = [];
    foreach ($recipients as $recipient) {
        $email = strtolower(trim((string) $recipient));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $uniqueRecipients[$email] = $email;
        }
    }

    $sentCount = 0;
    foreach ($uniqueRecipients as $email) {
        $sent = sendEmailNotificationWithAttachment(
            $email,
            $subject,
            $message,
            $attachmentData ? $uploadDirectory . '/' . $attachmentData['stored_name'] : null,
            $attachmentData['original_name'] ?? null
        );
        if ($sent) {
            $sentCount++;
        }
    }

    return ['sent' => $sentCount, 'total' => count($uniqueRecipients)];
}

function messageEmailResult(string $successMessage, array $delivery): string
{
    if ($delivery['total'] > 0 && $delivery['sent'] === $delivery['total']) {
        return $successMessage . ' Email notification accepted by the mail server; check the recipient\'s inbox and spam folder.';
    }

    if ($delivery['sent'] > 0) {
        return $successMessage . ' Email notification accepted by the mail server for ' . $delivery['sent'] . ' of '
            . $delivery['total'] . ' recipients; some submissions failed. Please check the SMTP setup guide.';
    }

    return $successMessage . ' The message is saved in the system, but email delivery failed'
        . ($delivery['total'] === 0 ? ' because no valid recipient email address was found.' : '. Please check the SMTP setup guide.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $messageId = (int) ($_POST['message_id'] ?? 0);
    $message = trim((string) ($_POST['message'] ?? ''));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $attachment = $_FILES['attachment'] ?? null;
    $attachmentData = null;

    if ($attachment && ($attachment['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($attachment['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int) ($attachment['size'] ?? 0) > 5 * 1024 * 1024) {
            $error = 'The image must be 5 MB or smaller.';
        } else {
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($attachment['tmp_name']);
            if (!isset($allowedImageMimes[$mimeType])) {
                $error = 'Only JPG, PNG, or WEBP images are allowed.';
            } else {
                $retryAfter = enforceUploadCooldown($pdo, $userId);
                if ($retryAfter > 0) {
                    $error = uploadCooldownMessage($retryAfter);
                } else {
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
                    $error = 'Unable to prepare image upload storage.';
                } else {
                    $storedName = bin2hex(random_bytes(16)) . '.' . $allowedImageMimes[$mimeType];
                    if (!move_uploaded_file($attachment['tmp_name'], $uploadDirectory . '/' . $storedName)) {
                        $error = 'Unable to save the uploaded image.';
                    } else {
                        $attachmentData = [
                            'original_name' => basename((string) $attachment['name']),
                            'stored_name' => $storedName,
                            'mime_type' => $mimeType,
                            'file_size' => (int) $attachment['size'],
                        ];
                    }
                }
                }
            }
        }
    }

    if ($action === 'delete' && $messageId > 0 && ($isClient || $isDepartmentAdmin || $isDepartmentStaff)) {
        if ($isClient) {
            $deleteStmt = $pdo->prepare('SELECT id FROM inbox_messages WHERE id = :id AND client_id = :client_id');
            $deleteStmt->execute(['id' => $messageId, 'client_id' => $userId]);
        } else {
            $deleteStmt = $pdo->prepare('SELECT id FROM inbox_messages WHERE id = :id AND recipient_department_id = :department_id');
            $deleteStmt->execute(['id' => $messageId, 'department_id' => (int) $user['department_id']]);
        }
        if (!$deleteStmt->fetchColumn()) {
            $error = 'Message not found or you are not allowed to delete it.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM notifications WHERE tracking_no = :tracking_no')
                    ->execute(['tracking_no' => 'MESSAGE-' . $messageId]);
                $pdo->prepare('DELETE FROM inbox_messages WHERE id = :id')
                    ->execute(['id' => $messageId]);
                $pdo->commit();
                $success = 'Message deleted successfully.';
                $selectedMessageId = 0;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    } elseif ($error !== '') {
        // Keep the upload validation message.
    } elseif ($message === '') {
        $error = 'Message is required.';
    } elseif ($action === 'send' && $isClient) {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $deptStmt = $pdo->prepare("SELECT id, official_email FROM departments WHERE id = :id AND COALESCE(deleted_at, '') = '' AND COALESCE(status, 'active') = 'active'");
        $deptStmt->execute(['id' => $departmentId]);
        $department = $deptStmt->fetch();
        if (!$department) {
            $error = 'Please select a valid department.';
        } elseif ($subject === '') {
            $error = 'Subject is required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO inbox_messages
                (client_id, sender_id, recipient_department_id, subject, message, is_read)
                VALUES (:client_id, :sender_id, :department_id, :subject, :message, 0)');
            $stmt->execute([
                'client_id' => $userId,
                'sender_id' => $userId,
                'department_id' => $departmentId,
                'subject' => $subject,
                'message' => $message,
            ]);
            $messageRecordId = (int) $pdo->lastInsertId();
            if ($attachmentData) {
               $pdo->prepare('INSERT INTO message_attachments (message_id, original_name, stored_name, mime_type, file_size)
                   VALUES (:message_id, :original_name, :stored_name, :mime_type, :file_size)')
                   ->execute(['message_id' => $messageRecordId] + $attachmentData);
            }
            sendDepartmentNotification(
                $pdo,
                $departmentId,
                'MESSAGE-' . $messageRecordId,
                'New message from client: ' . $subject,
                null
            );
            $recipientStmt = $pdo->prepare("SELECT email FROM users
                WHERE department_id = :department_id AND role IN ('department_admin', 'department_staff', 'staff')
                  AND COALESCE(account_status, 'active') = 'active'
                  AND COALESCE(email, '') <> ''");
            $recipientStmt->execute(['department_id' => $departmentId]);
            $recipientEmails = $recipientStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($department['official_email'])) {
                $recipientEmails[] = $department['official_email'];
            }
            $delivery = sendMessageEmailNotifications(
                $recipientEmails,
                'New message from client: ' . $subject,
                "From: " . ($user['name'] ?? 'Client') . "\n\n" . $message,
                $attachmentData,
                $uploadDirectory
            );
            $success = messageEmailResult('Message sent to the selected department.', $delivery);
        }
    } elseif ($action === 'send_department' && ($isDepartmentAdmin || $isDepartmentStaff)) {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $clientStmt = $pdo->prepare("SELECT u.id, u.email
            FROM users u
            WHERE u.id = :client_id AND u.role = 'client'
              AND EXISTS (
                  SELECT 1 FROM reports r
                  WHERE r.client_id = u.id
                    AND COALESCE(r.is_deleted, 0) = 0
                    AND (
                        r.department_id = :department_id
                        OR r.assigned_department_id = :assigned_department_id
                        OR r.sender_department_id = :sender_department_id
                    )
              )");
        $clientStmt->execute([
            'client_id' => $clientId,
            'department_id' => (int) $user['department_id'],
            'assigned_department_id' => (int) $user['department_id'],
            'sender_department_id' => (int) $user['department_id'],
        ]);
        $recipientClient = $clientStmt->fetch();
        if (!$recipientClient) {
            $error = 'Please select a client connected to your department.';
        } elseif ($subject === '') {
            $error = 'Subject is required.';
        } else {
            $sendStmt = $pdo->prepare('INSERT INTO inbox_messages
                (client_id, sender_id, recipient_user_id, recipient_department_id, subject, message, is_read)
                VALUES (:client_id, :sender_id, :recipient_user_id, :department_id, :subject, :message, 0)');
            $sendStmt->execute([
                'client_id' => $clientId,
                'sender_id' => $userId,
                'recipient_user_id' => $clientId,
                'department_id' => (int) $user['department_id'],
                'subject' => $subject,
                'message' => $message,
            ]);
            $newMessageId = (int) $pdo->lastInsertId();
            if ($attachmentData) {
               $pdo->prepare('INSERT INTO message_attachments (message_id, original_name, stored_name, mime_type, file_size)
                   VALUES (:message_id, :original_name, :stored_name, :mime_type, :file_size)')
                   ->execute(['message_id' => $newMessageId] + $attachmentData);
            }
            sendUserNotification($pdo, $clientId, 'MESSAGE-' . $newMessageId, 'New message from your department: ' . $subject);
            $delivery = sendMessageEmailNotifications(
                [$recipientClient['email'] ?? ''],
                'New message from your department: ' . $subject,
                "From: " . ($user['name'] ?? 'Department') . "\n\n" . $message,
                $attachmentData,
                $uploadDirectory
            );
            $success = messageEmailResult('Message sent to the client.', $delivery);
        }
    } elseif ($action === 'reply' && ($isDepartmentAdmin || $isDepartmentStaff)) {
        $stmt = $pdo->prepare('SELECT m.client_id, u.email FROM inbox_messages m
            INNER JOIN users u ON u.id = m.client_id
            WHERE m.id = :id AND m.recipient_department_id = :department_id');
        $stmt->execute(['id' => $messageId, 'department_id' => (int) $user['department_id']]);
        $replyRecipient = $stmt->fetch();
        $clientId = (int) ($replyRecipient['client_id'] ?? 0);
        if ($clientId <= 0) {
            $error = 'Message not found or not assigned to your department.';
        } else {
            $replyStmt = $pdo->prepare('INSERT INTO inbox_messages
                (client_id, sender_id, recipient_user_id, recipient_department_id, subject, message, is_read)
                VALUES (:client_id, :sender_id, :recipient_client_id, :department_id, :subject, :message, 0)');
            $replyStmt->execute([
                'client_id' => $clientId,
                'sender_id' => $userId,
                'recipient_client_id' => $clientId,
                'department_id' => (int) $user['department_id'],
                'subject' => $subject !== '' ? $subject : 'Reply from Department Admin',
                'message' => $message,
            ]);
            $replyRecordId = (int) $pdo->lastInsertId();
            if ($attachmentData) {
               $pdo->prepare('INSERT INTO message_attachments (message_id, original_name, stored_name, mime_type, file_size)
                   VALUES (:message_id, :original_name, :stored_name, :mime_type, :file_size)')
                   ->execute(['message_id' => $replyRecordId] + $attachmentData);
            }
            sendUserNotification(
                $pdo,
                $clientId,
                'MESSAGE-' . $replyRecordId,
                'Department reply: ' . ($subject !== '' ? $subject : 'Reply from Department Admin')
            );
            $replySubject = 'Department reply: ' . ($subject !== '' ? $subject : 'Reply from Department Admin');
            $delivery = sendMessageEmailNotifications(
                [$replyRecipient['email'] ?? ''],
                $replySubject,
                "From: " . ($user['name'] ?? 'Department') . "\n\n" . $message,
                $attachmentData,
                $uploadDirectory
            );
            $success = messageEmailResult('Reply sent to the client.', $delivery);
        }
    } else {
        $error = 'Invalid message action.';
    }
}

if ($isClient) {
    $deptStmt = $pdo->query("SELECT id, name FROM departments
        WHERE COALESCE(deleted_at, '') = '' AND COALESCE(status, 'active') = 'active'
        ORDER BY name ASC");
    $departments = $deptStmt->fetchAll();
    $stmt = $pdo->prepare('SELECT m.*, u.name AS sender_name, u.email AS sender_email, d.name AS department_name,
        ma.id AS attachment_id, ma.original_name AS attachment_name, ma.mime_type AS attachment_mime
        FROM inbox_messages m
        LEFT JOIN users u ON u.id = m.sender_id
        LEFT JOIN departments d ON d.id = m.recipient_department_id
        LEFT JOIN message_attachments ma ON ma.message_id = m.id
        WHERE m.client_id = :client_id ORDER BY m.created_at DESC');
    $stmt->execute(['client_id' => $userId]);
    $messages = $stmt->fetchAll();
} else {
    $clientStmt = $pdo->prepare("SELECT DISTINCT u.id, u.name, u.email
        FROM users u
        INNER JOIN reports r ON r.client_id = u.id
        WHERE u.role = 'client' AND COALESCE(r.is_deleted, 0) = 0
          AND (r.department_id = :department_id OR r.assigned_department_id = :assigned_department_id OR r.sender_department_id = :sender_department_id)
        ORDER BY u.name ASC");
    $clientStmt->execute([
        'department_id' => (int) $user['department_id'],
        'assigned_department_id' => (int) $user['department_id'],
        'sender_department_id' => (int) $user['department_id'],
    ]);
    $departmentClients = $clientStmt->fetchAll();
    $stmt = $pdo->prepare('SELECT m.*, u.name AS sender_name, u.email AS sender_email, d.name AS department_name,
        ma.id AS attachment_id, ma.original_name AS attachment_name, ma.mime_type AS attachment_mime
        FROM inbox_messages m
        LEFT JOIN users u ON u.id = m.sender_id
        LEFT JOIN departments d ON d.id = m.recipient_department_id
        LEFT JOIN message_attachments ma ON ma.message_id = m.id
        WHERE m.recipient_department_id = :department_id
           OR (m.sender_id = :user_id AND m.recipient_department_id = :department_id_outgoing)
        ORDER BY m.created_at DESC');
    $stmt->execute([
        'department_id' => (int) $user['department_id'],
        'user_id' => $userId,
        'department_id_outgoing' => (int) $user['department_id'],
    ]);
    $messages = $stmt->fetchAll();
}
$selectedMessage = null;
foreach ($messages as $item) {
    if ($selectedMessageId > 0 && (int) $item['id'] === $selectedMessageId) {
        $selectedMessage = $item;
        break;
    }
}
if ($selectedMessage === null && !empty($messages)) {
    $selectedMessage = $messages[0];
}

require_once APP_ROOT . '/includes/header.php';
?>
<div class="admin-module-page messages-page">
    <div class="admin-module-heading">
        <div><span class="admin-eyebrow">MESSAGES</span><h2><?= $isClient ? 'Contact a Department' : 'Client Messages' ?></h2><p><?= $isClient ? 'Send a general message to a Department Admin.' : 'Read and reply to messages sent to your department.' ?></p></div>
    </div>
    <?php if ($error !== ''): ?><div class="admin-alert admin-alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="admin-alert admin-alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <div class="mailbox-layout">
        <aside class="mailbox-sidebar">
            <?php if ($isClient || $isDepartmentAdmin || $isDepartmentStaff): ?>
                <details class="mailbox-compose" open>
                    <summary>✎ New Message</summary>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="<?= $isClient ? 'send' : 'send_department' ?>">
                        <?php if ($isClient): ?>
                            <label>Department<select name="department_id" required><option value="">Select department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>"><?= htmlspecialchars($department['name']) ?></option><?php endforeach; ?></select></label>
                        <?php else: ?>
                            <label>Client<select name="client_id" required><option value="">Select client</option><?php foreach ($departmentClients as $departmentClient): ?><option value="<?= (int) $departmentClient['id'] ?>"><?= htmlspecialchars($departmentClient['name'] . ' (' . $departmentClient['email'] . ')') ?></option><?php endforeach; ?></select></label>
                        <?php endif; ?>
                        <label>Subject<input type="text" name="subject" maxlength="180" required></label>
                        <label>Message<textarea name="message" rows="5" required></textarea></label>
                        <label>Attach image (optional)<input type="file" name="attachment" accept="image/jpeg,image/png,image/webp" capture="environment"></label>
                        <button type="submit" class="admin-primary-button" style="display: block; margin-left: auto;">Send Message</button>
                    </form>
                </details>
            <?php endif; ?>
            <div class="mailbox-folder-title">Inbox <span><?= count($messages) ?></span></div>
            <div class="mailbox-message-list">
                <?php foreach ($messages as $item): ?>
                    <a class="mailbox-message-item <?= $selectedMessage && (int) $selectedMessage['id'] === (int) $item['id'] ? 'is-active' : '' ?>" href="?message_id=<?= (int) $item['id'] ?>">
                        <span class="mailbox-avatar"><?= htmlspecialchars(strtoupper(substr((string) ($item['sender_name'] ?? 'U'), 0, 2))) ?></span>
                        <span class="mailbox-message-preview"><strong><?= htmlspecialchars($item['sender_name'] ?? 'User') ?></strong><b><?= htmlspecialchars($item['subject']) ?></b><small><?= htmlspecialchars(mb_strimwidth($item['message'], 0, 58, '...')) ?></small></span>
                        <time><?= date('g:i A', strtotime($item['created_at'])) ?></time>
                    </a>
                <?php endforeach; ?>
                <?php if (!$messages): ?><p class="admin-empty-state">No messages yet.</p><?php endif; ?>
            </div>
        </aside>
        <section class="mailbox-reader">
            <?php if ($selectedMessage): ?>
                <div class="mailbox-reader-header">
                    <div><span class="mailbox-label">MESSAGE</span><h3><?= htmlspecialchars($selectedMessage['subject']) ?></h3></div>
                    <time><?= date('m/d/Y h:i A', strtotime($selectedMessage['created_at'])) ?></time>
                </div>
                <form method="POST" class="mailbox-delete-form" onsubmit="return confirm('Delete this message permanently?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="message_id" value="<?= (int) $selectedMessage['id'] ?>">
                    <button type="submit" class="admin-secondary-button">Delete Message</button>
                </form>
                <div class="mailbox-sender">
                    <span class="mailbox-avatar mailbox-avatar-large"><?= htmlspecialchars(strtoupper(substr((string) ($selectedMessage['sender_name'] ?? 'U'), 0, 2))) ?></span>
                    <div><strong><?= htmlspecialchars($selectedMessage['sender_name'] ?? 'User') ?></strong><small><?= htmlspecialchars($selectedMessage['sender_email'] ?? '') ?> — <?= $isClient ? 'to Department Admin' : 'to me' ?></small></div>
                </div>
                <div class="mailbox-message-body"><?= nl2br(htmlspecialchars($selectedMessage['message'])) ?></div>
                <div class="mailbox-attachments"><strong>Attachments (<?= !empty($selectedMessage['attachment_id']) ? 1 : 0 ?>)</strong>
                    <?php if (!empty($selectedMessage['attachment_id'])): ?>
                        <a href="message_attachment.php?id=<?= (int) $selectedMessage['attachment_id'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($selectedMessage['attachment_name']) ?></a>
                    <?php else: ?><span>No attachments in this message</span><?php endif; ?>
                </div>
                <?php if (($isDepartmentAdmin || $isDepartmentStaff) && (int) $selectedMessage['sender_id'] !== $userId): ?>
                    <form method="POST" class="mailbox-reply" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="reply"><input type="hidden" name="message_id" value="<?= (int) $selectedMessage['id'] ?>">
                        <input type="text" name="subject" value="Re: <?= htmlspecialchars($selectedMessage['subject']) ?>" maxlength="180">
                        <label for="reply-message">Reply Message</label>
                        <textarea id="reply-message" name="message" rows="4" required placeholder="Reply to <?= htmlspecialchars($selectedMessage['sender_name'] ?? 'client') ?>"></textarea>
                        <label>Attach image (optional)<input type="file" name="attachment" accept="image/jpeg,image/png,image/webp" capture="environment"></label>
                        <button type="button" class="admin-secondary-button" data-ready-pickup>
                            Use “Ready for Pick-up” Message
                        </button>
                        <button type="submit" class="admin-primary-button">Send Reply</button>
                    </form>
                <?php endif; ?>
            <?php else: ?><div class="mailbox-empty-reader"><span>✉</span><h3>Select a message</h3><p>Choose a message from your inbox to read it here.</p></div><?php endif; ?>
        </section>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var quickReplyButton = document.querySelector('[data-ready-pickup]');
    if (!quickReplyButton) return;
    quickReplyButton.addEventListener('click', function () {
        var form = quickReplyButton.closest('form');
        var subject = form.querySelector('input[name="subject"]');
        var message = form.querySelector('textarea[name="message"]');
        subject.value = 'Your document is ready for pick-up';
        message.value = 'Good day. Your document is ready for pick-up at our office. Please bring a valid ID when you visit. Thank you.';
        message.focus();
    });
});
</script>
<?php require_once APP_ROOT . '/includes/footer.php'; ?>
