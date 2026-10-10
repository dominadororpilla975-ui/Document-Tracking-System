<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/privacy_guard.php';
require_once APP_ROOT . '/config/audit_log.php';

requireLogin();
$user = currentUser();
requirePermission('view_documents', $user);
requirePermission('manage_reports', $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? null);
    $reportId = (int)($_POST['report_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'Deleted from active documents');
    $action = $_POST['trash_action'] ?? 'delete';
    if ($reportId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = :id");
        $stmt->execute(['id' => $reportId]);
        $report = $stmt->fetch();

        if ($report) {
            $perm = checkDocumentPrivacy($user, $report, $pdo);
            
            // Only authorized staff can delete a document. The creating client is never allowed to delete it.
            if ($action === 'suspend' && hasPermission('override_workflow', $user) && $perm['can_modify']) {
                $pdo->prepare("UPDATE reports SET is_suspended=1, suspended_at=NOW(), suspended_by=:user_id, suspended_reason=:reason, status='Suspended' WHERE id=:id")
                    ->execute(['user_id'=>$user['id'], 'reason'=>$reason, 'id'=>$reportId]);
                $pdo->prepare("INSERT INTO report_logs (report_id, old_department, new_department, action, remarks, updated_by) VALUES (:report_id, :old_department, :new_department, 'document_suspended', :remarks, :updated_by)")
                    ->execute(['report_id'=>$reportId, 'old_department'=>$report['department_id'], 'new_department'=>$report['department_id'], 'remarks'=>$reason, 'updated_by'=>$user['id']]);
                auditLog($pdo, $user['id'], 'DOCUMENT_SUSPENDED', $reason, $reportId, $report['department_id'] ?? null);
                header('Location: documents.php?suspended=1');
                exit;
            }
            if ($perm['can_modify'] || ((int) ($report['client_id'] ?? 0) === (int) ($user['id'] ?? 0) && hasPermission('manage_reports', $user))) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE reports SET is_deleted = 1, deleted_at = NOW(), deleted_by = :user_id, deleted_reason = :reason WHERE id = :id")
                        ->execute(['user_id' => $user['id'], 'reason' => $reason, 'id' => $reportId]);
                    $pdo->prepare("INSERT INTO report_logs (report_id, old_department, new_department, action, remarks, updated_by) VALUES (:report_id, :old_department, :new_department, 'document_deleted', :remarks, :updated_by)")
                        ->execute(['report_id' => $reportId, 'old_department' => $report['department_id'], 'new_department' => $report['department_id'], 'remarks' => $reason, 'updated_by' => $user['id']]);
                    auditLog($pdo, $user['id'], 'DOCUMENT_DELETED', $reason, $reportId, $report['department_id'] ?? null);

                    $pdo->commit();
                    header("Location: trash_bin.php?deleted=1");
                    exit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    header("Location: documents.php?error=" . urlencode("Failed to delete document: " . $e->getMessage()));
                    exit();
                }
            }
        }
    }
}

header("Location: documents.php?error=unauthorized");
exit();
