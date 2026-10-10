<?php
/**
 * Google Authentication Helper Functions
 * Functions to manage Google-authenticated users and linking
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_verification.php';

/**
 * Link Google account to existing user
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $googleId Google ID
 * @param string $googleEmail Google email
 * @param string|null $profilePicture Google profile picture URL
 * @return bool Success
 */
function linkGoogleAccount($pdo, $userId, $googleId, $googleEmail, $profilePicture = null) {
    try {
        $stmt = $pdo->prepare(
            'UPDATE users 
             SET google_id = :google_id, profile_picture = :picture
             WHERE id = :id'
        );
        return $stmt->execute([
            'google_id' => $googleId,
            'picture' => $profilePicture,
            'id' => $userId
        ]);
    } catch (Exception $e) {
        error_log('Link Google account failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if Google ID is already registered
 * @param PDO $pdo Database connection
 * @param string $googleId Google ID
 * @return int|false User ID if exists, false otherwise
 */
function getGoogleUserById($pdo, $googleId) {
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE google_id = :google_id LIMIT 1');
        $stmt->execute(['google_id' => $googleId]);
        $result = $stmt->fetch();
        return $result ? $result['id'] : false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get user by Google email
 * @param PDO $pdo Database connection
 * @param string $email Google email
 * @return array|false User data if exists
 */
function getUserByGoogleEmail($pdo, $email) {
    try {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, google_id, email_verified, account_status, department_id, role 
             FROM users 
             WHERE LOWER(email) = :email 
             LIMIT 1'
        );
        $stmt->execute(['email' => strtolower($email)]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Create new Google-authenticated user (pending admin approval)
 * @param PDO $pdo Database connection
 * @param string $name User's name
 * @param string $email User's email
 * @param string $googleId Google ID
 * @param string|null $profilePicture Profile picture URL
 * @return int|false New user ID or false on failure
 */
function createGoogleUser($pdo, $name, $email, $googleId, $profilePicture = null) {
    try {
        // Generate a random password (user won't need it for Google auth)
        $randomPassword = bin2hex(random_bytes(16));
        $hash = password_hash($randomPassword, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password, google_id, profile_picture, role, email_verified, account_status, created_at)
             VALUES (:name, :email, :password, :google_id, :picture, :role, :email_verified, :account_status, NOW())'
        );

        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password' => $hash,
            'google_id' => $googleId,
            'picture' => $profilePicture,
            'role' => 'client', // Default role
            'email_verified' => 1, // Google email is pre-verified
            'account_status' => 'pending' // Awaits admin approval
        ]);

        return $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log('Create Google user failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Activate pending Google account
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int|null $departmentId Department to assign
 * @param string $role Role to assign
 * @return bool Success
 */
function activateGoogleAccount($pdo, $userId, $departmentId = null, $role = 'department_staff') {
    try {
        $stmt = $pdo->prepare(
            'UPDATE users 
             SET account_status = :status, 
                 department_id = :dept_id, 
                 role = :role 
             WHERE id = :id'
        );
        return $stmt->execute([
            'status' => 'active',
            'dept_id' => $departmentId,
            'role' => $role,
            'id' => $userId
        ]);
    } catch (Exception $e) {
        error_log('Activate Google account failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Reject pending Google account
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string|null $reason Reason for rejection
 * @return bool Success
 */
function rejectGoogleAccount($pdo, $userId, $reason = null) {
    try {
        $stmt = $pdo->prepare('UPDATE users SET account_status = :status WHERE id = :id');
        return $stmt->execute([
            'status' => 'rejected',
            'id' => $userId
        ]);
    } catch (Exception $e) {
        error_log('Reject Google account failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Suspend Google account
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return bool Success
 */
function suspendGoogleAccount($pdo, $userId) {
    try {
        $stmt = $pdo->prepare('UPDATE users SET account_status = :status WHERE id = :id');
        return $stmt->execute([
            'status' => 'suspended',
            'id' => $userId
        ]);
    } catch (Exception $e) {
        error_log('Suspend Google account failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Reactivate suspended Google account
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return bool Success
 */
function reactivateGoogleAccount($pdo, $userId) {
    try {
        $stmt = $pdo->prepare('UPDATE users SET account_status = :status WHERE id = :id');
        return $stmt->execute([
            'status' => 'active',
            'id' => $userId
        ]);
    } catch (Exception $e) {
        error_log('Reactivate Google account failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send account activation notification email
 * @param string $email User email
 * @param string $userName User name
 * @param string|null $departmentName Assigned department
 * @return bool
 */
function sendAccountActivationEmail($email, $userName, $departmentName = null) {
    require_once __DIR__ . '/../includes/mailer.php';

    $subject = 'Your Carranglan Municipal Document Tracking Account is Now Active';
    
    $body = "Hello " . htmlspecialchars($userName) . ",\n\n";
    $body .= "Great news! Your account for the Carranglan Municipal Document Tracking System has been approved and is now active.\n\n";
    
    if ($departmentName) {
        $body .= "Department Assignment: " . htmlspecialchars($departmentName) . "\n\n";
    }
    
    $body .= "You can now sign in with your Google account:\n";
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    $loginUrl = $protocol . '://' . $host . '/login.php';
    $body .= $loginUrl . "\n\n";
    
    $body .= "If you have any questions or issues accessing the system, please contact:\n";
    $body .= "Municipal Government Administrator\n";
    $body .= "Email: admin@carranglan.gov.ph\n\n";
    
    $body .= "Best regards,\n";
    $body .= "Carranglan Municipal Government\n";
    $body .= "Document Tracking System";

    return sendEmailNotification($email, $subject, $body);
}

/**
 * Send account rejection notification email
 * @param string $email User email
 * @param string $userName User name
 * @param string|null $reason Rejection reason
 * @return bool
 */
function sendAccountRejectionEmail($email, $userName, $reason = null) {
    require_once __DIR__ . '/../includes/mailer.php';

    $subject = 'Your Carranglan Municipal Document Tracking Account - Registration Not Approved';
    
    $body = "Hello " . htmlspecialchars($userName) . ",\n\n";
    $body .= "Thank you for registering with the Carranglan Municipal Document Tracking System.\n\n";
    $body .= "Unfortunately, your account registration has not been approved at this time.\n\n";
    
    if ($reason) {
        $body .= "Reason: " . htmlspecialchars($reason) . "\n\n";
    }
    
    $body .= "If you believe this is in error or have questions, please contact:\n";
    $body .= "Municipal Government Administrator\n";
    $body .= "Email: admin@carranglan.gov.ph\n\n";
    
    $body .= "Best regards,\n";
    $body .= "Carranglan Municipal Government\n";
    $body .= "Document Tracking System";

    return sendEmailNotification($email, $subject, $body);
}
