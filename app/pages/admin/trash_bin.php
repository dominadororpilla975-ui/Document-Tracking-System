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
requirePermission('view_trash_bin', $user);
$canManageUsers = hasPermission('manage_users', $user);
$canManageReports = hasPermission('manage_reports', $user);
$canPermanentlyDelete = hasPermission('permanent_delete', $user);
$isDepartmentScopedUser = isDepartmentUser($user) && !isSuperAdmin($user);
$canManageDepartments = hasPermission('manage_departments', $user);
$canManageServices = hasPermission('manage_services', $user);
$canManageAnnouncements = hasAnyPermission(['manage_announcements', 'manage_settings'], $user);

// Handle Actions (Restore, Resume, Permanent Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userAction = $_POST['user_action'] ?? '';
    if (in_array($userAction, ['unsuspend_user', 'restore_user'], true)) {
        requirePermission('manage_account_status', $user);
        requirePermission('manage_users', $user);
        $userId = (int) ($_POST['user_id'] ?? 0);
        $userWhere = "id = :user_id AND (LOWER(COALESCE(account_status, '')) = :suspended_status OR deleted_at IS NOT NULL)";
        $userParams = [
            'user_id' => $userId,
            'suspended_status' => 'suspended',
        ];
        if ($isDepartmentScopedUser) {
            $userWhere .= ' AND department_id = :department_id AND role IN ("department_staff", "client")';
            $userParams['department_id'] = (int) ($user['department_id'] ?? 0);
        }

        if ($userId > 0 && $userId !== (int) $user['id']) {
            $unsuspendStmt = $pdo->prepare('UPDATE users SET account_status = :active_status, deleted_at = NULL, deleted_by = NULL, deleted_reason = NULL WHERE ' . $userWhere);
            $unsuspendStmt->execute(array_merge(['active_status' => 'active'], $userParams));
            if ($unsuspendStmt->rowCount() > 0) {
                auditAccountStatusChanged($pdo, $userId, 'suspended', 'active', $user['id']);
                $_SESSION['success'] = 'User account unsuspended successfully.';
            } else {
                $_SESSION['error'] = 'The selected user account was not found or is no longer suspended.';
            }
        } else {
            $_SESSION['error'] = 'Select a valid user account to unsuspend.';
        }
        header('Location: trash_bin.php');
        exit;
    }

    $departmentAction = $_POST['department_action'] ?? '';
    if ($departmentAction !== '') {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        if ($canManageDepartments && $departmentId > 0
            && (!$isDepartmentScopedUser || $departmentId === (int) ($user['department_id'] ?? 0))) {
            if ($departmentAction === 'restore_department') {
                requirePermission('manage_departments', $user);
                $stmt = $pdo->prepare('UPDATE departments SET deleted_at = NULL, deleted_by = NULL, deleted_reason = NULL WHERE id = :id AND deleted_at IS NOT NULL');
                $stmt->execute(['id' => $departmentId]);
            } elseif ($departmentAction === 'permanent_delete_department') {
                requirePermission('permanent_delete', $user);
                try {
                    $stmt = $pdo->prepare('DELETE FROM departments WHERE id = :id AND deleted_at IS NOT NULL');
                    $stmt->execute(['id' => $departmentId]);
                    if ($stmt->rowCount() === 0) {
                        $_SESSION['error'] = 'Department was not found in the Trash Bin.';
                    }
                } catch (PDOException $exception) {
                    $_SESSION['error'] = 'This department cannot be permanently deleted because it is still linked to users, services, or workflow records.';
                }
            }
        } elseif ($isDepartmentScopedUser) {
            $_SESSION['error'] = 'You can only manage your own department in the Trash Bin.';
        }
        header('Location: trash_bin.php');
        exit;
    }

    $serviceAction = $_POST['service_action'] ?? '';
    if ($serviceAction !== '') {
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        $serviceScope = '';
        $serviceParams = ['id' => $serviceId];
        if ($isDepartmentScopedUser) {
            $serviceScope = ' AND department_id = :department_id';
            $serviceParams['department_id'] = (int) ($user['department_id'] ?? 0);
        }
        if ($canManageServices && $serviceId > 0) {
            try {
                if ($serviceAction === 'restore_service') {
                    requirePermission('manage_services', $user);
                    $stmt = $pdo->prepare('UPDATE department_services SET deleted_at = NULL, deleted_by = NULL, deleted_reason = NULL WHERE id = :id AND deleted_at IS NOT NULL' . $serviceScope);
                    $stmt->execute($serviceParams);
                } elseif ($serviceAction === 'permanent_delete_service') {
                    requirePermission('permanent_delete', $user);
                    $stmt = $pdo->prepare('DELETE FROM department_services WHERE id = :id AND deleted_at IS NOT NULL' . $serviceScope);
                    $stmt->execute($serviceParams);
                    if ($stmt->rowCount() === 0) {
                        $_SESSION['error'] = 'Department activity was not found in the Trash Bin.';
                    }
                }
            } catch (PDOException $exception) {
                $_SESSION['error'] = 'This department activity cannot be permanently deleted because it is still linked to records.';
            }
        }
        header('Location: trash_bin.php');
        exit;
    }

    $announcementAction = $_POST['announcement_action'] ?? '';
    if ($announcementAction !== '') {
        $announcementId = (int) ($_POST['announcement_id'] ?? 0);
        $announcementScope = '';
        $announcementParams = ['id' => $announcementId];
        if ($isDepartmentScopedUser) {
            $announcementScope = ' AND posted_by = :posted_by';
            $announcementParams['posted_by'] = (int) $user['id'];
        }
        if ($canManageAnnouncements && $announcementId > 0) {
            if ($announcementAction === 'restore_announcement') {
                $stmt = $pdo->prepare('UPDATE announcements SET deleted_at = NULL, deleted_by = NULL, deleted_reason = NULL WHERE id = :id AND deleted_at IS NOT NULL' . $announcementScope);
                $stmt->execute($announcementParams);
            } elseif ($announcementAction === 'permanent_delete_announcement') {
                requirePermission('permanent_delete', $user);
                $stmt = $pdo->prepare('DELETE FROM announcements WHERE id = :id AND deleted_at IS NOT NULL' . $announcementScope);
                $stmt->execute($announcementParams);
            }
        }
        header('Location: trash_bin.php');
        exit;
    }

    $reportId = (int) ($_POST['report_id'] ?? 0);
    $action = $_POST['trash_action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    
    $reportScope = '';
    $reportParams = ['id' => $reportId];
    if ($isDepartmentScopedUser) {
        $reportScope = ' AND department_id = :department_id';
        $reportParams['department_id'] = (int) ($user['department_id'] ?? 0);
        if (!isDepartmentAdmin($user) && !hasPermission('override_department_privacy', $user)) {
            $reportScope .= ' AND (COALESCE(department_private, 0) = 0 OR submitted_by_user_id = :private_submitter_id)';
            $reportParams['private_submitter_id'] = (int) ($user['id'] ?? 0);
        }
    } elseif (isClientUser($user)) {
        $reportScope = ' AND client_id = :client_id';
        $reportParams['client_id'] = (int) $user['id'];
    }
    $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = :id' . $reportScope . ' LIMIT 1');
    $stmt->execute($reportParams);
    $report = $stmt->fetch();
    
    if ($report && $reportId > 0) {
        if ($action === 'restore') {
            requirePermission('manage_reports', $user);
            $pdo->prepare('UPDATE reports SET is_deleted=0, deleted_at=NULL, deleted_by=NULL, deleted_reason=NULL WHERE id=:id')->execute(['id' => $reportId]);
            auditLog($pdo, $user['id'], 'DOCUMENT_RESTORED', $reason ?: 'Restored from Trash Bin', $reportId, $report['department_id'] ?? null);
        } elseif ($action === 'resume') {
            requirePermission('override_workflow', $user);
            $pdo->prepare("UPDATE reports SET is_suspended=0, suspended_at=NULL, suspended_by=NULL, suspended_reason=NULL, status=CASE WHEN status='Suspended' THEN 'Processing' ELSE status END WHERE id=:id")->execute(['id' => $reportId]);
            auditLog($pdo, $user['id'], 'DOCUMENT_RESUMED', $reason ?: 'Resumed from Trash Bin', $reportId, $report['department_id'] ?? null);
        } elseif ($action === 'permanent_delete') {
            requirePermission('permanent_delete', $user);
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM attachments WHERE report_id=:id')->execute(['id' => $reportId]);
                $pdo->prepare('DELETE FROM report_workflow_routes WHERE report_id=:id')->execute(['id' => $reportId]);
                $pdo->prepare('DELETE FROM report_logs WHERE report_id=:id')->execute(['id' => $reportId]);
                $pdo->prepare('DELETE FROM archived_records WHERE report_id=:id')->execute(['id' => $reportId]);
                $pdo->prepare('DELETE FROM reports WHERE id=:id')->execute(['id' => $reportId]);
                $pdo->commit();
                auditLog($pdo, $user['id'], 'DOCUMENT_PERMANENTLY_DELETED', $reason ?: 'Permanently deleted from Trash Bin', $reportId, $report['department_id'] ?? null);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($exception->getMessage());
            }
        }
    }
    header('Location: trash_bin.php');
    exit;
}

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$where = ['(COALESCE(r.is_deleted,0)=1 OR COALESCE(r.is_suspended,0)=1)'];
$params = [];
if ($isDepartmentScopedUser) {
    $where[] = 'r.department_id = :report_department_id';
    $params['report_department_id'] = (int) ($user['department_id'] ?? 0);
    if (!isDepartmentAdmin($user) && !hasPermission('override_department_privacy', $user)) {
        $where[] = '(COALESCE(r.department_private, 0) = 0 OR r.submitted_by_user_id = :private_submitter_id)';
        $params['private_submitter_id'] = (int) ($user['id'] ?? 0);
    }
} elseif (isClientUser($user)) {
    $where[] = 'r.client_id = :report_client_id';
    $params['report_client_id'] = (int) $user['id'];
}
if (!$canManageReports && !$canPermanentlyDelete) {
    $where[] = '1 = 0';
}

if ($search !== '') { 
    $where[] = '(r.tracking_number LIKE :tracking_search OR r.title LIKE :title_search OR u.name LIKE :client_search OR r.deleted_reason LIKE :deleted_reason_search OR r.suspended_reason LIKE :suspended_reason_search)'; 
    $searchValue = '%' . $search . '%';
    $params['tracking_search'] = $searchValue;
    $params['title_search'] = $searchValue;
    $params['client_search'] = $searchValue;
    $params['deleted_reason_search'] = $searchValue;
    $params['suspended_reason_search'] = $searchValue;
}
if ($statusFilter === 'deleted') $where[] = 'r.is_deleted=1';
if ($statusFilter === 'suspended') $where[] = 'r.is_suspended=1';

$stmt = $pdo->prepare('
    SELECT r.*, 
           u.name AS client_name, 
           du.name AS deleted_by_name, 
           su.name AS suspended_by_name, 
           d.name AS department_name 
    FROM reports r 
    LEFT JOIN users u ON u.id = r.client_id 
    LEFT JOIN users du ON du.id = r.deleted_by 
    LEFT JOIN users su ON su.id = r.suspended_by 
    LEFT JOIN departments d ON d.id = r.department_id 
    WHERE ' . implode(' AND ', $where) . ' 
    ORDER BY COALESCE(r.deleted_at, r.suspended_at) DESC
');
$stmt->execute($params);
$records = $stmt->fetchAll();

$trashedServices = [];
$serviceWhere = ['ds.deleted_at IS NOT NULL'];
$serviceParams = [];
if ($isDepartmentScopedUser) {
    $serviceWhere[] = 'ds.department_id = :trash_service_department_id';
    $serviceParams['trash_service_department_id'] = (int) ($user['department_id'] ?? 0);
}

$trashedAnnouncements = [];
if ($canManageAnnouncements) {
    $announcementWhere = ['a.deleted_at IS NOT NULL'];
    $announcementParams = [];
    if ($isDepartmentScopedUser) {
        $announcementWhere[] = 'a.posted_by = :trash_announcement_user_id';
        $announcementParams['trash_announcement_user_id'] = (int) $user['id'];
    }
    if ($search !== '') {
        $announcementWhere[] = '(a.title LIKE :trash_announcement_title OR a.content LIKE :trash_announcement_content OR a.deleted_reason LIKE :trash_announcement_reason)';
        $announcementSearch = '%' . $search . '%';
        $announcementParams['trash_announcement_title'] = $announcementSearch;
        $announcementParams['trash_announcement_content'] = $announcementSearch;
        $announcementParams['trash_announcement_reason'] = $announcementSearch;
    }
    $announcementStmt = $pdo->prepare(
        'SELECT a.*, u.name AS poster_name, du.name AS deleted_by_name
         FROM announcements a
         LEFT JOIN users u ON u.id = a.posted_by
         LEFT JOIN users du ON du.id = a.deleted_by
         WHERE ' . implode(' AND ', $announcementWhere) . '
         ORDER BY a.deleted_at DESC'
    );
    $announcementStmt->execute($announcementParams);
    $trashedAnnouncements = $announcementStmt->fetchAll();
}
if ($search !== '') {
    $serviceWhere[] = '(ds.service_name LIKE :trash_service_name OR d.name LIKE :trash_service_department OR ds.deleted_reason LIKE :trash_service_reason)';
    $serviceSearch = '%' . $search . '%';
    $serviceParams['trash_service_name'] = $serviceSearch;
    $serviceParams['trash_service_department'] = $serviceSearch;
    $serviceParams['trash_service_reason'] = $serviceSearch;
}
if ($canManageServices) {
    $serviceStmt = $pdo->prepare(
        'SELECT ds.*, d.name AS department_name, u.name AS deleted_by_name
         FROM department_services ds
         LEFT JOIN departments d ON d.id = ds.department_id
         LEFT JOIN users u ON u.id = ds.deleted_by
         WHERE ' . implode(' AND ', $serviceWhere) . '
         ORDER BY ds.deleted_at DESC'
    );
    $serviceStmt->execute($serviceParams);
    $trashedServices = $serviceStmt->fetchAll();
}

$userWhere = ["(LOWER(COALESCE(u.account_status, '')) = :user_suspended_status OR u.deleted_at IS NOT NULL)"];
$userParams = ['user_suspended_status' => 'suspended'];
if ($search !== '') {
    $userWhere[] = '(u.name LIKE :user_name_search OR u.email LIKE :user_email_search OR d.name LIKE :user_department_search)';
    $userParams['user_name_search'] = '%' . $search . '%';
    $userParams['user_email_search'] = '%' . $search . '%';
    $userParams['user_department_search'] = '%' . $search . '%';
}
if ($isDepartmentScopedUser) {
    $userWhere[] = 'u.department_id = :user_department_id';
    $userWhere[] = 'u.role IN ("department_staff", "client")';
    $userParams['user_department_id'] = (int) ($user['department_id'] ?? 0);
}
$suspendedUsers = [];
if ($canManageUsers) {
    $suspendedUsersStmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.role, u.created_at, u.deleted_at, d.name AS department_name
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE (' . implode(' AND ', $userWhere) . ')
         ORDER BY u.name ASC'
    );
    $suspendedUsersStmt->execute($userParams);
    $suspendedUsers = $suspendedUsersStmt->fetchAll();
}

$trashedDepartments = [];
if ($canManageDepartments) {
    $departmentWhere = ['d.deleted_at IS NOT NULL'];
    $departmentParams = [];
    if ($isDepartmentScopedUser) {
        $departmentWhere[] = 'd.id = :trash_department_id';
        $departmentParams['trash_department_id'] = (int) ($user['department_id'] ?? 0);
    }
    if ($search !== '') {
        $departmentWhere[] = '(d.name LIKE :trash_department_name OR d.department_code LIKE :trash_department_code OR d.official_email LIKE :trash_department_email OR d.deleted_reason LIKE :trash_department_reason)';
        $departmentSearch = '%' . $search . '%';
        $departmentParams += [
            'trash_department_name' => $departmentSearch,
            'trash_department_code' => $departmentSearch,
            'trash_department_email' => $departmentSearch,
            'trash_department_reason' => $departmentSearch,
        ];
    }
    $departmentStmt = $pdo->prepare(
        'SELECT d.*, u.name AS deleted_by_name
         FROM departments d
         LEFT JOIN users u ON u.id = d.deleted_by
         WHERE ' . implode(' AND ', $departmentWhere) . '
         ORDER BY d.deleted_at DESC'
    );
    $departmentStmt->execute($departmentParams);
    $trashedDepartments = $departmentStmt->fetchAll();
}

// Calculate Stats
$totalCount = count($records);
$deletedCount = 0;
$suspendedCount = 0;
$departmentsAffected = [];

foreach ($records as $rec) {
    if ((int) ($rec['is_deleted'] ?? 0) === 1) $deletedCount++;
    if ((int) ($rec['is_suspended'] ?? 0) === 1) $suspendedCount++;
    if (!empty($rec['department_name'])) $departmentsAffected[$rec['department_name']] = true;
}
$totalCount += count($suspendedUsers);
$totalCount += count($trashedDepartments);
$totalCount += count($trashedServices);
$totalCount += count($trashedAnnouncements);

$trashItems = [];
foreach ($records as $record) {
    $isSuspended = (int) ($record['is_suspended'] ?? 0) === 1;
    $trashItems[] = [
        'type' => 'Document',
        'name' => $record['tracking_number'],
        'description' => $record['title'],
        'owner' => $isSuspended ? ($record['suspended_by_name'] ?? 'System') : ($record['deleted_by_name'] ?? 'System'),
        'department' => $record['department_name'] ?? 'Unassigned',
        'status' => $isSuspended ? 'Suspended' : 'Deleted',
        'date' => $isSuspended ? $record['suspended_at'] : $record['deleted_at'],
        'reason' => $isSuspended ? ($record['suspended_reason'] ?? 'No reason provided') : ($record['deleted_reason'] ?? 'No reason provided'),
        'id' => (int) $record['id'],
        'action' => $isSuspended ? 'resume' : 'restore',
    ];
}
foreach ($trashedAnnouncements as $announcement) {
    $trashItems[] = ['type' => 'Announcement', 'name' => $announcement['title'], 'description' => mb_strimwidth($announcement['content'], 0, 100, '...'), 'owner' => $announcement['poster_name'] ?? 'System', 'department' => '—', 'status' => 'Deleted', 'date' => $announcement['deleted_at'], 'reason' => $announcement['deleted_reason'] ?? 'No reason provided', 'id' => (int) $announcement['id'], 'action' => 'restore_announcement'];
}
foreach ($trashedServices as $service) {
    $trashItems[] = ['type' => 'Activity', 'name' => $service['service_name'], 'description' => '', 'owner' => $service['deleted_by_name'] ?? 'System', 'department' => $service['department_name'] ?? '—', 'status' => 'Deleted', 'date' => $service['deleted_at'], 'reason' => $service['deleted_reason'] ?? 'No reason provided', 'id' => (int) $service['id'], 'action' => 'restore_service'];
}
foreach ($trashedDepartments as $department) {
    $trashItems[] = ['type' => 'Department', 'name' => $department['name'], 'description' => $department['department_code'] ?? '', 'owner' => $department['deleted_by_name'] ?? 'System', 'department' => '—', 'status' => 'Deleted', 'date' => $department['deleted_at'], 'reason' => $department['deleted_reason'] ?? 'No reason provided', 'id' => (int) $department['id'], 'action' => 'restore_department'];
}
foreach ($suspendedUsers as $suspendedUser) {
    $trashItems[] = ['type' => 'User', 'name' => $suspendedUser['name'], 'description' => $suspendedUser['email'], 'owner' => 'System', 'department' => $suspendedUser['department_name'] ?? 'Unassigned', 'status' => 'Suspended', 'date' => $suspendedUser['created_at'], 'reason' => 'Suspended account', 'id' => (int) $suspendedUser['id'], 'action' => 'unsuspend_user'];
}

require_once APP_ROOT . '/includes/header.php';
?>

<div class="admin-module-page trash-page">
    <?php if (!empty($_SESSION['success'])): ?>
        <div class="admin-alert admin-alert-success"><?= htmlspecialchars((string) $_SESSION['success']) ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="admin-alert admin-alert-error"><?= htmlspecialchars((string) $_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <!-- Header Title Section -->
    <div class="admin-module-heading" style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.25rem;">
        <div>
            <span class="admin-eyebrow" style="color: #ef4444; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">🗑️ RECOVERABLE RECORDS STORAGE</span>
            <h2 style="margin: 0.2rem 0 0.4rem 0; font-size: 1.6rem; color: #0f172a;"><?= $isDepartmentScopedUser ? 'Department Trash Bin' : 'Trash Bin' ?></h2>
            <p style="margin: 0; color: #64748b; font-size: 0.9rem;"><?= $isDepartmentScopedUser ? 'Deleted and suspended record activities from your department are stored here for recovery.' : 'Soft-deleted and suspended document record activities are safely stored here for recovery or permanent deletion.' ?></p>
        </div>
    </div>

    <!-- Summary Stats Grid -->
    <div class="trash-summary-grid">
        <div class="trash-summary-card total">
            <span>Total Items in Trash</span>
            <strong><?= $totalCount ?></strong>
        </div>
        <div class="trash-summary-card deleted">
            <span>Deleted Documents</span>
            <strong><?= $deletedCount ?></strong>
        </div>
        <div class="trash-summary-card suspended">
            <span>Suspended Documents</span>
            <strong><?= $suspendedCount ?></strong>
        </div>
        <?php if ($canManageUsers): ?>
            <div class="trash-summary-card suspended">
                <span>Suspended Users</span>
                <strong><?= count($suspendedUsers) ?></strong>
            </div>
        <?php endif; ?>
        <?php if ($canManageDepartments): ?>
            <div class="trash-summary-card offices">
                <span>Affected Offices</span>
                <strong><?= count($departmentsAffected) ?></strong>
            </div>
        <?php endif; ?>
    </div>

    <!-- Search & Filter Toolbar -->
    <form class="admin-filter-bar" method="GET" style="display:none;">
        <div style="flex: 1; min-width: 220px; position: relative;">
            <input name="search" value="<?= htmlspecialchars($search) ?>" placeholder="🔍 Search tracking number, title, client, or reason..." class="form-control" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem;">
        </div>
        <div style="min-width: 160px;">
            <select name="status" class="form-control" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem; background: #fff;">
                <option value="">All Status Types</option>
                <option value="deleted" <?= $statusFilter === 'deleted' ? 'selected' : '' ?>>Deleted Only</option>
                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended Only</option>
            </select>
        </div>
        <button type="submit" class="admin-primary-button" style="padding: 0.5rem 1.2rem; background: #0f172a; color: #fff; border: none; border-radius: 6px; font-weight: 600; font-size: 0.88rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem;">
            🔍 Filter Results
        </button>
        <?php if (!empty($search) || !empty($statusFilter)): ?>
            <a href="trash_bin.php" style="color: #64748b; font-size: 0.85rem; text-decoration: underline; font-weight: 500;">Reset Filters</a>
        <?php endif; ?>
    </form>

    <section class="admin-panel trash-unified-table" style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;overflow:hidden;">
        <div class="admin-table-wrap">
            <table class="admin-table" data-datatable-responsive="false" data-datatable-search="true" data-datatable-paging="true" data-datatable-ordering="true" data-datatable-info="true">
                <thead><tr><th style="width: 80px;">Type</th><th>Record</th><th>Department</th><th style="width: 90px;">Status</th><th>Action By</th><th>Date &amp; Reason</th><th class="dt-actions dt-center" style="width: 80px;">Actions</th></tr></thead>
                <tbody>
                <?php if (empty($trashItems)): ?>
                    <tr><td colspan="7" class="admin-empty-state">Trash Bin is empty.</td></tr>
                <?php else: foreach ($trashItems as $item): ?>
                    <tr>
                        <td><span class="badge badge-priority"><?= htmlspecialchars($item['type']) ?></span></td>
                        <td><strong><?= htmlspecialchars($item['name']) ?></strong><br><small><?= htmlspecialchars($item['description']) ?></small></td>
                        <td><?= htmlspecialchars($item['department']) ?></td>
                        <td><span class="badge <?= $item['status'] === 'Suspended' ? 'badge-processing' : 'badge-rejected' ?>"><?= htmlspecialchars($item['status']) ?></span></td>
                        <td><?= htmlspecialchars($item['owner']) ?></td>
                        <td><?= $item['date'] ? date('m/d/Y h:i A', strtotime($item['date'])) : '—' ?><br><small><?= htmlspecialchars($item['reason']) ?></small></td>
                        <td class="dt-actions dt-center">
                            <div class="action-menu-shell">
                                <button type="button" class="action-more-toggle" aria-label="More actions" title="More actions" aria-expanded="false">
                                    <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <div class="action-menu-panel">
                                    <?php if ($item['type'] === 'Document'): ?>
                                        <?php if (($item['action'] === 'resume' && hasPermission('override_workflow', $user)) || ($item['action'] === 'restore' && hasPermission('manage_reports', $user))): ?>
                                        <form method="POST" style="margin: 0;">
                                            <input type="hidden" name="report_id" value="<?= $item['id'] ?>">
                                            <input type="hidden" name="trash_action" value="<?= $item['action'] ?>">
                                            <button type="submit" class="action-menu-item" style="width: 100%;" aria-label="<?= $item['action'] === 'resume' ? 'Resume record' : 'Restore record' ?>">
                                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                                                <?= $item['action'] === 'resume' ? 'Resume' : 'Restore' ?>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($canPermanentlyDelete): ?>
                                            <form method="POST" style="margin: 0;" data-trash-confirm="delete">
                                                <input type="hidden" name="report_id" value="<?= $item['id'] ?>">
                                                <input type="hidden" name="reason" value="Permanently deleted from Trash Bin">
                                                <input type="hidden" name="trash_action" value="permanent_delete">
                                                <button type="submit" class="action-menu-item" style="color: #dc2626; width: 100%;" aria-label="Delete permanently">
                                                    <i class="bi bi-trash3" aria-hidden="true"></i>
                                                    Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php elseif ($item['type'] === 'User'): ?>
                                        <form method="POST" style="margin: 0;">
                                            <input type="hidden" name="user_action" value="unsuspend_user">
                                            <input type="hidden" name="user_id" value="<?= $item['id'] ?>">
                                            <button type="submit" class="action-menu-item" style="width: 100%;" aria-label="Unsuspend user">
                                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                                                Unsuspend
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="margin: 0;">
                                            <input type="hidden" name="<?= $item['type'] === 'Announcement' ? 'announcement_action' : ($item['type'] === 'Activity' ? 'service_action' : 'department_action') ?>" value="<?= $item['action'] ?>">
                                            <input type="hidden" name="<?= $item['type'] === 'Announcement' ? 'announcement_id' : ($item['type'] === 'Activity' ? 'service_id' : 'department_id') ?>" value="<?= $item['id'] ?>">
                                            <button type="submit" class="action-menu-item" style="width: 100%;" aria-label="Restore item">
                                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                                                Restore
                                            </button>
                                        </form>
                                        <?php if (($item['type'] === 'Announcement' && $canManageAnnouncements) || ($item['type'] === 'Activity' && $canManageServices) || ($item['type'] === 'Department' && $canManageDepartments)): ?>
                                            <form method="POST" style="margin: 0;">
                                                <input type="hidden" name="<?= $item['type'] === 'Announcement' ? 'announcement_action' : ($item['type'] === 'Activity' ? 'service_action' : 'department_action') ?>" value="<?= $item['type'] === 'Announcement' ? 'permanent_delete_announcement' : ($item['type'] === 'Activity' ? 'permanent_delete_service' : 'permanent_delete_department') ?>">
                                                <input type="hidden" name="<?= $item['type'] === 'Announcement' ? 'announcement_id' : ($item['type'] === 'Activity' ? 'service_id' : 'department_id') ?>" value="<?= $item['id'] ?>">
                                                <button type="submit" class="action-menu-item" style="color: #dc2626; width: 100%;" aria-label="Delete permanently">
                                                    <i class="bi bi-trash3" aria-hidden="true"></i>
                                                    Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <style>.trash-page > section:not(.trash-unified-table) { display:none !important; }</style>

    <!-- Recoverable Records Table Panel -->
    <section class="admin-panel" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div class="admin-table-wrap" style="overflow-x: auto;">
            <table class="admin-table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="background: #e2e8f0; border-bottom: 2px solid #cbd5e1;">
                        <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; color: #1e293b; font-weight: 700; font-size: 0.76rem; text-transform: uppercase;">Document Tracking</th>
                        <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; color: #1e293b; font-weight: 700; font-size: 0.76rem; text-transform: uppercase;">Client / Originator</th>
                        <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; color: #1e293b; font-weight: 700; font-size: 0.76rem; text-transform: uppercase;">Department Office</th>
                        <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; color: #1e293b; font-weight: 700; font-size: 0.76rem; text-transform: uppercase; text-align: center;">Status</th>
                        <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; color: #1e293b; font-weight: 700; font-size: 0.76rem; text-transform: uppercase;">Action By</th>
                        <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; color: #1e293b; font-weight: 700; font-size: 0.76rem; text-transform: uppercase;">Date & Reason</th>
                        <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; color: #1e293b; font-weight: 700; font-size: 0.76rem; text-transform: uppercase; text-align: center; width: 120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="7" style="padding: 3rem 1rem; text-align: center; background: #fafafa;">
                                <div style="max-width: 320px; margin: 0 auto;">
                                    <div style="font-size: 2.8rem; margin-bottom: 0.5rem; color: #94a3b8;">🗑️</div>
                                    <h4 style="margin: 0 0 0.25rem 0; color: #334155; font-size: 1.05rem;">Trash Bin is Empty</h4>
                                    <p style="margin: 0; color: #64748b; font-size: 0.85rem;">There are no deleted or suspended documents found in the system log.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): 
                            $isSuspended = (int) ($record['is_suspended'] ?? 0) === 1; 
                            $actionBy = $isSuspended ? ($record['suspended_by_name'] ?? 'System') : ($record['deleted_by_name'] ?? 'System');
                            $actionDate = $isSuspended ? $record['suspended_at'] : $record['deleted_at'];
                            $actionReason = $isSuspended ? ($record['suspended_reason'] ?? 'No reason provided') : ($record['deleted_reason'] ?? 'No reason provided');
                        ?>
                            <tr style="border-bottom: 1px solid #cbd5e1;">
                                <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1; vertical-align: middle;">
                                    <a href="report_details.php?id=<?= (int) $record['id'] ?>" style="font-weight: 700; color: #2563eb; text-decoration: none;">
                                        <?= htmlspecialchars($record['tracking_number']) ?>
                                    </a>
                                    <div style="color: #475569; font-size: 0.8rem; margin-top: 0.15rem; font-weight: 500;">
                                        <?= htmlspecialchars($record['title']) ?>
                                    </div>
                                </td>
                                <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1; vertical-align: middle; color: #334155;">
                                    <?= htmlspecialchars($record['client_name'] ?? '—') ?>
                                </td>
                                <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1; vertical-align: middle; color: #334155;">
                                    <span style="display: inline-block; background: #f1f5f9; padding: 0.2rem 0.45rem; border-radius: 4px; border: 1px solid #e2e8f0; font-size: 0.8rem; font-weight: 500;">
                                        <?= htmlspecialchars($record['department_name'] ?? 'Unassigned') ?>
                                    </span>
                                </td>
                                <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1; vertical-align: middle; text-align: center;">
                                    <?php if ($isSuspended): ?>
                                        <span style="display: inline-block; padding: 0.25rem 0.65rem; border-radius: 999px; background: #fff7ed; border: 1px solid #fed7aa; color: #ea580c; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.03em;">
                                            ⏸ SUSPENDED
                                        </span>
                                    <?php else: ?>
                                        <span style="display: inline-block; padding: 0.25rem 0.65rem; border-radius: 999px; background: #fef2f2; border: 1px solid #fca5a5; color: #dc2626; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.03em;">
                                            🗑 DELETED
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1; vertical-align: middle; color: #334155; font-weight: 500;">
                                    👤 <?= htmlspecialchars($actionBy) ?>
                                </td>
                                <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1; vertical-align: middle; color: #475569; font-size: 0.82rem;">
                                    <div><?= $actionDate ? date('m/d/Y h:i A', strtotime($actionDate)) : '—' ?></div>
                                    <?php if (!empty($actionReason)): ?>
                                        <div style="font-size: 0.76rem; color: #64748b; font-style: italic; margin-top: 0.15rem;">
                                            "<?= htmlspecialchars($actionReason) ?>"
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1; vertical-align: middle; text-align: center;">
                                    <div style="display: flex; gap: 0.35rem; justify-content: center; align-items: center;">
                                        <!-- View Button -->
                                        <a class="btn-action btn-action-view" title="View Details" href="report_details.php?id=<?= (int) $record['id'] ?>" aria-label="View Details"><i class="bi bi-eye-fill" aria-hidden="true"></i></a>
                                        
                                        <!-- Restore / Resume Button -->
                                        <?php if (($isSuspended && hasPermission('override_workflow', $user)) || (!$isSuspended && hasPermission('manage_reports', $user))): ?>
                                        <form method="POST" style="display: inline; margin: 0;" data-confirmation-action="restore">
                                            <input type="hidden" name="report_id" value="<?= (int) $record['id'] ?>">
                                            <input type="hidden" name="reason" value="Restored from Trash Bin by Admin">
                                            <input type="hidden" name="trash_action" value="<?= $isSuspended ? 'resume' : 'restore' ?>">
                                            <button class="btn-action btn-action-approve" title="<?= $isSuspended ? 'Resume Record' : 'Restore Record' ?>" type="submit" aria-label="Restore Record">✓</button>
                                        </form>
                                        <?php endif; ?>

                                        <?php if ($canPermanentlyDelete): ?>
                                            <form method="POST" style="display: inline; margin: 0;" data-trash-confirm="delete">
                                                <input type="hidden" name="report_id" value="<?= (int) $record['id'] ?>">
                                                <input type="hidden" name="reason" value="Permanently deleted by Admin">
                                                <input type="hidden" name="trash_action" value="permanent_delete">
                                                <button class="btn-action btn-action-delete" title="Delete Permanently" type="submit" aria-label="Delete Permanently">                                                <i class="bi bi-trash3" aria-hidden="true"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($canManageAnnouncements): ?>
    <section class="admin-panel trash-announcements-panel" style="margin-top: 1.25rem;">
        <div style="padding: 1rem 1.1rem; border-bottom: 1px solid #cbd5e1;">
            <h3 style="margin: 0 0 0.25rem; color: #0f172a;">Deleted Announcements</h3>
            <p style="margin: 0; color: #64748b; font-size: 0.85rem;">Restore announcements or permanently remove them from the system.</p>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table trash-announcements-table">
                <thead>
                    <tr>
                        <th>Announcement</th>
                        <th>Category</th>
                        <th>Posted By</th>
                        <th>Date / Reason</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trashedAnnouncements)): ?>
                        <tr><td colspan="5" class="admin-empty-state">No deleted announcements found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($trashedAnnouncements as $announcement): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($announcement['title']) ?></strong><br><small><?= htmlspecialchars(mb_strimwidth($announcement['content'], 0, 100, '...')) ?></small></td>
                                <td><?= htmlspecialchars($announcement['category'] ?? 'General Announcement') ?></td>
                                <td><?= htmlspecialchars($announcement['poster_name'] ?? 'System') ?></td>
                                <td><?= htmlspecialchars($announcement['deleted_at'] ?? '—') ?><br><small><?= htmlspecialchars($announcement['deleted_reason'] ?? 'No reason provided') ?></small></td>
                                <td>
                                    <form method="POST" style="display: inline;" data-confirmation-action="restore">
                                        <input type="hidden" name="announcement_action" value="restore_announcement">
                                        <input type="hidden" name="announcement_id" value="<?= (int) $announcement['id'] ?>">
                                        <button type="submit" class="btn-action btn-action-approve" title="Restore Announcement" aria-label="Restore Announcement">↶</button>
                                    </form>
                                    <form method="POST" style="display: inline;" data-trash-confirm="delete">
                                        <input type="hidden" name="announcement_action" value="permanent_delete_announcement">
                                        <input type="hidden" name="announcement_id" value="<?= (int) $announcement['id'] ?>">
                                        <button type="submit" class="btn-action btn-action-danger" title="Permanently Delete Announcement" aria-label="Permanently Delete Announcement">✕</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($canManageServices): ?>
    <section class="admin-panel" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-top: 1.25rem;">
        <div style="padding: 1rem 1.1rem; border-bottom: 1px solid #cbd5e1;">
            <h3 style="margin: 0 0 0.25rem; color: #0f172a;">Deleted Department Activities</h3>
            <p style="margin: 0; color: #64748b; font-size: 0.85rem;">Restore or permanently delete department activities moved to Trash Bin.</p>
        </div>
        <div class="admin-table-wrap" style="overflow-x: auto;">
            <table class="admin-table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="background: #e2e8f0; border-bottom: 2px solid #cbd5e1;">
                        <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: left;">Activity</th>
                        <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: left;">Department</th>
                        <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: left;">Deleted By</th>
                        <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: left;">Date / Reason</th>
                        <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trashedServices)): ?>
                        <tr><td colspan="5" style="padding: 1.5rem; text-align: center; color: #64748b;">No deleted department activities found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($trashedServices as $service): ?>
                            <tr style="border-bottom: 1px solid #cbd5e1;">
                                <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1;"><strong><?= htmlspecialchars($service['service_name']) ?></strong></td>
                                <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1;"><?= htmlspecialchars($service['department_name'] ?? '—') ?></td>
                                <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1;"><?= htmlspecialchars($service['deleted_by_name'] ?? 'System') ?></td>
                                <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1;"><?= htmlspecialchars($service['deleted_at'] ?? '—') ?><br><small><?= htmlspecialchars($service['deleted_reason'] ?? 'No reason provided') ?></small></td>
                                <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1; text-align: center;">
                                    <form method="POST" style="display: inline;" data-confirmation-action="restore">
                                        <input type="hidden" name="service_action" value="restore_service">
                                        <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
                                        <button type="submit" class="btn-action btn-action-approve" title="Restore Activity" aria-label="Restore Activity">↶</button>
                                    </form>
                                    <form method="POST" style="display: inline;" data-trash-confirm="delete">
                                        <input type="hidden" name="service_action" value="permanent_delete_service">
                                        <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
                                        <button type="submit" class="btn-action btn-action-danger" title="Permanently Delete Activity" aria-label="Permanently Delete Activity">✕</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($canManageDepartments): ?>
        <section class="admin-panel" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-top: 1.25rem;">
            <div style="padding: 1rem 1.1rem; border-bottom: 1px solid #cbd5e1;">
                <h3 style="margin: 0 0 0.25rem; color: #0f172a;">Deleted Departments</h3>
                <p style="margin: 0; color: #64748b; font-size: 0.85rem;">Restore a department or permanently delete it if it has no linked records.</p>
            </div>
            <div class="admin-table-wrap" style="overflow-x: auto;">
                <table class="admin-table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                    <thead>
                        <tr style="background: #e2e8f0; border-bottom: 2px solid #cbd5e1;">
                            <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: left;">Department</th>
                            <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: left;">Code</th>
                            <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: left;">Deleted By</th>
                            <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: left;">Date / Reason</th>
                            <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($trashedDepartments)): ?>
                            <tr><td colspan="5" style="padding: 1.5rem; text-align: center; color: #64748b;">No deleted departments found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($trashedDepartments as $department): ?>
                                <tr style="border-bottom: 1px solid #cbd5e1;">
                                    <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1;"><strong><?= htmlspecialchars($department['name']) ?></strong><br><small><?= htmlspecialchars($department['official_email'] ?? '—') ?></small></td>
                                    <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1;"><?= htmlspecialchars($department['department_code'] ?? '—') ?></td>
                                    <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1;"><?= htmlspecialchars($department['deleted_by_name'] ?? 'System') ?></td>
                                    <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1;"><?= htmlspecialchars($department['deleted_at'] ?? '—') ?><br><small><?= htmlspecialchars($department['deleted_reason'] ?? 'No reason provided') ?></small></td>
                                    <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1; text-align: center;">
                                        <form method="POST" style="display: inline;" data-confirmation-action="restore">
                                            <input type="hidden" name="department_action" value="restore_department">
                                            <input type="hidden" name="department_id" value="<?= (int) $department['id'] ?>">
                                            <button type="submit" class="btn-action btn-action-approve" title="Restore Department" aria-label="Restore Department">↶</button>
                                        </form>
                                        <form method="POST" style="display: inline;" data-trash-confirm="delete">
                                            <input type="hidden" name="department_action" value="permanent_delete_department">
                                            <input type="hidden" name="department_id" value="<?= (int) $department['id'] ?>">
                                            <button type="submit" class="btn-action btn-action-danger" title="Permanently Delete Department" aria-label="Permanently Delete Department">✕</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($canManageUsers): ?>
        <section class="admin-panel" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-top: 1.25rem;">
            <div style="padding: 1rem 1.1rem; border-bottom: 1px solid #cbd5e1;">
                <h3 style="margin: 0 0 0.25rem; color: #0f172a;">Suspended User Accounts</h3>
                <p style="margin: 0; color: #64748b; font-size: 0.85rem;">Unsuspend an account here to restore its access to the system.</p>
            </div>
            <div class="admin-table-wrap" style="overflow-x: auto;">
                <table class="admin-table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                    <thead>
                        <tr style="background: #e2e8f0; border-bottom: 2px solid #cbd5e1;">
                            <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: left;">Name</th>
                            <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: left;">Email</th>
                            <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: left;">Role</th>
                            <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: left;">Department</th>
                            <th style="padding: 0.75rem 0.8rem; border: 1px solid #cbd5e1; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($suspendedUsers)): ?>
                            <tr><td colspan="5" style="padding: 1.5rem; text-align: center; color: #64748b;">No suspended user accounts found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($suspendedUsers as $suspendedUser): ?>
                                <tr style="border-bottom: 1px solid #cbd5e1;">
                                    <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1;"><?= htmlspecialchars($suspendedUser['name']) ?></td>
                                    <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1;"><?= htmlspecialchars($suspendedUser['email']) ?></td>
                                    <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1;"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $suspendedUser['role']))) ?></td>
                                    <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1;"><?= htmlspecialchars($suspendedUser['department_name'] ?? 'Unassigned') ?></td>
                                    <td style="padding: 0.65rem 0.8rem; border: 1px solid #cbd5e1; text-align: center;">
                                        <form method="POST" style="display: inline;" data-confirmation-action="restore">
                                            <input type="hidden" name="user_action" value="<?= !empty($suspendedUser['deleted_at']) ? 'restore_user' : 'unsuspend_user' ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $suspendedUser['id'] ?>">
                                            <button type="submit" class="btn-action btn-action-approve" title="Unsuspend User" aria-label="Unsuspend User">✓</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<div id="trash-confirm-modal" hidden style="position:fixed; inset:0; z-index:2000; display:flex; align-items:center; justify-content:center; padding:1rem; background:rgba(15,23,42,.55);">
    <div role="dialog" aria-modal="true" aria-labelledby="trash-confirm-title" style="width:min(100%,420px); border-radius:14px; background:#fff; padding:1.5rem; box-shadow:0 20px 50px rgba(15,23,42,.25);">
        <h3 id="trash-confirm-title" style="margin:0 0 .75rem; font-size:1.2rem;">Delete?</h3>
        <p id="trash-confirm-message" style="margin:0 0 1rem; color:#475569;">Are you sure you want to delete this item?</p>
        <div style="display:flex; justify-content:flex-end; gap:.5rem;">
            <button type="button" class="btn btn-light" data-trash-cancel>Cancel</button>
            <button type="button" class="btn btn-danger" data-trash-submit>Delete</button>
        </div>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('trash-confirm-modal');
    let pendingForm = null;
    if (!modal) return;
    const title = document.getElementById('trash-confirm-title');
    const message = document.getElementById('trash-confirm-message');
    const submit = modal.querySelector('[data-trash-submit]');
    const close = () => { modal.hidden = true; pendingForm = null; };

    document.querySelectorAll('[data-trash-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            pendingForm = form;
            const isDelete = form.dataset.trashConfirm === 'delete';
            title.textContent = isDelete ? 'Delete?' : 'Restore?';
            message.textContent = isDelete
                ? 'Are you sure you want to delete this item?'
                : 'Are you sure you want to restore this item?';
            submit.textContent = isDelete ? 'Delete' : 'Restore';
            submit.className = isDelete ? 'btn btn-danger' : 'btn btn-primary';
            modal.hidden = false;
        });
    });

    modal.querySelector('[data-trash-cancel]').addEventListener('click', close);
    submit.addEventListener('click', () => {
        if (pendingForm) {
            pendingForm.removeAttribute('data-trash-confirm');
            pendingForm.submit();
        }
    });
    modal.addEventListener('click', (event) => { if (event.target === modal) close(); });
})();
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>