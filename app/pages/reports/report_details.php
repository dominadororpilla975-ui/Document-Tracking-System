<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/privacy_guard.php';
require_once APP_ROOT . '/includes/document_preview.php';
require_once APP_ROOT . '/includes/notifications.php';
require_once APP_ROOT . '/config/audit_log.php';

requireLogin();
$user = currentUser();
requirePermission('view_documents', $user);

$reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$reportId) {
    header("Location: dashboard.php");
    exit();
}
$error = isset($_GET['error']) ? (string) $_GET['error'] : '';
$success = isset($_GET['created']) ? (string) $_GET['created'] : '';

// Fetch report details
$stmt = $pdo->prepare("SELECT r.*, u.name as client_name, u.email as client_email, 
                      d_recv.name as receiving_dept_name, d_send.name as sending_dept_name,
                      d_origin.name as originating_dept_name,
                      ds.assigned_user_id as activity_assigned_user_id,
                      ds.service_name as activity_service_name
                      FROM reports r 
                      LEFT JOIN users u ON r.client_id = u.id 
                      LEFT JOIN departments d_recv ON r.department_id = d_recv.id 
                      LEFT JOIN departments d_send ON r.sender_department_id = d_send.id 
                      LEFT JOIN departments d_origin ON r.originating_department_id = d_origin.id
                      LEFT JOIN department_services ds ON ds.id = r.service_id
                      WHERE r.id = :id
                        AND COALESCE(r.is_deleted, 0) = 0
                        AND COALESCE(r.is_suspended, 0) = 0");
$stmt->execute(['id' => $reportId]);
$report = $stmt->fetch();

if (!$report) {
    die("Document record not found.");
}

function auditDocumentWorkflowAction(
    PDO $pdo,
    int $userId,
    string $action,
    string $details,
    int $reportId,
    ?int $departmentId,
    array $oldValues,
    array $newValues
): void {
    global $report;
    $trackingNumber = (string) ($report['tracking_number'] ?? '');
    $oldValues = ['tracking_number' => $trackingNumber] + $oldValues;
    $newValues = ['tracking_number' => $trackingNumber] + $newValues;
    $details = 'Document ' . $trackingNumber . '. ' . $details;

    if (!auditLog($pdo, $userId, $action, $details, $reportId, $departmentId, $oldValues, $newValues)) {
        throw new RuntimeException('The document action audit record could not be saved.');
    }
}

// Check Privacy & Department Permissions
$perm = checkDocumentPrivacy($user, $report, $pdo);

if (!$perm['can_view_meta']) {
    auditLog($pdo, (int) $user['id'], 'DOCUMENT_VIEW_DENIED', 'Document access denied: ' . (string) $perm['denial_reason'], $reportId, (int) ($report['department_id'] ?? 0));
    require_once APP_ROOT . '/includes/header.php';
    echo '<div class="alert alert-danger" style="margin: 3rem auto; max-width: 600px; padding: 2rem; text-align: center; border-radius: 12px; box-shadow: var(--shadow-md);">';
    echo '<h2 style="font-size: 1.5rem; font-weight: 800; margin-bottom: 0.5rem; color: #991b1b;">🚫 Access Denied</h2>';
    echo '<p style="font-size: 1rem; color: #7f1d1d;">' . htmlspecialchars($perm['denial_reason']) . '</p>';
    echo '<a href="dashboard.php" class="btn btn-secondary" style="margin-top: 1rem;">← Return to Dashboard</a>';
    echo '</div>';
    require_once APP_ROOT . '/includes/footer.php';
    exit();
}

auditDocumentViewed($pdo, (int) $user['id'], $reportId, (int) ($report['department_id'] ?? 0));

// Handle department actions and the document owner's final receipt confirmation.
$isClientCompletionRequest = ($_POST['action_type'] ?? '') === 'client_complete_document'
    && isClientUser($user)
    && (int)($user['id'] ?? 0) === (int)($report['client_id'] ?? 0)
    && hasPermission('complete_own_documents', $user);
$requestedAction = $_POST['action_type'] ?? '';
$departmentWorkflowActions = [
    'return_department',
    'receive_document',
    'accept_returned_document',
    'update_status',
    'reject_document',
];
$isDepartmentWorkflowAction = in_array($requestedAction, $departmentWorkflowActions, true);
$canPerformDepartmentWorkflowActions = isDepartmentUser($user) && $perm['can_modify'];
$requestedStatus = strtolower(trim((string) ($_POST['status'] ?? '')));
$requiredActionPermission = match ($requestedAction) {
    'return_department' => 'route_reports',
    'receive_document', 'accept_returned_document' => 'manage_reports',
    'update_status' => $requestedStatus === 'approved' ? 'approve_reports' : 'manage_reports',
    'reject_document' => 'approve_reports',
    'client_complete_document' => 'complete_own_documents',
    default => '',
};
$isAuthorizedWorkflowRequest = $isDepartmentWorkflowAction
    ? $canPerformDepartmentWorkflowActions
    : $isClientCompletionRequest;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isDepartmentWorkflowAction && !$canPerformDepartmentWorkflowActions) {
    $error = 'Only a user from the currently assigned department with document access can perform this action.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestedAction !== '' && $requiredActionPermission !== ''
    && hasPermission($requiredActionPermission, $user)
    && $isAuthorizedWorkflowRequest) {
    verifyCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action_type'];
    $trackingNo = $report['tracking_number'];

    if ($action === 'return_department') {
        $returnDeptId = (int)($_POST['return_dept_id'] ?? $report['sender_department_id'] ?? 0);
        $returnRemarks = trim($_POST['return_remarks'] ?? '');

        if ($returnRemarks === '') {
            $error = "Pakilagay ang dahilan kung bakit ibinabalik ang dokumento.";
        } else {
            if ($returnDeptId <= 0 && (int)($report['current_workflow_step'] ?? 1) > 1) {
                $prevRouteStmt = $pdo->prepare("SELECT department_id FROM report_workflow_routes WHERE report_id = :id AND step_number < :step AND department_id IS NOT NULL AND department_id != :curr_dept ORDER BY step_number DESC LIMIT 1");
                $prevRouteStmt->execute([
                    'id' => $reportId,
                    'step' => (int)($report['current_workflow_step'] ?? 999),
                    'curr_dept' => (int)($report['department_id'] ?? 0)
                ]);
                $prevR = $prevRouteStmt->fetch();
                if ($prevR && !empty($prevR['department_id'])) {
                    $returnDeptId = (int)$prevR['department_id'];
                }
            }
            if ($returnDeptId <= 0 && !empty($report['originating_department_id']) && (int)$report['originating_department_id'] !== (int)($report['department_id'] ?? 0)) {
                $returnDeptId = (int)$report['originating_department_id'];
            }

            if ($returnDeptId > 0) {
                $rStmt = $pdo->prepare("SELECT name FROM departments WHERE id = :id");
                $rStmt->execute(['id' => $returnDeptId]);
                $returnDept = $rStmt->fetch();
                if ($returnDept) {
                    $pdo->beginTransaction();
                    try {
                        $oldDeptId = (int)($report['department_id'] ?? 0);

                        // Find step number of return target in workflow routes
                        $targetStepStmt = $pdo->prepare("SELECT step_number FROM report_workflow_routes WHERE report_id = :id AND department_id = :dept_id ORDER BY step_number ASC LIMIT 1");
                        $targetStepStmt->execute(['id' => $reportId, 'dept_id' => $returnDeptId]);
                        $targetStepNum = $targetStepStmt->fetchColumn();

                        if ($targetStepNum !== false && (int)$targetStepNum > 0) {
                            $uReport = $pdo->prepare("UPDATE reports SET department_id = :dept_id1, assigned_department_id = :dept_id2, status = 'Returned', current_workflow_step = :step WHERE id = :id");
                            $uReport->execute(['dept_id1' => $returnDeptId, 'dept_id2' => $returnDeptId, 'step' => (int)$targetStepNum, 'id' => $reportId]);

                            $pdo->prepare("UPDATE report_workflow_routes SET status = 'Returned', approver_notes = CONCAT(COALESCE(approver_notes, ''), :note) WHERE report_id = :id AND step_number = :current_step")
                                ->execute([
                                    'note' => "\nReturned: " . $returnRemarks,
                                    'id' => $reportId,
                                    'current_step' => (int)$report['current_workflow_step']
                                ]);

                            $pdo->prepare("UPDATE report_workflow_routes SET status = 'In Progress', approver_notes = CONCAT(COALESCE(approver_notes, ''), :note) WHERE report_id = :id AND step_number = :target_step")
                                ->execute([
                                    'note' => "\nRe-opened for revisions: " . $returnRemarks,
                                    'id' => $reportId,
                                    'target_step' => (int)$targetStepNum
                                ]);
                        } else {
                            $uReport = $pdo->prepare("UPDATE reports SET department_id = :dept_id1, assigned_department_id = :dept_id2, status = 'Returned' WHERE id = :id");
                            $uReport->execute(['dept_id1' => $returnDeptId, 'dept_id2' => $returnDeptId, 'id' => $reportId]);
                        }

                        $logStmt = $pdo->prepare("INSERT INTO report_logs (report_id, old_department, new_department, action, remarks, updated_by) VALUES (:report_id, :old_dept, :new_dept, 'returned_to_sender', :remarks, :user_id)");
                        $logStmt->execute([
                            'report_id' => $reportId,
                            'old_dept' => $oldDeptId > 0 ? $oldDeptId : null,
                            'new_dept' => $returnDeptId,
                            'remarks' => "Returned to {$returnDept['name']} for revisions. Dahilan: " . $returnRemarks,
                            'user_id' => $user['id']
                        ]);

                        auditDocumentWorkflowAction(
                            $pdo,
                            (int) $user['id'],
                            'DOCUMENT_RETURNED',
                            'Document returned by ' . (string) ($user['name'] ?? 'administrator') . ' (user ID ' . (int) $user['id'] . ') to ' . (string) $returnDept['name'] . '. Reason: ' . $returnRemarks,
                            $reportId,
                            $returnDeptId,
                            ['department_id' => $oldDeptId, 'status' => (string) $report['status']],
                            ['department_id' => $returnDeptId, 'status' => 'Returned', 'reason' => $returnRemarks, 'actor_id' => (int) $user['id']]
                        );

                        $senderDeptName = !empty($user['department_name']) ? $user['department_name'] : ($user['name'] ?? 'Super Admin');
                        sendDepartmentNotification($pdo, $returnDeptId, $trackingNo, "Document {$trackingNo} was returned by {$senderDeptName} for revisions. Dahilan: {$returnRemarks}");
                        $pdo->commit();
                        $success = "Document successfully returned to " . htmlspecialchars($returnDept['name']) . " for revisions!";
                        $stmt->execute(['id' => $reportId]);
                        $report = $stmt->fetch();
                        $perm = checkDocumentPrivacy($user, $report, $pdo);
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = "Failed to return document: " . $e->getMessage();
                    }
                }
            } else {
                $error = "No previous sender department available to return to.";
            }
        }
    } elseif ($action === 'receive_document') {
        $receivingRemarks = trim($_POST['receiving_remarks'] ?? '');

        $currentRouteStmt = $pdo->prepare("SELECT destination_type FROM report_workflow_routes WHERE report_id = :report_id AND step_number = :step_number LIMIT 1");
        $currentRouteStmt->execute(['report_id' => $reportId, 'step_number' => (int)$report['current_workflow_step']]);
        $currentRoute = $currentRouteStmt->fetch();
        if (strtoupper((string)($currentRoute['destination_type'] ?? 'Department')) === 'RESIDENT') {
            throw new Exception('Resident must confirm receipt using the client Receive button; it cannot be received by a department.');
        }

        $pdo->beginTransaction();
        try {
            $receiveRoute = $pdo->prepare("UPDATE report_workflow_routes SET status = 'In Progress' WHERE report_id = :report_id AND step_number = :step_number AND status IN ('Pending', 'Awaiting Receiving')");
            $receiveRoute->execute(['report_id' => $reportId, 'step_number' => $report['current_workflow_step']]);
            if ($receiveRoute->rowCount() !== 1) {
                throw new RuntimeException('This document is no longer awaiting receipt.');
            }

            $pdo->prepare("UPDATE reports SET status = 'Processing' WHERE id = :id")->execute(['id' => $reportId]);

            $userLabel = htmlspecialchars($user['name'] ?? '');
            $noteText = "Received by {$userLabel} at " . date('Y-m-d H:i:s') . ".";
            if ($receivingRemarks) {
                $noteText .= " Remarks: " . $receivingRemarks;
            }
            $appendStmt = $pdo->prepare("UPDATE report_workflow_routes SET approver_notes = CONCAT(COALESCE(approver_notes,''), :note) WHERE report_id = :report_id AND step_number = :step_number");
            $appendStmt->execute(['note' => '\n' . $noteText, 'report_id' => $reportId, 'step_number' => $report['current_workflow_step']]);
            try {
                $pdo->prepare("UPDATE report_workflow_routes SET received_date = NOW() WHERE report_id = :report_id AND step_number = :step_number")
                    ->execute(['report_id' => $reportId, 'step_number' => $report['current_workflow_step']]);
            } catch (Throwable $exception) {
                error_log('Could not update receipt timestamp for report ' . $reportId . ': ' . $exception->getMessage());
            }
            $logStmt = $pdo->prepare("INSERT INTO report_logs (report_id, old_department, new_department, action, remarks, updated_by) VALUES (:report_id, :old_dept, :new_dept, 'document_received', :remarks, :user_id)");
            $logStmt->execute(['report_id' => $reportId, 'old_dept' => $report['department_id'], 'new_dept' => $report['department_id'], 'remarks' => $receivingRemarks ?: 'Confirmed receipt', 'user_id' => $user['id']]);
            auditDocumentWorkflowAction(
                $pdo,
                (int) $user['id'],
                'DOCUMENT_RECEIVED',
                'Document received by ' . (string) ($user['name'] ?? 'department staff') . ' (user ID ' . (int) $user['id'] . '). Remarks: ' . $receivingRemarks,
                $reportId,
                (int) ($report['department_id'] ?? 0),
                ['status' => (string) $report['status'], 'workflow_step' => (int) $report['current_workflow_step']],
                ['status' => 'Processing', 'route_status' => 'In Progress', 'remarks' => $receivingRemarks, 'actor_id' => (int) $user['id'], 'actor_name' => (string) ($user['name'] ?? '')]
            );

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Failed to record document receipt for report ' . $reportId . ': ' . $exception->getMessage());
            $error = 'The document could not be received and audited. Please try again.';
        }

        if ($error !== '') {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }
        } else {

        $success = 'Document received and marked as processing.';
        $stmt->execute(['id' => $reportId]); $report = $stmt->fetch();
        if (!empty($report['client_id'])) {
            sendUserNotification($pdo, $report['client_id'], $trackingNo, "Your document {$trackingNo} was received by {$user['department_name']} and is now being processed.");
        }

        // Retrieve updated route status for response
        $routeStmt = $pdo->prepare("SELECT status FROM report_workflow_routes WHERE report_id = :report_id AND step_number = :step_number");
        $routeStmt->execute(['report_id' => $reportId, 'step_number' => $report['current_workflow_step']]);
        $routeRow = $routeStmt->fetch();
        $routeStatus = $routeRow ? $routeRow['status'] : 'In Progress';

        // If AJAX request, return minimal JSON so client can update UI without full reload
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                            'message' => $success,
                            'report_status' => $report['status'],
                            'route_status' => $routeStatus,
                            'current_step' => (int)$report['current_workflow_step'],
                            'origin_acceptance' => (int)($report['department_id'] ?? 0) === (int)($report['originating_department_id'] ?? 0),
                            'origin_dept_name' => htmlspecialchars($report['originating_dept_name'] ?? $report['sending_dept_name'] ?? ''),
                            'received_at' => date('c'),
                            'received_by' => $user['name'] ?? ''
                        ]);
            exit;
        }
        }
    } elseif ($action === 'reject_document') {
        $remarks = trim($_POST['remarks'] ?? '');
        if ($remarks === '' || mb_strlen($remarks) > 1000) {
            $error = 'Enter a rejection reason of 1 to 1000 characters.';
        } elseif (in_array(strtoupper((string) $report['status']), ['REJECTED', 'COMPLETED', 'APPROVED'], true)) {
            $error = 'This document can no longer be rejected.';
        } else {
            $pdo->beginTransaction();
            try {
                $routeUpdate = $pdo->prepare("UPDATE report_workflow_routes SET status = 'Rejected', completed_date = NOW(), approver_notes = :remarks WHERE report_id = :report_id AND step_number = :step_number AND status NOT IN ('Completed', 'Approved', 'Rejected')");
                $routeUpdate->execute(['remarks' => $remarks, 'report_id' => $reportId, 'step_number' => $report['current_workflow_step']]);
                if ($routeUpdate->rowCount() !== 1) {
                    throw new RuntimeException('The current workflow step is no longer available for rejection.');
                }

                $pdo->prepare("UPDATE reports SET status = 'Rejected' WHERE id = :id")->execute(['id' => $reportId]);
                $pdo->prepare("INSERT INTO report_logs (report_id, old_department, new_department, action, remarks, updated_by) VALUES (:report_id, :old_dept, :new_dept, 'document_rejected', :remarks, :user_id)")
                    ->execute([
                        'report_id' => $reportId,
                        'old_dept' => $report['department_id'],
                        'new_dept' => $report['department_id'],
                        'remarks' => $remarks,
                        'user_id' => $user['id'],
                    ]);
                if (!empty($report['client_id'])) {
                    sendUserNotification($pdo, $report['client_id'], $trackingNo, "Your document {$trackingNo} was rejected. Reason: {$remarks}");
                }
                auditDocumentWorkflowAction(
                    $pdo,
                    (int) $user['id'],
                    'DOCUMENT_REJECTED',
                    'Document rejected by ' . (string) ($user['name'] ?? 'administrator') . ' (user ID ' . (int) $user['id'] . '). Reason: ' . $remarks,
                    $reportId,
                    (int) ($report['department_id'] ?? 0),
                    ['status' => (string) $report['status']],
                    ['status' => 'Rejected', 'reason' => $remarks, 'actor_id' => (int) $user['id'], 'actor_name' => (string) ($user['name'] ?? '')]
                );
                $pdo->commit();
                $success = 'Document rejected.';
                $stmt->execute(['id' => $reportId]);
                $report = $stmt->fetch();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Failed to reject document ' . $trackingNo . ': ' . $e->getMessage());
                $error = 'Failed to reject document. Please try again.';
            }
        }
    } elseif ($action === 'accept_returned_document') {
        $remarks = trim($_POST['remarks'] ?? '');
        $isOriginDeptUser = (int)($user['department_id'] ?? 0) === (int)($report['originating_department_id'] ?? 0)
                         || (int)($user['department_id'] ?? 0) === (int)($report['sender_department_id'] ?? 0)
                         || (int)($user['id'] ?? 0) === (int)($report['client_id'] ?? 0)
                         || hasPermission('override_workflow', $user);
        if (!$isOriginDeptUser) {
            $error = 'Only the originating department or document creator can confirm receipt of this document.';
        } elseif (strtoupper($report['status'] ?? '') === 'COMPLETED') {
            $success = 'Document receipt was already confirmed and archived.';
        } else {
            $pdo->beginTransaction();
            try {
                $originDeptId = (int)($report['originating_department_id'] ?: ($report['sender_department_id'] ?: $user['department_id']));

                $pdo->prepare("UPDATE reports SET status = 'Completed', department_id = :dept_id1, assigned_department_id = :dept_id2 WHERE id = :id")
                    ->execute(['dept_id1' => $originDeptId, 'dept_id2' => $originDeptId, 'id' => $reportId]);

                $pdo->prepare("INSERT INTO archived_records (report_id, archived_by, reason) VALUES (:report_id, :user_id, :reason) ON DUPLICATE KEY UPDATE archived_by = VALUES(archived_by), archived_at = NOW(), reason = VALUES(reason)")
                    ->execute(['report_id' => $reportId, 'user_id' => $user['id'], 'reason' => $remarks ?: 'Confirmed origin receipt & archived after workflow completion.']);

                $pdo->prepare("INSERT INTO report_logs (report_id, old_department, new_department, action, remarks, updated_by) VALUES (:report_id, :old_dept, :new_dept, 'origin_accepted_and_archived', :remarks, :user_id)")
                    ->execute([
                        'report_id' => $reportId,
                        'old_dept' => $report['department_id'],
                        'new_dept' => $originDeptId,
                        'remarks' => $remarks ?: 'Confirmed receipt of returned document at originating office.',
                        'user_id' => $user['id']
                    ]);

                // Ensure a completed route step is saved in report_workflow_routes so it appears in the workflow timeline
                $checkRoute = $pdo->prepare("SELECT id FROM report_workflow_routes WHERE report_id = :report_id AND department_id = :dept_id");
                $checkRoute->execute(['report_id' => $reportId, 'dept_id' => $originDeptId]);
                $existingRoute = $checkRoute->fetch();

                if ($existingRoute) {
                    $pdo->prepare("UPDATE report_workflow_routes SET status = 'Completed', completed_date = NOW(), approver_notes = COALESCE(NULLIF(:remarks, ''), 'Received back & completed by originating department') WHERE report_id = :report_id AND department_id = :dept_id")
                        ->execute(['remarks' => $remarks, 'report_id' => $reportId, 'dept_id' => $originDeptId]);
                } else {
                    $maxStep = (int)$pdo->query("SELECT COALESCE(MAX(step_number), 0) FROM report_workflow_routes WHERE report_id = " . (int)$reportId)->fetchColumn();
                    $insRoute = $pdo->prepare("INSERT INTO report_workflow_routes (report_id, step_number, department_id, approver_notes, status, completed_date) VALUES (:report_id, :step_number, :dept_id, :notes, 'Completed', NOW())");
                    $insRoute->execute([
                        'report_id' => $reportId,
                        'step_number' => $maxStep + 1,
                        'dept_id' => $originDeptId,
                        'notes' => $remarks ?: 'Received back & completed by originating department'
                    ]);
                }

                auditDocumentWorkflowAction(
                    $pdo,
                    (int) $user['id'],
                    'ORIGIN_RECEIPT_CONFIRMED',
                    'Origin receipt confirmed by ' . (string) ($user['name'] ?? 'department staff') . ' (user ID ' . (int) $user['id'] . '). Reason: ' . ($remarks ?: 'Confirmed receipt of returned document at originating office.'),
                    $reportId,
                    $originDeptId,
                    ['status' => (string) $report['status']],
                    ['status' => 'Completed', 'reason' => $remarks, 'actor_id' => (int) $user['id'], 'actor_name' => (string) ($user['name'] ?? '')]
                );

                $pdo->commit();
                $success = 'Document receipt confirmed and archived by originating department.';
                $stmt->execute(['id' => $reportId]);
                $report = $stmt->fetch();

                // Notify the client via in-app notification & Email with full Return Receipt details
                if (!empty($report['client_email']) || !empty($report['client_id'])) {
                    $clientEmail = $report['client_email'] ?? '';
                    $clientName = $report['client_name'] ?? 'Document Owner';
                    if (empty($clientEmail) && !empty($report['client_id'])) {
                        $cStmt = $pdo->prepare("SELECT email, name FROM users WHERE id = :id");
                        $cStmt->execute(['id' => $report['client_id']]);
                        $cRow = $cStmt->fetch();
                        if ($cRow) {
                            $clientEmail = $cRow['email'];
                            $clientName = $cRow['name'] ?: $clientName;
                        }
                    }

                    $clientMsg = "Your document {$trackingNo} has completed its workflow and was received back by {$user['department_name']}.";
                    try {
                        require_once APP_ROOT . '/includes/notifications.php';
                        if (!empty($report['client_id'])) {
                            sendUserNotification($pdo, $report['client_id'], $trackingNo, $clientMsg);
                        }
                    } catch (Exception $e) {
                        error_log('Failed to send in-app notification for report ' . $reportId . ': ' . $e->getMessage());
                    }

                    if (!empty($clientEmail)) {
                        try {
                            require_once APP_ROOT . '/includes/mailer.php';
                            $originDeptTitle = $report['originating_dept_name'] ?? $report['sending_dept_name'] ?? ($user['department_name'] ?? 'Originating Office');
                            $receivedByName = $user['name'] ?? 'Authorized Staff';
                            $receivedDateStr = date('m/d/Y - h:i A');
                            $remarksText = $remarks ?: 'Confirmed receipt of returned document at originating office.';

                            $emailSubject = "Document Completed: Return Receipt for Document {$trackingNo}";
                            $emailBody = "Hello {$clientName},\n\n" .
                                         "Good day!\n\n" .
                                         "Your document titled \"{$report['title']}\" (Tracking No: {$trackingNo}) has successfully completed its entire workflow and has been officially received back by the originating department.\n\n" .
                                         "=======================================================\n" .
                                         "KATUNAYAN NG PAGTANGGAP (PROOF OF RETURN RECEIPT)\n" .
                                         "=======================================================\n" .
                                         "Document Tracking No : {$trackingNo}\n" .
                                         "Document Title       : {$report['title']}\n" .
                                         "Originating Dept     : {$originDeptTitle}\n" .
                                         "Received By          : {$receivedByName}\n" .
                                         "Date & Time          : {$receivedDateStr}\n" .
                                         "Tala / Remarks       : {$remarksText}\n" .
                                         "Status               : Completed & Archived\n" .
                                         "=======================================================\n\n" .
                                         "You can view the complete tracking history and details here:\n" .
                                         (isset($_SERVER['HTTP_HOST']) ? ("http://" . $_SERVER['HTTP_HOST'] . "/report_details.php?id={$reportId}") : "/report_details.php?id={$reportId}") . "\n\n" .
                                         "Thank you,\nDocument Tracking System";

                            sendEmailNotification($clientEmail, $emailSubject, $emailBody);
                        } catch (Exception $e) {
                            error_log('Failed to send email notification to client for report ' . $reportId . ': ' . $e->getMessage());
                        }
                    }
                }

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => $success,
                        'report_status' => $report['status']
                    ]);
                    exit;
                }

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Failed to confirm origin receipt: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'client_complete_document') {
        // Client confirms the document was received back after the office workflow finished.
        if ((int)$user['id'] !== (int)$report['client_id']) {
            $error = 'Only the document creator can confirm receiving this document.';
        } elseif (strtoupper($report['status'] ?? '') === 'COMPLETED') {
            $error = 'This document has already been completed.';
        } else {
            $residentRouteStmt = $pdo->prepare("SELECT destination_type FROM report_workflow_routes WHERE report_id = :report_id AND step_number = :step_number LIMIT 1");
            $residentRouteStmt->execute(['report_id' => $reportId, 'step_number' => (int)$report['current_workflow_step']]);
            $residentRoute = $residentRouteStmt->fetch();
            if (($residentRoute['destination_type'] ?? '') !== 'Resident') {
                $error = 'Client receipt confirmation is only available when the document reaches Resident.';
            } else {
            $pdo->beginTransaction();
            try {
                // Resident is the final destination; completion clears office assignment.
                $pdo->prepare("UPDATE reports SET status = 'Completed', department_id = NULL, assigned_department_id = NULL WHERE id = :id")->execute(['id' => $reportId]);

                // Archive if not already
                $pdo->prepare("INSERT INTO archived_records (report_id, archived_by, reason) VALUES (:report_id, :user_id, :reason) ON DUPLICATE KEY UPDATE archived_by = VALUES(archived_by), archived_at = NOW(), reason = VALUES(reason)")
                    ->execute(['report_id' => $reportId, 'user_id' => $user['id'], 'reason' => 'Client confirmed receipt of returned document.']);

                // Log action
                $pdo->prepare("INSERT INTO report_logs (report_id, old_department, new_department, action, remarks, updated_by) VALUES (:report_id, :old_dept, :new_dept, 'client_confirmed_receipt', :remarks, :user_id)")
                    ->execute(['report_id' => $reportId, 'old_dept' => $report['department_id'], 'new_dept' => $report['department_id'], 'remarks' => 'Client confirmed receiving the returned document.', 'user_id' => $user['id']]);

                $pdo->prepare("UPDATE report_workflow_routes SET status = 'Completed', completed_date = NOW(), approver_notes = 'Resident confirmed receipt of the document' WHERE report_id = :report_id AND step_number = :step_number AND destination_type = 'Resident'")
                    ->execute(['report_id' => $reportId, 'step_number' => (int)$report['current_workflow_step']]);

                auditDocumentWorkflowAction(
                    $pdo,
                    (int) $user['id'],
                    'CLIENT_RECEIPT_CONFIRMED',
                    'Document receipt confirmed by client ' . (string) ($user['name'] ?? 'user') . ' (user ID ' . (int) $user['id'] . ').',
                    $reportId,
                    null,
                    ['status' => (string) $report['status']],
                    ['status' => 'Completed', 'actor_id' => (int) $user['id'], 'actor_name' => (string) ($user['name'] ?? '')]
                );

                $pdo->commit();
                $success = 'You have confirmed that you received the document.';
                $stmt->execute(['id' => $reportId]); $report = $stmt->fetch();

                // JSON response for AJAX
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    $completedStep = (int)$report['total_workflow_steps'];
                    echo json_encode(['success' => true, 'message' => $success, 'report_status' => $report['status'], 'completed_step' => $completedStep]);
                    exit;
                }

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Failed to confirm receipt: ' . $e->getMessage();
            }
            }
        }
    } elseif ($action === 'update_status') {
        $newStatus = trim($_POST['status'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $advanceToNextDepartment = strtolower($newStatus) === 'approved';
        if ($advanceToNextDepartment && ($remarks === '' || strlen($remarks) > 4000)) {
            $error = 'Enter an approval reason of 1 to 1000 characters.';
        } else {
        $pdo->beginTransaction();
        try {
            $fallbackDepartmentId = (int)($report['department_id'] ?? $report['assigned_department_id'] ?? $report['sender_department_id'] ?? $report['originating_department_id'] ?? 0);
            $reportStatusToSave = $newStatus;
            $nextDepartmentId = null; $nextStepNumber = null;

            if ($advanceToNextDepartment) {
                $currentRouteStmt = $pdo->prepare("SELECT destination_type FROM report_workflow_routes WHERE report_id = :report_id AND step_number = :step_number LIMIT 1");
                $currentRouteStmt->execute(['report_id' => $reportId, 'step_number' => (int)$report['current_workflow_step']]);
                $currentRoute = $currentRouteStmt->fetch();
                if (strtoupper((string)($currentRoute['destination_type'] ?? 'Department')) === 'RESIDENT') {
                    throw new Exception('Resident must confirm receipt using the client Receive button; it cannot be approved by a department.');
                }

                // The chain is data-driven: any number of route rows can be assigned to a document.
                $pdo->prepare("UPDATE report_workflow_routes SET status = 'Approved', completed_date = NOW(), approver_notes = :remarks WHERE report_id = :report_id AND step_number = :current_step")
                    ->execute(['remarks'=>$remarks, 'report_id'=>$reportId, 'current_step'=>(int)$report['current_workflow_step']]);
                $nextRouteStmt = $pdo->prepare("SELECT step_number, department_id, destination_type, destination_id FROM report_workflow_routes WHERE report_id = :report_id AND step_number > :current_step AND status IN ('Pending', 'Awaiting Receiving', 'In Progress') ORDER BY step_number ASC LIMIT 1");
                $nextRouteStmt->execute([
                    'report_id' => $reportId,
                    'current_step' => (int)$report['current_workflow_step']
                ]);
                $nextRoute = $nextRouteStmt->fetch();

                if ($nextRoute) {
                    $nextDepartmentId = (int)($nextRoute['department_id'] ?? 0);
                    $nextStepNumber = (int)$nextRoute['step_number'];

                    // The next department must explicitly acknowledge receipt before it becomes in progress.
                    $activateNextRoute = $pdo->prepare("UPDATE report_workflow_routes SET status = 'Awaiting Receiving' WHERE report_id = :report_id AND step_number = :step_number");
                    $activateNextRoute->execute([
                        'report_id' => $reportId,
                        'step_number' => $nextStepNumber
                    ]);

                    $reportStatusToSave = ($nextRoute['destination_type'] ?? 'Department') === 'Resident' ? 'RETURNED_TO_CLIENT' : 'Assigned';
                } else {
                    // Final department approval: return to the originating department so they can receive the completed document.
                    $returnDeptId = (int)($report['originating_department_id'] ?? $report['sender_department_id'] ?? $report['department_id'] ?? 0);
                    $reportStatusToSave = $returnDeptId > 0 ? 'RETURNED_TO_ORIGIN' : 'RETURNED_TO_CLIENT';
                    $nextDepartmentId = $returnDeptId > 0 ? $returnDeptId : null;
                    $nextStepNumber = null;

                    // Ensure the document is returned to a valid department instead of leaving department_id null.
                    if ($nextDepartmentId > 0) {
                        $pdo->prepare("UPDATE reports SET department_id = :dept_id1, assigned_department_id = :dept_id2 WHERE id = :id")
                            ->execute(['dept_id1' => $nextDepartmentId, 'dept_id2' => $nextDepartmentId, 'id' => $reportId]);
                    }
                    $pdo->prepare("UPDATE reports SET total_workflow_steps = total_workflow_steps + 1 WHERE id = :id")->execute(['id' => $reportId]);
                }
            }

            $setParts = ["status = :status"];
            $updateParams = [
                'status' => $reportStatusToSave,
            ];

            if ($advanceToNextDepartment && $nextStepNumber !== null) {
                $setParts[] = "current_workflow_step = :current_step";
                $updateParams['current_step'] = $nextStepNumber;
                if ($nextDepartmentId !== null && $nextDepartmentId > 0) {
                    $setParts[] = "department_id = :department_id";
                    $setParts[] = "assigned_department_id = :assigned_department_id";
                    $updateParams['department_id'] = $nextDepartmentId;
                    $updateParams['assigned_department_id'] = $nextDepartmentId;
                }
            } elseif ($fallbackDepartmentId > 0) {
                $setParts[] = "department_id = COALESCE(department_id, :fallback_department_id)";
                $updateParams['fallback_department_id'] = $fallbackDepartmentId;
            }

            $updateSql = "UPDATE reports SET " . implode(', ', $setParts) . " WHERE id = :report_id";
            $updateParams['report_id'] = $reportId;
            $uStmt = $pdo->prepare($updateSql);
            $uStmt->execute($updateParams);

            $logNewDepartmentId = ($nextDepartmentId !== null && $nextDepartmentId > 0)
                ? $nextDepartmentId
                : (($report['department_id'] ?? 0) > 0 ? (int)$report['department_id'] : null);
            $log = $pdo->prepare("INSERT INTO report_logs (report_id, old_department, new_department, action, remarks, updated_by) VALUES (:report_id, :old_dept, :new_dept, 'status_change', :remarks, :user_id)");
            $log->execute([
                'report_id' => $reportId,
                'old_dept' => $report['department_id'],
                'new_dept' => $logNewDepartmentId,
                'remarks' => ($advanceToNextDepartment && in_array(strtoupper($reportStatusToSave), ['RETURNED_TO_ORIGIN', 'RETURNED_TO_CLIENT']))
                    ? "All assigned departments approved. The document was returned to the creator for final receipt confirmation. " . $remarks
                    : (($advanceToNextDepartment && $nextDepartmentId !== null && $nextStepNumber !== null)
                    ? "Approved and forwarded to the next department. " . $remarks
                    : "Status updated to '{$newStatus}'. " . $remarks),
                'user_id' => $user['id']
            ]);
            auditDocumentWorkflowAction(
                $pdo,
                (int) $user['id'],
                $advanceToNextDepartment ? 'DOCUMENT_APPROVED' : 'DOCUMENT_STATUS_CHANGED',
                ($advanceToNextDepartment ? 'Document approved by ' : 'Document status changed by ')
                    . (string) ($user['name'] ?? 'administrator') . ' (user ID ' . (int) $user['id'] . ').'
                    . ($advanceToNextDepartment ? ' Approval reason: ' . $remarks : ' Remarks: ' . $remarks),
                $reportId,
                (int) ($report['department_id'] ?? 0),
                ['status' => (string) $report['status'], 'workflow_step' => (int) $report['current_workflow_step']],
                [
                    'status' => (string) $reportStatusToSave,
                    'workflow_step' => $nextStepNumber ?? (int) $report['current_workflow_step'],
                    'reason' => $remarks,
                    'actor_id' => (int) $user['id'],
                    'actor_name' => (string) ($user['name'] ?? ''),
                ]
            );
            if ($advanceToNextDepartment && strtoupper($reportStatusToSave) === 'RETURNED_TO_ORIGIN' && $nextDepartmentId !== null) {
                sendDepartmentNotification($pdo, $nextDepartmentId, $trackingNo, "Document {$trackingNo} has returned to the originating department and is awaiting receipt.");
            } elseif ($advanceToNextDepartment && strtoupper($reportStatusToSave) === 'RETURNED_TO_CLIENT') {
                $clientMessage = "Your document {$trackingNo} has completed all department processing and is now ready for your final receipt confirmation.";
                sendUserNotification($pdo, $report['client_id'], $trackingNo, $clientMessage);

                $clientEmail = trim((string)($report['client_email'] ?? ''));
                if ($clientEmail === '' && !empty($report['client_id'])) {
                    $clientStmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
                    $clientStmt->execute(['id' => (int)$report['client_id']]);
                    $clientEmail = trim((string)$clientStmt->fetchColumn());
                }
                if ($clientEmail !== '') {
                    require_once APP_ROOT . '/includes/mailer.php';
                    $emailSubject = "Document Ready for Receipt: {$trackingNo}";
                    $emailBody = "Hello " . ($report['client_name'] ?? 'Document Owner') . ",\n\n" .
                        "Your document {$trackingNo} has completed all department processing and is now ready for your final receipt confirmation.\n\n" .
                        "Please sign in to the Document Tracking System and use the client Receive button to confirm receipt.\n\n" .
                        "Document details: " . (isset($_SERVER['HTTP_HOST']) ? "http://{$_SERVER['HTTP_HOST']}/report_details.php?id={$reportId}" : "/report_details.php?id={$reportId}") . "\n\n" .
                        "Thank you,\nDocument Tracking System";
                    sendEmailNotification($clientEmail, $emailSubject, $emailBody);
                }
            } elseif ($advanceToNextDepartment && $nextDepartmentId !== null) {
                sendDepartmentNotification($pdo, $nextDepartmentId, $trackingNo, "Document {$trackingNo} was approved and assigned to your department.");
                // Optional email to next department that they have a new assigned document
                $emailSubject = "Document {$trackingNo} assigned to your department";
                $emailBody = "Hello,\n\nDocument {$trackingNo} has been approved and assigned to your department.\nPlease log in to the Document Tracking System to confirm receipt.\n\nLink: " . (isset($_SERVER['HTTP_HOST']) ? ('http://' . $_SERVER['HTTP_HOST'] . "/report_details.php?id={$reportId}") : ("/report_details.php?id={$reportId}"));
                try { sendEmailNotificationToDepartment($pdo, $nextDepartmentId, $emailSubject, $emailBody); } catch (Exception $e) { error_log('Email notify failed: '.$e->getMessage()); }
            }
            if ($advanceToNextDepartment && strtoupper($reportStatusToSave) !== 'RETURNED_TO_CLIENT' && !empty($report['client_id'])) {
                sendUserNotification($pdo, $report['client_id'], $trackingNo, "Your document {$trackingNo} was approved and forwarded to the next workflow step.");
            }
            $pdo->commit();
            $success = "Status updated successfully!";
            $stmt->execute(['id' => $reportId]);
            $report = $stmt->fetch();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to update status: " . $e->getMessage();
        }
        }
    }
}

// Fetch workflow routes
$routesStmt = $pdo->prepare("SELECT rwf.*, COALESCE(d.name, CASE WHEN rwf.destination_type = 'Resident' THEN 'Resident' ELSE 'Department' END) as dept_name FROM report_workflow_routes rwf LEFT JOIN departments d ON rwf.department_id = d.id WHERE rwf.report_id = :id ORDER BY rwf.step_order ASC, rwf.step_number ASC");
$routesStmt->execute(['id' => $reportId]);
$workflowRoutes = $routesStmt->fetchAll();
$currentRouteStatus = '';
$currentDestinationType = '';
$currentRouteId = 0;
foreach ($workflowRoutes as $workflowRoute) {
    if ((int)$workflowRoute['step_number'] === (int)$report['current_workflow_step']) {
        $currentRouteId = (int)$workflowRoute['id'];
        $currentRouteStatus = strtolower($workflowRoute['status']);
        $currentDestinationType = $workflowRoute['destination_type'] ?? 'Department';
        if ($currentDestinationType === 'Resident' && $currentRouteStatus === '') $currentRouteStatus = 'awaiting receiving';
        // Assigned means transit. It is not in progress until Receiving is acknowledged.
        if (strtolower($report['status']) === 'assigned') $currentRouteStatus = 'awaiting receiving';
        break;
    }
}
$isResidentReceiptPending = hasPermission('complete_own_documents', $user)
    && (int)($user['id'] ?? 0) === (int)($report['client_id'] ?? 0)
    && $currentDestinationType === 'Resident'
    && in_array($currentRouteStatus, ['pending', 'awaiting receiving'], true)
    && strtoupper($report['status'] ?? '') !== 'COMPLETED';

// Fetch logs
$logsStmt = $pdo->prepare("SELECT l.*, u.name as updater_name, u.role as updater_role, d_old.name as old_dept_name, d_new.name as new_dept_name FROM report_logs l LEFT JOIN users u ON l.updated_by = u.id LEFT JOIN departments d_old ON l.old_department = d_old.id LEFT JOIN departments d_new ON l.new_department = d_new.id WHERE l.report_id = :id ORDER BY l.date_updated DESC");
$logsStmt->execute(['id' => $reportId]);
$logs = $logsStmt->fetchAll();
$rejectionLog = null;
foreach ($logs as $log) {
    if (strtolower((string) ($log['action'] ?? '')) === 'document_rejected') {
        $rejectionLog = $log;
        break;
    }
}

$attachmentsStmt = $pdo->prepare('SELECT a.id, a.original_name, a.mime_type, a.file_size, a.version_number, a.revision_note, a.requirement_name, a.created_at, u.name AS uploaded_by_name FROM attachments a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.report_id = :report_id ORDER BY a.version_number ASC, a.created_at ASC');
$attachmentsStmt->execute(['report_id' => $reportId]);
$attachments = $attachmentsStmt->fetchAll();

$activityAnswers = json_decode((string) ($report['activity_details_answers'] ?? ''), true);
if (!is_array($activityAnswers)) {
    $activityAnswers = [];
}

// Build a map of received info per department based on 'document_received' log entries
$receivedByDept = [];
$originReceiptLog = null;
foreach ($logs as $lg) {
    if (isset($lg['action']) && strtolower($lg['action']) === 'document_received') {
        $deptId = (int)($lg['new_department'] ?? 0);
        if ($deptId > 0 && !isset($receivedByDept[$deptId])) {
            $receivedByDept[$deptId] = [
                'date' => $lg['date_updated'] ?? ($lg['created_at'] ?? null),
                'by' => $lg['updater_name'] ?? 'System',
                'remarks' => $lg['remarks'] ?? ''
            ];
        }
    }
    if ($originReceiptLog === null && in_array($lg['action'] ?? '', ['origin_accepted_and_archived', 'client_confirmed_receipt', 'client_complete_document'])) {
        $originReceiptLog = $lg;
    }
}

$trackingNo = $report['tracking_number'];
$submissionModeLabel = ($report['submission_mode'] ?? 'online') === 'walkin' ? 'Walk-in' : 'Online';

require_once APP_ROOT . '/includes/header.php';
?>

<div style="margin-bottom: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-top: 0.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1>Document <?= $trackingNo ?></h1>
            <p style="color: #64748b; font-size: 0.9rem;">Submitted on <?= date('m/d/Y - h:i A', strtotime($report['created_at'])) ?></p>
            <?php if (!empty($report['service_name'])): ?>
                <p style="margin: 0.35rem 0 0; color: #334155;"><strong>Requested Service:</strong> <?= htmlspecialchars($report['service_name']) ?></p>
            <?php endif; ?>
            <?php if ($activityAnswers): ?>
                <div style="margin-top:.8rem; padding:.8rem 1rem; border:1px solid #dbeafe; border-radius:8px; background:#eff6ff;">
                    <strong style="color:#1e3a8a;">Submitted Activity Details</strong>
                    <div style="display:grid; gap:.3rem; margin-top:.45rem; font-size:.88rem;">
                        <?php foreach ($activityAnswers as $answer): ?>
                            <div><strong><?= htmlspecialchars((string) ($answer['label'] ?? 'Detail')) ?>:</strong> <?= htmlspecialchars((string) ($answer['value'] ?? '')) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
            <?php if ($perm['can_view_content']): ?>
                <a href="receiving_copy.php?id=<?= $report['id'] ?>" target="_blank" class="btn btn-primary">🖨️ Print Receiving Copy</a>
            <?php endif; ?>
            <?php 
                $statusClass = 'badge-pending';
                $st = strtolower(str_replace(' ', '-', $report['status']));
                if ($st === 'assigned' || $st === 'under-review') $statusClass = 'badge-assigned';
                elseif ($st === 'processing') $statusClass = 'badge-processing';
                elseif ($st === 'approved' || $st === 'completed') $statusClass = 'badge-approved';
                elseif ($st === 'returned' || $st === 'returned-to-origin' || $st === 'rejected') $statusClass = 'badge-rejected';
            ?>
            <span class="badge <?= $statusClass ?>" style="font-size: 0.9rem; padding: 0.5rem 1rem;"><?= htmlspecialchars($report['status']) ?></span>
            <?php if (!empty($report['deadline_at'])): ?>
                <?php $deadlineOverdue = strtotime($report['deadline_at']) < time() && !in_array($report['status'], ['Completed', 'Approved', 'Rejected'], true); ?>
                <span class="badge <?= $deadlineOverdue ? 'badge-rejected' : 'badge-assigned' ?>" style="font-size:.9rem;padding:.5rem 1rem;"><?= $deadlineOverdue ? '⚠️ Overdue' : 'Due ' . date('m/d/Y', strtotime($report['deadline_at'])) ?></span>
            <?php endif; ?>
        </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- SUPER ADMIN PRIVACY NOTICE -->
<?php if (hasPermission('override_department_privacy', $user)): ?>
    <div class="alert alert-info" style="background: #eff6ff; border: 1px solid #93c5fd; color: #1e40af; border-radius: 8px; padding: 1.25rem;">
        <h4 style="margin-bottom: 0.3rem; font-weight: 800;">Administrator Access</h4>
        <p style="margin: 0; font-size: 0.9rem;">Administrators can review document content, attachments, workflow history, and processing activity.</p>
    </div>
<?php endif; ?>

<?php
$isDocumentOwner = (int) ($user['id'] ?? 0) === (int) ($report['client_id'] ?? 0);
$canUploadDocumentFile = !isSuperAdmin($user)
    && hasPermission('manage_file_revisions', $user)
    && ($perm['can_modify'] || $isDocumentOwner);
$assignedDepartmentId = (int) ($report['assigned_department_id'] ?? $report['department_id'] ?? 0);
$canUploadClearance = $canUploadDocumentFile
    && $perm['can_modify']
    && (isSuperAdmin($user) || ((int) ($user['department_id'] ?? 0) > 0 && (int) $user['department_id'] === $assignedDepartmentId));
?>

<?php if ($perm['can_view_content']): ?>
    <div class="card" style="margin-bottom: 1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
            <h3 style="margin: 0;">Document File Versions</h3>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <button type="button" class="btn btn-primary" title="View file versions" aria-label="View file versions" onclick="document.getElementById('document-versions-modal').hidden=false;">
                     <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16">
  <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"></path>
  <path d="M8 5.5a2.5 2.5 
0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"></path>
</svg>
                <?php if ($canUploadDocumentFile): ?>
                    <button type="button" class="btn btn-light" title="<?= $canUploadClearance ? 'Upload clearance file' : 'Upload document revision' ?>" aria-label="<?= $canUploadClearance ? 'Upload clearance file' : 'Upload document revision' ?>" onclick="document.getElementById('clearance-upload-modal').hidden=false;">
                        <i class="bi bi-upload"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <p style="margin:.75rem 0 0;color:#64748b;">Open the modal to preview PDF, DOCX, and XLSX files, search versions, or download originals.</p>
    </div>

    <div id="document-versions-modal" class="document-versions-modal" hidden>
        <div class="document-versions-dialog" role="dialog" aria-modal="true" aria-labelledby="document-versions-title">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem;">
                <h3 id="document-versions-title" style="margin:0;">Document File Versions</h3>
                <button type="button" class="document-versions-close" title="Close details" aria-label="Cancel and close file versions" onclick="document.getElementById('document-versions-modal').hidden=true;">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
            <div class="table-responsive document-versions-table-wrap">
                <table class="table table-striped table-hover align-middle mb-0 document-versions-table" data-datatable-responsive="true" data-datatable-search="true" data-datatable-paging="true" data-datatable-ordering="true" data-datatable-info="true" data-datatable-order='[[0,"asc"]]'>
                    <thead>
                        <tr>
                            <th>Version</th>
                            <th>File</th>
                            <?php if (hasPermission('manage_reports', $user) || hasPermission('manage_file_revisions', $user)): ?><th>Uploaded By</th><?php endif; ?>
                            <th>Date Uploaded</th>
                            <th>Notes</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attachments as $attachment): ?>
                            <tr>
                                <td data-label="Version"><?= (int)$attachment['version_number'] ?></td>
                                <td data-label="File"><?= !empty($attachment['requirement_name']) ? htmlspecialchars($attachment['requirement_name']) . ': ' : '' ?><strong><?= htmlspecialchars($attachment['original_name']) ?></strong><br><small><?= number_format(((int)$attachment['file_size']) / 1024, 1) ?> KB</small></td>
                                <?php if (hasPermission('manage_reports', $user) || hasPermission('manage_file_revisions', $user)): ?><td data-label="Uploaded By"><?= htmlspecialchars($attachment['uploaded_by_name'] ?? 'System') ?></td><?php endif; ?>
                                <td data-label="Date Uploaded"><?= date('m/d/Y h:i A', strtotime($attachment['created_at'])) ?></td>
                                <td data-label="Notes"><?= htmlspecialchars($attachment['revision_note'] ?? '') ?></td>
                                <td data-label="Action" style="max-width:100%;">
                                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;max-width:100%;">
                                    <?php if (strtolower((string) ($attachment['mime_type'] ?? '')) === 'application/pdf'): ?>
                                        <a href="download_attachment.php?id=<?= (int)$attachment['id'] ?>&amp;view=1" class="btn btn-primary" style="box-sizing:border-box;flex:0 0 2.5rem;width:2.5rem;height:2.5rem;padding:0;display:inline-flex;align-items:center;justify-content:center;" title="View PDF" aria-label="View <?= htmlspecialchars($attachment['original_name']) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                    <?php elseif (officePreviewMimeType($attachment) !== null): ?>
                                        <a href="document_viewer.php?id=<?= (int)$attachment['id'] ?>" class="btn btn-primary" style="box-sizing:border-box;flex:0 0 2.5rem;width:2.5rem;height:2.5rem;padding:0;display:inline-flex;align-items:center;justify-content:center;" title="View document" aria-label="View <?= htmlspecialchars($attachment['original_name']) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                    <?php endif; ?>
                                    <a href="download_attachment.php?id=<?= (int)$attachment['id'] ?>" class="icon-circle-button primary" style="box-sizing:border-box;flex:0 0 2.5rem;width:2.5rem;height:2.5rem;padding:0;display:inline-flex;align-items:center;justify-content:center;" title="Download file" aria-label="Download <?= htmlspecialchars($attachment['original_name']) ?>"><i class="bi bi-download" aria-hidden="true"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (empty($attachments)): ?><p style="color:#64748b;margin:1rem 0 0;">No files uploaded.</p><?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($canUploadDocumentFile): ?>
    <div id="clearance-upload-modal" hidden style="position:fixed; inset:0; z-index:2000; display:flex; align-items:center; justify-content:center; padding:1rem; background:rgba(15,23,42,.55);">
        <div role="dialog" aria-modal="true" aria-labelledby="clearance-upload-title" style="width:min(100%,460px); border-radius:14px; background:#fff; padding:1.5rem; box-shadow:0 20px 50px rgba(15,23,42,.25);">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1rem;">
                <h3 id="clearance-upload-title" style="margin:0; font-size:1.15rem;"><?= $canUploadClearance ? 'Upload Clearance File' : 'Upload Document Revision' ?></h3>
            </div>
            <form method="POST" action="upload_revision.php" enctype="multipart/form-data">
                <input type="hidden" name="report_id" value="<?= (int)$reportId ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="upload_type" value="<?= $canUploadClearance ? 'clearance' : 'revision' ?>">
                <label for="clearance-revision-file" class="form-label"><?= $canUploadClearance ? 'Clearance file' : 'Revised document file' ?></label>
                <input id="clearance-revision-file" type="file" name="revision_file" class="form-control mb-3" accept=".pdf,.jpg,.jpeg" required>
                <small class="form-text text-muted">PDF or JPG/JPEG only, up to 10 MB.</small>
                <label for="clearance-revision-note" class="form-label"><?= $canUploadClearance ? 'Clearance note' : 'Revision note' ?> <span style="color:#64748b;">(optional)</span></label>
                <input id="clearance-revision-note" type="text" name="revision_note" class="form-control mb-3" placeholder="<?= $canUploadClearance ? 'Clearance note (optional)' : 'Revision note (optional)' ?>" maxlength="500">
                <div style="display:flex; justify-content:flex-end; gap:.5rem;">
                    <button type="button" class="icon-circle-button secondary" title="Cancel" aria-label="Cancel" onclick="document.getElementById('clearance-upload-modal').hidden=true;">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                    <button type="submit" class="btn btn-light" title="<?= $canUploadClearance ? 'Upload Clearance' : 'Upload Revision' ?>" aria-label="<?= $canUploadClearance ? 'Upload clearance' : 'Upload revision' ?>">
                        <i class="bi bi-upload"></i></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- SHOPEE-STYLE WORKFLOW PROGRESS TRACKER -->
<div class="card document-workflow-shell" style="border-left: 4px solid var(--primary); background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);">
    <div class="workflow-main-header">
        <h3 class="workflow-main-title">Full Workflow Status</h3>
        <div class="workflow-center-pill">
            <span class="workflow-pill-title">Document <?= $trackingNo ?> Details</span>
            <span class="workflow-pill-subtitle">Submission date: <?= date('m/d/Y', strtotime($report['created_at'])) ?></span>
        </div>
    </div>

    <div class="workflow-progress">
        <?php
        $allRoutes = $workflowRoutes;
        if (empty($allRoutes)) {
            $allRoutes = [['step_number' => 1, 'dept_name' => $report['receiving_dept_name'] ?? 'Receiving Desk', 'status' => $report['status'] === 'Completed' ? 'Completed' : ($report['status'] === 'Pending' ? 'Pending' : 'In Progress'), 'completed_date' => null]];
        }
        $originDeptName = $report['originating_dept_name'] ?? $report['sending_dept_name'] ?? 'Originating Department';
        $isOriginUser = (
            (int)($user['department_id'] ?? 0) === (int)($report['originating_department_id'] ?? 0) ||
            (int)($user['department_id'] ?? 0) === (int)($report['sender_department_id'] ?? 0) ||
            (int)($user['id'] ?? 0) === (int)($report['client_id'] ?? 0) ||
            hasPermission('override_workflow', $user)
        );
        $originAcceptanceVisible = (
            in_array(strtoupper($report['status'] ?? ''), ['RETURNED_TO_ORIGIN', 'RETURNED_TO_CLIENT', 'PROCESSING']) &&
            $isOriginUser
        );
        $isDocumentCompleted = (strtoupper($report['status'] ?? '') === 'COMPLETED' || $originReceiptLog !== null);

        $totalSteps = count($allRoutes);
        $currentStepNum = (int)$report['current_workflow_step'];
        $completedCount = 0;
        foreach ($allRoutes as $r) {
            $stLower = strtolower($r['status']);
            if ($stLower === 'completed' || $stLower === 'approved') $completedCount++;
        }
        $progressPercent = $totalSteps > 0 ? ($completedCount / $totalSteps) * 100 : 0;
        ?>
        <div class="progress-track-fill" style="width: <?= min($progressPercent, 100) ?>%;"></div>

        <?php foreach ($allRoutes as $idx => $r): 
            $st = strtolower($r['status']);
            $stepNum = (int)$r['step_number'];
            $stepClass = 'pending';
            if ($st === 'completed' || $st === 'approved') { $stepClass = 'completed'; }
            elseif ($st === 'in progress') { $stepClass = 'current'; }
            elseif ($st === 'rejected') { $stepClass = 'rejected'; }
            elseif ($st === 'returned') { $stepClass = 'returned'; }
            $displayStatus = $r['status'];
            $isTransitTarget = strtolower($report['status']) === 'assigned' && $stepNum === $currentStepNum;
            if ($st === 'awaiting receiving' || $isTransitTarget || (($r['destination_type'] ?? '') === 'Resident' && in_array($st, ['pending', 'awaiting receiving'], true))) {
                $stepClass = 'pending';
                $displayStatus = 'Awaiting Receiving';
            }
            $deptShortName = $r['dept_name'] ?: (($r['destination_type'] ?? '') === 'Resident' ? 'Resident' : 'Department');
            if (strlen($deptShortName) > 30) { $deptShortName = substr($deptShortName, 0, 28) . '...'; }

            // Choose center icon based on status
            $centerIconHtml = '';
            if ($st === 'awaiting receiving' || $isTransitTarget) {
                // Not yet received - show receive icon
                $centerIconHtml = '<span class="step-icon" aria-hidden="true">📥</span>';
            } elseif ($stepClass === 'completed') {
                $centerIconHtml = '<span class="step-icon" aria-hidden="true">✓</span>';
            } elseif ($stepClass === 'current') {
                $centerIconHtml = '<span class="step-icon" aria-hidden="true">⟳</span>';
            } elseif ($stepClass === 'rejected') {
                $centerIconHtml = '<span class="step-icon" aria-hidden="true">✕</span>';
            } else {
                $centerIconHtml = '<span class="step-number-text">' . $stepNum . '</span>';
            }
        ?>
            <div class="workflow-step <?= $stepClass ?>" data-step="<?= $stepNum ?>">
                <div class="workflow-step-badge">Assigned: <?= htmlspecialchars($r['dept_name']) ?></div>
                <div class="step-circle">
                    <?= $centerIconHtml ?>
                </div>
                <div class="step-label"><?= htmlspecialchars($deptShortName) ?> - <?= htmlspecialchars($displayStatus) ?></div>
                <?php if ($r['completed_date']): ?>
                    <span class="step-date"><?= date('m/d/Y', strtotime($r['completed_date'])) ?></span>
                <?php endif; ?>
                <?php if (!empty($r['department_id']) && isset($receivedByDept[(int)$r['department_id']])): 
                    $rec = $receivedByDept[(int)$r['department_id']];
                ?>
                    <div class="step-received">Received by <?= htmlspecialchars($rec['by']) ?> • <?= date('m/d/Y h:i A', strtotime($rec['date'])) ?><?php if (!empty($rec['remarks'])): ?> — <?= htmlspecialchars($rec['remarks']) ?><?php endif; ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

    </div>

    <?php if (strtoupper((string) ($report['status'] ?? '')) === 'REJECTED'): ?>
        <details class="rejection-reason-card">
            <summary>
                <span class="rejection-reason-icon"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></span>
                <span>
                    <strong>Document Rejected</strong>
                    <small>Click to view the reason</small>
                </span>
                <i class="bi bi-chevron-down rejection-reason-chevron" aria-hidden="true"></i>
            </summary>
            <div class="rejection-reason-content">
                <?php if ($rejectionLog): ?>
                    <div class="rejection-reason-meta">
                        Rejected by <?= htmlspecialchars($rejectionLog['updater_name'] ?? 'System') ?>
                        <?php if (!empty($rejectionLog['date_updated'])): ?>
                            · <?= date('m/d/Y h:i A', strtotime($rejectionLog['date_updated'])) ?>
                        <?php endif; ?>
                    </div>
                    <p><?= nl2br(htmlspecialchars((string) ($rejectionLog['remarks'] ?? 'No rejection reason was recorded.'))) ?></p>
                <?php else: ?>
                    <p>No rejection reason was recorded for this document.</p>
                <?php endif; ?>
            </div>
        </details>
    <?php endif; ?>

    <?php
    $isClientReceiptActionAvailable = $isDocumentOwner
        && isClientUser($user)
        && $isResidentReceiptPending
        && !$isDocumentCompleted
        && hasPermission('complete_own_documents', $user);
    $showWorkflowActions = $canPerformDepartmentWorkflowActions || $isClientReceiptActionAvailable;
    ?>
    <!-- UNIFIED WORKFLOW ACTION & RETURN RECEIPT PANEL -->
    <?php if ($showWorkflowActions): ?>
    <div class="processing-actions-panel" style="margin-top: 1.25rem; border-top: 1px dashed #cbd5e1; padding-top: 1.25rem;">
        <?php if ($isDocumentCompleted): ?>
            <!-- Resident receipt confirmation -->
            <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 10px; padding: 1rem 1.25rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem; color: #166534; margin-bottom: 0.5rem;">
                    <span style="font-size: 1.35rem;">✅</span>
                    <div>
                        <h4 style="margin: 0; font-size: 0.95rem; font-weight: 700; color: #15803d;">Resident Receipt Confirmed</h4>
                        <p style="margin: 0.1rem 0 0 0; font-size: 0.83rem; color: #166534;">The resident confirmed receipt of the document. The workflow is complete.</p>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.6rem; background: #ffffff; padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid #bbf7d0; font-size: 0.85rem;">
                    <div>
                        <span style="color: #4b5563; font-size: 0.75rem; display: block; font-weight: 600;">Received By:</span>
                        <strong style="color: #111827;"><?= htmlspecialchars($originReceiptLog['updater_name'] ?? $report['client_name'] ?? $user['name'] ?? 'Resident') ?></strong>
                    </div>
                    <div>
                        <span style="color: #4b5563; font-size: 0.75rem; display: block; font-weight: 600;">Petsa at Oras (Date &amp; Time):</span>
                        <strong style="color: #111827;"><?= date('m/d/Y - h:i A', strtotime($originReceiptLog['date_updated'] ?? $report['updated_at'] ?? 'now')) ?></strong>
                    </div>
                    <div>
                        <span style="color: #4b5563; font-size: 0.75rem; display: block; font-weight: 600;">Remarks:</span>
                        <span style="color: #374151; font-style: italic;">Client confirmed receiving the document.</span>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <div class="action-button-row" style="display: flex; gap: 0.75rem; flex-wrap: wrap; justify-content: flex-end;">
                    <?php if ($canPerformDepartmentWorkflowActions && strtoupper($currentDestinationType) !== 'RESIDENT'): ?>
                        <?php if (hasPermission('approve_reports', $user) && ($currentRouteStatus === 'in progress' || strtolower($report['status']) === 'processing')): ?>
                            <button type="button" class="btn-action btn-action-success workflow-icon-button" title="Approve document" aria-label="Approve document" data-workflow-modal="approve"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
                        <?php endif; ?>

                        <?php if (hasPermission('manage_reports', $user) && ($currentRouteStatus === 'awaiting receiving' || $currentRouteStatus === 'pending')): ?>
                            <button type="button" class="btn-action btn-action-primary workflow-icon-button" title="Confirm document receipt" aria-label="Confirm document receipt" data-workflow-modal="receive"><i class="bi bi-inbox-fill" aria-hidden="true"></i></button>
                        <?php endif; ?>

                        <?php if (hasPermission('route_reports', $user) && $report['sender_department_id'] && $report['sender_department_id'] != $report['department_id']): ?>
                            <button type="button" class="btn-action btn-action-danger workflow-icon-button" title="Return to sender" aria-label="Return to sender" data-workflow-modal="return">
                                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                            </button>
                        <?php endif; ?>
                        <?php if (hasPermission('approve_reports', $user) && !in_array(strtoupper((string) $report['status']), ['REJECTED', 'COMPLETED', 'APPROVED'], true) && !in_array($currentRouteStatus, ['completed', 'approved', 'rejected'], true)): ?>
                            <button type="button" class="btn-action btn-action-danger workflow-icon-button workflow-reject-trigger" title="Reject document" aria-label="Reject document" data-workflow-modal="reject">
                                <i class="bi bi-x-circle" aria-hidden="true"></i>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ($isResidentReceiptPending && !$isDocumentCompleted): ?>
                    <form method="POST" action="report_details.php?id=<?= $reportId ?>" class="action-button-form" style="width: 100%; margin-top: 0.5rem;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                        <input type="hidden" name="action_type" value="client_complete_document">
                        <button type="submit" class="btn-action btn-action-success" data-confirm-action="receive" style="width: 100%; justify-content: center;">📥 Receive Document (Client Confirmation)</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div style="display: flex; flex-direction: column; gap: 1.5rem; width: 100%; margin-top: 1.5rem;">
    <?php if ($perm['can_view_content']): ?>
        <div class="card" style="width: 100%; margin: 0;">
            <h3 style="margin-bottom: 0.75rem; color: #0f172a;">Document Title &amp; Details</h3>
            <h2 style="font-size: 1.3rem; font-weight: 800; color: #0f172a; margin-bottom: 0.75rem;"><?= htmlspecialchars($report['title']) ?></h2>
            <p style="white-space: pre-line; color: #334155; font-size: 0.98rem; line-height: 1.6;"><?= htmlspecialchars($report['description']) ?></p>
        </div>
    <?php endif; ?>

    <div class="card" style="width: 100%; margin: 0;">
        <h3 style="margin-bottom: 1rem; color: #0f172a; font-size: 1.1rem;">Document Information</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; background: #f8fafc; padding: 1.25rem; border-radius: 10px; border: 1px solid #e2e8f0;">
            <div>
                <span style="color: #64748b; display: block; font-size: 0.8rem; font-weight: 500;">Unique ID.</span>
                <strong style="font-family: monospace; font-size: 1.1rem; color: #2563eb;"><?= $trackingNo ?></strong>
            </div>
            <div>
                <span style="color: #64748b; display: block; font-size: 0.8rem; font-weight: 500;">Submission Type</span>
                <strong style="color: #0f172a; font-size: 0.95rem; text-transform: capitalize;">
                    <?= htmlspecialchars($submissionModeLabel) ?>
                </strong>
            </div>
            <div>
                <span style="color: #64748b; display: block; font-size: 0.8rem; font-weight: 500;">Submitted By</span>
                <strong style="color: #0f172a; font-size: 0.95rem; text-transform: capitalize;">
                    <?= htmlspecialchars(ucfirst((string) ($report['submission_source'] ?? 'client'))) ?>
                </strong>
            </div>
            <div>
                <span style="color: #64748b; display: block; font-size: 0.8rem; font-weight: 500;">Sender Department</span>
                <strong style="color: #0f172a; font-size: 0.95rem;">🏢 <?= htmlspecialchars($report['sending_dept_name'] ?? 'Origin Desk') ?></strong>
            </div>
            <div>
                <span style="color: #64748b; display: block; font-size: 0.8rem; font-weight: 500;">Current Assigned Department</span>
                <strong style="color: #0f172a; font-size: 0.95rem;">🏢 <?= htmlspecialchars($report['receiving_dept_name'] ?? 'Unassigned') ?></strong>
            </div>
            <div>
                <span style="color: #64748b; display: block; font-size: 0.8rem; font-weight: 500;">Priority</span>
                <strong style="text-transform: capitalize; color: #1e293b; font-size: 0.95rem;">
                    <?= htmlspecialchars($report['priority'] ?? 'Medium') ?>
                </strong>
            </div>
        </div>
    </div>

    <div class="card document-timeline-card" style="width: 100%; margin: 0;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; margin-bottom:1rem;">
            <div>
                <h3 style="margin:0;">Complete Document Timeline</h3>
                <p style="margin:.3rem 0 0; color:#64748b; font-size:.85rem;">Every submission, receipt, routing, status change, approval, return, and completion event is listed below.</p>
            </div>
            <span class="badge badge-assigned"><?= count($logs) ?> recorded events</span>
        </div>
        <?php if (empty($logs)): ?>
            <div style="padding:1rem; border:1px dashed #cbd5e1; border-radius:8px; color:#64748b; font-size:.9rem;">
                No timeline events have been recorded yet. The first event will appear when this document is submitted or processed.
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <?php foreach ($logs as $log): 
                    $act = strtolower($log['action'] ?? '');
                    $actIcon = '📝';
                    $actLabel = ucwords(str_replace('_', ' ', $log['action'] ?? 'Updated'));
                    $borderColor = '#64748b';

                    if ($act === 'created') {
                        $actIcon = '✨';
                        $actLabel = 'Document Created & Initialized';
                        $borderColor = '#2563eb';
                    } elseif ($act === 'document_received') {
                        $actIcon = '📥';
                        $actLabel = 'Document Received by ' . htmlspecialchars($log['new_dept_name'] ?? 'Department');
                        $borderColor = '#0284c7';
                    } elseif ($act === 'department_forwarded') {
                        $actIcon = '📤';
                        $actLabel = 'Forwarded: ' . htmlspecialchars($log['old_dept_name'] ?? 'Office') . ' ➔ ' . htmlspecialchars($log['new_dept_name'] ?? 'Target Office');
                        $borderColor = '#3b82f6';
                    } elseif ($act === 'returned_to_sender') {
                        $actIcon = '↩️';
                        $actLabel = 'Returned to: ' . htmlspecialchars($log['new_dept_name'] ?? 'Sender') . ' for Revisions';
                        $borderColor = '#ef4444';
                    } elseif ($act === 'status_change') {
                        $actIcon = '🔄';
                        $actLabel = 'Status Updated';
                        $borderColor = '#f59e0b';
                    } elseif (in_array($act, ['origin_accepted_and_archived', 'client_confirmed_receipt', 'client_complete_document'])) {
                        $actIcon = '✅';
                        $actLabel = 'Returned Document Received Back & Completed';
                        $borderColor = '#16a34a';
                    } elseif ($act === 'document_rejected') {
                        $actIcon = '✕';
                        $actLabel = 'Document Rejected';
                        $borderColor = '#dc2626';
                    }

                    $roleText = !empty($log['updater_role']) ? '(' . htmlspecialchars(ucwords(str_replace('_', ' ', $log['updater_role']))) . ')' : '';
                ?>
                    <div style="border-left: 4px solid <?= $borderColor ?>; padding: 0.75rem 1rem; background: #f8fafc; border-radius: 0 8px 8px 0; border: 1px solid #e2e8f0; border-left: 4px solid <?= $borderColor ?>;">
                        <div style="font-size: 0.82rem; color: #64748b; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                            <span><strong>👤 <?= htmlspecialchars($log['updater_name'] ?? 'System') ?></strong> <?= $roleText ?></span>
                            <span style="font-size: 0.8rem; color: #94a3b8;">📅 <?= date('m/d/Y - h:i A', strtotime($log['date_updated'])) ?></span>
                        </div>
                        <div style="font-size: 0.92rem; font-weight: 700; margin-top: 0.35rem; color: #0f172a; display: flex; align-items: center; gap: 0.4rem;">
                            <span><?= $actIcon ?></span> <span><?= $actLabel ?></span>
                        </div>
                        <?php if ($log['remarks']): ?>
                            <div style="font-size: 0.875rem; color: #475569; margin-top: 0.35rem; font-style: italic; background: #ffffff; padding: 0.4rem 0.6rem; border-radius: 6px; border: 1px solid #f1f5f9;">
                                "<?= htmlspecialchars($log['remarks']) ?>"
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<dialog id="workflow-action-modal" class="workflow-action-modal" aria-labelledby="workflow-action-modal-title">
    <div class="workflow-action-dialog">
        <div class="workflow-action-modal-header">
            <h3 id="workflow-action-modal-title">Document action</h3>
        </div>
        <?php if ($canPerformDepartmentWorkflowActions && strtoupper($currentDestinationType) !== 'RESIDENT'): ?>
            <?php if (hasPermission('approve_reports', $user) && ($currentRouteStatus === 'in progress' || strtolower($report['status']) === 'processing')): ?>
                <form id="approve-form-<?= $reportId ?>" method="POST" action="report_details.php?id=<?= $reportId ?>" class="action-button-form workflow-modal-form" data-modal-title="Approve document">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action_type" value="update_status">
                    <input type="hidden" name="status" value="Approved">
                    <p>Are you sure you want to approve this document and continue it to the next workflow step?</p>
                    <label for="approve-remarks-<?= $reportId ?>">Reason for approval <span aria-hidden="true">*</span></label>
                    <textarea id="approve-remarks-<?= $reportId ?>" name="remarks" rows="4" maxlength="1000" placeholder="Enter the reason for approving this document..." required></textarea>
                    <div class="workflow-modal-actions">
                        <button type="button" class="btn-action btn-action-secondary" data-workflow-modal-close>Cancel</button>
                        <button type="submit" class="btn-action btn-action-success">Approve Document</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (hasPermission('manage_reports', $user) && ($currentRouteStatus === 'awaiting receiving' || $currentRouteStatus === 'pending')): ?>
                <form id="receive-form-<?= $reportId ?>" method="POST" action="report_details.php?id=<?= $reportId ?>" class="action-button-form workflow-modal-form" data-modal-title="Confirm document receipt">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action_type" value="receive_document">
                    <label for="receiving_remarks_<?= $reportId ?>">Remarks or signature (optional)</label>
                    <textarea name="receiving_remarks" id="receiving_remarks_<?= $reportId ?>" rows="3" placeholder="Maglagay ng remarks o lagda kung kinakailangan"></textarea>
                    <div class="workflow-modal-actions">
                        <button type="button" class="btn-action btn-action-secondary" data-workflow-modal-close>Cancel</button>
                        <button type="submit" class="btn-action btn-action-primary">Confirm Receipt</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (hasPermission('route_reports', $user) && $report['sender_department_id'] && $report['sender_department_id'] != $report['department_id']): ?>
                <form id="return-form-<?= $reportId ?>" method="POST" action="report_details.php?id=<?= $reportId ?>" class="action-button-form workflow-modal-form" data-modal-title="Return document to sender">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action_type" value="return_department">
                    <label for="return-remarks-<?= $reportId ?>">Dahilan ng pagbabalik <span aria-hidden="true">*</span></label>
                    <textarea id="return-remarks-<?= $reportId ?>" name="return_remarks" rows="4" maxlength="1000" placeholder="Ilagay kung bakit ibinabalik ang dokumento..." required></textarea>
                    <div class="workflow-modal-actions">
                        <button type="button" class="btn-action btn-action-secondary" data-workflow-modal-close>Cancel</button>
                        <button type="submit" class="btn-action btn-action-danger">Return</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (hasPermission('approve_reports', $user) && !in_array(strtoupper((string) $report['status']), ['REJECTED', 'COMPLETED', 'APPROVED'], true) && !in_array($currentRouteStatus, ['completed', 'approved', 'rejected'], true)): ?>
                <form id="reject-form-<?= $reportId ?>" method="POST" action="report_details.php?id=<?= $reportId ?>" class="action-button-form workflow-modal-form workflow-reject-form" data-modal-title="Reject document">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action_type" value="reject_document">
                    <label for="reject-remarks-<?= $reportId ?>">Reason for rejection <span aria-hidden="true">*</span></label>
                    <textarea id="reject-remarks-<?= $reportId ?>" name="remarks" rows="4" maxlength="1000" placeholder="Explain why this document is being rejected..." required></textarea>
                    <div class="workflow-modal-actions">
                        <button type="button" class="btn-action btn-action-secondary reject-cancel-button" title="Cancel" aria-label="Cancel" data-workflow-modal-close>
                            <i class="bi bi-check-lg" aria-hidden="true"></i>
                        </button>
                        <button type="submit" title="Reject document" class="btn btn-outline-danger" aria-label="Reject document">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-return-left" viewBox="0 0 16 16">
                            <path fill-rule="evenodd" d="M14.5 1.5a.5.5 0 0 1 .5.5v4.8a2.5 2.5 0 0 1-2.5 2.5H2.707l3.347 3.346a.5.5 0 0 1-.708.708l-4.2-4.2a.5.5 0 0 1 0-.708l4-4a.5.5 0 1 1 .708.708L2.707 8.3H12.5A1.5 1.5 0 0 0 14 6.8V2a.5.5 0 0 1 .5-.5"></path>
                            </svg>
                        </button>
                    </div>
                </form>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</dialog>

<!-- Client Complete Confirmation Modal -->
<div id="modal-overlay" aria-hidden="true"></div>
<div id="client-complete-modal" role="dialog" aria-modal="true" aria-labelledby="client-complete-title">
  <h3 id="client-complete-title">Confirm Completion</h3>
  <p>Are you sure you want to mark this document as completed? This will archive the document and notify relevant parties.</p>
  <div class="modal-actions">
    <button type="button" id="modal-cancel-btn" class="btn-action btn-action-secondary">Cancel</button>
    <button type="button" id="modal-confirm-btn" class="btn-action btn-action-success">Yes, Complete</button>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var workflowModal = document.getElementById('workflow-action-modal');
  var workflowModalTitle = document.getElementById('workflow-action-modal-title');
  var activeWorkflowTrigger = null;
  function closeWorkflowModal() {
    if (!workflowModal) return;
    if (workflowModal.open) workflowModal.close();
    workflowModal.querySelectorAll('.workflow-modal-form').forEach(function(form) {
      form.hidden = true;
    });
    if (activeWorkflowTrigger) activeWorkflowTrigger.focus();
  }
  if (workflowModal) {
    document.querySelectorAll('[data-workflow-modal]').forEach(function(trigger) {
      trigger.addEventListener('click', function() {
        var actionForm = document.getElementById(trigger.getAttribute('data-workflow-modal') + '-form-<?= $reportId ?>');
        if (!actionForm) return;
        workflowModal.querySelectorAll('.workflow-modal-form').forEach(function(form) {
          form.hidden = true;
        });
        actionForm.hidden = false;
        workflowModalTitle.textContent = actionForm.getAttribute('data-modal-title') || 'Document action';
        activeWorkflowTrigger = trigger;
        if (!workflowModal.open) workflowModal.showModal();
        var firstField = actionForm.querySelector('textarea, select, input:not([type="hidden"])');
        if (firstField) firstField.focus();
      });
    });
    workflowModal.querySelectorAll('[data-workflow-modal-close]').forEach(function(button) {
      button.addEventListener('click', closeWorkflowModal);
    });
    workflowModal.addEventListener('click', function(event) {
      if (event.target === workflowModal) closeWorkflowModal();
    });
    workflowModal.addEventListener('cancel', function(event) {
      event.preventDefault();
      closeWorkflowModal();
    });
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape' && workflowModal.open) closeWorkflowModal();
    });
  }

  // Intercept the Receive form and perform AJAX submit so the department sees immediate "In Progress"
  document.querySelectorAll('form.action-button-form').forEach(function(form){
    var actionInput = form.querySelector('input[name="action_type"]');
    if (!actionInput) return;
    if (actionInput.value === 'receive_document') {
      form.addEventListener('submit', function(e){
        e.preventDefault();
        var fd = new FormData(form);
        fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function(resp){ return resp.json(); })
          .then(function(data){
            if (data && data.success) {
                            closeWorkflowModal();
                            // Re-render the server-side action panel so Approve & Forward appears after receipt.
                            window.location.reload();
                            return;

              // Replace the action area with the available workflow action.
              var panel = document.querySelector('.processing-actions-panel .action-button-row');
              if (panel) {
                if (data.origin_acceptance) {
                  // Show Accept Returned Document button for originating department
                  panel.innerHTML = '<form method="POST" action="report_details.php?id=<?= $reportId ?>" class="action-button-form">' +
                                    '<input type="hidden" name="action_type" value="accept_returned_document">' +
                                    '<button type="submit" class="btn-action btn-action-success">Accept Returned Document (' + (data.origin_dept_name || 'Originating Dept') + ')</button>' +
                                    '</form>';
                } else {
                  panel.innerHTML = '<form method="POST" action="report_details.php?id=<?= $reportId ?>" class="action-button-form">' +
                                    '<input type="hidden" name="action_type" value="update_status">' +
                                    '<input type="hidden" name="status" value="Approved">' +
                                    '<button type="submit" class="btn-action btn-action-success">Approve Document</button>' +
                                    '</form>';
                }
              }

              // Update workflow step visual to In Progress
              var stepEl = document.querySelector('.workflow-step[data-step="' + data.current_step + '"]');
              if (stepEl) {
                stepEl.classList.remove('pending');
                stepEl.classList.add('current');
                var circle = stepEl.querySelector('.step-circle');
                if (circle) circle.innerHTML = '<span class="step-icon" aria-hidden="true">⟳</span>';
                // If response included received info, show it under the step
                if (data.received_at) {
                  var recDiv = stepEl.querySelector('.step-received');
                  if (!recDiv) {
                    recDiv = document.createElement('div');
                    recDiv.className = 'step-received';
                    stepEl.appendChild(recDiv);
                  }
                  var receivedBy = data.received_by || 'Staff';
                  var dt = new Date(data.received_at);
                  recDiv.textContent = 'Received by ' + receivedBy + ' • ' + dt.toLocaleString();
                }
              }

              // Update global report status badge
              var badge = document.querySelector('.badge');
              if (badge) badge.textContent = data.report_status;

              // Show a small success message
              var alertDiv = document.createElement('div');
              alertDiv.className = 'alert alert-success';
              alertDiv.textContent = data.message || 'Document is now In Progress.';
              var container = document.querySelector('.document-workflow-shell');
              if (container) container.parentNode.insertBefore(alertDiv, container);

            } else {
              alert('Failed to mark document received.');
            }
          }).catch(function(err){
            console.error(err); alert('Network error while receiving document.');
          });
      });
    }
  });

  // Delegate submit handling so dynamically injected forms (Accept) are caught too
  // Helper to perform AJAX action and update UI consistently
  function performActionFetch(actionUrl, fd) {
    fetch(actionUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(resp){ return resp.json(); })
      .then(function(data){
        if (data && data.success) {
          // Update workflow step visual to Completed
          var stepEl = document.querySelector('.workflow-step[data-step="' + (data.completed_step || data.current_step) + '"]');
          if (stepEl) {
            stepEl.classList.remove('current','pending');
            stepEl.classList.add('completed');
            var circle = stepEl.querySelector('.step-circle');
            if (circle) circle.innerHTML = '<span class="step-icon" aria-hidden="true">✓</span>';
          }

          // Fill progress track to 100%
          var pfill = document.querySelector('.progress-track-fill');
          if (pfill) pfill.style.width = '100%';

          // Update global report status badge
          var badge = document.querySelector('.badge');
          if (badge) badge.textContent = data.report_status;

          // Replace action buttons with a completed notice.
          var panel = document.querySelector('.processing-actions-panel .action-button-row');
          if (panel) panel.innerHTML = '<div class="pill" style="padding:0.6rem 0.9rem; background:#dcfce7; color:#166534; border-radius:8px; font-weight:700;">Document Completed</div>';

          // Show success alert
          var alertDiv = document.createElement('div');
          alertDiv.className = 'alert alert-success';
          alertDiv.textContent = data.message || 'Document accepted and archived.';
          var container = document.querySelector('.document-workflow-shell');
          if (container) container.parentNode.insertBefore(alertDiv, container);

        } else {
          alert('Failed to complete the requested action.');
        }
      }).catch(function(err){ console.error(err); alert('Network error while completing action.'); });
  }

  // Modal wiring for client-complete confirmation
  var modalOverlay = document.getElementById('modal-overlay');
  var modal = document.getElementById('client-complete-modal');
  var modalConfirmBtn = document.getElementById('modal-confirm-btn');
  var modalCancelBtn = document.getElementById('modal-cancel-btn');

  if (modalConfirmBtn) {
    modalConfirmBtn.addEventListener('click', function(){
      if (window.__pendingClientComplete) {
        var pending = window.__pendingClientComplete;
        // hide modal
        if (modalOverlay) modalOverlay.classList.remove('show');
        if (modal) modal.classList.remove('show');
        // perform the pending AJAX action
        performActionFetch(pending.formAction, pending.fd);
        window.__pendingClientComplete = null;
      }
    });
  }
  if (modalCancelBtn) {
    modalCancelBtn.addEventListener('click', function(){
      if (modalOverlay) modalOverlay.classList.remove('show');
      if (modal) modal.classList.remove('show');
      window.__pendingClientComplete = null;
    });
  }

  document.addEventListener('submit', function(e){
    var form = e.target;
    if (!form || !form.classList || !form.classList.contains('action-button-form')) return;
    var actionInput = form.querySelector('input[name="action_type"]');
    if (!actionInput) return;

    if (actionInput.value === 'accept_returned_document' || actionInput.value === 'client_complete_document') {
      e.preventDefault();
      var fd = new FormData(form);
      // If this is the client confirming completion, show a small confirmation modal first
      if (actionInput.value === 'client_complete_document') {
        if (modalOverlay) modalOverlay.classList.add('show');
        if (modal) modal.classList.add('show');
        window.__pendingClientComplete = { formAction: form.action, fd: fd };
      } else {
        // proceed immediately for accept_returned_document
        performActionFetch(form.action, fd);
      }
    }
  });
});
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
