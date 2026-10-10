<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/config/audit_log.php';

requireLogin();
$user = currentUser();
$userId = (int) $user['id'];
$role = strtolower((string) ($user['role'] ?? ''));
$departmentId = (int) ($user['department_id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if (in_array($role, ['super_admin', 'admin'], true)) {
            $unreadCount = (int) $pdo->query(
                "SELECT COUNT(*) FROM notifications
                 WHERE admin_is_read = 0 AND tracking_no NOT LIKE 'MESSAGE-%'"
            )->fetchColumn();
        } elseif ($role === 'client') {
            $countUnread = $pdo->prepare(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND client_is_read = 0'
            );
            $countUnread->execute(['user_id' => $userId]);
            $unreadCount = (int) $countUnread->fetchColumn();
        } elseif ($departmentId > 0) {
            $countUnread = $pdo->prepare(
                'SELECT COUNT(*) FROM notifications n
                 WHERE (n.department_id = :department_id OR n.user_id = :user_id)
                   AND NOT EXISTS (
                       SELECT 1 FROM notification_reads nr
                       WHERE nr.notification_id = n.id AND nr.user_id = :read_user_id
                   )'
            );
            $countUnread->execute([
                'department_id' => $departmentId,
                'user_id' => $userId,
                'read_user_id' => $userId,
            ]);
            $unreadCount = (int) $countUnread->fetchColumn();
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'This account has no department notification access.']);
            exit;
        }

        echo json_encode(['success' => true, 'unread_count' => $unreadCount]);
    } catch (Throwable $exception) {
        error_log('Failed to fetch notification count: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to fetch notification count.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

verifyCsrfToken($_POST['csrf_token'] ?? null);
$notificationId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
if (!$notificationId || $notificationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid notification is required.']);
    exit;
}

try {
    if (in_array($role, ['super_admin', 'admin'], true)) {
        $markRead = $pdo->prepare(
            "UPDATE notifications SET admin_is_read = 1
             WHERE id = :id AND tracking_no NOT LIKE 'MESSAGE-%'"
        );
        $markRead->execute(['id' => $notificationId]);
        $unreadCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM notifications
             WHERE admin_is_read = 0 AND tracking_no NOT LIKE 'MESSAGE-%'"
        )->fetchColumn();
    } elseif ($role === 'client') {
        $markRead = $pdo->prepare(
            'UPDATE notifications SET client_is_read = 1 WHERE id = :id AND user_id = :user_id'
        );
        $markRead->execute(['id' => $notificationId, 'user_id' => $userId]);
        $countUnread = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND client_is_read = 0');
        $countUnread->execute(['user_id' => $userId]);
        $unreadCount = (int) $countUnread->fetchColumn();
    } else {
        if ($departmentId <= 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'This account has no department notification access.']);
            exit;
        }
        $ownedNotification = $pdo->prepare(
            'SELECT id FROM notifications WHERE id = :id AND (department_id = :department_id OR user_id = :user_id) LIMIT 1'
        );
        $ownedNotification->execute([
            'id' => $notificationId,
            'department_id' => $departmentId,
            'user_id' => $userId,
        ]);
        if (!$ownedNotification->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Notification not found.']);
            exit;
        }

        $markRead = $pdo->prepare(
            'INSERT IGNORE INTO notification_reads (notification_id, user_id) VALUES (:notification_id, :user_id)'
        );
        $markRead->execute(['notification_id' => $notificationId, 'user_id' => $userId]);
        $countUnread = $pdo->prepare(
            'SELECT COUNT(*) FROM notifications n
             WHERE (n.department_id = :department_id OR n.user_id = :user_id)
               AND NOT EXISTS (
                   SELECT 1 FROM notification_reads nr
                   WHERE nr.notification_id = n.id AND nr.user_id = :read_user_id
               )'
        );
        $countUnread->execute([
            'department_id' => $departmentId,
            'user_id' => $userId,
            'read_user_id' => $userId,
        ]);
        $unreadCount = (int) $countUnread->fetchColumn();
    }

    if ($markRead->rowCount() > 0) {
        auditLog(
            $pdo,
            $userId,
            'NOTIFICATION_READ',
            'Notification marked as read',
            null,
            $departmentId > 0 ? $departmentId : null,
            null,
            ['notification_id' => $notificationId]
        );
    }

    echo json_encode(['success' => true, 'unread_count' => $unreadCount]);
} catch (Throwable $exception) {
    error_log('Failed to mark notification as read: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update notification status.']);
}
