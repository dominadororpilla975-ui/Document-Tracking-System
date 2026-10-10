<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('DTSSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

require_once __DIR__ . '/../config/db.php';

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): void
{
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    $submittedToken = trim((string) $token);
    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $_SESSION['error'] = 'Your security token expired. Please refresh the page and submit again.';
        $returnUrl = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
        $returnPath = parse_url($returnUrl, PHP_URL_PATH);
        if (!$returnPath || strpos($returnPath, '/report-tracking-system/') !== 0) {
            $returnPath = '/report-tracking-system/dashboard.php';
        }
        header('Location: ' . $returnPath . '?error=csrf_expired');
        exit();
    }
}

function getSystemSetting(string $key, ?string $default = null): ?string
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) {
        return $default;
    }
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1');
    $stmt->execute(['setting_key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT u.*, d.name AS department_name
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = :id'
    );
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_unset();
        session_destroy();
        return null;
    }

    $status = strtolower((string) ($user['account_status'] ?? 'active'));
    $role = strtolower((string) ($user['role'] ?? 'client'));

    // Only enforce pending approval redirect for client accounts created via Google.
    if ($status !== 'active' && $role === 'client') {
        if (in_array($status, ['pending', 'email_verified'], true)) {
            $_SESSION['pending_user_id'] = (int) $user['id'];
            $_SESSION['pending_email'] = $user['email'] ?? '';
            $_SESSION['pending_name'] = $user['name'] ?? 'User';
        }

        return null;
    }

    // For non-client roles (department/admin), don't block here; assume admin-managed accounts
    // will be active. If a non-client account is not active, let higher-level checks handle it.

    $_SESSION['user'] = $user;
    return $user;
}

function isLoggedIn(): bool
{
    return currentUser() !== null;
}

function requireLogin(): void
{
    if (!empty($_SESSION['pending_user_id'])) {
        header('Location: account_pending_approval.php');
        exit();
    }

    $user = currentUser();
    if (!$user) {
        if (!empty($_SESSION['user_id'])) {
            $_SESSION['error'] = 'Your account is still awaiting admin approval. Please wait for confirmation.';
        }
        header('Location: login.php');
        exit();
    }

    $status = strtolower((string) ($user['account_status'] ?? 'active'));
    if ($status !== 'active') {
        if (in_array($status, ['pending', 'email_verified'], true)) {
            $_SESSION['pending_user_id'] = (int) $user['id'];
            $_SESSION['pending_email'] = $user['email'] ?? '';
            $_SESSION['pending_name'] = $user['name'] ?? 'User';
            header('Location: account_pending_approval.php');
            exit();
        }

        $_SESSION['error'] = 'Your account is not active. Please contact the administrator.';
        header('Location: login.php');
        exit();
    }

    if (!isSuperAdmin($user) && getSystemSetting('maintenance_mode', '0') === '1') {
        $_SESSION['error'] = 'The system is currently under maintenance.';
        header('Location: login.php?maintenance=1');
        exit();
    }

    // Inactivity timeout enforcement (minutes/hours configured by Super Admin)
    $timeoutMinutes = (float) getSystemSetting('session_timeout_minutes', '0');
    if ($timeoutMinutes <= 0) {
        $timeoutHours = (float) getSystemSetting('session_timeout_hours', '2');
        $timeoutMinutes = max(1, (int) ($timeoutHours * 60));
    }
    if ($timeoutMinutes > 0) {
        $maxLifetimeSeconds = (int) ($timeoutMinutes * 60);
        $lastActivity = $_SESSION['last_activity'] ?? time();
        if ((time() - $lastActivity) > $maxLifetimeSeconds) {
            logoutUser();
            header('Location: login.php?timeout=1');
            exit();
        }
        $_SESSION['last_activity'] = time();
    }
}

function normalizeRole(?string $role): string
{
    $normalized = strtolower(trim((string) ($role ?? 'client')));
    $aliases = [
        'department' => 'department_staff',
        'staff' => 'department_staff',
        'administrator' => 'admin',
    ];

    return $aliases[$normalized] ?? $normalized;
}

function hasRole(?array $user, array $allowedRoles): bool
{
    if (!$user) {
        return false;
    }

    $currentRole = normalizeRole($user['role'] ?? 'client');
    $allowed = array_map(static fn ($role) => normalizeRole($role), $allowedRoles);

    return in_array($currentRole, $allowed, true);
}

function requireRole(array $allowedRoles, ?array $user = null): void
{
    $effectiveUser = $user ?? currentUser();
    if (!hasRole($effectiveUser, $allowedRoles)) {
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header('Location: dashboard.php?error=unauthorized');
        exit();
    }
}

function hasPermission(string $permission, ?array $user = null): bool
{
    $effectiveUser = $user ?? currentUser();
    if (!$effectiveUser) {
        return false;
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) {
        return false;
    }

    $customRoleId = (int) ($effectiveUser['custom_role_id'] ?? 0);
    if ($customRoleId > 0) {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM role_permissions
             WHERE role_id = :role_id AND permission_name = :permission_name
             LIMIT 1'
        );
        $stmt->execute([
            'role_id' => $customRoleId,
            'permission_name' => trim($permission),
        ]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM role_permissions rp
             INNER JOIN roles r ON r.id = rp.role_id
             WHERE r.slug = :role_slug AND rp.permission_name = :permission_name
             LIMIT 1'
        );
        $stmt->execute([
            'role_slug' => normalizeRole($effectiveUser['role'] ?? null),
            'permission_name' => trim($permission),
        ]);
    }

    return (bool) $stmt->fetchColumn();
}

function hasAnyPermission(array $permissions, ?array $user = null): bool
{
    foreach ($permissions as $permission) {
        if (is_string($permission) && hasPermission($permission, $user)) {
            return true;
        }
    }

    return false;
}

function requirePermission(string $permission, ?array $user = null): void
{
    if (!hasPermission($permission, $user)) {
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header('Location: dashboard.php?error=unauthorized');
        exit();
    }
}

function requireAnyPermission(array $permissions, ?array $user = null): void
{
    if (!hasAnyPermission($permissions, $user)) {
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header('Location: dashboard.php?error=unauthorized');
        exit();
    }
}

function isClientUser(?array $user = null): bool
{
    if (!$user) {
        $user = currentUser();
    }

    return hasRole($user, ['client']);
}

function isDepartmentUser(?array $user = null): bool
{
    if (!$user) {
        $user = currentUser();
    }

    return hasRole($user, ['department_admin', 'department_staff', 'department', 'staff']);
}

function isDepartmentAdmin(?array $user = null): bool
{
    if (!$user) {
        $user = currentUser();
    }

    return hasRole($user, ['department_admin', 'department']);
}

function isSuperAdmin(?array $user = null): bool
{
    if (!$user) {
        $user = currentUser();
    }

    return hasRole($user, ['super_admin', 'admin']);
}

function canManageDepartmentStaff(?array $user = null, ?int $departmentId = null): bool
{
    if (!$user) {
        $user = currentUser();
    }

    if (!hasPermission('manage_users', $user)) {
        return false;
    }

    if (isDepartmentAdmin($user) && $departmentId !== null) {
        return ((int) ($user['department_id'] ?? 0)) === $departmentId;
    }

    return true;
}

function canCreateDepartmentStaff(?array $user = null): bool
{
    if (!$user) {
        $user = currentUser();
    }

    return hasPermission('create_department_staff', $user);
}

function canManageDepartmentAdmin(?array $user = null): bool
{
    if (!$user) {
        $user = currentUser();
    }

    return hasPermission('manage_users', $user) && hasPermission('manage_roles', $user);
}

function canViewOwnDepartmentUsers(?array $user = null): bool
{
    if (!$user) {
        $user = currentUser();
    }

    if (!hasPermission('manage_users', $user)) {
        return false;
    }

    return !isDepartmentAdmin($user)
        || (int) ($user['department_id'] ?? 0) > 0;
}

function canManageUsers(?array $user = null): bool
{
    if (!$user) {
        $user = currentUser();
    }

    return hasPermission('manage_users', $user);
}

function canCreateReports(?array $user = null): bool
{
    if (!$user) {
        $user = currentUser();
    }

    return hasPermission('create_reports', $user);
}

function loginUser($pdo, string $email, string $password): array
{
    $email = trim(strtolower($email));
    if ($email === '' || $password === '') {
        return ['success' => false, 'message' => 'Email and password are required.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }

    $stmt = $pdo->prepare(
        'SELECT u.*, d.name AS department_name
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE LOWER(u.email) = :email'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    // Demo/admin/department accounts can still use password login.
    // Client accounts are restricted to Google-only login.
    if (($user['role'] ?? 'client') === 'client' && empty($user['google_id'])) {
        return ['success' => false, 'message' => 'This system accepts Google accounts only for clients. Please sign in with your Google account.'];
    }

    // Check account status
    $status = $user['account_status'] ?? 'active';
    if ($status === 'pending' || $status === 'email_verified') {
        return ['success' => false, 'message' => 'Your account is pending admin approval. You will receive an email when it is activated.'];
    } elseif ($status === 'rejected') {
        return ['success' => false, 'message' => 'Your account registration was not approved. Please contact the administrator.'];
    } elseif ($status === 'suspended') {
        return ['success' => false, 'message' => 'Your account has been suspended. Please contact the administrator.'];
    }

    $storedHash = $user['password'] ?? '';
    $tempPassword = $user['temp_password'] ?? null;
    $isValid = password_verify($password, $storedHash) || ($tempPassword !== null && $tempPassword === $password);

    if (!$isValid) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    // Update last login
    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')
        ->execute(['id' => (int) $user['id']]);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user'] = $user;
    return ['success' => true, 'user' => $user];
}

function logoutUser(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('DTSSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function registerUser($pdo, string $name, string $email, string $password, string $role = 'client', ?int $departmentId = null, bool $startSession = true): array
{
    $name = trim($name);
    $email = trim(strtolower($email));

    if ($name === '' || $email === '' || $password === '') {
        return ['success' => false, 'message' => 'All fields are required.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }

    // Only clients must use Google-based registration.
    if (($role ?? 'client') === 'client') {
        return ['success' => false, 'message' => 'Client accounts are accepted only through Google sign-in. Please use your Google account to continue.'];
    }

    if (strlen($password) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters long.'];
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = :email LIMIT 1');
    $check->execute(['email' => $email]);
    if ($check->fetch()) {
        return ['success' => false, 'message' => 'An account with this email already exists.'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, password, role, department_id, created_at)
         VALUES (:name, :email, :password, :role, :department_id, NOW())'
    );
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'password' => $hash,
        'role' => $role,
        'department_id' => $departmentId
    ]);

    $userId = $pdo->lastInsertId();
    $user = $pdo->prepare(
        'SELECT u.*, d.name AS department_name
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = :id'
    );
    $user->execute(['id' => $userId]);
    $createdUser = $user->fetch();

    if ($startSession) {
        $_SESSION['user_id'] = (int) $createdUser['id'];
        $_SESSION['user'] = $createdUser;
    }

    return ['success' => true, 'user' => $createdUser, 'message' => 'Account created successfully.'];
}
