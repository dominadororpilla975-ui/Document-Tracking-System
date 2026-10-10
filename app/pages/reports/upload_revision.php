<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/privacy_guard.php';
require_once APP_ROOT . '/includes/notifications.php';
require_once APP_ROOT . '/includes/upload_security.php';

requireLogin();
$user = currentUser();
requirePermission('view_documents', $user);
requirePermission('manage_file_revisions', $user);
$reportId = (int) ($_POST['report_id'] ?? 0);
$file = $_FILES['revision_file'] ?? null;
if ($reportId <= 0 || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    header('Location: report_details.php?id=' . $reportId . '&error=' . urlencode('Please select a valid file.'));
    exit;
}
verifyCsrfToken($_POST['csrf_token'] ?? null);

$stmt = $pdo->prepare('SELECT * FROM reports WHERE id = :id AND COALESCE(is_deleted, 0) = 0 AND COALESCE(is_suspended, 0) = 0');
$stmt->execute(['id' => $reportId]);
$report = $stmt->fetch();
if (!$report) {
    http_response_code(404);
    exit('Document not found.');
}
$permission = checkDocumentPrivacy($user, $report, $pdo);
$uploadType = strtolower(trim((string) ($_POST['upload_type'] ?? 'revision')));
$isClearanceUpload = $uploadType === 'clearance';
$assignedDepartmentId = (int) ($report['assigned_department_id'] ?? $report['department_id'] ?? 0);
$canUpload = !isSuperAdmin($user) && ($isClearanceUpload
    ? $permission['can_view_content']
        && (int) ($user['department_id'] ?? 0) > 0
        && (int) $user['department_id'] === $assignedDepartmentId
    : $permission['can_modify'] || (int) $user['id'] === (int) $report['client_id']);
if (!$canUpload) {
    http_response_code(403);
    exit($isClearanceUpload
        ? 'Only the assigned department user can upload a clearance.'
        : 'You do not have permission to upload a revision.');
}
if ((int)$file['size'] > 10 * 1024 * 1024) {
    header('Location: report_details.php?id=' . $reportId . '&error=' . urlencode('The file must not exceed 10 MB.'));
    exit;
}
$allowed = ['application/pdf', 'image/jpeg'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
if (!in_array($mime, $allowed, true)) {
    header('Location: report_details.php?id=' . $reportId . '&error=' . urlencode('This file type is not allowed.'));
    exit;
}
$retryAfter = enforceUploadCooldown($pdo, (int) $user['id']);
if ($retryAfter > 0) {
    header('Location: report_details.php?id=' . $reportId . '&error=' . urlencode(uploadCooldownMessage($retryAfter)));
    exit;
}

$storedName = bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
$uploadDirectory = APP_ROOT . '/uploads';
if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true)) {
    throw new RuntimeException('Unable to prepare secure attachment storage.');
}
if (!move_uploaded_file($file['tmp_name'], $uploadDirectory . '/' . $storedName)) {
    throw new RuntimeException('Unable to save the revised file.');
}
$versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM attachments WHERE report_id = :report_id');
$versionStmt->execute(['report_id' => $reportId]);
$version = (int)$versionStmt->fetchColumn();
$insert = $pdo->prepare('INSERT INTO attachments (report_id, original_name, stored_name, mime_type, file_size, version_number, revision_note, requirement_name, uploaded_by) VALUES (:report_id, :original_name, :stored_name, :mime_type, :file_size, :version_number, :revision_note, :requirement_name, :uploaded_by)');
$insert->execute([
    'report_id' => $reportId,
    'original_name' => basename((string)$file['name']),
    'stored_name' => $storedName,
    'mime_type' => $mime,
    'file_size' => (int)$file['size'],
    'version_number' => $version,
    'revision_note' => trim((string)($_POST['revision_note'] ?? '')) ?: ($isClearanceUpload ? 'Clearance uploaded by the assigned department.' : null),
    'requirement_name' => $isClearanceUpload ? 'Clearance' : null,
    'uploaded_by' => (int)$user['id'],
]);
$trackingNo = $report['tracking_number'];
if ($isClearanceUpload) {
    sendUserNotification($pdo, (int)($report['client_id'] ?? 0), $trackingNo, 'A clearance file is now available for your document ' . $trackingNo . '.');
} elseif (($user['role'] ?? '') === 'client') {
    sendDepartmentNotification($pdo, (int)($report['department_id'] ?? 0), $trackingNo, 'A revised file was uploaded for ' . $trackingNo . '.');
} else {
    sendUserNotification($pdo, (int)($report['client_id'] ?? 0), $trackingNo, 'A revised file was uploaded for your document ' . $trackingNo . '.');
}
header('Location: report_details.php?id=' . $reportId . '&created=' . urlencode($isClearanceUpload ? 'Clearance uploaded successfully.' : 'File version ' . $version . ' uploaded successfully.'));
exit;
