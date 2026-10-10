<?php
require_once __DIR__ . '/auth.php';

/**
 * Validates document privacy and permission rules for RBAC
 */
function checkDocumentPrivacy($user, $report, $pdo = null) {
    if (!$user || !$report) {
        return [
            'can_view_meta' => false,
            'can_view_content' => false,
            'can_modify' => false,
            'denial_reason' => 'You do not have permission to access this document.'
        ];
    }

    if (isSuperAdmin($user)) {
        return [
            'can_view_meta' => true,
            'can_view_content' => true,
            'can_modify' => true,
            'denial_reason' => null
        ];
    }

    $userDeptId = (int)($user['department_id'] ?? 0);
    $reportAssignedDept = (int)($report['assigned_department_id'] ?? $report['department_id'] ?? 0);
    $reportClientUserId = (int)($report['client_id'] ?? 0);
    $reportSenderDept = (int)($report['sender_department_id'] ?? 0);

    // Document owners may act only when the matching permission is explicitly granted.
    if (($user['role'] ?? '') === 'client' && (int)$user['id'] === $reportClientUserId) {
        return [
            'can_view_meta' => true,
            'can_view_content' => true,
            'can_modify' => hasPermission('manage_reports', $user),
            'denial_reason' => null
        ];
    }

    $isPrivateDepartmentDocument = (int) ($report['department_private'] ?? 0) === 1;
    $isDocumentSubmitter = (int) ($user['id'] ?? 0) === (int) ($report['submitted_by_user_id'] ?? 0);
    $canViewPrivateDepartmentDocuments = isDepartmentAdmin($user)
        || hasPermission('override_department_privacy', $user);
    if ($isPrivateDepartmentDocument && !$canViewPrivateDepartmentDocuments && !$isDocumentSubmitter) {
        return [
            'can_view_meta' => false,
            'can_view_content' => false,
            'can_modify' => false,
            'denial_reason' => 'This document is private to the Department Admin, submitting staff member, and requesting client.'
        ];
    }

    // 2. Current Assigned Department Has Full Access (Read, Edit, Forward, Return, Print, Download)
    if ($userDeptId > 0 && $userDeptId === $reportAssignedDept) {
        return [
            'can_view_meta' => true,
            'can_view_content' => true,
            'can_modify' => true,
            'denial_reason' => null
        ];
    }

    // 2b. Sender / Creator Department Has Read-Only Access
    if ($userDeptId > 0 && $userDeptId === $reportSenderDept) {
        return [
            'can_view_meta' => true,
            'can_view_content' => true,
            'can_modify' => false,
            'denial_reason' => null
        ];
    }

    // 3. Document Submitter / Creator Has Read-Only View of their document
    if ((int)$user['id'] === $reportClientUserId) {
        return [
            'can_view_meta' => true,
            'can_view_content' => true,
            'can_modify' => hasPermission('manage_reports', $user),
            'denial_reason' => null
        ];
    }

    // 4. Check if user's department was part of historical document workflow chain
    if ($pdo && $userDeptId > 0) {
        $histStmt = $pdo->prepare("SELECT COUNT(*) FROM report_workflow_routes WHERE report_id = :id AND department_id = :dept_id");
        $histStmt->execute(['id' => $report['id'], 'dept_id' => $userDeptId]);
        if ($histStmt->fetchColumn() > 0) {
            return [
                'can_view_meta' => true,
                'can_view_content' => true, // Read history
                'can_modify' => false,      // Cannot modify, only current department can modify
                'denial_reason' => null
            ];
        }
    }

    // 5. Unrelated Department Access DENIED
    return [
        'can_view_meta' => false,
        'can_view_content' => false,
        'can_modify' => false,
        'denial_reason' => 'You do not have permission to access this document.'
    ];
}
