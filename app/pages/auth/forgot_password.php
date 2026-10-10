<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/mailer.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(strtolower($_POST['email'] ?? ''));
    if ($email === '') {
        $error = 'Please enter your email address.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        if (!$stmt->fetch()) {
            $error = 'No account was found with that email address.';
        } else {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo->prepare('DELETE FROM password_resets WHERE email = :email')->execute(['email' => $email]);
            $pdo->prepare(
                'INSERT INTO password_resets (email, token, expires_at, created_at) VALUES (:email, :token, :expires_at, NOW())'
            )->execute([
                'email' => $email,
                'token' => $token,
                'expires_at' => $expiresAt,
            ]);

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/.');
            $resetLink = $protocol . '://' . $host . $basePath . '/reset_password.php?token=' . urlencode($token);

            $body = "You requested a password reset for your account.\n\n";
            $body .= "Use the link below to reset your password:\n";
            $body .= $resetLink . "\n\n";
            $body .= "If you did not request this, you can safely ignore this email.";

            if (sendEmailNotification($email, 'Password Reset Request', $body)) {
                $success = 'A password reset link has been sent to your email address.';
            } else {
                $error = 'The reset link could not be sent. Please check the mail configuration or contact the administrator.';
            }
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

    <p class="auth-welcome">Reset your password to continue.</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="forgot_password.php" class="auth-form">
        <div class="input-wrap">
            <span class="field-icon">✉️</span>
            <label for="email" class="auth-field-label">Email Address</label>
            <input type="email" id="email" name="email" class="login-input" placeholder="Email Address" required>
        </div>

        <button type="submit" class="login-btn">SEND RESET LINK</button>
    </form>

    <div class="auth-bottom-links auth-bottom-links-single">
        <a href="login.php">Back to Login</a>
        <a href="index.php">Back to Landing Page</a>
    </div>
</div>

