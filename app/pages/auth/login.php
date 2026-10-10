<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/config/google_oauth.php';
require_once APP_ROOT . '/config/audit_log.php';

if (!empty($_SESSION['pending_user_id'])) {
    header('Location: account_pending_approval.php');
    exit();
}

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);
if (isset($_GET['timeout'])) {
    $error = 'Your session has expired due to inactivity. Please sign in again.';
}
$googleLoginUrl = isGoogleOAuthConfigured() ? getGoogleLoginURL() : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? null);
    $now = time();
    $attempts = $_SESSION['login_attempts'] ?? ['count' => 0, 'started_at' => $now];
    if ($now - (int)$attempts['started_at'] > 900) {
        $attempts = ['count' => 0, 'started_at' => $now];
    }
    if ((int)$attempts['count'] >= 5) {
        $error = 'Too many login attempts. Please try again in 15 minutes.';
    } else {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $result = loginUser($pdo, $email, $password);
    if ($result['success']) {
        unset($_SESSION['login_attempts']);
        // Log the login action
        auditEmailLogin($pdo, $result['user']['id'], $email);
        header('Location: dashboard.php');
        exit();
    }

    $error = $result['message'];
    $attempts['count']++;
    $_SESSION['login_attempts'] = $attempts;
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<div class="auth-panel">
    <div class="auth-brand" aria-label="Document Tracking System">
        <h1>DOCUMENT<br>TRACKING SYSTEM</h1>
    </div>

    <p class="auth-welcome">Welcome back! Sign in to continue.</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <div class="input-wrap input-email">
            <input type="email" id="email" name="email" class="login-input" placeholder="Username" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>

        <div class="input-wrap input-password password-input-wrap">
            <input type="password" id="password" name="password" class="login-input" placeholder="Password" required>
            <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password" title="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
        </div>
        <button type="submit" class="login-btn">LOGIN</button>
    </form>

    <div class="auth-divider"><span>or sign in with</span></div>

    <?php if ($googleLoginUrl): ?>
        <a href="<?= htmlspecialchars($googleLoginUrl) ?>" class="google-btn">
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
        <div class="alert alert-warning">Google sign-in is not configured yet. Add your Google Client ID and Client Secret in the app settings to enable this feature.</div>
    <?php endif; ?>

    <div class="auth-bottom-links">
        <a href="register.php">Register to New to DTS?</a>
        <a href="index.php">Back to Landing Page</a>
    </div>
</div>
