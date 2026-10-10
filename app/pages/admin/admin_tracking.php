<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
requireLogin();
$user = currentUser();
requirePermission('view_documents', $user);
$isSuperAdmin = isSuperAdmin($user);
$isDepartmentUser = isDepartmentUser($user);
$userDeptId = (int) ($user['department_id'] ?? 0);

$tracking = trim($_GET['tracking'] ?? '');
$document = null;
$routes = [];
$logs = [];
if ($tracking !== '') {
    $scopeSql = '';
    $params = [
        'tracking' => $tracking,
        'id' => (int) preg_replace('/[^0-9]/', '', $tracking),
    ];
    if (!$isSuperAdmin) {
        if ($isDepartmentUser && $userDeptId > 0) {
            $scopeSql = ' AND (r.department_id = :department_id
                OR r.assigned_department_id = :assigned_department_id
                OR r.sender_department_id = :sender_department_id
                OR EXISTS (
                    SELECT 1 FROM report_workflow_routes scope_route
                    WHERE scope_route.report_id = r.id
                      AND scope_route.department_id = :route_department_id
                ))';
            $params['department_id'] = $userDeptId;
            $params['assigned_department_id'] = $userDeptId;
            $params['sender_department_id'] = $userDeptId;
            $params['route_department_id'] = $userDeptId;
            if (!isDepartmentAdmin($user) && !hasPermission('override_department_privacy', $user)) {
                $scopeSql .= ' AND (COALESCE(r.department_private, 0) = 0 OR r.submitted_by_user_id = :private_submitter_id)';
                $params['private_submitter_id'] = (int) ($user['id'] ?? 0);
            }
        } else {
            $scopeSql = ' AND r.client_id = :client_id';
            $params['client_id'] = (int) ($user['id'] ?? 0);
        }
    }
    $stmt = $pdo->prepare('SELECT r.*, u.name AS client_name, d.name AS current_department
        FROM reports r
        LEFT JOIN users u ON u.id = r.client_id
        LEFT JOIN departments d ON d.id = r.department_id
        WHERE (r.tracking_number = :tracking OR r.id = :id)
          AND COALESCE(r.is_deleted, 0) = 0
          AND COALESCE(r.is_suspended, 0) = 0' . $scopeSql . ' LIMIT 1');
    $stmt->execute($params);
    $document = $stmt->fetch();
    if ($document) {
        $routeStmt = $pdo->prepare('SELECT rw.*, d.name AS department_name, u.name AS assigned_name FROM report_workflow_routes rw LEFT JOIN departments d ON d.id = rw.department_id LEFT JOIN users u ON u.id = rw.assigned_user_id WHERE rw.report_id = :id ORDER BY rw.step_number');
        $routeStmt->execute(['id' => $document['id']]);
        $routes = $routeStmt->fetchAll();
        $logStmt = $pdo->prepare('SELECT l.*, u.name AS user_name FROM report_logs l LEFT JOIN users u ON u.id = l.updated_by WHERE l.report_id = :id ORDER BY l.date_updated DESC');
        $logStmt->execute(['id' => $document['id']]);
        $logs = $logStmt->fetchAll();
    }
}
require_once APP_ROOT . '/includes/header.php';
?>
<div class="admin-module-page">
    <div class="admin-module-heading"><div><span class="admin-eyebrow">DOCUMENT TRACKING</span><h2>Track a Document</h2><p>Search a tracking number and view its current office, status, and complete movement history.</p></div></div>
    <form class="admin-filter-bar" method="GET"><input name="tracking" value="<?= htmlspecialchars($tracking) ?>" placeholder="Enter tracking number" aria-label="Tracking number"><button class="admin-primary-button" type="submit">Search Tracking</button></form>
    <?php if ($tracking !== '' && !$document): ?><div class="admin-empty-state">No document was found for that tracking number.</div><?php endif; ?>
    <?php if ($document): ?>
        <div class="admin-summary-grid"><div><span>Tracking Number</span><strong><?= htmlspecialchars($document['tracking_number']) ?></strong></div><div><span>Status</span><strong><?= htmlspecialchars($document['status']) ?></strong></div><div><span>Current Office</span><strong><?= htmlspecialchars($document['current_department'] ?? 'Resident') ?></strong></div><div><span>Client</span><strong><?= htmlspecialchars($document['client_name'] ?? '—') ?></strong></div></div>
        <section class="admin-panel"><h3>Document Timeline</h3><div class="admin-timeline"><?php foreach ($routes as $route): ?><div class="admin-timeline-item"><span class="admin-timeline-dot"></span><div><strong>Step <?= (int) $route['step_number'] ?> · <?= htmlspecialchars($route['department_name'] ?? 'Resident') ?></strong><span><?= htmlspecialchars($route['status']) ?><?= $route['assigned_name'] ? ' · ' . htmlspecialchars($route['assigned_name']) : '' ?></span><small><?= $route['completed_date'] ? date('m/d/Y h:i A', strtotime($route['completed_date'])) : date('m/d/Y h:i A', strtotime($route['assigned_date'])) ?></small><p><?= htmlspecialchars($route['approver_notes'] ?? '') ?></p></div></div><?php endforeach; ?></div></section>
        <section class="admin-panel"><h3>Activity History</h3><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Action</th><th>User</th><th>Remarks</th><th>Date & Time</th></tr></thead><tbody><?php foreach ($logs as $log): ?><tr><td><?= htmlspecialchars($log['action']) ?></td><td><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td><td><?= htmlspecialchars($log['remarks'] ?? '—') ?></td><td><?= date('m/d/Y h:i A', strtotime($log['date_updated'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php endif; ?>
</div>
<?php require_once APP_ROOT . '/includes/footer.php'; ?>