<?php
function getDepartmentNotifications($pdo, $departmentId)
{
    $stmt = $pdo->prepare(
        'SELECT * FROM notifications WHERE department_id = :department_id ORDER BY created_at DESC'
    );
    $stmt->execute(['department_id' => (int) $departmentId]);
    return $stmt->fetchAll();
}

function sendDepartmentNotification($pdo, $departmentId, $trackingNo, $message, $userId = null): bool
{
    if ((int) $departmentId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO notifications (department_id, user_id, tracking_no, message, is_read, client_is_read, admin_is_read, created_at)
         VALUES (:department_id, :user_id, :tracking_no, :message, 0, 0, 0, NOW())'
    );

    return $stmt->execute([
        'department_id' => (int) $departmentId,
        'user_id' => $userId,
        'tracking_no' => $trackingNo,
        'message' => $message,
    ]);
}

function markNotificationsRead($pdo, $departmentId, array $ids = []): bool
{
    if ((int) $departmentId <= 0 || empty($ids)) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'UPDATE notifications SET admin_is_read = 1 WHERE department_id = ? AND id IN (' . $placeholders . ')';
    $params = [$departmentId];
    foreach ($ids as $id) {
        $params[] = (int) $id;
    }

    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

/**
 * Send a notification to an individual user (client).
 * Inserts a notification row with user_id set and department_id NULL.
 */
function sendUserNotification($pdo, $userId, $trackingNo, $message): bool
{
    if ((int) $userId <= 0) return false;

    $deptId = null;
    try {
        $uStmt = $pdo->prepare("SELECT department_id FROM users WHERE id = :id");
        $uStmt->execute(['id' => (int)$userId]);
        $val = $uStmt->fetchColumn();
        if ($val && (int)$val > 0) {
            $deptId = (int)$val;
        }
    } catch (Exception $e) { /* ignore */ }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (department_id, user_id, tracking_no, message, is_read, client_is_read, admin_is_read, created_at)
             VALUES (:dept_id, :user_id, :tracking_no, :message, 0, 0, 0, NOW())'
        );

        return $stmt->execute([
            'dept_id' => $deptId,
            'user_id' => (int)$userId,
            'tracking_no' => $trackingNo,
            'message' => $message,
        ]);
    } catch (Exception $e) {
        error_log('sendUserNotification error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send a system-wide notification for administrative monitoring.
 */
function sendSystemNotification($pdo, $trackingNo, $message): bool
{
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (department_id, user_id, tracking_no, message, is_read, client_is_read, admin_is_read, created_at)
         VALUES (NULL, NULL, :tracking_no, :message, 0, 0, 0, NOW())'
    );

    return $stmt->execute([
        'tracking_no' => $trackingNo,
        'message' => $message,
    ]);
}

/**
 * Attempt to send an email to users belonging to a department.
 * This is a best-effort helper — it will return true if at least one mail() call succeeded.
 * Note: PHP mail() must be configured on the server for outgoing email to work.
 */
function sendEmailNotificationToDepartment($pdo, $departmentId, $subject, $body, $from = null): bool
{
    if ((int) $departmentId <= 0) return false;

    $stmt = $pdo->prepare("SELECT email, name FROM users WHERE department_id = :dept AND COALESCE(email, '') <> ''");
    $stmt->execute(['dept' => $departmentId]);
    $rows = $stmt->fetchAll();
    if (empty($rows)) return false;

    $from = $from ?: (getenv('MAIL_FROM') ?: 'no-reply@localhost');
    $headers = 'From: ' . $from . "\r\n" . 'Content-Type: text/plain; charset=UTF-8' . "\r\n";

    $sentAny = false;
    foreach ($rows as $r) {
        $to = $r['email'];
        // Simple subject personalization
        $personalSubject = trim($subject);
        try {
            $ok = @mail($to, $personalSubject, $body, $headers);
            if ($ok) $sentAny = true;
        } catch (Exception $e) {
            // best-effort: do not throw
            error_log('Email send failed to ' . $to . ': ' . $e->getMessage());
        }
    }
    return $sentAny;
}
