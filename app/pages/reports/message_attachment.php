<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';

requireLogin();
$user = currentUser();
requirePermission('manage_notifications', $user);
$attachmentId = (int) ($_GET['id'] ?? 0);
if ($attachmentId <= 0) {
    http_response_code(400);
    exit('Invalid attachment.');
}

$stmt = $pdo->prepare(
    'SELECT ma.*, m.client_id, m.recipient_department_id, m.sender_id
     FROM message_attachments ma
     INNER JOIN inbox_messages m ON m.id = ma.message_id
     WHERE ma.id = :id LIMIT 1'
);
$stmt->execute(['id' => $attachmentId]);
$attachment = $stmt->fetch();
if (!$attachment) {
    http_response_code(404);
    exit('Attachment not found.');
}

$isOwner = (int) $attachment['client_id'] === (int) $user['id']
    || (int) $attachment['sender_id'] === (int) $user['id'];
$sameDepartment = !empty($user['department_id'])
    && (int) $attachment['recipient_department_id'] === (int) $user['department_id'];
if (!$isOwner && !$sameDepartment) {
    http_response_code(403);
    exit('You do not have permission to access this attachment.');
}

$path = APP_ROOT . '/uploads/message_attachments/' . basename((string) $attachment['stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Attachment file not found.');
}

$downloadName = str_replace(["\r", "\n", '"'], '', (string) $attachment['original_name']);
header('Content-Type: ' . $attachment['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . ($downloadName ?: 'message-image') . '"');
readfile($path);
exit;
