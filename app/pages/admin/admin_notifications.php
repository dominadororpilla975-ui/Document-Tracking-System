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
$userId = (int) ($user['id'] ?? 0);
$departmentId = (int) ($user['department_id'] ?? 0);
$role = strtolower((string) ($user['role'] ?? ''));
$isSuperAdmin = in_array($role, ['super_admin', 'admin'], true);
$isClient = $role === 'client';
$isDepartmentUser = in_array($role, ['department_admin', 'department_staff', 'department', 'staff'], true);
$usesUserReadState = !$isSuperAdmin && !$isClient;
$readColumn = $isClient ? 'client_is_read' : 'admin_is_read';
$readSelect = $usesUserReadState
    ? 'CASE WHEN EXISTS (SELECT 1 FROM notification_reads nr WHERE nr.notification_id = n.id AND nr.user_id = :read_user_id) THEN 1 ELSE 0 END AS viewer_is_read'
    : "n.{$readColumn} AS viewer_is_read";

$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? null);
    $notificationId = (int) ($_POST['notification_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_notification' && $notificationId > 0) {
        $deleteWhere = ['id = :id'];
        $deleteParams = ['id' => $notificationId];
        if ($isClient) {
            $deleteWhere[] = 'user_id = :user_id';
            $deleteParams['user_id'] = $userId;
        } elseif (!$isSuperAdmin) {
            $deleteWhere[] = '(department_id = :department_id OR user_id = :user_id)';
            $deleteParams['department_id'] = $departmentId;
            $deleteParams['user_id'] = $userId;
        }
        $pdo->prepare('DELETE FROM notifications WHERE ' . implode(' AND ', $deleteWhere))->execute($deleteParams);
    } elseif ($action === 'mark_all_read') {
        $where = [];
        $params = [];
        if ($isSuperAdmin) {
            $where[] = 'COALESCE((SELECT r2.department_private FROM reports r2 WHERE r2.tracking_number = notifications.tracking_no LIMIT 1), 0) = 0';
            $where[] = "notifications.tracking_no NOT LIKE 'MESSAGE-%'";
        } elseif (!$isSuperAdmin) {
            if ($isClient) {
                $where[] = 'user_id = :user_id';
                $params['user_id'] = $userId;
            } else {
                $where[] = '(department_id = :department_id OR user_id = :user_id)';
                $params['department_id'] = $departmentId;
                $params['user_id'] = $userId;
            }
        }
        $scope = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        if ($usesUserReadState) {
            $stmt = $pdo->prepare("INSERT INTO notification_reads (notification_id, user_id, read_at)
                SELECT id, :read_user_id, NOW() FROM notifications{$scope}
                ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)");
            $stmt->execute(array_merge(['read_user_id' => $userId], $params));
        } else {
            $stmt = $pdo->prepare("UPDATE notifications SET {$readColumn} = 1{$scope}");
            $stmt->execute($params);
        }
    } elseif ($notificationId > 0) {
        $where = ['id = :id'];
        $params = ['id' => $notificationId];
        if ($isSuperAdmin) {
            $where[] = 'COALESCE((SELECT r2.department_private FROM reports r2 WHERE r2.tracking_number = notifications.tracking_no LIMIT 1), 0) = 0';
            $where[] = "notifications.tracking_no NOT LIKE 'MESSAGE-%'";
        } else {
            if ($isClient) {
                $where[] = 'user_id = :user_id';
                $params['user_id'] = $userId;
            } else {
                $where[] = '(department_id = :department_id OR user_id = :user_id)';
                $params['department_id'] = $departmentId;
                $params['user_id'] = $userId;
            }
        }
        if ($usesUserReadState) {
            $stmt = $pdo->prepare("INSERT INTO notification_reads (notification_id, user_id, read_at)
                SELECT id, :read_user_id, NOW() FROM notifications WHERE " . implode(' AND ', $where) . "
                ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)");
            $stmt->execute(array_merge(['read_user_id' => $userId], $params));
        } else {
            $stmt = $pdo->prepare("UPDATE notifications SET {$readColumn} = 1 WHERE " . implode(' AND ', $where));
            $stmt->execute($params);
        }
    }

    header('Location: admin_notifications.php');
    exit;
}

$where = [];
$params = [];
if (!$isSuperAdmin) {
    if ($isClient) {
        $where[] = 'n.user_id = :user_id';
        $params['user_id'] = $userId;
    } else {
        $where[] = '(n.department_id = :department_id OR n.user_id = :user_id)';
        $params['department_id'] = $departmentId;
        $params['user_id'] = $userId;
    }
}
if ($filter === 'unread') $where[] = $usesUserReadState
    ? 'NOT EXISTS (SELECT 1 FROM notification_reads nr_filter WHERE nr_filter.notification_id = n.id AND nr_filter.user_id = :filter_user_id)'
    : "n.{$readColumn} = 0";
if ($filter === 'read') $where[] = $usesUserReadState
    ? 'EXISTS (SELECT 1 FROM notification_reads nr_filter WHERE nr_filter.notification_id = n.id AND nr_filter.user_id = :filter_user_id)'
    : "n.{$readColumn} = 1";
if ($usesUserReadState && in_array($filter, ['unread', 'read'], true)) {
    $params['filter_user_id'] = $userId;
}
if ($search !== '') {
    $where[] = '(n.tracking_no LIKE :search OR n.message LIKE :search OR d.name LIKE :search)';
    $params['search'] = '%' . $search . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
if ($usesUserReadState) $params['read_user_id'] = $userId;
$stmt = $pdo->prepare("SELECT n.*, d.name AS department_name, u.name AS recipient_name, r.id AS report_id, {$readSelect}
    FROM notifications n
    LEFT JOIN departments d ON d.id = n.department_id
    LEFT JOIN users u ON u.id = n.user_id
    LEFT JOIN reports r ON r.tracking_number = n.tracking_no
    {$whereSql}
    ORDER BY n.created_at DESC
    LIMIT 300");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$countWhere = [];
$countParams = [];
if (!$isSuperAdmin) {
    if ($isClient) {
        $countWhere[] = 'n.user_id = :user_id';
        $countParams['user_id'] = $userId;
    } else {
        $countWhere[] = '(n.department_id = :department_id OR n.user_id = :user_id)';
        $countParams['department_id'] = $departmentId;
        $countParams['user_id'] = $userId;
    }
}
$countScope = $countWhere ? ' AND ' . implode(' AND ', $countWhere) : '';
$countReadWhere = $usesUserReadState
    ? 'NOT EXISTS (SELECT 1 FROM notification_reads nr_count WHERE nr_count.notification_id = n.id AND nr_count.user_id = :count_user_id)'
    : "n.{$readColumn} = 0";
if ($usesUserReadState) $countParams['count_user_id'] = $userId;
$countMessageScope = $isSuperAdmin ? " AND n.tracking_no NOT LIKE 'MESSAGE-%'" : '';
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications n LEFT JOIN reports r ON r.tracking_number = n.tracking_no WHERE {$countReadWhere} AND COALESCE(r.department_private, 0) = 0{$countMessageScope}{$countScope}");
$countStmt->execute($countParams);
$unreadCount = (int) $countStmt->fetchColumn();

require_once APP_ROOT . '/includes/header.php';
?>
<div class="admin-module-page">
    <div class="admin-module-heading">
        <div><span class="admin-eyebrow">NOTIFICATIONS</span><h2>Notification Center <?php if ($unreadCount > 0): ?><span class="admin-status-badge"><?= $unreadCount ?> unread</span><?php endif; ?></h2><p><?= $isSuperAdmin ? 'Review system-wide alerts.' : ($isClient ? 'Track updates for your documents.' : 'Review alerts for your department.') ?></p></div>
        <?php if ($unreadCount > 0): ?><form method="POST"><input type="hidden" name="action" value="mark_all_read"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><button type="submit" class="admin-secondary-button admin-icon-control" title="Mark all as read" aria-label="Mark all notifications as read"><i class="bi bi-check-lg" aria-hidden="true"></i></button></form><?php endif; ?>
    </div>
    <?php if (!$isClient && !$isDepartmentUser): ?>
        <form class="admin-filter-bar" method="GET"><input name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search notifications"><select name="filter"><option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Notifications</option><option value="unread" <?= $filter === 'unread' ? 'selected' : '' ?>>Unread Only</option><option value="read" <?= $filter === 'read' ? 'selected' : '' ?>>Read Only</option></select><button type="submit" class="admin-primary-button admin-icon-control" title="Filter notifications" aria-label="Filter notifications">🔎</button></form>
    <?php endif; ?>
    <section class="admin-panel"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Status</th><th>Tracking</th><th>Recipient / Office</th><th>Message</th><th>Date</th><th>Action</th></tr></thead><tbody><?php if (!$notifications): ?><tr><td colspan="6" class="admin-empty-state">No notifications found.</td></tr><?php else: foreach ($notifications as $notification): ?>    <tr class="<?= !$notification['viewer_is_read'] ? 'admin-unread' : '' ?>"><td><span class="admin-status-badge"><?= $notification['viewer_is_read'] ? 'Read' : 'New' ?></span></td><td><?php if (!empty($notification['report_id'])): ?><a href="report_details.php?id=<?= (int) $notification['report_id'] ?>"><?= htmlspecialchars($notification['tracking_no']) ?></a><?php else: ?><?= htmlspecialchars($notification['tracking_no'] ?: 'System Notice') ?><?php endif; ?></td><td><?= htmlspecialchars($notification['recipient_name'] ?? $notification['department_name'] ?? 'System-wide') ?></td><td><?= htmlspecialchars($notification['message']) ?></td><td><?= date('m/d/Y h:i A', strtotime($notification['created_at'])) ?></td><td><div class="action-menu-shell"><button type="button" class="action-more-toggle" aria-label="More actions" title="More actions" aria-expanded="false"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button><div class="action-menu-panel"><?php if (!$notification['viewer_is_read']): ?><form method="POST" style="margin: 0;"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><button type="submit" class="action-menu-item" style="width: 100%;" aria-label="Mark notification as read"><i class="bi bi-check-lg" aria-hidden="true"></i>Mark as read</button></form><?php endif; ?><form method="POST" style="margin: 0;" onsubmit="return confirm('Delete this notification?');"><input type="hidden" name="action" value="delete_notification"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><button type="submit" class="action-menu-item" style="color: #dc2626; width: 100%;" aria-label="Delete notification"><i class="bi bi-trash3" aria-hidden="true"></i>Delete</button></form></div></div></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
</div>
<?php require_once APP_ROOT . '/includes/footer.php'; ?>
