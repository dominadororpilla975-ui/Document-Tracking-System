<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
requireLogin(); $user=currentUser(); requirePermission('view_reports', $user);
$canExportReports = hasPermission('export_reports', $user);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$isDepartmentScopedUser = isDepartmentUser($user) && !isSuperAdmin($user);
$userDepartmentId = (int) ($user['department_id'] ?? 0);
$department = $isDepartmentScopedUser ? $userDepartmentId : (int) ($_GET['department_id'] ?? 0);
$where = ['COALESCE(r.is_deleted, 0) = 0', 'COALESCE(r.is_suspended, 0) = 0'];
$params = [];
if ($from !== '') {
    $where[] = 'DATE(r.created_at) >= :from';
    $params['from'] = $from;
}
if ($to !== '') {
    $where[] = 'DATE(r.created_at) <= :to';
    $params['to'] = $to;
}
if ($department > 0) {
    $where[] = 'r.department_id = :department_id';
    $params['department_id'] = $department;
} elseif ($isDepartmentScopedUser) {
    $where[] = '1 = 0';
}
if ($isDepartmentScopedUser && !isDepartmentAdmin($user) && !hasPermission('override_department_privacy', $user)) {
    $where[] = '(COALESCE(r.department_private, 0) = 0 OR r.submitted_by_user_id = :private_submitter_id)';
    $params['private_submitter_id'] = (int) ($user['id'] ?? 0);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$departmentStmt = $isDepartmentScopedUser
    ? $pdo->prepare('SELECT id, name FROM departments WHERE id = :department_id')
    : $pdo->query('SELECT id, name FROM departments ORDER BY name');
if ($isDepartmentScopedUser) {
    $departmentStmt->execute(['department_id' => $userDepartmentId]);
}
$departments = $departmentStmt->fetchAll();
$summary=$pdo->prepare("SELECT COUNT(*) total, SUM(r.status='Pending') pending, SUM(r.status='Processing') processing, SUM(r.status IN ('Completed','Approved')) completed, SUM(r.status='Rejected') rejected FROM reports r {$whereSql}");$summary->execute($params);$stats=$summary->fetch();
$byDept=$pdo->prepare("SELECT d.name, COUNT(r.id) total FROM reports r LEFT JOIN departments d ON d.id=r.department_id {$whereSql} GROUP BY r.department_id ORDER BY total DESC");$byDept->execute($params);$departmentRows=$byDept->fetchAll();
require_once APP_ROOT.'/includes/header.php';
?><div class="admin-module-page"><div class="admin-module-heading"><div><span class="admin-eyebrow">REPORTS & ANALYTICS</span><h2>Document Reports</h2><p>Filter document volume by date and office for monitoring and print-ready summaries.</p></div><?php if ($canExportReports): ?><button class="admin-secondary-button" onclick="window.print()">Print Report</button><?php endif; ?></div><form class="admin-filter-bar" method="GET"><input type="date" name="from" value="<?=htmlspecialchars($from)?>"><input type="date" name="to" value="<?=htmlspecialchars($to)?>"><select name="department_id"><?php if (!$isDepartmentScopedUser): ?><option value="0">All offices</option><?php endif; ?><?php foreach($departments as $dept):?><option value="<?= (int)$dept['id']?>" <?=$department===(int)$dept['id']?'selected':''?>><?=htmlspecialchars($dept['name'])?></option><?php endforeach;?></select><button class="admin-primary-button">Apply Filters</button></form><div class="admin-summary-grid"><div><span>Total Documents</span><strong><?= (int)($stats['total']??0)?></strong></div><div><span>Pending</span><strong><?= (int)($stats['pending']??0)?></strong></div><div><span>Processing</span><strong><?= (int)($stats['processing']??0)?></strong></div><div><span>Completed</span><strong><?= (int)($stats['completed']??0)?></strong></div><div><span>Rejected</span><strong><?= (int)($stats['rejected']??0)?></strong></div></div><section class="admin-panel"><h3>Documents by Office</h3><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Office</th><th>Documents</th></tr></thead><tbody><?php foreach($departmentRows as $row):?><tr><td><?=htmlspecialchars($row['name']??'Unassigned')?></td><td><?= (int)$row['total']?></td></tr><?php endforeach;?></tbody></table></div></section></div><?php require_once APP_ROOT.'/includes/footer.php'; ?>