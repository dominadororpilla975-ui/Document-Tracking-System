<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/privacy_guard.php';
require_once APP_ROOT . '/includes/notifications.php';

requireLogin();
$user = currentUser();
requirePermission('view_documents', $user);
$isSuperAdmin = isSuperAdmin($user);
$userDeptId = (int)($user['department_id'] ?? 0);
$isPersonalScope = isClientUser($user) || (!$isSuperAdmin && $userDeptId <= 0);
$canCreateReports = hasPermission('create_reports', $user);
$canManageReports = hasPermission('manage_reports', $user);

$search = trim($_GET['search'] ?? '');
$documentStatusFilters = [
    'pending_review' => ['pending', 'under review', 'assigned'],
    'received' => ['assigned', 'under review'],
    'in_progress' => ['processing'],
    'awaiting_action' => ['pending'],
    'completed' => ['completed', 'approved'],
    'approved' => ['completed', 'approved'],
    'rejected' => ['rejected', 'returned'],
    'declined' => ['rejected', 'returned'],
];
$requestedStatusFilter = trim((string) ($_GET['status'] ?? ''));
$statusFilter = $requestedStatusFilter === 'all' ? '' : $requestedStatusFilter;
$invalidStatusFilter = $statusFilter !== '' && !isset($documentStatusFilters[$statusFilter]);
if ($invalidStatusFilter) {
    $statusFilter = '';
}
$statusFilterLabels = [
    'pending_review' => 'Pending Review',
    'received' => 'Received',
    'in_progress' => 'In Progress',
    'awaiting_action' => 'Awaiting Action',
    'completed' => 'Completed',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'declined' => 'Declined',
];

$params = [];
$whereClauses = [];
$whereClauses[] = 'COALESCE(r.is_deleted, 0) = 0 AND COALESCE(r.is_suspended, 0) = 0';
if ($invalidStatusFilter) {
    $whereClauses[] = '1 = 0';
}

if ($isSuperAdmin) {
    $whereClauses[] = 'COALESCE(r.department_private, 0) = 0';
} elseif ($isPersonalScope) {
    // CLIENT USERS: Strict isolation query for their own documents
    $whereClauses[] = "r.client_id = ?";
    $params[] = $user['id'];
} else {
    // DEPARTMENT USERS: Strict isolation query for their department only
    $whereClauses[] = "(r.department_id = ? OR r.assigned_department_id = ? OR r.sender_department_id = ?)";
    $params[] = $userDeptId;
    $params[] = $userDeptId;
    $params[] = $userDeptId;
    $canViewPrivateDepartmentDocuments = isDepartmentAdmin($user)
        || hasPermission('override_department_privacy', $user);
    if (!$canViewPrivateDepartmentDocuments) {
        $whereClauses[] = '(COALESCE(r.department_private, 0) = 0 OR r.submitted_by_user_id = ?)';
        $params[] = (int) $user['id'];
    }
}

if ($statusFilter !== '') {
    $statuses = $documentStatusFilters[$statusFilter];
    $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));
    $whereClauses[] = "LOWER(r.status) IN ({$statusPlaceholders})";
    foreach ($statuses as $status) {
        $params[] = $status;
    }
}

if ($search !== '') {
    $whereClauses[] = 'u.name LIKE ?';
    $params[] = '%' . $search . '%';
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

$query = "SELECT r.*, u.name as client_name, d_recv.name as receiving_dept_name, d_send.name as sending_dept_name 
          FROM reports r 
          LEFT JOIN users u ON r.client_id = u.id 
          LEFT JOIN departments d_recv ON r.department_id = d_recv.id 
          LEFT JOIN departments d_send ON r.sender_department_id = d_send.id 
          LEFT JOIN department_services ds ON ds.id = r.service_id
          {$whereSql} 
          ORDER BY r.created_at DESC";

if (!$isSuperAdmin && !$isPersonalScope) {
    $whereSql = "WHERE " . implode(" AND ", $whereClauses);
    $query = "SELECT r.*, u.name as client_name, ds.assigned_user_id as activity_assigned_user_id, d_recv.name as receiving_dept_name, d_send.name as sending_dept_name
              FROM reports r
              LEFT JOIN users u ON r.client_id = u.id
              LEFT JOIN departments d_recv ON r.department_id = d_recv.id
              LEFT JOIN departments d_send ON r.sender_department_id = d_send.id
              LEFT JOIN department_services ds ON ds.id = r.service_id
              {$whereSql}
              ORDER BY r.created_at DESC";
}
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$isPersonalScope && !$isSuperAdmin) {
    foreach ($reports as $doc) {
        if (!empty($doc['deadline_at']) && empty($doc['overdue_notified_at'])
            && strtotime($doc['deadline_at']) < time()
            && !in_array($doc['status'], ['Completed', 'Approved', 'Rejected'], true)) {
            $trackingNo = $doc['tracking_number'];
            sendDepartmentNotification($pdo, (int) $userDeptId, $trackingNo, 'Document ' . $trackingNo . ' is overdue and requires attention.');
            $mark = $pdo->prepare('UPDATE reports SET overdue_notified_at = NOW() WHERE id = :id AND overdue_notified_at IS NULL');
            $mark->execute(['id' => (int) $doc['id']]);
        }
    }
}
require_once APP_ROOT . '/includes/header.php';
?>

<div class="documents-page">
<div class="documents-header-row">
    <div>
        <h2>
            <?php
            if ($isSuperAdmin) {
                echo 'System Document Metadata Search';
            } elseif ($isPersonalScope) {
                echo 'My Documents Registry';
            } else {
                echo 'Department Documents Registry';
            }
            ?>
        </h2>
        <p style="color: #64748b; font-size: 0.9rem;">
            <?php
            if ($isSuperAdmin) {
                echo 'Search documents by the name of the document owner';
            } elseif ($isPersonalScope) {
                echo 'Viewing documents submitted for you to monitor tracking progress';
            } else {
                echo 'Search documents by the name of the document owner';
            }
            ?>
        </p>
    </div>
    <?php if (!$isSuperAdmin && $canCreateReports): ?>
        <a href="submit_report.php" class="icon-circle-button secondary documents-create-button add-create-control" aria-label="Create new document">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            Create Document
        </a>
    <?php endif; ?>
</div>

<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">🗑️ Document deleted successfully!</div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<?php if ($invalidStatusFilter): ?>
    <div class="alert alert-danger">The selected document status filter is invalid.</div>
<?php elseif ($statusFilter !== ''): ?>
    <div class="alert alert-info" role="status">
        Showing <?= htmlspecialchars($statusFilterLabels[$statusFilter]) ?> documents.
        <a href="documents.php">Show all documents</a>
    </div>
<?php endif; ?>

<!-- Table Listing -->
<div class="card documents-table-card">
    <div class="table-responsive">
        <table class="table documents-data-table" data-datatable-responsive="false" data-datatable-search="true" data-datatable-paging="true" data-datatable-ordering="true" data-datatable-info="false">
            <thead>
                <tr>
                    <th>Unique ID.</th>
                    <?php if (!$isSuperAdmin): ?>
                        <th>Document Title</th>
                    <?php endif; ?>
                    <th>Submission Type</th>
                    <th>Submitted By</th>
                    <th>Sender Department</th>
                    <th>Receiving Department</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Deadline</th>
                    <th>Date Received</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reports)): ?>
                    <?php foreach ($reports as $doc): 
                        $trackingNo = $doc['tracking_number'];
                        $submissionModeLabel = ($doc['submission_mode'] ?? 'online') === 'walkin' ? 'Walk-in' : 'Online';
                        
                        $statusClass = 'badge-pending';
                        $st = strtolower(str_replace(' ', '-', $doc['status']));
                        if ($st === 'assigned' || $st === 'under-review') $statusClass = 'badge-assigned';
                        elseif ($st === 'processing') $statusClass = 'badge-processing';
                        elseif ($st === 'approved' || $st === 'completed') $statusClass = 'badge-approved';
                        elseif ($st === 'returned' || $st === 'rejected') $statusClass = 'badge-rejected';
                    ?>
                        <tr>
                            <td data-label="Unique ID."><code style="font-weight: 700; color: #1e293b;"><?= $trackingNo ?></code></td>
                            <?php if (!$isSuperAdmin): ?>
                                <td data-label="Document Title"><strong><?= htmlspecialchars($doc['title']) ?></strong></td>
                            <?php endif; ?>
                            <td data-label="Submission Type"><?= htmlspecialchars($submissionModeLabel) ?></td>
                            <td data-label="Submitted By"><?= htmlspecialchars(ucfirst((string) ($doc['submission_source'] ?? 'client'))) ?></td>
                            <td data-label="Sender Department"><?= htmlspecialchars($doc['sending_dept_name'] ?? 'Receiving Desk') ?></td>
                            <td data-label="Receiving Department"><?= htmlspecialchars($doc['receiving_dept_name'] ?? 'Office of the Mayor') ?></td>
                            <td data-label="Status">
                                <span class="badge <?= $statusClass ?>">
                                    <?= htmlspecialchars($doc['status']) ?>
                                </span>
                            </td>
                            <td data-label="Priority">
                                <span class="badge badge-priority">
                                    <?= htmlspecialchars(ucfirst($doc['priority'] ?? 'Medium')) ?>
                                </span>
                            </td>
                            <td data-label="Deadline">
                                <?php if (!empty($doc['deadline_at'])): ?>
                                    <?php $isOverdue = strtotime($doc['deadline_at']) < time() && !in_array($doc['status'], ['Completed', 'Approved', 'Rejected'], true); ?>
                                    <span class="badge <?= $isOverdue ? 'badge-rejected' : 'badge-assigned' ?>"><?= $isOverdue ? 'Overdue' : date('m/d/Y', strtotime($doc['deadline_at'])) ?></span>
                                <?php else: ?><span style="color:#94a3b8;">No deadline</span><?php endif; ?>
                            </td>
                            <td data-label="Date Received"><?= date('m/d/Y', strtotime($doc['created_at'])) ?></td>
                            <td data-label="Actions" class="dt-actions dt-center">
                                <div class="action-menu-shell">
                                    <button type="button" class="action-more-toggle" aria-label="More actions" title="More actions" aria-expanded="false">
                                        <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                    </button>
                                    <div class="action-menu-panel">
                                        <a href="report_details.php?id=<?= (int) $doc['id'] ?>" class="action-menu-item">
                                            <i class="bi bi-eye-fill" aria-hidden="true"></i>
                                            <span><?= $isSuperAdmin ? 'View Metadata' : 'View Document' ?></span>
                                        </a>
                                        <?php if ($isPersonalScope || (!$isPersonalScope && ($isSuperAdmin || $canManageReports))): ?>
                                            <?php if ($isSuperAdmin): ?>
                                                <form method="POST" action="delete_report.php" style="margin: 0;" onsubmit="return confirm('Suspend Document <?= $trackingNo ?>? It can be resumed from Trash Bin.');">
                                                    <input type="hidden" name="report_id" value="<?= (int) $doc['id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                    <input type="hidden" name="trash_action" value="suspend">
                                                    <input type="hidden" name="reason" value="Suspended by Super Admin">
                                                    <button type="submit" class="action-menu-item" style="color: #ea580c; width: 100%; border: 0; background: transparent; text-align: left; cursor: pointer;">
                                                        <i class="bi bi-pause-fill" aria-hidden="true"></i>
                                                        <span>Suspend</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($isSuperAdmin || $isPersonalScope || !isDepartmentAdmin($user)): ?>
                                                <form method="POST" action="delete_report.php" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete Document <?= $trackingNo ?>? This action cannot be undone.');">
                                                    <input type="hidden" name="report_id" value="<?= (int) $doc['id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                    <button type="submit" class="action-menu-item text-danger" style="color: #dc2626; width: 100%; border: 0; background: transparent; text-align: left; cursor: pointer;">
                                                        <i class="bi bi-trash3" aria-hidden="true"></i>
                                                        <span>Delete</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
