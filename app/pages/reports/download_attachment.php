<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/privacy_guard.php';
require_once APP_ROOT . '/includes/document_preview.php';
require_once APP_ROOT . '/config/audit_log.php';

requireLogin();
$user = currentUser();
requirePermission('view_documents', $user);
$attachmentId = (int) ($_GET['id'] ?? 0);
if ($attachmentId <= 0) {
    http_response_code(400);
    exit('Invalid attachment.');
}

$stmt = $pdo->prepare(
    'SELECT a.*, r.client_id, r.department_id, r.assigned_department_id, r.sender_department_id
     FROM attachments a
     INNER JOIN reports r ON r.id = a.report_id
     WHERE a.id = :id LIMIT 1'
);
$stmt->execute(['id' => $attachmentId]);
$attachment = $stmt->fetch();
if (!$attachment) {
    http_response_code(404);
    exit('Attachment not found.');
}

$permission = checkDocumentPrivacy($user, $attachment, $pdo);
if (!$permission['can_view_content']) {
    http_response_code(403);
    exit('You do not have permission to access this attachment.');
}

$path = APP_ROOT . '/uploads/' . basename((string) $attachment['stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Attachment file not found.');
}
$viewMode = (string) ($_GET['view'] ?? '');
$viewInline = $viewMode === '1';
$viewOffice = $viewMode === 'office';
if ($viewInline && strtolower((string) ($attachment['mime_type'] ?? '')) !== 'application/pdf') {
    http_response_code(415);
    exit('Only PDF attachments can be previewed in the browser. Download this file to open it.');
}
$attachmentMimeType = strtolower((string) ($attachment['mime_type'] ?? ''));
$officePreviewMimeType = officePreviewMimeType($attachment);
if ($viewOffice && $officePreviewMimeType === null) {
    http_response_code(415);
    exit('Browser preview is available for DOCX and XLSX attachments only. Download this file to open it.');
}
$action = ($viewInline || $viewOffice) ? 'DOCUMENT_PREVIEWED' : 'DOCUMENT_DOWNLOADED';
$actionDescription = ($viewInline || $viewOffice) ? 'Attachment previewed: ' : 'Attachment downloaded: ';
auditLog($pdo, (int)$user['id'], $action, $actionDescription . $attachment['original_name'], (int)$attachment['report_id'], (int)($attachment['department_id'] ?? 0));

$downloadName = str_replace(["\r", "\n", '"'], '', (string) $attachment['original_name']);
header('Content-Type: ' . ($viewOffice ? $officePreviewMimeType : $attachment['mime_type']));
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: ' . (($viewInline || $viewOffice) ? 'inline' : 'attachment') . '; filename="' . ($downloadName ?: 'document-attachment') . '"');
readfile($path);
exit;
