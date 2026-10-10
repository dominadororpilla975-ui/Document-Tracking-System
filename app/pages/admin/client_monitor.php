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
requirePermission('view_documents', $user);

$stmt = $pdo->prepare("SELECT r.*, d_recv.name as receiving_dept_name, d_send.name as sending_dept_name 
                      FROM reports r
                      LEFT JOIN departments d_recv ON r.department_id = d_recv.id
                      LEFT JOIN departments d_send ON r.sender_department_id = d_send.id
                      WHERE r.client_id = :client_id
                        AND COALESCE(r.is_deleted, 0) = 0
                        AND COALESCE(r.is_suspended, 0) = 0
                      ORDER BY r.created_at DESC");
$stmt->execute(['client_id' => $user['id']]);
$reports = $stmt->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<div style="max-width: 1100px; margin: 0 auto;">
    <div style="margin-bottom: 1.5rem;">
        <h1 style="margin-bottom: 0.25rem;">Client Monitoring Portal</h1>
        <p style="color: #64748b; margin: 0;">Track your submitted documents and current workflow status.</p>
    </div>

    <?php if (empty($reports)): ?>
        <div class="card" style="text-align: center; padding: 2rem;">
            <h3 style="margin-bottom: 0.5rem;">No documents yet</h3>
            <p style="color: #64748b; margin-bottom: 1rem;">Your submitted requests will appear here once they are created.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <?php foreach ($reports as $report): ?>
                    <?php
                        $statusClass = 'badge-pending';
                        $st = strtolower(str_replace(' ', '-', $report['status']));
                        if ($st === 'assigned' || $st === 'under-review') $statusClass = 'badge-assigned';
                        elseif ($st === 'processing') $statusClass = 'badge-processing';
                        elseif ($st === 'approved' || $st === 'completed') $statusClass = 'badge-approved';
                        elseif ($st === 'returned' || $st === 'rejected') $statusClass = 'badge-rejected';
                    ?>
                    <div style="border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem 1.25rem; background: #fff;">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                            <div>
                                <h3 style="margin: 0 0 0.25rem; font-size: 1rem; color: #0f172a;">
                                    <?= htmlspecialchars($report['title']) ?>
                                </h3>
                                <p style="margin: 0; color: #64748b; font-size: 0.9rem;">
                                    Tracking No: <strong><?= htmlspecialchars($report['tracking_number'] ?? 'N/A') ?></strong>
                                </p>
                            </div>
                            <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($report['status']) ?></span>
                        </div>

                        <div style="margin-top: 0.75rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; font-size: 0.9rem; color: #334155;">
                            <div><strong>Sender Dept:</strong><br><?= htmlspecialchars($report['sending_dept_name'] ?? 'N/A') ?></div>
                            <div><strong>Current Dept:</strong><br><?= htmlspecialchars($report['receiving_dept_name'] ?? 'N/A') ?></div>
                            <div><strong>Priority:</strong><br><?= htmlspecialchars($report['priority'] ?? 'Medium') ?></div>
                            <div><strong>Submitted:</strong><br><?= date('m/d/Y', strtotime($report['created_at'])) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
