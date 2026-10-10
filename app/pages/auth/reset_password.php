<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$token = trim($_GET['token'] ?? '');
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($token === '') {
        $error = 'Reset token is missing.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT email, expires_at FROM password_resets WHERE token = :token LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $error = 'This password reset link is invalid or has already been used.';
        } elseif (strtotime($reset['expires_at']) < time()) {
            $error = 'This password reset link has expired. Please request a new one.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $userStmt = $pdo->prepare('UPDATE users SET password = :password, temp_password = NULL WHERE email = :email');
            $userStmt->execute(['password' => $hash, 'email' => $reset['email']]);

            $pdo->prepare('DELETE FROM password_resets WHERE token = :token')->execute(['token' => $token]);
            $success = 'Password updated successfully. You can now log in with your new password.';
        }
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<div class="auth-panel auth-panel-wide">
    <div class="auth-brand" aria-label="Document Tracking System">
        <div class="auth-brand-mark">📄</div>
        <h1>DOCUMENT<br>TRACKING SYSTEM</h1>
    </div>

    <p class="auth-welcome">Set a new password.</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <div style="text-align: center; margin-top: 1rem;">
            <a href="login.php" class="login-btn" style="display:inline-block; width:auto; padding-inline:1.5rem;">GO TO LOGIN</a>
        </div>
    <?php else: ?>
        <form method="POST" action="reset_password.php" class="auth-form">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="input-wrap password-input-wrap">
                <span class="field-icon">🔒</span>
                <label for="password" class="auth-field-label">New Password</label>
                <input type="password" id="password" name="password" class="login-input" placeholder="New Password" required>
                <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password" title="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>

            <div class="input-wrap password-input-wrap">
                <span class="field-icon">🔐</span>
                <label for="confirm_password" class="auth-field-label">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="login-input" placeholder="Confirm New Password" required>
                <button type="button" class="password-toggle" data-password-toggle="confirm_password" aria-label="Show password" title="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>

            <button type="submit" class="login-btn">UPDATE PASSWORD</button>
        </form>
    <?php endif; ?>

    <div class="auth-bottom-links auth-bottom-links-single" style="margin-top: 1.2rem;">
        <a href="login.php">Back to Login</a>
    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
