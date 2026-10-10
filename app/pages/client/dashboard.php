<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/privacy_guard.php';
require_once APP_ROOT . '/includes/notifications.php';
require_once APP_ROOT . '/includes/announcements_helper.php';

requireLogin();
$user = currentUser();
if (!hasPermission('view_dashboard', $user)) {
    header('Location: documents.php?error=unauthorized');
    exit();
}
$isSuperAdmin = isSuperAdmin($user);
$userDeptId = (int)($user['department_id'] ?? 0);
$isPersonalScope = isClientUser($user) || (!$isSuperAdmin && $userDeptId <= 0);
$canCreateReports = hasPermission('create_reports', $user);
$canViewDocuments = hasPermission('view_documents', $user);

$notifications = $userDeptId ? getDepartmentNotifications($pdo, $userDeptId) : [];
$announcements = getActiveAnnouncements($user);

if ($isSuperAdmin) {
    $stmt = $pdo->query("SELECT r.id, r.tracking_number, r.title, r.status, r.priority, r.created_at, r.department_id, r.sender_department_id,
                        d_recv.name as receiving_dept_name, d_send.name as sending_dept_name
                        FROM reports r
                        LEFT JOIN departments d_recv ON r.department_id = d_recv.id
                        LEFT JOIN departments d_send ON r.sender_department_id = d_send.id
                        WHERE COALESCE(r.is_deleted, 0) = 0 AND COALESCE(r.is_suspended, 0) = 0
                        ORDER BY r.created_at DESC");
    $reports = $stmt->fetchAll();
} elseif ($isPersonalScope) {
    $stmt = $pdo->prepare("SELECT r.*, u.name as client_name, d_recv.name as receiving_dept_name, d_send.name as sending_dept_name
                          FROM reports r
                          LEFT JOIN users u ON r.client_id = u.id
                          LEFT JOIN departments d_recv ON r.department_id = d_recv.id
                          LEFT JOIN departments d_send ON r.sender_department_id = d_send.id
                          WHERE r.client_id = :client_id
                            AND COALESCE(r.is_deleted, 0) = 0 AND COALESCE(r.is_suspended, 0) = 0
                          ORDER BY r.created_at DESC");
    $stmt->execute(['client_id' => $user['id']]);
    $reports = $stmt->fetchAll();
} else {
    $canViewPrivateDepartmentDocuments = isDepartmentAdmin($user)
        || hasPermission('override_department_privacy', $user);
    $privateFilter = $canViewPrivateDepartmentDocuments
        ? ''
        : ' AND (COALESCE(r.department_private, 0) = 0 OR r.submitted_by_user_id = :staff_submitter_id)';
    $stmt = $pdo->prepare("SELECT r.*, u.name as client_name, d_recv.name as receiving_dept_name, d_send.name as sending_dept_name
                          FROM reports r
                          LEFT JOIN users u ON r.client_id = u.id
                          LEFT JOIN departments d_recv ON r.department_id = d_recv.id
                          LEFT JOIN departments d_send ON r.sender_department_id = d_send.id
                          WHERE (r.department_id = :d1 OR r.assigned_department_id = :d2 OR r.sender_department_id = :d3)
                            AND COALESCE(r.is_deleted, 0) = 0 AND COALESCE(r.is_suspended, 0) = 0{$privateFilter}
                          ORDER BY r.created_at DESC");
    $dashboardParams = ['d1' => $userDeptId, 'd2' => $userDeptId, 'd3' => $userDeptId];
    if (!$canViewPrivateDepartmentDocuments) {
        $dashboardParams['staff_submitter_id'] = (int) $user['id'];
    }
    $stmt->execute($dashboardParams);
    $reports = $stmt->fetchAll();
}

$countTotal = count($reports);
$countPending = count(array_filter($reports, fn($r) => ($r['status'] ?? '') === 'Pending'));
$countReceived = count(array_filter($reports, fn($r) => in_array($r['status'] ?? '', ['Assigned', 'Under Review'], true)));
$countProcessing = count(array_filter($reports, fn($r) => ($r['status'] ?? '') === 'Processing'));
$countCompleted = count(array_filter($reports, fn($r) => in_array($r['status'] ?? '', ['Completed', 'Approved'], true)));
$countReturned = count(array_filter($reports, fn($r) => in_array($r['status'] ?? '', ['Returned', 'Rejected'], true)));

require_once APP_ROOT . '/includes/header.php';

if ($isPersonalScope) {
    $pendingReviewCount = count(array_filter($reports, fn($r) => in_array($r['status'] ?? '', ['Pending', 'Under Review', 'Assigned'], true)));
    $inProgressCount = count(array_filter($reports, fn($r) => ($r['status'] ?? '') === 'Processing'));
    $completedCount = count(array_filter($reports, fn($r) => in_array($r['status'] ?? '', ['Completed', 'Approved'], true)));
    $rejectedCount = count(array_filter($reports, fn($r) => in_array($r['status'] ?? '', ['Rejected', 'Returned'], true)));
    $totalClientDocs = max(1, count($reports));
    $pendingReviewPct = round(($pendingReviewCount / $totalClientDocs) * 100, 1);
    $inProgressPct = round(($inProgressCount / $totalClientDocs) * 100, 1);
    $completedPct = round(($completedCount / $totalClientDocs) * 100, 1);
    $rejectedPct = round(($rejectedCount / $totalClientDocs) * 100, 1);
    ?>

    <div class="citizen-dashboard">
        <div class="citizen-header-row">
            <h2>Dashboard</h2>
        </div>

        <section class="client-help-panel dashboard-quick-actions" aria-label="Dashboard actions">
            <span class="client-help-kicker">Quick Guide</span>
            <div class="dashboard-guide-heading">
                <h3>Need Help Using The Portal?</h3>
                <button type="button" class="icon-circle-button primary" title="Open Quick Guide" aria-label="Open Quick Guide" data-dashboard-modal-open="client-guide-modal"><i class="bi bi-info-lg" aria-hidden="true"></i></button>
                <?php if ($canCreateReports): ?>
                    <a class="icon-circle-button primary dashboard-add-document-link add-create-control" href="submit_report.php" title="Add a Document" aria-label="Add A Document"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add A Document</a>
                <?php endif; ?>
            </div>
        </section>

        <div class="dashboard-modal-backdrop" id="client-guide-modal" hidden>
            <section class="dashboard-modal" role="dialog" aria-modal="true" aria-labelledby="client-help-title">
                <div class="dashboard-modal-header">
                    <div><span class="client-help-kicker">Quick Guide</span><h3 id="client-help-title">How To Use The Client Portal</h3></div>
                    <button type="button" class="icon-circle-button secondary" title="Close" aria-label="Close" data-dashboard-modal-close><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <p>Submit a request and track its progress online from start to finish.</p>
                <ol class="client-help-steps">
                    <li><span class="client-help-step-number">1</span><strong>Submit A Request</strong><span>Choose the department and service you need.</span></li>
                    <li><span class="client-help-step-number">2</span><strong>Provide The Details</strong><span>Enter the title, purpose, and requested information.</span></li>
                    <li><span class="client-help-step-number">3</span><strong>Save Your Tracking Number</strong><span>Use it to find your document anytime.</span></li>
                    <li><span class="client-help-step-number">4</span><strong>Track Your Request</strong><span>Review its status, remarks, and activity history.</span></li>
                </ol>
            </section>
        </div>

        <div class="citizen-stats-grid">
            <?php if ($canViewDocuments): ?><a class="citizen-stat-card dashboard-document-filter-card" href="documents.php?status=pending_review" aria-label="My Pending Review: open matching documents"><?php else: ?><div class="citizen-stat-card"><?php endif; ?>
                <div class="citizen-stat-icon orange"><span>◔</span></div>
                <div class="citizen-stat-body">
                    <div class="citizen-stat-label">My Pending Review</div>
                    <div class="citizen-stat-value"><?= $pendingReviewCount ?></div>
                    <div class="citizen-stat-sub">Documents</div>
                </div>
            <?= $canViewDocuments ? '</a>' : '</div>' ?>

            <?php if ($canViewDocuments): ?><a class="citizen-stat-card dashboard-document-filter-card" href="documents.php?status=in_progress" aria-label="My In Progress: open matching documents"><?php else: ?><div class="citizen-stat-card"><?php endif; ?>
                <div class="citizen-stat-icon amber"><span>◷</span></div>
                <div class="citizen-stat-body">
                    <div class="citizen-stat-label">My In Progress</div>
                    <div class="citizen-stat-value"><?= $inProgressCount ?></div>
                    <div class="citizen-stat-sub">Documents</div>
                </div>
            <?= $canViewDocuments ? '</a>' : '</div>' ?>

            <?php if ($canViewDocuments): ?><a class="citizen-stat-card dashboard-document-filter-card" href="documents.php?status=completed" aria-label="My Completed Documents: open matching documents"><?php else: ?><div class="citizen-stat-card"><?php endif; ?>
                <div class="citizen-stat-icon teal"><span>✓</span></div>
                <div class="citizen-stat-body">
                    <div class="citizen-stat-label">My Completed Docs</div>
                    <div class="citizen-stat-value"><?= $completedCount ?></div>
                    <div class="citizen-stat-sub">Documents</div>
                </div>
            <?= $canViewDocuments ? '</a>' : '</div>' ?>

            <?php if ($canViewDocuments): ?><a class="citizen-stat-card dashboard-document-filter-card" href="documents.php?status=rejected" aria-label="My Rejected Documents: open matching documents"><?php else: ?><div class="citizen-stat-card"><?php endif; ?>
                <div class="citizen-stat-icon red"><span>✕</span></div>
                <div class="citizen-stat-body">
                    <div class="citizen-stat-label">My Rejected</div>
                    <div class="citizen-stat-value"><?= $rejectedCount ?></div>
                    <div class="citizen-stat-sub">Documents</div>
                </div>
            <?= $canViewDocuments ? '</a>' : '</div>' ?>

            <?php if ($canViewDocuments): ?><a class="citizen-stat-card dashboard-manage-documents-card" href="documents.php" aria-label="Manage Documents: open Documents page"><?php else: ?><div class="citizen-stat-card dashboard-manage-documents-card"><?php endif; ?>
                <div class="citizen-stat-icon blue"><i class="bi bi-folder2-open" aria-hidden="true"></i></div>
                <div class="citizen-stat-body">
                    <div class="citizen-stat-label">Manage Documents</div>
                    <div class="citizen-stat-value"><?= $countTotal ?></div>
                    <div class="citizen-stat-sub"><?= $canViewDocuments ? 'Open document registry' : 'Documents on dashboard' ?></div>
                </div>
            <?= $canViewDocuments ? '</a>' : '</div>' ?>
        </div>

        <div class="citizen-main-grid">
            <div class="citizen-card portfolio-card">
                <h3>My Document Portfolio</h3>
                <div class="portfolio-layout">
                    <div class="portfolio-donut">
                        <div class="portfolio-inner">
                            <div class="portfolio-total"><?= count($reports) ?: 0 ?></div>
                            <div class="portfolio-caption">Documents</div>
                        </div>
                    </div>

                    <div class="portfolio-legend">
                        <div class="legend-row"><span class="legend-swatch green"></span><span class="legend-label">My Submitted</span><strong><?= $pendingReviewCount ?> (<?= $pendingReviewPct ?>%)</strong></div>
                        <div class="legend-row"><span class="legend-swatch orange"></span><span class="legend-label">My In Review</span><strong><?= $inProgressCount ?> (<?= $inProgressPct ?>%)</strong></div>
                        <div class="legend-row"><span class="legend-swatch teal"></span><span class="legend-label">My Completed</span><strong><?= $completedCount ?> (<?= $completedPct ?>%)</strong></div>
                        <div class="legend-row"><span class="legend-swatch red"></span><span class="legend-label">Rejected</span><strong><?= $rejectedCount ?> (<?= $rejectedPct ?>%)</strong></div>
                    </div>
                </div>
            </div>

        </div>

        <div class="citizen-bottom-grid client-documents-grid">
            <div class="citizen-card recent-card">
                <h3>Recent Documents</h3>
                <table class="citizen-table">
                    <thead>
                        <tr>
                            <th>Unique ID</th>
                            <th>Document Title</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Date Submitted</th>
                            <?php if ($canViewDocuments): ?><th>Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reports)): ?>
                            <tr>
                                <td colspan="<?= $canViewDocuments ? 6 : 5 ?>" class="empty-state">No Records Found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (array_slice($reports, 0, 5) as $doc):
                                $trackingNo = $doc['tracking_number'];
                                $status = htmlspecialchars($doc['status'] ?? 'Pending');
                                $statusClass = 'status-pending';
                                if (in_array($doc['status'] ?? '', ['Under Review', 'Assigned'], true)) {
                                    $statusClass = 'status-review';
                                } elseif (($doc['status'] ?? '') === 'Processing') {
                                    $statusClass = 'status-progress';
                                } elseif (in_array($doc['status'] ?? '', ['Completed', 'Approved'], true)) {
                                    $statusClass = 'status-completed';
                                } elseif (in_array($doc['status'] ?? '', ['Rejected', 'Returned'], true)) {
                                    $statusClass = 'status-rejected';
                                }
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($trackingNo) ?></td>
                                    <td><?= htmlspecialchars($doc['title'] ?? 'Untitled Document') ?></td>
                                    <td><?= htmlspecialchars($doc['receiving_dept_name'] ?? 'Municipal Office') ?></td>
                                    <td><span class="status-pill <?= $statusClass ?>"><?= $status ?></span></td>
                                    <td><?= date('m/d/Y', strtotime($doc['created_at'])) ?></td>
                                    <?php if ($canViewDocuments): ?><td><a href="report_details.php?id=<?= (int)$doc['id'] ?>" class="icon-circle-button primary" title="View Document" aria-label="View Document"><i class="bi bi-eye-fill" aria-hidden="true"></i></a></td><?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
<?php } else { ?>
    <div class="department-dashboard<?= $isSuperAdmin ? ' super-admin-dashboard' : ' department-user-dashboard' ?>">
        <div class="department-header-row">
            <h2><?= $isSuperAdmin ? 'Super Administrator' : htmlspecialchars($user['department_name'] ?? 'Document Desk') ?></h2>
        </div>

        <div class="department-stats-grid">
            <?php if ($canViewDocuments): ?><a class="department-stat-card dashboard-document-filter-card" href="documents.php?status=received" aria-label="Received documents: open matching documents"><?php else: ?><div class="department-stat-card"><?php endif; ?>
                <div class="department-stat-icon blue">◫</div>
                <div class="department-stat-body">
                    <div class="department-stat-label">Received</div>
                    <div class="department-stat-value"><?= $countReceived ?></div>
                    <div class="department-stat-sub">Documents</div>
                </div>
            <?= $canViewDocuments ? '</a>' : '</div>' ?>

            <?php if ($canViewDocuments): ?><a class="department-stat-card dashboard-document-filter-card" href="documents.php?status=in_progress" aria-label="In Review documents: open matching documents"><?php else: ?><div class="department-stat-card"><?php endif; ?>
                <div class="department-stat-icon soft-blue">◌</div>
                <div class="department-stat-body">
                    <div class="department-stat-label">In Review</div>
                    <div class="department-stat-value"><?= $countProcessing ?></div>
                    <div class="department-stat-sub">Documents</div>
                </div>
            <?= $canViewDocuments ? '</a>' : '</div>' ?>

            <?php if ($canViewDocuments): ?><a class="department-stat-card dashboard-document-filter-card" href="documents.php?status=awaiting_action" aria-label="Awaiting Action documents: open matching documents"><?php else: ?><div class="department-stat-card"><?php endif; ?>
                <div class="department-stat-icon amber">◔</div>
                <div class="department-stat-body">
                    <div class="department-stat-label">Awaiting Action</div>
                    <div class="department-stat-value"><?= $countPending ?></div>
                    <div class="department-stat-sub">Documents</div>
                </div>
            <?= $canViewDocuments ? '</a>' : '</div>' ?>

            <?php if ($canViewDocuments): ?><a class="department-stat-card dashboard-document-filter-card" href="documents.php?status=approved" aria-label="Approved documents: open matching documents"><?php else: ?><div class="department-stat-card"><?php endif; ?>
                <div class="department-stat-icon green">✓</div>
                <div class="department-stat-body">
                    <div class="department-stat-label">Approved</div>
                    <div class="department-stat-value"><?= $countCompleted ?></div>
                    <div class="department-stat-sub">Documents</div>
                </div>
            <?= $canViewDocuments ? '</a>' : '</div>' ?>

            <?php if ($canViewDocuments): ?><a class="department-stat-card dashboard-document-filter-card" href="documents.php?status=declined" aria-label="Declined documents: open matching documents"><?php else: ?><div class="department-stat-card"><?php endif; ?>
                <div class="department-stat-icon red">✕</div>
                <div class="department-stat-body">
                    <div class="department-stat-label">Declined</div>
                    <div class="department-stat-value"><?= $countReturned ?></div>
                    <div class="department-stat-sub">Documents</div>
                </div>
            <?= $canViewDocuments ? '</a>' : '</div>' ?>

            <?php if ($canViewDocuments): ?><a class="department-stat-card dashboard-manage-documents-card" href="documents.php" aria-label="Manage Documents: open Documents page"><?php else: ?><div class="department-stat-card dashboard-manage-documents-card"><?php endif; ?>
                <div class="department-stat-icon blue"><i class="bi bi-folder2-open" aria-hidden="true"></i></div>
                <div class="department-stat-body">
                    <div class="department-stat-label">Manage Documents</div>
                    <div class="department-stat-value"><?= $countTotal ?></div>
                    <div class="department-stat-sub"><?= $canViewDocuments ? 'Open document registry' : 'Documents on dashboard' ?></div>
                </div>
            <?= $canViewDocuments ? '</a>' : '</div>' ?>
        </div>

        <div class="department-card donut-card">
            <h3>Document Status Overview</h3>
            <div class="dept-donut-wrap">
                <div class="dept-donut-chart">
                    <div class="dept-donut-inner">
                        <div class="dept-donut-total"><?= max(1, $countTotal) ?></div>
                        <div class="dept-donut-label">Documents</div>
                    </div>
                </div>

                <div class="dept-legend">
                    <div class="dept-legend-item"><span class="dept-swatch blue"></span><span>Submitted</span><strong><?= $countPending ?> (<?= $countTotal ? round(($countPending / $countTotal) * 100, 1) : 0 ?>%)</strong></div>
                    <div class="dept-legend-item"><span class="dept-swatch orange"></span><span>In Review</span><strong><?= $countProcessing ?> (<?= $countTotal ? round(($countProcessing / $countTotal) * 100, 1) : 0 ?>%)</strong></div>
                    <div class="dept-legend-item"><span class="dept-swatch green"></span><span>Approved</span><strong><?= $countCompleted ?> (<?= $countTotal ? round(($countCompleted / $countTotal) * 100, 1) : 0 ?>%)</strong></div>
                    <div class="dept-legend-item"><span class="dept-swatch red"></span><span>Rejected</span><strong><?= $countReturned ?> (<?= $countTotal ? round(($countReturned / $countTotal) * 100, 1) : 0 ?>%)</strong></div>
                </div>
            </div>
        </div>

        <div class="department-bottom-grid">
            <div class="department-card action-card">
                <h3>Papers Requiring Action</h3>
                <table class="department-table">
                    <thead>
                        <tr>
                            <th>Unique ID</th>
                            <th>Document Title</th>
                            <th>Origin</th>
                            <th>Reviewer</th>
                            <th>Due Date</th>
                            <?php if ($canViewDocuments): ?><th>Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reports)): ?>
                            <tr><td colspan="<?= $canViewDocuments ? 6 : 5 ?>" class="empty-department-state">No Records Found.</td></tr>
                        <?php else: ?>
                            <?php foreach (array_slice($reports, 0, 5) as $doc):
                                $trackingNo = $doc['tracking_number'];
                                $actionLabel = in_array($doc['status'] ?? '', ['Completed', 'Approved'], true) ? 'Approve & Forward' : 'Review';
                                $actionClass = in_array($doc['status'] ?? '', ['Completed', 'Approved'], true) ? 'approve-btn' : 'decline-btn';
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($trackingNo) ?></td>
                                    <td><?= htmlspecialchars($doc['title'] ?? 'Untitled Document') ?></td>
                                    <td><?= htmlspecialchars($doc['sending_dept_name'] ?? 'General Services') ?></td>
                                    <td><?= htmlspecialchars($user['name'] ?? 'Current Reviewer') ?></td>
                                    <td><?= date('m/d/Y', strtotime($doc['created_at'])) ?></td>
                                    <?php if ($canViewDocuments): ?><td>
                                        <a href="report_details.php?id=<?= (int)$doc['id'] ?>" class="table-action-btn <?= $actionClass ?>" title="<?= htmlspecialchars($actionLabel) ?>" aria-label="<?= htmlspecialchars($actionLabel) ?>">
                                            <i class="bi <?= $actionLabel === 'Approve & Forward' ? 'bi-check-lg' : 'bi-eye-fill' ?>" aria-hidden="true"></i>
                                        </a>
                                    </td><?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
<?php } ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-dashboard-modal-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            var modal = document.getElementById(button.dataset.dashboardModalOpen);
            if (modal) modal.hidden = false;
        });
    });
    document.querySelectorAll('[data-dashboard-modal-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            var modal = button.closest('.dashboard-modal-backdrop');
            if (modal) modal.hidden = true;
        });
    });
});
</script>
