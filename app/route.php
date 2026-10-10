<?php
require_once __DIR__ . '/bootstrap.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$routeName = basename($requestPath === '/' ? '/index.php' : $requestPath);
$routes = [
    'account_pending_approval.php' => APP_ROOT . '/app/pages/auth/account_pending_approval.php',
    'admin_approvals.php' => APP_ROOT . '/app/pages/admin/admin_approvals.php',
    'admin_archive.php' => APP_ROOT . '/app/pages/admin/admin_archive.php',
    'admin_audit_logs.php' => APP_ROOT . '/app/pages/admin/admin_audit_logs.php',
    'admin_notifications.php' => APP_ROOT . '/app/pages/admin/admin_notifications.php',
    'admin_reports.php' => APP_ROOT . '/app/pages/admin/admin_reports.php',
    'admin_routing.php' => APP_ROOT . '/app/pages/admin/admin_routing.php',
    'admin_settings.php' => APP_ROOT . '/app/pages/admin/admin_settings.php',
    'admin_settings_access.php' => APP_ROOT . '/app/pages/admin/admin_settings_access.php',
    'admin_settings_appearance.php' => APP_ROOT . '/app/pages/admin/admin_settings.php',
    'admin_settings_contact.php' => APP_ROOT . '/app/pages/admin/admin_settings.php',
    'admin_settings_landing.php' => APP_ROOT . '/app/pages/admin/admin_settings.php',
    'admin_settings_maintenance.php' => APP_ROOT . '/app/pages/admin/admin_settings.php',
    'admin_settings_retention.php' => APP_ROOT . '/app/pages/admin/admin_settings.php',
    'admin_settings_security.php' => APP_ROOT . '/app/pages/admin/admin_settings_security.php',
    'admin_settings_sidebar.php' => APP_ROOT . '/app/pages/admin/admin_settings.php',
    'admin_system_notifications.php' => APP_ROOT . '/app/pages/admin/admin_system_notifications.php',
    'admin_tracking.php' => APP_ROOT . '/app/pages/admin/admin_tracking.php',
    'announcements.php' => APP_ROOT . '/app/pages/client/announcements.php',
    'backup_database.php' => APP_ROOT . '/app/pages/admin/backup_database.php',
    'client_monitor.php' => APP_ROOT . '/app/pages/admin/client_monitor.php',
    'dashboard.php' => APP_ROOT . '/app/pages/client/dashboard.php',
    'delete_report.php' => APP_ROOT . '/app/pages/reports/delete_report.php',
    'department_management.php' => APP_ROOT . '/app/pages/admin/department_management.php',
    'documents.php' => APP_ROOT . '/app/pages/client/documents.php',
    'document_viewer.php' => APP_ROOT . '/app/pages/reports/document_viewer.php',
    'download_attachment.php' => APP_ROOT . '/app/pages/reports/download_attachment.php',
    'forgot_password.php' => APP_ROOT . '/app/pages/auth/forgot_password.php',
    'index.php' => APP_ROOT . '/app/pages/public/index.php',
    'login.php' => APP_ROOT . '/app/pages/auth/login.php',
    'logout.php' => APP_ROOT . '/app/pages/auth/logout.php',
    'manage_users.php' => APP_ROOT . '/app/pages/admin/manage_users.php',
    'message_attachment.php' => APP_ROOT . '/app/pages/reports/message_attachment.php',
    'messages.php' => APP_ROOT . '/app/pages/client/messages.php',
    'notifications_read.php' => APP_ROOT . '/app/pages/notifications_read.php',
    'profile.php' => APP_ROOT . '/app/pages/client/profile.php',
    'receiving_copy.php' => APP_ROOT . '/app/pages/reports/receiving_copy.php',
    'records_monitor.php' => APP_ROOT . '/app/pages/reports/records_monitor.php',
    'register.php' => APP_ROOT . '/app/pages/auth/register.php',
    'report_details.php' => APP_ROOT . '/app/pages/reports/report_details.php',
    'reset_password.php' => APP_ROOT . '/app/pages/auth/reset_password.php',
    'role_management.php' => APP_ROOT . '/app/pages/admin/role_management.php',
    'service_file_download.php' => APP_ROOT . '/app/pages/reports/service_file_download.php',
    'service_management.php' => APP_ROOT . '/app/pages/admin/service_management.php',
    'submit_report.php' => APP_ROOT . '/app/pages/reports/submit_report.php',
    'track.php' => APP_ROOT . '/app/pages/reports/track.php',
    'trash_bin.php' => APP_ROOT . '/app/pages/admin/trash_bin.php',
    'upload_revision.php' => APP_ROOT . '/app/pages/reports/upload_revision.php',
    'verify_email.php' => APP_ROOT . '/app/pages/auth/verify_email.php',
    'verify_email_pending.php' => APP_ROOT . '/app/pages/auth/verify_email_pending.php',
];

if (!isset($routes[$routeName])) {
    http_response_code(404);
    exit('Not Found');
}

$publicScriptName = $requestPath === '/' ? '/index.php' : $requestPath;
$_SERVER['SCRIPT_NAME'] = $publicScriptName;
$_SERVER['PHP_SELF'] = $publicScriptName;

if ($routeName === 'admin_settings_appearance.php') {
    $settingsSection = 'appearance';
} elseif ($routeName === 'admin_settings_contact.php') {
    $settingsSection = 'contact';
} elseif ($routeName === 'admin_settings_landing.php') {
    $settingsSection = 'landing';
} elseif ($routeName === 'admin_settings_maintenance.php') {
    $settingsSection = 'maintenance';
} elseif ($routeName === 'admin_settings_retention.php') {
    $settingsSection = 'retention';
} elseif ($routeName === 'admin_settings_sidebar.php') {
    $settingsSection = 'sidebar';
}

define('APP_ROUTE_ENTRY', true);

require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/retention_cleanup.php';
$retentionPdo = $GLOBALS['pdo'] ?? null;
if ($retentionPdo instanceof PDO) {
    register_shutdown_function(static function () use ($retentionPdo): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            runRetentionCleanup($retentionPdo);
        } catch (Throwable $exception) {
            error_log('Automatic retention cleanup failed: ' . $exception->getMessage());
        }
    });
}
$requestActorId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestPath = '/' . $routeName;
$requestMetadata = [];
foreach (['action', 'action_type', 'status'] as $key) {
    $value = $_POST[$key] ?? null;
    if (is_string($value) && preg_match('/^[a-zA-Z0-9 _-]{1,60}$/', $value)) {
        $requestMetadata[$key] = $value;
    }
}
foreach (['id', 'report_id', 'department_id', 'service_id', 'user_id', 'role_id', 'announcement_id', 'message_id'] as $key) {
    $value = $_POST[$key] ?? $_GET[$key] ?? null;
    if (is_scalar($value) && ctype_digit((string) $value)) {
        $requestMetadata[$key] = (int) $value;
    }
}

register_shutdown_function(static function () use (
    $routeName,
    $requestMethod,
    $requestMetadata,
    $requestPath,
    $requestActorId
): void {
    if ($routeName === 'notifications_read.php' && $requestMethod === 'GET') {
        return;
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || $pdo->inTransaction()) {
        return;
    }

    require_once APP_ROOT . '/config/audit_log.php';

    $actorId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : $requestActorId;
    $isAction = in_array($requestMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    $routeAction = strtoupper((string) pathinfo($routeName, PATHINFO_FILENAME));
    $event = ($isAction ? 'REQUEST_' : 'PAGE_VIEW_') . $routeAction;
    $safeMetadata = [
        'route' => $requestPath,
        'method' => $requestMethod,
        'http_status' => http_response_code(),
    ];

    $safeMetadata = array_merge($safeMetadata, $requestMetadata);

    try {
        auditLog(
            $pdo,
            $actorId,
            $event,
            ($isAction ? 'Request action submitted to ' : 'Page viewed: ')
                . $routeName . ($isAction ? ' (' . ($safeMetadata['action_type'] ?? $safeMetadata['action'] ?? $requestMethod) . ')' : ''),
            in_array($routeName, ['report_details.php', 'delete_report.php', 'download_attachment.php', 'receiving_copy.php', 'upload_revision.php'], true)
                ? (int) ($safeMetadata['report_id'] ?? $safeMetadata['id'] ?? 0) ?: null
                : null,
            null,
            null,
            $safeMetadata
        );
    } catch (Throwable $exception) {
        error_log('Request audit failed: ' . $exception->getMessage());
    }
});

require $routes[$routeName];