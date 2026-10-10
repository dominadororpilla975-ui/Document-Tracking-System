<?php
/**
 * Email Verification Helper Functions
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/mailer.php';

/**
 * Generate email verification token
 * @return string Secure token
 */
function generateVerificationToken() {
    return bin2hex(random_bytes(32));
}

/**
 * Create email verification record
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $email Email address
 * @return string Verification token or empty string on failure
 */
function createEmailVerification($pdo, $userId, $email) {
    try {
        $token = generateVerificationToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // Delete any existing verification tokens for this user
        $pdo->prepare('DELETE FROM email_verifications WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);

        // Create new verification record
        $stmt = $pdo->prepare(
            'INSERT INTO email_verifications (user_id, email, token, expires_at) 
             VALUES (:user_id, :email, :token, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'email' => $email,
            'token' => $token,
            'expires_at' => $expiresAt
        ]);

        return $token;
    } catch (Exception $e) {
        error_log('Email verification token creation failed: ' . $e->getMessage());
        return '';
    }
}

/**
 * Send email verification email
 * @param PDO $pdo Database connection
 * @param array $user User data
 * @param string $token Verification token
 * @return bool True if email sent, false otherwise
 */
function sendVerificationEmail($pdo, $user, $token) {
    try {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        $verificationLink = $protocol . '://' . $host . '/auth/verify_email.php?token=' . urlencode($token);

        $subject = 'Email Verification - Carranglan Municipal Document Tracking System';
        $body = "Hello " . htmlspecialchars($user['name']) . ",\n\n";
        $body .= "Thank you for registering with the Carranglan Municipal Document Tracking System.\n\n";
        $body .= "To complete your registration, please verify your email address by clicking the link below:\n\n";
        $body .= $verificationLink . "\n\n";
        $body .= "This link will expire in 24 hours.\n\n";
        $body .= "If you did not create this account, you can safely ignore this email.\n\n";
        $body .= "Best regards,\n";
        $body .= "Carranglan Municipal Government\n";
        $body .= "Document Tracking System";

        return sendEmailNotification($user['email'], $subject, $body);
    } catch (Exception $e) {
        error_log('Verification email send failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Verify email using token
 * @param PDO $pdo Database connection
 * @param string $token Verification token
 * @return array|false User data if verification successful, false otherwise
 */
function verifyEmailToken($pdo, $token) {
    try {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, email, verified_at, expires_at 
             FROM email_verifications 
             WHERE token = :token 
             LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $verification = $stmt->fetch();

        if (!$verification) {
            return false;
        }

        // Check if token already used
        if ($verification['verified_at'] !== null) {
            return false;
        }

        // Check if token expired
        if (strtotime($verification['expires_at']) < time()) {
            return false;
        }

        // Mark token as verified
        $updateVerif = $pdo->prepare(
            'UPDATE email_verifications 
             SET verified_at = NOW() 
             WHERE token = :token'
        );
        $updateVerif->execute(['token' => $token]);

        // Update user's email_verified status
        $updateUser = $pdo->prepare(
            'UPDATE users 
             SET email_verified = 1, account_status = :status 
             WHERE id = :id'
        );
        $updateUser->execute([
            'status' => 'email_verified',
            'id' => $verification['user_id']
        ]);

        return $verification;
    } catch (Exception $e) {
        error_log('Email verification failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if user's email is verified
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return bool True if email verified, false otherwise
 */
function isEmailVerified($pdo, $userId) {
    try {
        $stmt = $pdo->prepare('SELECT email_verified FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        return $user && (int)$user['email_verified'] === 1;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Resend verification email
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return bool True if email sent, false otherwise
 */
function resendVerificationEmail($pdo, $userId) {
    try {
        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return false;
        }

        $token = createEmailVerification($pdo, $userId, $user['email']);
        if (!$token) {
            return false;
        }

        return sendVerificationEmail($pdo, $user, $token);
    } catch (Exception $e) {
        error_log('Resend verification email failed: ' . $e->getMessage());
        return false;
    }
}
