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
requirePermission('manage_notifications', $user);
$canOverridePrivacy = hasPermission('override_department_privacy', $user);
$privateNotificationFilter = $canOverridePrivacy ? '' : ' AND COALESCE((SELECT r2.department_private FROM reports r2 WHERE r2.tracking_number = notifications.tracking_no LIMIT 1), 0) = 0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? null);
    if (($_POST['action'] ?? '') === 'delete_notification') {
        $deleteStmt = $pdo->prepare("DELETE FROM notifications WHERE id = :id AND tracking_no NOT LIKE 'MESSAGE-%'{$privateNotificationFilter}");
        $deleteStmt->execute(['id' => (int) ($_POST['notification_id'] ?? 0)]);
        header('Location: admin_system_notifications.php');
        exit;
    }
    if (($_POST['action'] ?? '') === 'mark_all_read') {
        $pdo->exec("UPDATE notifications n LEFT JOIN reports r ON r.tracking_number = n.tracking_no SET n.admin_is_read = 1 WHERE n.admin_is_read = 0 AND n.tracking_no NOT LIKE 'MESSAGE-%'" . ($canOverridePrivacy ? '' : ' AND COALESCE(r.department_private, 0) = 0'));
    } elseif (isset($_POST['notification_id'])) {
        $stmt = $pdo->prepare('UPDATE notifications SET admin_is_read = 1 WHERE id = :id');
        $stmt->execute(['id' => (int) $_POST['notification_id']]);
    }

    header('Location: admin_system_notifications.php');
    exit;
}

$where = [];
$params = [];
$where[] = "n.tracking_no NOT LIKE 'MESSAGE-%'";
if (!$canOverridePrivacy) {
    $where[] = 'COALESCE(r.department_private, 0) = 0';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare(
    "SELECT n.*, d.name AS department_name, u.name AS recipient_name, r.id AS report_id
     FROM notifications n
     LEFT JOIN departments d ON d.id = n.department_id
     LEFT JOIN users u ON u.id = n.user_id
     LEFT JOIN reports r ON r.tracking_number = n.tracking_no
     {$whereSql}
     ORDER BY n.created_at DESC"
);
$stmt->execute($params);
$notifications = $stmt->fetchAll();
$totalNotifications = count($notifications);

$unreadStmt = $pdo->query("SELECT COUNT(*) FROM notifications n LEFT JOIN reports r ON r.tracking_number = n.tracking_no WHERE n.admin_is_read = 0 AND n.tracking_no NOT LIKE 'MESSAGE-%'" . ($canOverridePrivacy ? '' : ' AND COALESCE(r.department_private, 0) = 0'));
$unreadCount = (int) $unreadStmt->fetchColumn();

require_once APP_ROOT . '/includes/header.php';
?>

<div class="admin-module-page notifications-page">
    <div class="admin-module-heading">
        <div>
            <span class="admin-eyebrow">SYSTEM MONITORING</span>
            <h2>System Notifications</h2>
            <p>Monitor document assignments, routing updates, approvals, and completion alerts across the municipality.</p>
        </div>
        <?php if ($unreadCount > 0): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="admin-secondary-button">Mark All as Read</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="admin-summary-grid">
        <div><span>Unread Notifications</span><strong><?= $unreadCount ?></strong></div>
        <div><span>Displayed Notifications</span><strong><?= count($notifications) ?></strong></div>
        <div><span>Total Notifications</span><strong><?= $totalNotifications ?></strong></div>
    </div>

    <section class="admin-panel">
        <div class="admin-table-wrap">
            <table class="admin-table notification-table" data-datatable-responsive="false" data-datatable-search="true" data-datatable-paging="true" data-datatable-ordering="true" data-datatable-info="true">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Tracking</th>
                        <th>Recipient / Office</th>
                        <th>Notification</th>
                        <th>Date &amp; Time</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$notifications): ?>
                        <tr><td colspan="6" class="admin-empty-state">No system notifications found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <tr class="<?= !$notification['admin_is_read'] ? 'admin-unread' : '' ?>">
                                <td data-label="Status"><span class="admin-status-badge"><?= $notification['admin_is_read'] ? 'Read' : 'New' ?></span></td>
                                <td data-label="Tracking">
                                    <?php if (!empty($notification['report_id'])): ?>
                                        <a href="report_details.php?id=<?= (int) $notification['report_id'] ?>"><?= htmlspecialchars($notification['tracking_no']) ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($notification['tracking_no'] ?: 'System Notice') ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Recipient / Office"><?= htmlspecialchars($notification['recipient_name'] ?? $notification['department_name'] ?? 'System-wide') ?></td>
                                <td data-label="Notification"><?= htmlspecialchars($notification['message']) ?></td>
                                <td data-label="Date &amp; Time"><?= date('m/d/Y h:i A', strtotime($notification['created_at'])) ?></td>
                                <td data-label="Action" class="notification-actions">
                                    <div class="action-menu-shell">
                                        <button type="button" class="action-more-toggle" aria-label="More actions" title="More actions" aria-expanded="false">
                                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                        </button>
                                        <div class="action-menu-panel">
                                            <?php if (!$notification['admin_is_read']): ?>
                                                <form method="POST" style="margin: 0;">
                                                    <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                    <button type="submit" class="action-menu-item" style="width: 100%;" aria-label="Mark notification as read">
                                                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                                                        Mark as read
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <div class="action-menu-item" style="pointer-events: none; opacity: 0.75;">
                                                    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                                                    Read
                                                </div>
                                            <?php endif; ?>
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Delete this notification?');">
                                                <input type="hidden" name="action" value="delete_notification">
                                                <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                <button type="submit" class="action-menu-item" style="color: #dc2626; width: 100%;" aria-label="Delete notification">
                                                    <i class="bi bi-trash3" aria-hidden="true"></i>
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
