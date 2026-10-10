<?php
/**
 * Google OAuth 2.0 Callback Handler
 * This file handles the callback from Google's OAuth service
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/google_oauth.php';
require_once __DIR__ . '/../config/email_verification.php';
require_once __DIR__ . '/../config/audit_log.php';
require_once __DIR__ . '/../includes/mailer.php';

if (!isGoogleOAuthConfigured()) {
    $_SESSION['error'] = 'Google OAuth is not configured. Add a valid Client ID and Client Secret in Google Cloud Console and update the app settings.';
    header('Location: ../login.php');
    exit();
}

// Check for authorization code
$code = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';
$state = (string) ($_GET['state'] ?? '');
$expectedState = (string) ($_SESSION['google_oauth_state'] ?? '');
unset($_SESSION['google_oauth_state']);

if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    $_SESSION['error'] = 'Google authentication session expired or is invalid. Please try again.';
    header('Location: ../login.php');
    exit();
}

if ($error) {
    $_SESSION['error'] = 'Google authentication failed: ' . urldecode($_GET['error_description'] ?? $error);
    header('Location: ../login.php');
    exit();
}

if (!$code) {
    $_SESSION['error'] = 'Invalid authorization code.';
    header('Location: ../login.php');
    exit();
}

// Exchange authorization code for access token
$tokenData = exchangeGoogleCode($code);

if (!$tokenData || empty($tokenData['access_token'])) {
    $tokenError = $tokenData['error_description'] ?? $tokenData['error'] ?? 'unknown OAuth error';
    $_SESSION['error'] = 'Google token exchange failed: ' . htmlspecialchars((string) $tokenError, ENT_QUOTES, 'UTF-8');
    header('Location: ../login.php');
    exit();
}

// Get user info from Google
$userInfo = getGoogleUserInfo($tokenData['access_token']);

if (!$userInfo) {
    $_SESSION['error'] = 'Failed to retrieve user information from Google.';
    header('Location: ../login.php');
    exit();
}

// Validate required fields
if (empty($userInfo['id']) || empty($userInfo['email']) || empty($userInfo['name'])) {
    $_SESSION['error'] = 'Google account is missing required information.';
    header('Location: ../login.php');
    exit();
}

try {
    $googleId = $userInfo['id'];
    $googleEmail = $userInfo['email'];
    $googleName = $userInfo['name'];
    $profilePicture = $userInfo['picture'] ?? null;

    // Check if user exists by Google ID
    $stmt = $pdo->prepare('SELECT id, name, email, role, account_status FROM users WHERE google_id = :google_id LIMIT 1');
    $stmt->execute(['google_id' => $googleId]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        $userStatus = strtolower($existingUser['account_status'] ?? 'active');
        $userRole = strtolower($existingUser['role'] ?? 'client');

        if ($userStatus !== 'active') {
            $_SESSION['error'] = 'Your account is not active. Please contact the administrator.';
            header('Location: ../login.php');
            exit();
        }

        // Existing Google user - log them in
        $userId = $existingUser['id'];

        // Refresh the Google profile image on every successful login.
        $pdo->prepare(
            'UPDATE users
             SET name = COALESCE(NULLIF(:name, ""), name),
                 profile_picture = COALESCE(NULLIF(:picture, ""), profile_picture),
                 last_login = NOW()
             WHERE id = :id'
        )->execute([
            'name' => $googleName,
            'picture' => $profilePicture,
            'id' => $userId,
        ]);

        // Log the login action
        auditGoogleLogin($pdo, $userId, $googleEmail);

        // Set session
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['google_authenticated'] = true;

        // Redirect to dashboard
        header('Location: ../dashboard.php');
        exit();
    }

    // Check if email exists (link existing local account to Google)
    $stmt = $pdo->prepare('SELECT id, name, email, role, email_verified, account_status FROM users WHERE LOWER(email) = :email LIMIT 1');
    $stmt->execute(['email' => $googleEmail]);
    $emailUser = $stmt->fetch();

    if ($emailUser) {
        $emailStatus = strtolower($emailUser['account_status'] ?? 'active');
        $emailRole = strtolower($emailUser['role'] ?? 'client');

        if ($emailStatus !== 'active') {
            $_SESSION['error'] = 'This account is not active. Please contact the administrator.';
            header('Location: ../login.php');
            exit();
        }

        // Email exists - link Google ID to existing account
        $pdo->prepare('UPDATE users SET google_id = :google_id, profile_picture = :picture WHERE LOWER(email) = :email')
            ->execute([
                'google_id' => $googleId,
                'picture' => $profilePicture,
                'email' => $googleEmail
            ]);

        $userId = $emailUser['id'];

        // Log the action
        auditLog($pdo, $userId, 'GOOGLE_LINKED', 'Google account linked to existing email account');

        // Account already active
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['google_authenticated'] = true;

        // Update last login
        $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')
            ->execute(['id' => $userId]);

        header('Location: ../dashboard.php');
        exit();
    }

    // New user - create an active account because Google verified the email.
    $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT); // Random password

    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, password, google_id, profile_picture, role, email_verified, account_status, created_at)
         VALUES (:name, :email, :password, :google_id, :picture, :role, :email_verified, :account_status, NOW())'
    );

    $stmt->execute([
        'name' => $googleName,
        'email' => $googleEmail,
        'password' => $hash,
        'google_id' => $googleId,
        'picture' => $profilePicture,
        'role' => 'client', // Default role for new users
        'email_verified' => 1, // Google email is pre-verified
        'account_status' => 'active'
    ]);

    $userId = $pdo->lastInsertId();

    // Log account creation
    auditAccountCreated($pdo, $userId, $googleEmail, 'google');
    auditEmailVerified($pdo, $userId, $googleEmail); // Google email is pre-verified

    // Google has already verified the account, so no approval step is required.
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['google_authenticated'] = true;

    header('Location: ../dashboard.php');
    exit();

} catch (Exception $e) {
    error_log('Google OAuth callback error: ' . $e->getMessage());
    $_SESSION['error'] = 'An error occurred during authentication. Please try again.';
    header('Location: ../login.php');
    exit();
}
