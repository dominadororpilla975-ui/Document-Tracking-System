<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
requireLogin();
$user = currentUser();
requirePermission('route_reports', $user);
$search = trim($_GET['search'] ?? '');
$params = [];
$conditions = [];
if ($search !== '') {
    $conditions[] = '(r.tracking_number LIKE :tracking_search OR d1.name LIKE :source_search OR d2.name LIKE :destination_search)';
    $searchValue = '%' . $search . '%';
    $params['tracking_search'] = $searchValue;
    $params['source_search'] = $searchValue;
    $params['destination_search'] = $searchValue;
}
$isDepartmentScopedUser = isDepartmentUser($user) && !isSuperAdmin($user);
$userDepartmentId = (int) ($user['department_id'] ?? 0);
if ($isDepartmentScopedUser && $userDepartmentId > 0) {
    $conditions[] = '(r.department_id = :report_department_id OR r.assigned_department_id = :assigned_department_id OR r.sender_department_id = :sender_department_id OR rw.department_id = :route_department_id)';
    $params['report_department_id'] = $userDepartmentId;
    $params['assigned_department_id'] = $userDepartmentId;
    $params['sender_department_id'] = $userDepartmentId;
    $params['route_department_id'] = $userDepartmentId;
    if (!isDepartmentAdmin($user) && !hasPermission('override_department_privacy', $user)) {
        $conditions[] = '(COALESCE(r.department_private, 0) = 0 OR r.submitted_by_user_id = :private_submitter_id)';
        $params['private_submitter_id'] = (int) ($user['id'] ?? 0);
    }
} elseif ($isDepartmentScopedUser) {
    $conditions[] = '1 = 0';
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$stmt = $pdo->prepare("SELECT rw.*, r.tracking_number, r.title, d1.name AS source_department, d2.name AS destination_department, u.name AS assigned_name FROM report_workflow_routes rw INNER JOIN reports r ON r.id = rw.report_id LEFT JOIN departments d1 ON d1.id = r.sender_department_id LEFT JOIN departments d2 ON d2.id = rw.department_id LEFT JOIN users u ON u.id = rw.assigned_user_id {$where} ORDER BY rw.assigned_date DESC LIMIT 200");
$stmt->execute($params); $routes = $stmt->fetchAll();
require_once APP_ROOT . '/includes/header.php';
?>
<div class="admin-module-page"><div class="admin-module-heading"><div><span class="admin-eyebrow">DOCUMENT ROUTING</span><h2>Routing Monitor</h2><p>Review source office, destination office, assigned employee, date, and routing remarks.</p></div></div><form class="admin-filter-bar" method="GET"><input name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search tracking number or office"><button class="admin-primary-button">Search</button></form><section class="admin-panel"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Tracking</th><th>Document</th><th>Source Office</th><th>Destination</th><th>Assigned Employee</th><th>Route Date</th><th>Status / Remarks</th></tr></thead><tbody><?php foreach ($routes as $route): ?><tr><td><?= htmlspecialchars($route['tracking_number'] ?? '') ?></td><td><?= htmlspecialchars($route['title']) ?></td><td><?= htmlspecialchars($route['source_department'] ?? '—') ?></td><td><?= htmlspecialchars($route['destination_department'] ?? 'Resident') ?></td><td><?= htmlspecialchars($route['assigned_name'] ?? 'Unassigned') ?></td><td><?= date('m/d/Y h:i A', strtotime($route['assigned_date'])) ?></td><td><span class="admin-status-badge"><?= htmlspecialchars($route['status']) ?></span><br><small><?= htmlspecialchars($route['approver_notes'] ?? '') ?></small></td></tr><?php endforeach; ?></tbody></table></div></section></div>
<?php require_once APP_ROOT . '/includes/footer.php'; ?>