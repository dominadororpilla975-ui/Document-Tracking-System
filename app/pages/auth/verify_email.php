<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

/**
 * Email Verification Page
 * Handles email verification link clicks from verification emails
 */

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/config/email_verification.php';
require_once APP_ROOT . '/config/audit_log.php';
require_once APP_ROOT . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = trim($_GET['token'] ?? '');
$message = '';
$success = false;

if (!$token) {
    $message = 'Invalid or missing verification token.';
} else {
    $result = verifyEmailToken($pdo, $token);
    if ($result === false) {
        $message = 'Verification token is invalid, expired, or already used.';
    } else {
        $success = true;
        $message = 'Email verified successfully! Your account is now active.';

        // Log the verification
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $result['user_id']]);
        $user = $stmt->fetch();

        if ($user) {
            auditEmailVerified($pdo, $user['id'], $user['email']);
        }

        // If user is logged in, refresh their session
        if (!empty($_SESSION['user_id'])) {
            $currentUser = currentUser();
            if ($currentUser && $currentUser['id'] == $result['user_id']) {
                $_SESSION['user'] = $currentUser;
            }
        }
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<div style="max-width: 600px; margin: 4rem auto; padding: 0 1rem;">
    <div style="background: #f8fafc; border-radius: 16px; padding: 2.5rem 2rem; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);">
        <?php if ($success): ?>
            <div style="text-align: center; color: #10b981;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">✓</div>
                <h1 style="margin: 0 0 0.5rem; font-size: 1.75rem; color: #059669;">Email Verified</h1>
            </div>

            <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 12px; padding: 1rem; margin: 1.5rem 0; color: #065f46;">
                <p style="margin: 0;">
                    <?= htmlspecialchars($message) ?>
                </p>
            </div>

            <div style="text-align: center; margin-top: 2rem;">
                <p style="color: #475569; margin-bottom: 1rem;">Your email has been verified successfully!</p>
                
                <?php if (isLoggedIn()): ?>
                    <a href="dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary">Sign In</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="text-align: center; color: #dc2626;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">✗</div>
                <h1 style="margin: 0 0 0.5rem; font-size: 1.75rem; color: #991b1b;">Verification Failed</h1>
            </div>

            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 1rem; margin: 1.5rem 0; color: #7f1d1d;">
                <p style="margin: 0;">
                    <?= htmlspecialchars($message) ?>
                </p>
            </div>

            <div style="text-align: center; margin-top: 2rem;">
                <p style="color: #475569; margin-bottom: 1rem;">Please request a new verification email.</p>
                <a href="login.php" class="btn btn-secondary">Back to Login</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
