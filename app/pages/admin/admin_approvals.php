<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
requireLogin(); $user = currentUser(); requirePermission('approve_reports', $user);
$status = trim($_GET['status'] ?? '');
$conditions = ["rw.status IN ('Approved','Rejected','In Progress','Awaiting Receiving')"];
$params = [];
if ($status !== '') {
    $conditions[] = 'rw.status = :status';
    $params['status'] = $status;
}
$isDepartmentScopedUser = isDepartmentUser($user) && !isSuperAdmin($user);
$userDepartmentId = (int) ($user['department_id'] ?? 0);
if ($isDepartmentScopedUser && $userDepartmentId > 0) {
    $conditions[] = '(rw.department_id = :route_department_id OR r.department_id = :report_department_id OR r.assigned_department_id = :assigned_department_id OR r.sender_department_id = :sender_department_id)';
    $params['route_department_id'] = $userDepartmentId;
    $params['report_department_id'] = $userDepartmentId;
    $params['assigned_department_id'] = $userDepartmentId;
    $params['sender_department_id'] = $userDepartmentId;
    if (!isDepartmentAdmin($user) && !hasPermission('override_department_privacy', $user)) {
        $conditions[] = '(COALESCE(r.department_private, 0) = 0 OR r.submitted_by_user_id = :private_submitter_id)';
        $params['private_submitter_id'] = (int) ($user['id'] ?? 0);
    }
} elseif ($isDepartmentScopedUser) {
    $conditions[] = '1 = 0';
}
$where = 'WHERE ' . implode(' AND ', $conditions);
$stmt=$pdo->prepare("SELECT rw.*, r.tracking_number, r.title, d.name AS department_name, u.name AS approving_name FROM report_workflow_routes rw INNER JOIN reports r ON r.id=rw.report_id LEFT JOIN departments d ON d.id=rw.department_id LEFT JOIN users u ON u.id=rw.assigned_user_id {$where} ORDER BY COALESCE(rw.completed_date,rw.assigned_date) DESC LIMIT 200");
$stmt->execute($params);
$approvals=$stmt->fetchAll();
require_once APP_ROOT . '/includes/header.php';
?><div class="admin-module-page"><div class="admin-module-heading"><div><span class="admin-eyebrow">APPROVALS</span><h2>Approval Center</h2><p>Monitor documents waiting for action and review approval outcomes.</p></div></div><form class="admin-filter-bar" method="GET"><select name="status"><option value="">All approval activity</option><?php foreach(['Awaiting Receiving','In Progress','Approved','Rejected'] as $option): ?><option <?= $status===$option?'selected':'' ?>><?= $option ?></option><?php endforeach; ?></select><button class="admin-primary-button">Filter</button></form><section class="admin-panel"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Tracking</th><th>Document</th><th>Department</th><th>Status</th><th>Approving Officer</th><th>Date</th><th>Remarks</th></tr></thead><tbody><?php foreach($approvals as $approval): ?><tr><td><?= htmlspecialchars($approval['tracking_number'] ?? '') ?></td><td><?= htmlspecialchars($approval['title']) ?></td><td><?= htmlspecialchars($approval['department_name'] ?? 'Resident') ?></td><td><span class="admin-status-badge"><?= htmlspecialchars($approval['status']) ?></span></td><td><?= htmlspecialchars($approval['approving_name'] ?? '—') ?></td><td><?= date('m/d/Y h:i A', strtotime($approval['completed_date'] ?: $approval['assigned_date'])) ?></td><td><?= htmlspecialchars($approval['approver_notes'] ?? '—') ?></td></tr><?php endforeach; ?></tbody></table></div></section></div><?php require_once APP_ROOT . '/includes/footer.php'; ?>