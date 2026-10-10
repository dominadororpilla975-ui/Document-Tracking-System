<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

/**
 * Email Verification Pending Page
 * Shows after email registration, prompts user to verify email
 */

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/config/email_verification.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not pending
if (empty($_SESSION['pending_user_id'])) {
    header('Location: login.php');
    exit();
}

$pendingUserId = (int) $_SESSION['pending_user_id'];
$pendingEmail = $_SESSION['pending_email'] ?? 'unknown@email.com';

// Verify the user still exists
$stmt = $pdo->prepare('SELECT id, email_verified FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $pendingUserId]);
$user = $stmt->fetch();

if (!$user) {
    session_unset();
    $_SESSION['error'] = 'Account not found.';
    header('Location: login.php');
    exit();
}

// If email is already verified, redirect
if ($user['email_verified']) {
    header('Location: dashboard.php');
    exit();
}

$resendMessage = '';
$resendError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resend_email'])) {
        if (resendVerificationEmail($pdo, $pendingUserId)) {
            $resendMessage = 'Verification email sent! Please check your inbox.';
        } else {
            $resendError = 'Failed to resend verification email. Please try again later.';
        }
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<div style="max-width: 600px; margin: 4rem auto; padding: 0 1rem;">
    <div style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); border-radius: 16px; padding: 2.5rem 2rem; border: 2px solid #60a5fa; box-shadow: 0 4px 12px rgba(96, 165, 250, 0.15);">
        <!-- Icon -->
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <div style="font-size: 4rem; margin-bottom: 0.5rem;">📧</div>
            <h1 style="margin: 0; font-size: 2rem; color: #1e40af; font-weight: 800;">Verify Your Email</h1>
        </div>

        <!-- Message -->
        <div style="background: rgba(255, 255, 255, 0.8); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; border-left: 4px solid #3b82f6;">
            <p style="margin: 0 0 1rem; color: #1e40af; font-weight: 600;">
                Almost there!
            </p>
            
            <p style="margin: 0 0 1rem; color: #1e3a8a; font-size: 0.95rem; line-height: 1.6;">
                A verification email has been sent to <strong><?= htmlspecialchars($pendingEmail) ?></strong>
            </p>

            <p style="margin: 0; color: #1e3a8a; font-size: 0.95rem; line-height: 1.6;">
                Please check your inbox and click the verification link to complete your registration. The link expires in 24 hours.
            </p>
        </div>

        <!-- Instructions -->
        <div style="background: #f0f9ff; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; border: 1px solid #0369a1;">
            <h3 style="margin: 0 0 0.75rem; color: #0c4a6e; font-size: 1rem; font-weight: 700;">What to do:</h3>
            <ol style="margin: 0; padding-left: 1.5rem; color: #0c4a6e; font-size: 0.9rem; line-height: 1.8;">
                <li>Open your email inbox</li>
                <li>Look for the email from "Document Tracking System"</li>
                <li>Click the verification link in the email</li>
                <li>Your account will be activated immediately</li>
            </ol>
        </div>

        <!-- Don't see email? -->
        <div style="background: #fff7ed; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; border-left: 4px solid #f97316;">
            <p style="margin: 0 0 0.75rem; color: #7c2d12; font-weight: 600; font-size: 0.95rem;">Didn't receive the email?</p>
            <p style="margin: 0 0 1rem; color: #7c2d12; font-size: 0.9rem;">
                Check your spam or junk folder. If you still don't see it, you can request a new verification email:
            </p>
            <form method="POST" action="verify_email_pending.php" style="margin: 0;">
                <button type="submit" name="resend_email" value="1" class="btn btn-secondary" style="width: 100%; margin-top: 0.5rem;">Resend Verification Email</button>
            </form>
        </div>

        <?php if ($resendMessage): ?>
            <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 12px; padding: 1rem; margin-bottom: 1rem; color: #065f46; font-size: 0.9rem;">
                <?= htmlspecialchars($resendMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($resendError): ?>
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 1rem; margin-bottom: 1rem; color: #7f1d1d; font-size: 0.9rem;">
                <?= htmlspecialchars($resendError) ?>
            </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 0.75rem; margin-top: 2rem; justify-content: center;">
            <a href="login.php" class="btn btn-secondary">Back to Login</a>
            <a href="index.php" class="btn btn-primary">Go to Home</a>
        </div>
    </div>

    <!-- Info Box -->
    <div style="margin-top: 2rem; padding: 1rem; background: #f3f4f6; border-radius: 12px; border-left: 4px solid #9ca3af;">
        <p style="margin: 0; color: #4b5563; font-size: 0.85rem; line-height: 1.6;">
            <strong>Security Note:</strong> We never share your email address. This verification is required for account security.
        </p>
    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
