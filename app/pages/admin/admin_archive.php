<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
requireLogin(); $user=currentUser(); requirePermission('view_archive', $user);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_report_id'])) {
	requirePermission('manage_reports', $user);
	$reportId = (int) $_POST['restore_report_id'];
	$pdo->prepare('DELETE FROM archived_records WHERE report_id = :report_id')->execute(['report_id' => $reportId]);
	$pdo->prepare("UPDATE reports SET status = CASE WHEN status = 'Completed' THEN 'Processing' ELSE status END WHERE id = :id")->execute(['id' => $reportId]);
	header('Location: admin_archive.php?restored=1');
	exit;
}
$search=trim($_GET['search']??'');$params=[];$where='';if($search!==''){$where='WHERE r.tracking_number LIKE :search OR r.title LIKE :search OR u.name LIKE :search';$params['search']='%'.$search.'%';}
$stmt=$pdo->prepare("SELECT ar.*,r.tracking_number,r.title,r.status,r.created_at,u.name AS client_name,d.name AS department_name,au.name AS archived_by_name FROM archived_records ar INNER JOIN reports r ON r.id=ar.report_id LEFT JOIN users u ON u.id=r.client_id LEFT JOIN departments d ON d.id=r.department_id LEFT JOIN users au ON au.id=ar.archived_by {$where} ORDER BY ar.archived_at DESC");$stmt->execute($params);$archives=$stmt->fetchAll();
require_once APP_ROOT.'/includes/header.php';
?><div class="admin-module-page"><div class="admin-module-heading"><div><span class="admin-eyebrow">ARCHIVE</span><h2>Archived Documents</h2><p>Search completed documents retained for official records.</p></div></div><form class="admin-filter-bar" method="GET"><input name="search" value="<?=htmlspecialchars($search)?>" placeholder="Tracking number, title, or client"><button class="admin-primary-button">Search Archive</button></form><section class="admin-panel"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Tracking</th><th>Document</th><th>Client</th><th>Office</th><th>Archived By</th><th>Archived Date</th><th>Action</th></tr></thead><tbody><?php foreach($archives as $archive):?><tr><td><?=htmlspecialchars($archive['tracking_number']??'')?></td><td><?=htmlspecialchars($archive['title'])?></td><td><?=htmlspecialchars($archive['client_name']??'—')?></td><td><?=htmlspecialchars($archive['department_name']??'—')?></td><td><?=htmlspecialchars($archive['archived_by_name']??'System')?></td><td><?=date('m/d/Y h:i A',strtotime($archive['archived_at']))?></td><td><a class="btn-action btn-action-view" title="View Archived Details" href="report_details.php?id=<?= (int)$archive['report_id'] ?>"><i class="bi bi-eye-fill" aria-hidden="true"></i></a></td></tr><?php endforeach;?></tbody></table></div></section></div><?php require_once APP_ROOT.'/includes/footer.php'; ?>