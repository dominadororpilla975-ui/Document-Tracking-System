<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/privacy_guard.php';

requireLogin();
$user = currentUser();
requirePermission('view_workflow', $user);
$isSuperAdmin = isSuperAdmin($user);
$userDeptId = (int)($user['department_id'] ?? 0);

// Fetch reports scoped by RBAC role & department
if ($isSuperAdmin) {
    $sqlPrivateFilter = '';
    $sql = "SELECT r.*, u.name as client_name, d.name as dept_name 
            FROM reports r 
            LEFT JOIN users u ON r.client_id = u.id 
            LEFT JOIN departments d ON r.department_id = d.id 
            WHERE COALESCE(r.is_deleted, 0) = 0 AND COALESCE(r.is_suspended, 0) = 0{$sqlPrivateFilter}
            ORDER BY r.created_at DESC";
    $stmt = $pdo->query($sql);
    $reports = $stmt->fetchAll();
} elseif ($user['role'] === 'client') {
    $sql = "SELECT r.*, u.name as client_name, d.name as dept_name 
            FROM reports r 
            LEFT JOIN users u ON r.client_id = u.id 
            LEFT JOIN departments d ON r.department_id = d.id 
            WHERE r.client_id = :client_id
              AND COALESCE(r.is_deleted, 0) = 0 AND COALESCE(r.is_suspended, 0) = 0
            ORDER BY r.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['client_id' => $user['id']]);
    $reports = $stmt->fetchAll();
} else {
    $activityPrivacySql = '';
    $canViewPrivateDepartmentDocuments = isDepartmentAdmin($user)
        || hasPermission('override_department_privacy', $user);
    if (!$canViewPrivateDepartmentDocuments) {
        $activityPrivacySql = ' AND (COALESCE(r.department_private, 0) = 0 OR r.submitted_by_user_id = :staff_submitter_id)';
    }
    $sql = "SELECT r.*, u.name as client_name, d.name as dept_name 
            FROM reports r 
            LEFT JOIN users u ON r.client_id = u.id 
            LEFT JOIN departments d ON r.department_id = d.id 
            LEFT JOIN department_services ds ON ds.id = r.service_id
            WHERE (r.department_id = :d1 OR r.assigned_department_id = :d2 OR r.sender_department_id = :d3
               OR r.id IN (SELECT report_id FROM report_workflow_routes WHERE department_id = :d4))
              AND COALESCE(r.is_deleted, 0) = 0 AND COALESCE(r.is_suspended, 0) = 0" . $activityPrivacySql . "
            ORDER BY r.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $monitorParams = ['d1' => $userDeptId, 'd2' => $userDeptId, 'd3' => $userDeptId, 'd4' => $userDeptId];
    if (!$canViewPrivateDepartmentDocuments) {
        $monitorParams['staff_submitter_id'] = (int) $user['id'];
    }
    $stmt->execute($monitorParams);
    $reports = $stmt->fetchAll();
}

// Map workflow routes for each report
$reportRoutesMap = [];
if (!empty($reports)) {
    $reportIds = array_column($reports, 'id');
    $inClause = implode(',', array_map('intval', $reportIds));
    
    $routesStmt = $pdo->query("SELECT rwf.*, COALESCE(d.name, CASE WHEN rwf.destination_type = 'Resident' THEN 'Resident' ELSE 'Department' END) as dept_name 
                              FROM report_workflow_routes rwf 
                              LEFT JOIN departments d ON rwf.department_id = d.id 
                              WHERE rwf.report_id IN ($inClause) 
                              ORDER BY rwf.report_id, rwf.step_order ASC, rwf.step_number ASC");
    $allRoutes = $routesStmt->fetchAll();

    foreach ($allRoutes as $rt) {
        $reportRoutesMap[$rt['report_id']][] = $rt;
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<div style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.6rem; font-weight: 800;">
        <?php
        if ($isSuperAdmin) {
            echo 'Overall System Workflow Monitor';
        } elseif ($user['role'] === 'client') {
            echo 'My Document Workflows Monitor';
        } else {
            echo 'Department Workflow Monitor (' . htmlspecialchars($user['department_name'] ?? 'My Dept') . ')';
        }
        ?>
    </h2>
    <p style="color: #64748b; font-size: 0.95rem;">
        <?php
        if ($isSuperAdmin) {
            echo 'Real-time tracking of document movement across municipal departments';
        } elseif ($user['role'] === 'client') {
            echo 'Real-time routing progress and approval tracking for your documents';
        } else {
            echo 'Tracking document movement and routing steps for your department';
        }
        ?>
    </p>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Unique ID.</th>
                    <?php if (!$isSuperAdmin): ?>
                        <th>Document Title</th>
                    <?php endif; ?>
                    <th>Submitted By</th>
                    <th>Department Routing Flow</th>
                    <th>Current Step</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reports)): ?>
                    <tr>
                        <td colspan="<?= $isSuperAdmin ? 4 : 5 ?>" style="text-align: center; color: #94a3b8; padding: 2rem;">
                            No records found in monitoring system.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reports as $doc): 
                        $trackingNo = $doc['tracking_number'];
                        $routes = $reportRoutesMap[$doc['id']] ?? [];
                    ?>
                        <tr>
                            <td><code style="font-weight: 700; color: #0f172a;"><?= $trackingNo ?></code></td>
                            <?php if (!$isSuperAdmin): ?>
                                <td>
                                    <strong><?= htmlspecialchars($doc['title']) ?></strong>
                                </td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($doc['client_name'] ?? 'N/A') ?></td>
                            <td>
                                <!-- Visual Department Flow -->
                                <?php if (empty($routes)): ?>
                                    <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                                        <span class="badge badge-assigned">
                                            🏢 <?= htmlspecialchars($doc['dept_name'] ?? 'Receiving Desk') ?>
                                        </span>
                                        <i class="bi bi-arrow-right" style="color: #94a3b8;" aria-hidden="true"></i>
                                        <span class="badge badge-pending">Pending Review</span>
                                    </div>
                                <?php else: ?>
                                    <div style="display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                                        <?php foreach ($routes as $idx => $r): ?>
                                            <?php if ($idx > 0): ?><i class="bi bi-arrow-right" style="color: #94a3b8;" aria-hidden="true"></i><?php endif; ?>
                                            <span class="badge <?= $r['status'] === 'Completed' || $r['status'] === 'Approved' ? 'badge-approved' : ($r['status'] === 'In Progress' ? 'badge-processing' : 'badge-pending') ?>" title="Step <?= $r['step_number'] ?>">
                                                Step <?= $r['step_number'] ?>: <?= htmlspecialchars($r['dept_name']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-under-review">
                                    Step <?= $doc['current_workflow_step'] ?? 1 ?> / <?= max(count($routes), $doc['total_workflow_steps'] ?? 1) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
