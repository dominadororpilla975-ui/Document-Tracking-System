<?php
/**
 * Audit Logging Helper Functions
 * Track all important system and user actions for security and compliance
 */

require_once __DIR__ . '/db.php';

/**
 * Log an action to the audit trail
 * @param PDO $pdo Database connection
 * @param int|null $userId User ID performing the action
 * @param string $action Action type (e.g., GOOGLE_LOGIN, ACCOUNT_CREATED, DOCUMENT_VIEWED)
 * @param string|null $details Additional details about the action
 * @param int|null $documentId Related document ID if applicable
 * @param int|null $departmentId Related department ID if applicable
 * @param array|null $oldValues Values before the change
 * @param array|null $newValues Values after the change
 * @return bool True if logged successfully, false otherwise
 */
function auditLog($pdo, $userId, $action, $details = null, $documentId = null, $departmentId = null, $oldValues = null, $newValues = null) {
    static $availableColumns = null;

    try {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if ($oldValues === null && $newValues === null) {
            $fallbackSummary = [
                'action' => $action,
                'details' => $details,
                'document_id' => $documentId,
                'department_id' => $departmentId,
                'ip_address' => $ipAddress,
            ];
            $oldValues = $fallbackSummary;
            $newValues = $fallbackSummary;
        }

        if ($availableColumns === null) {
            $availableColumns = $pdo->query('SHOW COLUMNS FROM audit_logs')->fetchAll(PDO::FETCH_COLUMN);
        }

        $values = [
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
            'document_id' => $documentId,
            'department_id' => $departmentId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ];
        if (in_array('old_values', $availableColumns, true)) {
            $values['old_values'] = $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null;
        }
        if (in_array('new_values', $availableColumns, true)) {
            $values['new_values'] = $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;
        }
        $values = array_filter(
            $values,
            static fn ($value, $column): bool => in_array($column, $availableColumns, true),
            ARRAY_FILTER_USE_BOTH
        );

        $columns = array_keys($values);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (' . implode(', ', $columns) . ', created_at)
             VALUES (' . implode(', ', $placeholders) . ', NOW())'
        );

        return $stmt->execute($values);
    } catch (Throwable $e) {
        error_log('Audit log failed for action ' . (string) $action . ' and user ' . (string) $userId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Log Google login event
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $googleEmail Google email address
 * @return bool
 */
function auditGoogleLogin($pdo, $userId, $googleEmail) {
    return auditLog($pdo, $userId, 'GOOGLE_LOGIN', 'Logged in with Google: ' . $googleEmail);
}

/**
 * Log traditional login event
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $email Email address
 * @return bool
 */
function auditEmailLogin($pdo, $userId, $email) {
    return auditLog($pdo, $userId, 'EMAIL_LOGIN', 'Logged in with email: ' . $email);
}

/**
 * Log account creation event
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $email Email address
 * @param string $source Source of registration (google, email, admin)
 * @return bool
 */
function auditAccountCreated($pdo, $userId, $email, $source = 'email') {
    return auditLog($pdo, $userId, 'ACCOUNT_CREATED', 'Account created via ' . $source . ': ' . $email);
}

/**
 * Log email verification event
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $email Email address
 * @return bool
 */
function auditEmailVerified($pdo, $userId, $email) {
    return auditLog($pdo, $userId, 'EMAIL_VERIFIED', 'Email verified: ' . $email);
}

/**
 * Log department assignment event
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $departmentId Department ID
 * @param int|null $adminId Admin performing the action
 * @return bool
 */
function auditDepartmentAssigned($pdo, $userId, $departmentId, $adminId = null) {
    $details = 'Department assigned by admin ID: ' . ($adminId ?? 'system');
    return auditLog($pdo, $adminId, 'DEPARTMENT_ASSIGNED', $details, null, $departmentId);
}

/**
 * Log document viewed event
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $documentId Document ID
 * @param int|null $departmentId Department ID
 * @return bool
 */
function auditDocumentViewed($pdo, $userId, $documentId, $departmentId = null) {
    return auditLog($pdo, $userId, 'DOCUMENT_VIEWED', 'Document viewed', $documentId, $departmentId);
}

/**
 * Log document sent event
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $documentId Document ID
 * @param int|null $recipientDeptId Recipient department ID
 * @return bool
 */
function auditDocumentSent($pdo, $userId, $documentId, $recipientDeptId = null) {
    return auditLog($pdo, $userId, 'DOCUMENT_SENT', 'Document sent to department', $documentId, $recipientDeptId);
}

/**
 * Log document received event
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $documentId Document ID
 * @param int|null $departmentId Department ID
 * @return bool
 */
function auditDocumentReceived($pdo, $userId, $documentId, $departmentId = null) {
    return auditLog($pdo, $userId, 'DOCUMENT_RECEIVED', 'Document received', $documentId, $departmentId);
}

/**
 * Log document action event (approve, reject, return, etc.)
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $actionType Type of action (approve, reject, return, etc.)
 * @param int $documentId Document ID
 * @param string|null $remarks Remarks or notes
 * @param int|null $departmentId Department ID
 * @return bool
 */
function auditDocumentAction($pdo, $userId, $actionType, $documentId, $remarks = null, $departmentId = null) {
    $action = 'DOCUMENT_' . strtoupper($actionType);
    $details = 'Action: ' . $actionType;
    if ($remarks) {
        $details .= ' | Remarks: ' . $remarks;
    }
    return auditLog($pdo, $userId, $action, $details, $documentId, $departmentId);
}

/**
 * Log account status change
 * @param PDO $pdo Database connection
 * @param int $userId User ID whose account changed
 * @param string $oldStatus Previous status
 * @param string $newStatus New status
 * @param int|null $adminId Admin performing the action
 * @return bool
 */
function auditAccountStatusChanged($pdo, $userId, $oldStatus, $newStatus, $adminId = null) {
    $details = 'Account status changed from ' . $oldStatus . ' to ' . $newStatus;
    return auditLog($pdo, $adminId, 'ACCOUNT_STATUS_CHANGED', $details, null, null, ['account_status' => $oldStatus], ['account_status' => $newStatus]);
}

/**
 * Get audit logs for a user
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $limit Number of logs to retrieve
 * @return array Audit log entries
 */
function getUserAuditLogs($pdo, $userId, $limit = 50) {
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM audit_logs 
             WHERE user_id = :user_id 
             ORDER BY created_at DESC 
             LIMIT :limit'
        );
        $stmt->execute(['user_id' => $userId, 'limit' => $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Get audit logs failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get audit logs by action type
 * @param PDO $pdo Database connection
 * @param string $action Action type to filter by
 * @param int $limit Number of logs to retrieve
 * @return array Audit log entries
 */
function getAuditLogsByAction($pdo, $action, $limit = 50) {
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM audit_logs 
             WHERE action = :action 
             ORDER BY created_at DESC 
             LIMIT :limit'
        );
        $stmt->execute(['action' => $action, 'limit' => $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Get audit logs by action failed: ' . $e->getMessage());
        return [];
    }
}
