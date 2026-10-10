<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

/**
 * Account Pending Approval Page
 * Displayed when a new Google account has been created and is awaiting admin review
 */

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';

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
$pendingName = $_SESSION['pending_name'] ?? 'User';

// Verify the user still exists and is pending
$stmt = $pdo->prepare('SELECT id, name, email, account_status, role FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $pendingUserId]);
$dbUser = $stmt->fetch();

if (!$dbUser) {
    session_unset();
    $_SESSION['error'] = 'Account not found.';
    header('Location: login.php');
    exit();
}

// If account was activated, clear pending flags and redirect to dashboard
if ($dbUser['account_status'] === 'active') {
    unset($_SESSION['pending_user_id'], $_SESSION['pending_email'], $_SESSION['pending_name'], $_SESSION['google_authenticated']);
    $_SESSION['user_id'] = $pendingUserId;
    $_SESSION['user'] = $dbUser;
    header('Location: dashboard.php');
    exit();
}

// Set up lightweight user array for header/sidebar rendering
$user = [
    'id' => $pendingUserId,
    'name' => $dbUser['name'] ?? $pendingName,
    'email' => $dbUser['email'] ?? $pendingEmail,
    'role' => 'client',
    'account_status' => 'pending'
];

require_once APP_ROOT . '/includes/header.php';

$pendingDisplayName = $dbUser['name'] ?? ($pendingName ?? 'User');
$pendingDisplayEmail = $dbUser['email'] ?? ($pendingEmail ?? 'email@example.com');
?>

<div style="max-width: 720px; margin: 2rem auto; padding: 0 1rem; font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <div style="background: #ffffff; border-radius: 20px; border: 1px solid #e2e8f0; padding: 3rem 2.5rem; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05); text-align: center;">
        
        <!-- Clipboard Illustration -->
        <div style="position: relative; width: 140px; height: 140px; margin: 0 auto 1.5rem auto; display: flex; align-items: center; justify-content: center;">
            <!-- Outer soft blue glowing background circle -->
            <div style="position: absolute; width: 130px; height: 130px; background: #e0f2fe; border-radius: 50%; top: 5px; left: 5px;"></div>
            
            <!-- Floating blue cross decorative elements -->
            <span style="position: absolute; top: 10px; left: 15px; color: #93c5fd; font-size: 1.1rem; font-weight: 300;">+</span>
            <span style="position: absolute; bottom: 15px; right: 15px; color: #93c5fd; font-size: 1.1rem; font-weight: 300;">+</span>
            <span style="position: absolute; top: 25px; right: 10px; color: #cbd5e1; font-size: 0.8rem;">⬡</span>

            <!-- Clipboard SVG Icon -->
            <svg width="85" height="85" viewBox="0 0 24 24" fill="none" style="position: relative; z-index: 2;">
                <!-- Clipboard Board -->
                <rect x="5" y="4" width="14" height="17" rx="3" fill="#ffffff" stroke="#2563eb" stroke-width="1.8"/>
                <!-- Clip Top -->
                <path d="M9 3h6a1 1 0 0 1 1 1v1H8V4a1 1 0 0 1 1-1z" fill="#2563eb"/>
                <!-- User Profile Badge on Clipboard -->
                <circle cx="12" cy="10.5" r="2.5" fill="#93c5fd"/>
                <path d="M8.5 15.5c0-1.8 1.5-3 3.5-3s3.5 1.2 3.5 3" fill="#93c5fd"/>
                <!-- Lines on Document -->
                <line x1="8" y1="17.5" x2="16" y2="17.5" stroke="#cbd5e1" stroke-width="1.5" stroke-linecap="round"/>
            </svg>

            <!-- Green Checkmark Circle Badge -->
            <div style="position: absolute; bottom: 8px; right: 12px; width: 36px; height: 36px; background: #10b981; border: 3px solid #ffffff; border-radius: 50%; display: flex; align-items: center; justify-content: center; z-index: 3; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
        </div>

        <!-- Main Title & Subtitle -->
        <h1 style="font-size: 1.85rem; font-weight: 800; color: #0f172a; margin: 0 0 0.6rem 0; letter-spacing: -0.02em;">
            Account Verification Pending
        </h1>
        <h3 style="font-size: 1.05rem; font-weight: 700; color: #334155; margin: 0 0 0.4rem 0;">
            Thank you for registering!
        </h3>
        <p style="font-size: 0.92rem; color: #64748b; margin: 0 0 2rem 0; line-height: 1.5; max-width: 540px; margin-left: auto; margin-right: auto;">
            Your account has been successfully submitted and is currently under review by our administrator.
        </p>

        <!-- Blue Banner Box: What happens next? -->
        <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 14px; padding: 1.25rem 1.5rem; text-align: left; margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: flex-start;">
            <div style="width: 36px; height: 36px; background: #2563eb; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: white; font-weight: 800; font-size: 1.1rem;">
                i
            </div>
            <div>
                <h4 style="margin: 0 0 0.3rem 0; font-size: 0.98rem; font-weight: 700; color: #1e40af;">
                    What happens next?
                </h4>
                <p style="margin: 0; font-size: 0.88rem; color: #1e3a8a; line-height: 1.5;">
                    You will be notified via email once your account is verified and activated. You can then log in and start using the system.
                </p>
            </div>
        </div>

        <p style="font-size: 0.86rem; color: #64748b; margin: 0 0 1.5rem 0;">
            If you have any questions, please contact the Municipal Administrator's Office.
        </p>

        <!-- Registration Details Card -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 1.25rem 1.5rem; text-align: left; margin-bottom: 1.5rem;">
            <h4 style="margin: 0 0 1rem 0; font-size: 0.92rem; font-weight: 700; color: #1e293b;">
                Registration Details
            </h4>
            <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.9rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem; color: #475569;">
                    <span style="font-size: 1.1rem; width: 24px; text-align: center; color: #64748b;">👤</span>
                    <span style="font-weight: 600; width: 120px; color: #334155;">Full Name</span>
                    <span style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($pendingDisplayName) ?></span>
                </div>
                <div style="display: flex; align-items: center; gap: 0.75rem; color: #475569;">
                    <span style="font-size: 1.1rem; width: 24px; text-align: center; color: #64748b;">✉️</span>
                    <span style="font-weight: 600; width: 120px; color: #334155;">Email Address</span>
                    <span style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($pendingDisplayEmail) ?></span>
                </div>
            </div>
        </div>

        <!-- Amber Notice Box: Please wait for verification -->
        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 14px; padding: 1.1rem 1.5rem; text-align: left; display: flex; gap: 1rem; align-items: center;">
            <div style="width: 40px; height: 40px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.25rem;">
                🕒
            </div>
            <div>
                <h4 style="margin: 0 0 0.2rem 0; font-size: 0.95rem; font-weight: 700; color: #92400e;">
                    Please wait for verification.
                </h4>
                <p style="margin: 0; font-size: 0.85rem; color: #78350f; line-height: 1.4;">
                    This usually takes 1-2 business days. Thank you for your patience.
                </p>
            </div>
        </div>

    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
