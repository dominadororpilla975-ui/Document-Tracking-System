<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/config/google_oauth.php';
require_once APP_ROOT . '/config/email_verification.php';
require_once APP_ROOT . '/config/audit_log.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';
$googleLoginUrl = isGoogleOAuthConfigured() ? getGoogleLoginURL() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($name === '' || $email === '' || $password === '' || $confirm === '') {
        $error = 'All fields are required.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $result = registerUser($pdo, $name, $email, $password, 'client', null);
        if ($result['success']) {
            $userId = $result['user']['id'];

            // Create email verification token
            $token = createEmailVerification($pdo, $userId, $email);
            if ($token) {
                // Send verification email
                sendVerificationEmail($pdo, $result['user'], $token);
                auditAccountCreated($pdo, $userId, $email, 'email');
            }

            // Set session for pending verification
            $_SESSION['pending_user_id'] = $userId;
            $_SESSION['pending_email'] = $email;

            header('Location: verify_email_pending.php');
            exit();
        }
        $error = $result['message'];
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<div class="auth-panel auth-panel-wide">
    <div class="auth-brand" aria-label="Document Tracking System">
        <h1>DOCUMENT<br>TRACKING SYSTEM</h1>
    </div>

    <p class="auth-welcome">Create your account to continue.</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="register.php" class="auth-form auth-form-compact">
        Welcome to the Municipality of Carranglan Client Portal

Don't have an account yet?
You can register or sign in using your Google Account to access the Client Portal.<br>

If you are unable to register online or do not have a Google Account, you may visit the designated Municipal Office / 
Document Tracking System to submit your document personally.
    </form>

    <div class="auth-divider"><span>or continue with</span></div>

    <?php if ($googleLoginUrl): ?>
        <a href="<?= htmlspecialchars($googleLoginUrl) ?>" class="google-btn google-btn-compact">
            <span class="google-badge">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="#4285F4" d="M21.6 12.23c0-.72-.06-1.41-.17-2.08H12v3.94h5.39a4.61 4.61 0 0 1-1.99 3.03v2.5h3.22c1.89-1.74 2.98-4.3 2.98-7.39Z"/>
                    <path fill="#34A853" d="M12 22c2.7 0 4.96-.9 6.62-2.43l-3.22-2.5c-.89.6-2.03.96-3.4.96-2.61 0-4.82-1.76-5.6-4.13H.76v2.62A10 10 0 0 0 12 22Z"/>
                    <path fill="#FBBC05" d="M6.4 19.9c-.66-1.24-1.04-2.61-1.04-4.1s.38-2.86 1.04-4.1V8.98H2.76A10 10 0 0 0 1.5 12c0 1.62.39 3.16 1.08 4.52l3.82-3.7Z"/>
                    <path fill="#EA4335" d="M12 5.94c1.47 0 2.8.5 3.85 1.49l2.9-2.9A9.98 9.98 0 0 0 12 2.5a10 10 0 0 0-9.79 6.98l3.64 2.82c.78-2.37 2.99-4.36 6.15-4.36Z"/>
                </svg>
            </span>
            <span>Continue with Google</span>
        </a>
    <?php else: ?>
        <div class="alert alert-warning">Google sign-in is not configured yet. Add your Google Client ID and Client Secret to enable this option.</div>
    <?php endif; ?>

    <div class="auth-bottom-links auth-bottom-links-single">
        <a href="login.php">Already have an account? Sign In</a>
        <a href="index.php">Back to Landing Page</a>
    </div>
</div>

