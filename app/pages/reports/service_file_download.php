<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';

requireLogin();
$user = currentUser();
$documentId = (int) ($_GET['id'] ?? 0);
if ($documentId <= 0) {
    http_response_code(400);
    exit('Invalid service file.');
}

$stmt = $pdo->prepare(
    'SELECT sd.*, ds.department_id, ds.status AS service_status, ds.deleted_at AS service_deleted_at,
            d.status AS department_status, d.deleted_at AS department_deleted_at
     FROM service_documents sd
     INNER JOIN department_services ds ON ds.id = sd.service_id
     INNER JOIN departments d ON d.id = ds.department_id
     WHERE sd.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $documentId]);
$document = $stmt->fetch();
if (!$document) {
    http_response_code(404);
    exit('Service file not found.');
}

$isClient = isClientUser($user);
$canDownload = isSuperAdmin($user)
    || ($isClient
        && strtolower((string) ($document['service_status'] ?? '')) === 'active'
        && empty($document['service_deleted_at'])
        && strtolower((string) ($document['department_status'] ?? '')) === 'active'
        && empty($document['department_deleted_at']))
    || (!$isClient && (int) ($user['department_id'] ?? 0) === (int) $document['department_id']);
if (!$canDownload) {
    http_response_code(403);
    exit('You do not have permission to download this service file.');
}

$path = APP_ROOT . '/uploads/services/' . basename((string) $document['stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Service file is no longer available.');
}

$downloadName = str_replace(["\r", "\n", '"'], '', (string) $document['original_name']);
header('Content-Type: ' . $document['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . ($downloadName ?: 'service-file') . '"');
readfile($path);
