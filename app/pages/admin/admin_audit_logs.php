<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
requireLogin();
$user = currentUser();
requirePermission('view_audit_logs', $user);

$stmt = $pdo->prepare("SELECT a.*, u.name AS user_name, u.role, r.tracking_number
    FROM audit_logs a
    LEFT JOIN users u ON u.id = a.user_id
    LEFT JOIN reports r ON r.id = a.document_id
    ORDER BY a.created_at DESC, a.id DESC");
$stmt->execute();
$logs = $stmt->fetchAll();

$legacyReceiptsStmt = $pdo->query("
    SELECT
        rl.id AS report_log_id,
        rl.report_id AS document_id,
        rl.new_department AS department_id,
        rl.updated_by AS user_id,
        rl.remarks,
        rl.date_updated AS created_at,
        r.tracking_number,
        u.name AS user_name,
        u.role,
        'DOCUMENT_RECEIVED' AS action
    FROM report_logs rl
    INNER JOIN reports r ON r.id = rl.report_id
    LEFT JOIN users u ON u.id = rl.updated_by
    WHERE LOWER(rl.action) = 'document_received'
      AND NOT EXISTS (
          SELECT 1
          FROM audit_logs a
          WHERE a.document_id = rl.report_id
            AND a.user_id = rl.updated_by
            AND a.action = 'DOCUMENT_RECEIVED'
            AND ABS(TIMESTAMPDIFF(SECOND, a.created_at, rl.date_updated)) <= 5
      )
");
foreach ($legacyReceiptsStmt->fetchAll(PDO::FETCH_ASSOC) as $legacyReceipt) {
    $legacyReceipt['details'] = 'Document ' . (string) $legacyReceipt['tracking_number']
        . ' was received by ' . (string) ($legacyReceipt['user_name'] ?? 'department user')
        . '. Remarks: ' . (string) ($legacyReceipt['remarks'] ?? '')
        . ' (Recovered from document timeline; no matching audit entry was found.)';
    $legacyReceipt['old_values'] = null;
    $legacyReceipt['new_values'] = json_encode([
        'source' => 'report_logs',
        'action' => 'document_received',
        'remarks' => $legacyReceipt['remarks'] ?? '',
        'actor_id' => (int) $legacyReceipt['user_id'],
        'actor_name' => $legacyReceipt['user_name'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    $legacyReceipt['fallback_report_log_id'] = (int) $legacyReceipt['report_log_id'];
    $logs[] = $legacyReceipt;
}
usort($logs, static fn (array $left, array $right): int =>
    (strtotime((string) $right['created_at']) <=> strtotime((string) $left['created_at']))
        ?: ((int) ($right['id'] ?? $right['report_log_id'] ?? 0) <=> (int) ($left['id'] ?? $left['report_log_id'] ?? 0))
);

function auditModule(string $action): string
{
    $action = strtoupper($action);
    $routeAction = preg_replace('/^(PAGE_VIEW|REQUEST)_/', '', $action) ?? $action;
    if (str_contains($action, 'DOCUMENT') || preg_match('/REPORT|ATTACHMENT|RECEIVING_COPY|TRACKING|ARCHIVE/', $routeAction)) {
        return 'Documents';
    }
    if (str_contains($action, 'ACCOUNT') || str_contains($action, 'GOOGLE') || str_contains($action, 'EMAIL') || preg_match('/USER|ROLE|PROFILE|LOGIN|REGISTER/', $routeAction)) {
        return 'Users & Authentication';
    }
    if (str_contains($action, 'DEPARTMENT') || preg_match('/DEPARTMENT/', $routeAction)) {
        return 'Departments';
    }
    if (str_contains($action, 'SERVICE') || str_contains($action, 'ACTIVITY') || preg_match('/SERVICE/', $routeAction)) {
        return 'Activities & Services';
    }
    if (str_contains($action, 'ANNOUNCEMENT') || preg_match('/ANNOUNCEMENT/', $routeAction)) {
        return 'Announcements';
    }
    if (str_contains($action, 'NOTIFICATION') || str_contains($action, 'MESSAGE')) {
        return 'Notifications & Messages';
    }
    if (preg_match('/SETTING|BACKUP|AUDIT|TRASH|SYSTEM/', $routeAction)) {
        return 'System & Settings';
    }
    return 'System';
}

function auditActionLabel(string $action, ?string $details = null, ?string $newValuesJson = null): string
{
    $labels = [
        'DOCUMENT_APPROVED' => 'Approved',
        'DOCUMENT_RECEIVED' => 'Received',
        'DOCUMENT_REJECTED' => 'Rejected',
        'DOCUMENT_RETURNED' => 'Returned',
        'DOCUMENT_STATUS_CHANGED' => 'Status Updated',
        'DOCUMENT_VIEWED' => 'Viewed',
        'DOCUMENT_VIEW_DENIED' => 'View Denied',
        'ORIGIN_RECEIPT_CONFIRMED' => 'Origin Receipt Confirmed',
        'CLIENT_RECEIPT_CONFIRMED' => 'Client Receipt Confirmed',
        'DOCUMENT_DOWNLOADED' => 'Downloaded',
        'DOCUMENT_SUSPENDED' => 'Suspended',
        'DOCUMENT_DELETED' => 'Deleted',
        'DOCUMENT_RESTORED' => 'Restored',
        'DOCUMENT_RESUMED' => 'Resumed',
        'DOCUMENT_PERMANENTLY_DELETED' => 'Permanently Deleted',
    ];
    $normalizedAction = strtoupper($action);
    if (isset($labels[$normalizedAction])) {
        return $labels[$normalizedAction];
    }

    if ($normalizedAction === 'REQUEST_REPORT_DETAILS') {
        $requestPayload = json_decode((string) $newValuesJson, true);
        $requestAction = strtolower((string) ($requestPayload['action_type'] ?? ''));
        $requestStatus = strtolower((string) ($requestPayload['status'] ?? ''));
        $requestActionLabels = [
            'receive_document' => 'Received',
            'accept_returned_document' => 'Origin Receipt Confirmed',
            'reject_document' => 'Rejected',
            'return_department' => 'Returned',
            'client_complete_document' => 'Client Receipt Confirmed',
        ];
        if ($requestAction === 'update_status') {
            return $requestStatus === 'approved' ? 'Approved' : 'Status Updated';
        }
        if (isset($requestActionLabels[$requestAction])) {
            return $requestActionLabels[$requestAction];
        }

        return 'Document Action';
    }

    if ($normalizedAction === 'PAGE_VIEW_REPORT_DETAILS') {
        return 'Viewed';
    }

    $label = preg_replace('/^(PAGE_VIEW|REQUEST)_/', '', $normalizedAction) ?? $normalizedAction;
    return ucwords(strtolower(str_replace('_', ' ', $label)));
}

function auditActionStatusClass(string $action, ?string $newValuesJson = null): string
{
    $normalizedAction = strtoupper($action);
    if ($normalizedAction === 'REQUEST_REPORT_DETAILS') {
        $payload = json_decode((string) $newValuesJson, true);
        $requestAction = strtolower((string) ($payload['action_type'] ?? ''));
        $normalizedAction = match ($requestAction) {
            'receive_document' => 'DOCUMENT_RECEIVED',
            'accept_returned_document' => 'ORIGIN_RECEIPT_CONFIRMED',
            'reject_document' => 'DOCUMENT_REJECTED',
            'return_department' => 'DOCUMENT_RETURNED',
            'client_complete_document' => 'CLIENT_RECEIPT_CONFIRMED',
            'update_status' => strtolower((string) ($payload['status'] ?? '')) === 'approved'
                ? 'DOCUMENT_APPROVED'
                : 'DOCUMENT_STATUS_CHANGED',
            default => $normalizedAction,
        };
    }

    return match ($normalizedAction) {
        'DOCUMENT_APPROVED', 'ORIGIN_RECEIPT_CONFIRMED', 'CLIENT_RECEIPT_CONFIRMED',
        'DOCUMENT_RESTORED', 'DOCUMENT_RESUMED' => 'badge-approved',
        'DOCUMENT_RECEIVED', 'DOCUMENT_STATUS_CHANGED' => 'badge-processing',
        'DOCUMENT_REJECTED', 'DOCUMENT_RETURNED', 'DOCUMENT_VIEW_DENIED',
        'DOCUMENT_SUSPENDED', 'DOCUMENT_DELETED', 'DOCUMENT_PERMANENTLY_DELETED' => 'badge-rejected',
        default => 'badge-assigned',
    };
}

function formatAuditPayload(?string $value, array $fallback = []): string
{
    if ($value === null || trim((string) $value) === '') {
        if ($fallback === []) {
            return '{"details":"No payload captured for this activity."}';
        }

        return json_encode($fallback, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"details":"No payload captured for this activity."}';
    }

    $trimmed = trim((string) $value);
    $decoded = json_decode($trimmed, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_array($decoded)) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $trimmed;
        }

        if ($decoded !== null) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $trimmed;
        }
    }

    return $trimmed;
}

require_once APP_ROOT . '/includes/header.php';
?>
<div class="admin-module-page audit-logs-page">
    <div class="admin-module-heading">
        <div>
            <span class="admin-eyebrow">SECURITY</span>
            <h2>Audit Logs</h2>
            <p>System page views and submitted actions are recorded here, including document approvals, rejections, and workflow movements.</p>
        </div>
    </div>
    <section class="admin-panel">
        <div class="admin-table-wrap">
            <table class="admin-table" data-datatable-search="true" data-datatable-paging="true" data-datatable-ordering="true" data-datatable-info="true" data-datatable-order='[[0,"desc"]]'>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Module</th>
                        <th>Status / Action</th>
                        <th>Document</th>
                        <th>Details</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2rem; color: #64748b;">No audit logs found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): 
                            $auditModalId = 'audit-details-' . (
                                isset($log['fallback_report_log_id'])
                                    ? 'report-log-' . (int) $log['fallback_report_log_id']
                                    : (int) $log['id']
                            );
                            $fallbackPayload = [
                                'action' => (string) ($log['action'] ?? ''),
                                'details' => (string) ($log['details'] ?? ''),
                                'document_id' => $log['document_id'] ?? null,
                                'department_id' => $log['department_id'] ?? null,
                                'ip_address' => $log['ip_address'] ?? null
                            ];
                            $oldValue = formatAuditPayload($log['old_values'] ?? null, $fallbackPayload);
                            $newValue = formatAuditPayload($log['new_values'] ?? null, $fallbackPayload);
                        ?>
                            <tr>
                                <td><?= date('m/d/Y h:i A', strtotime($log['created_at'])) ?></td>
                                <td><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td>
                                <td><span class="admin-status-badge audit-module-badge"><?= htmlspecialchars(auditModule((string) $log['action'])) ?></span></td>
                                <?php $actionLabel = auditActionLabel(
                                    (string) $log['action'],
                                    $log['details'] ?? null,
                                    $log['new_values'] ?? null
                                ); ?>
                                <td><span class="badge <?= htmlspecialchars(auditActionStatusClass(
                                    (string) $log['action'],
                                    $log['new_values'] ?? null
                                )) ?>"><?= htmlspecialchars($actionLabel) ?></span></td>
                                <td><span class="audit-document-number"><?= htmlspecialchars($log['tracking_number'] ?? '—') ?></span></td>
                                <td><?= htmlspecialchars($log['details'] ?? '—') ?></td>
                                <td>
                                    <div class="action-menu-shell">
                                        <button type="button" class="action-more-toggle" aria-label="More actions" title="More actions" aria-expanded="false">
                                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                        </button>
                                        <div class="action-menu-panel">
                                            <button type="button" class="action-menu-item" aria-label="View audit details" data-audit-details-open="<?= htmlspecialchars($auditModalId) ?>">
                                                <i class="bi bi-eye-fill" aria-hidden="true"></i>View
                                            </button>
                                        </div>
                                    </div>
                                    <div id="<?= htmlspecialchars($auditModalId) ?>" data-audit-details-modal style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:100000;align-items:center;justify-content:center;padding:1rem;">
                                        <div style="background:#fff;border-radius:12px;width:min(720px,96vw);max-height:90vh;overflow-y:auto;padding:clamp(1rem,4vw,1.5rem);">
                                            <h3 style="margin:0;">Audit Change Details</h3>
                                            <p><strong>Module:</strong> <?= htmlspecialchars(auditModule((string) $log['action'])) ?></p>
                                            <p><strong>Action:</strong> <?= htmlspecialchars(auditActionLabel(
                                                (string) $log['action'],
                                                $log['details'] ?? null,
                                                $log['new_values'] ?? null
                                            )) ?></p>
                                            <p><strong>Document:</strong> <?= htmlspecialchars($log['tracking_number'] ?? '—') ?></p>
                                            <p><strong>Details:</strong> <?= htmlspecialchars($log['details'] ?? '—') ?></p>
                                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
                                                <div>
                                                    <h4>Old Value</h4>
                                                    <pre style="white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.75rem;"><?= htmlspecialchars($oldValue) ?></pre>
                                                </div>
                                                <div>
                                                    <h4>New Value</h4>
                                                    <pre style="white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.75rem;"><?= htmlspecialchars($newValue) ?></pre>
                                                </div>
                                            </div>
                                            <div style="display:flex;justify-content:flex-end;margin-top:1rem;">
                                                <button type="button" class="btn btn-light" title="Close details" aria-label="Close audit details" data-audit-details-close>
                                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script>
document.addEventListener('click', function (event) {
    var openButton = event.target.closest('[data-audit-details-open]');
    if (openButton) {
        event.preventDefault();
        event.stopPropagation();

        var menu = openButton.closest('.action-menu-shell');
        if (menu) {
            menu.classList.remove('is-open');
            var toggle = menu.querySelector('.action-more-toggle');
            var panel = menu.querySelector('.action-menu-panel');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
            if (panel) {
                ['display', 'visibility', 'position', 'top', 'bottom', 'left', 'right', 'zIndex'].forEach(function (property) {
                    panel.style[property] = '';
                });
            }
        }

        var modal = document.getElementById(openButton.dataset.auditDetailsOpen);
        if (modal) {
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
            modal.style.display = 'flex';
        }
        return;
    }

    var closeButton = event.target.closest('[data-audit-details-close]');
    if (closeButton) {
        event.preventDefault();
        event.stopPropagation();

        var modalToClose = closeButton.closest('[data-audit-details-modal]');
        if (modalToClose) {
            modalToClose.style.display = 'none';
        }
        return;
    }

    if (event.target.matches('[data-audit-details-modal]')) {
        event.target.style.display = 'none';
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('[data-audit-details-modal]').forEach(function (modal) {
            modal.style.display = 'none';
        });
    }
});
</script>
<?php require_once APP_ROOT . '/includes/footer.php'; ?>