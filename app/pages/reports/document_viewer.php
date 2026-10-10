<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/privacy_guard.php';
require_once APP_ROOT . '/includes/document_preview.php';

requireLogin();
$user = currentUser();
requirePermission('view_documents', $user);

$attachmentId = (int) ($_GET['id'] ?? 0);
if ($attachmentId <= 0) {
    http_response_code(400);
    exit('Invalid attachment.');
}

$stmt = $pdo->prepare(
    'SELECT a.id, a.original_name, a.mime_type, a.report_id,
            r.client_id, r.department_id, r.assigned_department_id, r.sender_department_id,
            r.department_private, r.submitted_by_user_id
     FROM attachments a
     INNER JOIN reports r ON r.id = a.report_id
     WHERE a.id = :id
       AND COALESCE(r.is_deleted, 0) = 0
       AND COALESCE(r.is_suspended, 0) = 0
     LIMIT 1'
);
$stmt->execute(['id' => $attachmentId]);
$attachment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$attachment) {
    http_response_code(404);
    exit('Attachment not found.');
}

$permission = checkDocumentPrivacy($user, $attachment, $pdo);
if (!$permission['can_view_content']) {
    http_response_code(403);
    exit('You do not have permission to view this attachment.');
}

$mimeType = officePreviewMimeType($attachment);
$previewTypes = [
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel workbook',
];
if ($mimeType === null) {
    http_response_code(415);
    exit('Browser preview is available for DOCX and XLSX attachments only. Download this file to open it.');
}

$viewerConfig = json_encode([
    'mimeType' => $mimeType,
    'fileName' => (string) $attachment['original_name'],
    'fileUrl' => 'download_attachment.php?id=' . $attachmentId . '&view=office',
    'downloadUrl' => 'download_attachment.php?id=' . $attachmentId,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($viewerConfig === false) {
    throw new RuntimeException('Unable to prepare the document viewer.');
}
$safeFileName = htmlspecialchars((string) $attachment['original_name'], ENT_QUOTES, 'UTF-8');
$documentType = $previewTypes[$mimeType];

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $safeFileName ?> - Document Preview</title>
    <link rel="stylesheet" href="css/document_viewer.css?v=1">
    <script defer src="js/document-viewer/vendor/purify.min.js"></script>
    <script defer src="js/document-viewer/vendor/jszip.min.js"></script>
    <script defer src="js/document-viewer/vendor/mammoth.browser.min.js"></script>
    <script defer src="js/document-viewer/viewer.js?v=1"></script>
</head>
<body>
    <main class="document-viewer">
        <header class="viewer-header">
            <div>
                <p class="viewer-eyebrow"><?= htmlspecialchars($documentType, ENT_QUOTES, 'UTF-8') ?> · Read-only preview</p>
                <h1><?= $safeFileName ?></h1>
                <p class="viewer-privacy-note">This preview is rendered in your browser. The document is not sent to an online document viewer.</p>
            </div>
            <nav class="viewer-actions" aria-label="Document actions">
                <a href="<?= htmlspecialchars('report_details.php?id=' . (int) $attachment['report_id'], ENT_QUOTES, 'UTF-8') ?>">Back to document</a>
                <a class="download-link" href="<?= htmlspecialchars('download_attachment.php?id=' . $attachmentId, ENT_QUOTES, 'UTF-8') ?>">Download original</a>
            </nav>
        </header>
        <div id="viewer-status" class="viewer-status" role="status" aria-live="polite">Loading secure document preview…</div>
        <section id="viewer-content" class="viewer-content" aria-label="Document preview"></section>
    </main>
    <script>
        window.documentViewerConfig = <?= $viewerConfig ?>;
    </script>
</body>
</html>
