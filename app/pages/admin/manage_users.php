<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/config/google_user_helper.php';
require_once APP_ROOT . '/config/audit_log.php';

requireLogin();
$user = currentUser();
requirePermission('manage_users', $user);

$customRoleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'custom_role_id'")->fetch();
if (!$customRoleColumn) {
    http_response_code(503);
    exit('Custom permission roles are not initialized in the database. Back up the database, run "php config/db_migrate.php" from the project root, then reload this page.');
}

$defaultRoleSeeds = [
    ['slug' => 'client', 'name' => 'Client'],
    ['slug' => 'department_staff', 'name' => 'Department Staff'],
    ['slug' => 'department_admin', 'name' => 'Department Admin'],
    ['slug' => 'super_admin', 'name' => 'Super Admin'],
];

$roleOptionsStmt = $pdo->query('SELECT slug, name FROM roles ORDER BY name ASC');
$roleOptions = $roleOptionsStmt ? $roleOptionsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
if (empty($roleOptions)) {
    $roleOptions = $defaultRoleSeeds;
}

$allRoleOptions = [];
$builtInRoleSlugs = ['client', 'department_staff', 'department_admin', 'super_admin', 'admin'];
foreach ($roleOptions as $roleOption) {
    $slug = strtolower(trim((string) ($roleOption['slug'] ?? '')));
    $name = trim((string) ($roleOption['name'] ?? ($slug !== '' ? ucfirst(str_replace('_', ' ', $slug)) : '')));
    if ($slug === '' || $name === '') {
        continue;
    }
    $allRoleOptions[] = ['slug' => $slug, 'name' => $name];
}
if (empty($allRoleOptions)) {
    $allRoleOptions = $defaultRoleSeeds;
}

$canManageAllRoles = hasPermission('manage_roles', $user);
$isDepartmentScopedUser = isDepartmentUser($user) && !isSuperAdmin($user);
$canCreateStaffUser = canCreateDepartmentStaff($user);

$assignableRoleOptions = array_values(array_filter(
    $allRoleOptions,
    static fn (array $role): bool => in_array($role['slug'], $builtInRoleSlugs, true)
));
$assignableCustomRoleOptions = $canManageAllRoles
    ? array_values(array_filter(
        $allRoleOptions,
        static fn (array $role): bool => !in_array($role['slug'], $builtInRoleSlugs, true)
    ))
    : [];
if ($isDepartmentScopedUser) {
    $assignableRoleOptions = array_values(array_filter($assignableRoleOptions, static fn (array $role): bool => $role['slug'] === 'department_staff'));
} elseif (!$canManageAllRoles) {
    $assignableRoleOptions = array_values(array_filter($assignableRoleOptions, static fn (array $role): bool => !in_array($role['slug'], ['admin', 'super_admin'], true)));
}
$assignableUserRoleOptions = array_merge($assignableRoleOptions, $assignableCustomRoleOptions);

$allowedRoleSlugs = array_map(static fn (array $role): string => $role['slug'], $assignableRoleOptions);
$assignableCustomRoleSlugs = array_map(static fn (array $role): string => $role['slug'], $assignableCustomRoleOptions);
$departmentScopedRoleSlugs = $pdo->query(
    "SELECT DISTINCT r.slug
     FROM roles r
     INNER JOIN role_permissions rp ON rp.role_id = r.id
     WHERE rp.permission_name = 'view_documents'
       AND r.slug NOT IN ('client', 'admin', 'super_admin')"
)->fetchAll(PDO::FETCH_COLUMN);
$departmentScopedRoleSlugs = array_map('strval', $departmentScopedRoleSlugs);

$roleFilter = $_GET['role_filter'] ?? 'all';
$searchFilter = trim((string) ($_GET['search'] ?? ''));
$statusFilter = $_GET['status'] ?? 'active';
$allowedRoleFilters = array_merge(
    ['all'],
    array_values(array_diff(array_column($assignableRoleOptions, 'slug'), ['admin', 'super_admin']))
);
$allowedStatuses = ['active', 'rejected'];
$roleFilter = in_array($roleFilter, $allowedRoleFilters, true) ? $roleFilter : 'all';
$statusFilter = in_array($statusFilter, $allowedStatuses, true) ? $statusFilter : 'active';
if ($isDepartmentScopedUser && $roleFilter === 'client') {
    $roleFilter = 'department_staff';
}
$departmentQuery = 'SELECT * FROM departments WHERE deleted_at IS NULL';
$departmentParams = [];
if ($isDepartmentScopedUser) {
    $departmentQuery .= ' AND id = :department_id';
    $departmentParams['department_id'] = (int) ($user['department_id'] ?? 0);
}
$departmentQuery .= ' ORDER BY name ASC';
$departmentStmt = $pdo->prepare($departmentQuery);
$departmentStmt->execute($departmentParams);
$departments = $departmentStmt->fetchAll();

// Get active users
$activeUsersSql = 'SELECT u.*, d.name AS department_name,
        custom_role.slug AS custom_role_slug, custom_role.name AS custom_role_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN roles custom_role ON custom_role.id = u.custom_role_id
     WHERE u.account_status = :account_status AND u.deleted_at IS NULL';
$params = [];
$params['account_status'] = $statusFilter;
if ($isDepartmentScopedUser) {
    $activeUsersSql .= ' AND u.department_id = :department_id';
    $params['department_id'] = (int) ($user['department_id'] ?? 0);
}
if ($roleFilter !== 'all') {
    $activeUsersSql .= ' AND u.role = :role_filter';
    $params['role_filter'] = $roleFilter;
} else {
    $activeUsersSql .= " AND u.role NOT IN ('admin', 'super_admin')";
}
if ($searchFilter !== '') {
    $activeUsersSql .= ' AND (u.name LIKE :user_name_search OR u.email LIKE :user_email_search)';
    $searchValue = '%' . $searchFilter . '%';
    $params['user_name_search'] = $searchValue;
    $params['user_email_search'] = $searchValue;
}
$userCountStmt = $pdo->prepare('SELECT COUNT(*) FROM (' . $activeUsersSql . ') AS filtered_users');
$userCountStmt->execute($params);
$totalUsers = (int) $userCountStmt->fetchColumn();
$activeUsersSql .= ' ORDER BY u.name ASC';
$activeUsersStmt = $pdo->prepare($activeUsersSql);
foreach ($params as $paramName => $paramValue) {
    $activeUsersStmt->bindValue(':' . $paramName, $paramValue);
}
$activeUsersStmt->execute();
$users = $activeUsersStmt->fetchAll();

$error = '';
$success = '';
$pending = [];
$showCreateUserModal = false;
$createUserForm = [
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'email' => '',
    'role' => 'department_staff',
    'department_id' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $showCreateUserModal = true;
        $firstNameInput = $_POST['first_name'] ?? '';
        $middleNameInput = $_POST['middle_name'] ?? '';
        $lastNameInput = $_POST['last_name'] ?? '';
        $emailInput = $_POST['email'] ?? '';
        $passwordInput = $_POST['password'] ?? '';
        $roleInput = $_POST['role'] ?? 'department_staff';
        $departmentInput = $_POST['department_id'] ?? '';
        $createUserForm['first_name'] = is_string($firstNameInput) ? trim($firstNameInput) : '';
        $createUserForm['middle_name'] = is_string($middleNameInput) ? trim($middleNameInput) : '';
        $createUserForm['last_name'] = is_string($lastNameInput) ? trim($lastNameInput) : '';
        $name = trim(implode(' ', array_filter(
            [$createUserForm['first_name'], $createUserForm['middle_name'], $createUserForm['last_name']],
            static fn (string $part): bool => $part !== ''
        )));
        $email = is_string($emailInput) ? trim(strtolower($emailInput)) : '';
        $createUserForm['email'] = $email;
        $password = is_string($passwordInput) ? $passwordInput : '';
        $role = is_string($roleInput) ? trim($roleInput) : '';
        $departmentId = is_scalar($departmentInput) ? (int) $departmentInput : 0;
        $createUserForm['role'] = $role;
        $createUserForm['department_id'] = (string) $departmentId;

        if ($isDepartmentScopedUser && !$canCreateStaffUser) {
            $error = 'Your role is not allowed to create department staff accounts.';
        } elseif ($role === 'department_staff' && !hasPermission('create_department_staff', $user)) {
            $error = 'You do not have permission to create department staff accounts.';
        } elseif (!$canManageAllRoles && !in_array($role, ['client', 'department_staff'], true)) {
            $error = 'You do not have permission to assign this role.';
        } elseif ($isDepartmentScopedUser) {
            if ($role !== 'department_staff') {
                $error = 'Department users can only create department staff accounts.';
            } elseif ($departmentId !== (int) ($user['department_id'] ?? 0)) {
                $error = 'Department users can only assign users to their own department.';
            }
        } elseif ($canManageAllRoles) {
            if (!in_array($role, $allowedRoleSlugs, true)) {
                $error = 'Selected role is not available for this account.';
            }
        } elseif (!in_array($role, $allowedRoleSlugs, true)) {
            $error = 'Selected role is not available for this account.';
        }

        if (empty($error) && ($createUserForm['first_name'] === '' || $createUserForm['last_name'] === '' || $email === '' || $password === '')) {
            $error = 'First name, last name, email, and password are required.';
        }
        if (empty($error) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address. Email addresses from any valid domain are accepted.';
        }
        if (empty($error) && strlen($email) > 100) {
            $error = 'Email address must be 100 characters or fewer.';
        }
        if (empty($error) && strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        }
        if (empty($error) && mb_strlen($name) > 100) {
            $error = 'The combined name must be 100 characters or fewer.';
        }
        if (empty($error) && $departmentId <= 0) {
            $error = 'Select a department for this user.';
        }
        if (empty($error)) {
            $departmentCheck = $pdo->prepare('SELECT id FROM departments WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $departmentCheck->execute(['id' => $departmentId]);
            if (!$departmentCheck->fetchColumn()) {
                $error = 'Select a valid active department.';
            }
        }

        if (empty($error)) {
            $result = registerUser($pdo, $name, $email, $password, $role, $departmentId, false);
            if ($result['success']) {
                $success = 'User created successfully.';
                $showCreateUserModal = false;
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'approve_account') {
        requirePermission('manage_account_status', $user);
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $departmentId = !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null;
        $role = trim($_POST['role'] ?? 'client');

        if ($canManageAllRoles && !in_array($role, $allowedRoleSlugs, true)) {
            $error = 'Selected role is not available for this account.';
        } elseif (!$canManageAllRoles && !in_array($role, ['client', 'department_staff'], true)) {
            $error = 'You do not have permission to assign this role.';
        }
        if ($isDepartmentScopedUser) {
            if ($role !== 'department_staff') {
                $error = 'Department users can only approve department staff accounts.';
            } elseif ($departmentId !== (int) ($user['department_id'] ?? 0)) {
                $error = 'Department users can only approve users for their own department.';
            } else {
                $targetStmt = $pdo->prepare('SELECT department_id, role, account_status FROM users WHERE id = :id LIMIT 1');
                $targetStmt->execute(['id' => $targetId]);
                $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
                if (!$target || (int) ($target['department_id'] ?? 0) !== (int) ($user['department_id'] ?? 0) || ($target['role'] ?? '') !== 'department_staff') {
                    $error = 'Department users can only approve their own department staff.';
                }
            }
        }
        if (empty($error) && in_array($role, $departmentScopedRoleSlugs, true)) {
            if (!$departmentId) {
                $error = 'Assign this document-viewing role to a department.';
            } else {
                $departmentCheck = $pdo->prepare('SELECT id FROM departments WHERE id = :id AND deleted_at IS NULL LIMIT 1');
                $departmentCheck->execute(['id' => $departmentId]);
                if (!$departmentCheck->fetchColumn()) {
                    $error = 'Select a valid active department for this role.';
                }
            }
        }

        if ($targetId <= 0) {
            $error = 'Invalid user selected.';
        } else {
            if (empty($error) && activateGoogleAccount($pdo, $targetId, $departmentId, $role)) {
                // Send activation email
                $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
                $userStmt->execute(['id' => $targetId]);
                $userData = $userStmt->fetch();

                $deptStmt = $pdo->prepare('SELECT name FROM departments WHERE id = :id');
                $deptStmt->execute(['id' => $departmentId]);
                $deptData = $deptStmt->fetch();
                $deptName = $deptData ? $deptData['name'] : null;

                sendAccountActivationEmail($userData['email'], $userData['name'], $deptName);
                auditAccountStatusChanged($pdo, $targetId, 'pending', 'active', $user['id']);
                $success = 'Account approved and activation email sent.';
            } else {
                $error = 'Failed to approve account.';
            }
        }
    } elseif ($action === 'reject_account') {
        requirePermission('manage_account_status', $user);
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Account registration not approved');

        if ($isDepartmentScopedUser) {
            $targetUser = $pdo->prepare('SELECT department_id, role FROM users WHERE id = :id LIMIT 1');
            $targetUser->execute(['id' => $targetId]);
            $targetUserData = $targetUser->fetch(PDO::FETCH_ASSOC);
            if (!$targetUserData || (int) ($targetUserData['department_id'] ?? 0) !== (int) ($user['department_id'] ?? 0) || ($targetUserData['role'] ?? '') !== 'department_staff') {
                $error = 'Department users can only reject their own department staff.';
            }
        }

        if ($targetId <= 0) {
            $error = 'Invalid user selected.';
        } else {
            if (empty($error) && rejectGoogleAccount($pdo, $targetId, $reason)) {
                // Send rejection email
                $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
                $userStmt->execute(['id' => $targetId]);
                $userData = $userStmt->fetch();
                sendAccountRejectionEmail($userData['email'], $userData['name'], $reason);
                auditAccountStatusChanged($pdo, $targetId, 'pending', 'rejected', $user['id']);
                $success = 'Account rejected and notification email sent.';
            } else {
                $error = 'Failed to reject account.';
            }
        }
    } elseif ($action === 'suspend_account') {
        requirePermission('manage_account_status', $user);
        $targetId = (int) ($_POST['user_id'] ?? 0);

        if ($targetId <= 0) {
            $error = 'Invalid user selected.';
        } elseif ($targetId === (int) $user['id']) {
            $error = 'You cannot suspend your own account.';
        } else {
            if ($isDepartmentScopedUser) {
                $targetUser = $pdo->prepare('SELECT department_id, role FROM users WHERE id = :id LIMIT 1');
                $targetUser->execute(['id' => $targetId]);
                $targetUserData = $targetUser->fetch(PDO::FETCH_ASSOC);
                if (!$targetUserData || (int) ($targetUserData['department_id'] ?? 0) !== (int) ($user['department_id'] ?? 0) || ($targetUserData['role'] ?? '') !== 'department_staff') {
                    $error = 'Department users can only suspend their own department staff.';
                }
            }
            if (empty($error) && suspendGoogleAccount($pdo, $targetId)) {
                auditAccountStatusChanged($pdo, $targetId, 'active', 'suspended', $user['id']);
                header('Location: trash_bin.php');
                exit;
            } else {
                $error = 'Failed to suspend account.';
            }
        }
    } elseif ($action === 'reactivate_account') {
        requirePermission('manage_account_status', $user);
        $targetId = (int) ($_POST['user_id'] ?? 0);

        if ($targetId <= 0) {
            $error = 'Invalid user selected.';
        } else {
            if ($isDepartmentScopedUser) {
                $targetStmt = $pdo->prepare('SELECT department_id, role FROM users WHERE id = :id LIMIT 1');
                $targetStmt->execute(['id' => $targetId]);
                $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
                if (!$target || (int) ($target['department_id'] ?? 0) !== (int) ($user['department_id'] ?? 0) || ($target['role'] ?? '') !== 'department_staff') {
                    $error = 'Department users can only reactivate their own department staff.';
                }
            }
            if (empty($error) && reactivateGoogleAccount($pdo, $targetId)) {
                auditAccountStatusChanged($pdo, $targetId, 'suspended', 'active', $user['id']);
                $success = 'Account reactivated.';
            } else {
                $error = 'Failed to reactivate account.';
            }
        }
    } elseif ($action === 'delete_user') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if ($targetId <= 0) {
            $error = 'Invalid user selected.';
        } elseif ($targetId === (int) $user['id']) {
            $error = 'You cannot delete your own administrator account.';
        } else {
            if ($isDepartmentScopedUser) {
                $targetUser = $pdo->prepare('SELECT department_id, role FROM users WHERE id = :id LIMIT 1');
                $targetUser->execute(['id' => $targetId]);
                $targetUserData = $targetUser->fetch(PDO::FETCH_ASSOC);
                if (!$targetUserData || (int) ($targetUserData['department_id'] ?? 0) !== (int) ($user['department_id'] ?? 0) || ($targetUserData['role'] ?? '') !== 'department_staff') {
                    $error = 'Department users can only delete their own department staff.';
                }
            }

            if (empty($error)) {
                $dependencyStmt = $pdo->prepare(
                    'SELECT
                        (SELECT COUNT(*) FROM report_logs WHERE updated_by = :report_log_user_id) AS report_log_count,
                        (SELECT COUNT(*) FROM archived_records WHERE archived_by = :archived_user_id) AS archived_record_count,
                        (SELECT COUNT(*) FROM reports WHERE client_id = :client_user_id) AS client_report_count'
                );
                $dependencyStmt->execute([
                    'report_log_user_id' => $targetId,
                    'archived_user_id' => $targetId,
                    'client_user_id' => $targetId,
                ]);
                $dependencyCounts = $dependencyStmt->fetch(PDO::FETCH_ASSOC);

                $hasDependencies = ($dependencyCounts['report_log_count'] ?? 0) > 0
                    || ($dependencyCounts['archived_record_count'] ?? 0) > 0
                    || ($dependencyCounts['client_report_count'] ?? 0) > 0;

                if ($hasDependencies) {
                    $error = 'Cannot delete this user because they have existing report activity or audit records tied to their account.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare('UPDATE users SET deleted_at = NOW(), deleted_by = :deleted_by, deleted_reason = :deleted_reason WHERE id = :id')
                            ->execute([
                                'deleted_by' => (int) $user['id'],
                                'deleted_reason' => 'Moved to Trash Bin from User Management',
                                'id' => $targetId,
                            ]);
                        $pdo->commit();
                        $success = 'User moved to the Trash Bin.';
                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = 'Unable to delete this user because the database still has dependent records.';
                        error_log('User deletion failed for user_id=' . $targetId . ': ' . $e->getMessage());
                    }
                }
            }
        }
    } elseif ($action === 'update_user_role') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $role = trim($_POST['role'] ?? 'client');
        $departmentId = !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null;
        $selectedCustomRole = in_array($role, $assignableCustomRoleSlugs, true);

        if ($targetId <= 0) {
            $error = 'Invalid user selected.';
        } elseif (!$isDepartmentScopedUser && !$canManageAllRoles) {
            $error = 'You do not have permission to change user roles.';
        } elseif ($selectedCustomRole && !$canManageAllRoles) {
            $error = 'You need role-management permission to assign a custom role.';
        } else {
            $targetStmt = $pdo->prepare(
                'SELECT u.department_id, u.role, u.custom_role_id, assigned_role.slug AS assigned_custom_role_slug
                 FROM users u
                 LEFT JOIN roles assigned_role ON assigned_role.id = u.custom_role_id
                 WHERE u.id = :id
                 LIMIT 1'
            );
            $targetStmt->execute(['id' => $targetId]);
            $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                $error = 'The selected user no longer exists.';
            }
            $keepExistingCustomRole = !$canManageAllRoles
                && $target
                && !empty($target['custom_role_id'])
                && ($target['assigned_custom_role_slug'] ?? '') === $role;
            if ($keepExistingCustomRole) {
                $selectedCustomRole = false;
            }

            if ($isDepartmentScopedUser) {
                $targetRole = (string) ($target['role'] ?? '');
                $isDepartmentAccount = in_array($targetRole, ['department_admin', 'department_staff', 'department', 'staff'], true);
                $canAssignDepartmentCustomRole = ($canManageAllRoles && $selectedCustomRole) || $keepExistingCustomRole;
                if (!$target
                    || (int) ($target['department_id'] ?? 0) !== (int) ($user['department_id'] ?? 0)
                    || $departmentId !== (int) ($user['department_id'] ?? 0)
                    || !$isDepartmentAccount
                    || (!$canAssignDepartmentCustomRole && $role !== $targetRole)) {
                    $error = 'Department users can only manage accounts in their own department and assign new custom roles with role-management permission.';
                }
            } elseif (!in_array($role, $allowedRoleSlugs, true) && !$selectedCustomRole && !$keepExistingCustomRole) {
                $error = 'Selected role is not available for this account.';
            }
            $departmentScopeRole = $keepExistingCustomRole
                ? (string) ($target['assigned_custom_role_slug'] ?? '')
                : $role;
            if (empty($error) && in_array($departmentScopeRole, $departmentScopedRoleSlugs, true)
                && ($target['role'] ?? '') !== 'client') {
                if (!$departmentId) {
                    $error = 'Assign this document-viewing role to a department.';
                } else {
                    $departmentCheck = $pdo->prepare('SELECT id FROM departments WHERE id = :id AND deleted_at IS NULL LIMIT 1');
                    $departmentCheck->execute(['id' => $departmentId]);
                    if (!$departmentCheck->fetchColumn()) {
                        $error = 'Select a valid active department for this role.';
                    }
                }
            }
            if (empty($error)) {
                if ($selectedCustomRole) {
                    $customRoleStmt = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
                    $customRoleStmt->execute(['slug' => $role]);
                    $customRoleId = (int) $customRoleStmt->fetchColumn();
                    if ($customRoleId <= 0) {
                        $error = 'The selected custom role no longer exists.';
                    } else {
                        $pdo->prepare(
                            'UPDATE users SET custom_role_id = :custom_role_id, department_id = :dept_id WHERE id = :id'
                        )->execute([
                            'custom_role_id' => $customRoleId,
                            'dept_id' => $departmentId,
                            'id' => $targetId,
                        ]);
                    }
                } elseif ($keepExistingCustomRole) {
                    $pdo->prepare(
                        'UPDATE users SET department_id = :dept_id WHERE id = :id'
                    )->execute([
                        'dept_id' => $departmentId,
                        'id' => $targetId,
                    ]);
                } elseif ($canManageAllRoles) {
                    $pdo->prepare(
                        'UPDATE users SET role = :role, custom_role_id = NULL, department_id = :dept_id WHERE id = :id'
                    )->execute([
                        'role' => $role,
                        'dept_id' => $departmentId,
                        'id' => $targetId,
                    ]);
                } else {
                    $pdo->prepare(
                        'UPDATE users SET role = :role, department_id = :dept_id WHERE id = :id'
                    )->execute([
                        'role' => $role,
                        'dept_id' => $departmentId,
                        'id' => $targetId,
                    ]);
                }
            }
            if (empty($error)) {
                $success = 'User role and department updated.';
            }
        }
    }

    if ($success !== '') {
        // Refresh the current filtered page after a successful action.
        $activeUsersStmt->execute();
        $users = $activeUsersStmt->fetchAll();
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<div class="management-toolbar">
    <div>
        <h1>User Management</h1>
        <p style="color: #64748b; margin: 0;">Manage user accounts, roles, and department access. Google accounts are active immediately after sign-in.</p>
    </div>
    <?php if (!$isDepartmentScopedUser || $canCreateStaffUser): ?>
        <button type="button" class="admin-primary-button add-create-control" title="Add User" aria-label="Add user" onclick="document.getElementById('add-user-modal').style.display='flex';"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add User</button>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if (!empty($pending)): ?>
    <div class="card" style="margin-bottom: 2rem; border: 2px solid #fbbf24;">
        <h2 style="color: #92400e; margin-top: 0;">⏳ Pending Approvals (<?= count($pending) ?>)</h2>
        <p style="color: #b45309; margin: 0 0 1rem;">New accounts awaiting admin review</p>

        <div class="table-responsive">
            <table class="table" style="margin: 0;">
                <thead style="background: #fefce8;">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Google Account</th>
                        <th>Email Status</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $person): ?>
                        <tr style="background: #fffbeb;">
                            <td><strong><?= htmlspecialchars($person['name']) ?></strong></td>
                            <td><?= htmlspecialchars($person['email']) ?></td>
                            <td>
                                <?php if ($person['google_id']): ?>
                                    <span style="display: inline-block; background: #e0f2fe; color: #0369a1; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">✓ Google</span>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($person['email_verified']): ?>
                                    <span style="display: inline-block; background: #dcfce7; color: #166534; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">✓ Verified</span>
                                <?php else: ?>
                                    <span style="display: inline-block; background: #fecaca; color: #7f1d1d; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.85rem; color: #64748b;">
                                <?= date('m/d/Y', strtotime($person['created_at'] ?? 'now')) ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                    <button type="button" class="btn-action btn-action-success" title="Approve User" aria-label="Approve user" onclick="document.getElementById('approve_<?= $person['id'] ?>').style.display = 'flex';">✓</button>
                                    <form method="POST" action="manage_users.php" data-user-confirm="reject" style="display: inline;">
                                        <input type="hidden" name="action" value="reject_account">
                                        <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                                        <input type="hidden" name="reason" value="Account registration not approved by administrator">
                                        <button type="button" class="btn-action btn-action-danger" title="Reject User" aria-label="Reject user" data-user-trigger="reject">✕</button>
                                    </form>
                                </div>

                                <!-- Approval Modal -->
                                <div id="approve_<?= $person['id'] ?>" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 999; align-items: center; justify-content: center;">
                                    <div style="background: white; padding: 2rem; border-radius: 12px; max-width: 500px; width: 90%;">
                                        <h3 style="margin-top: 0;">Approve Account</h3>
                                        <p style="color: #64748b;">Assign <?= htmlspecialchars($person['name']) ?> a role (department is optional for Clients):</p>

                                        <form method="POST" action="manage_users.php">
                                            <input type="hidden" name="action" value="approve_account">
                                            <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">

                                            <div class="form-group" id="dept_group_<?= $person['id'] ?>">
                                                <label for="department_<?= $person['id'] ?>">Department <small style="color:#64748b;">(optional for Client)</small></label>
                                                <select id="department_<?= $person['id'] ?>" name="department_id" class="form-control">
                                                    <option value="">— None / Not assigned —</option>
                                                    <?php foreach ($departments as $dept): ?>
                                                        <option value="<?= (int) $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="form-group">
                                                <label for="role_<?= $person['id'] ?>">Role</label>
                                                <select id="role_<?= $person['id'] ?>" name="role" class="form-control" required onchange="toggleDept<?= $person['id'] ?>(this.value);">
                                                    <?php foreach ($assignableRoleOptions as $roleOption): ?>
                                                        <option value="<?= htmlspecialchars($roleOption['slug']) ?>"><?= htmlspecialchars($roleOption['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                                                <button type="button" class="icon-circle-button secondary" title="Cancel" aria-label="Cancel" onclick="document.getElementById('approve_<?= $person['id'] ?>').style.display = 'none';">✕</button>
                                                <button type="submit" class="icon-circle-button success" title="Approve & Send Email" aria-label="Approve and send email">✓</button>
                                            </div>
                                        </form>

                                        <script>
                                        function toggleDept<?= $person['id'] ?>(role) {
                                            var deptGroup = document.getElementById('dept_group_<?= $person['id'] ?>');
                                            var deptSelect = document.getElementById('department_<?= $person['id'] ?>');
                                            if (!deptGroup || !deptSelect) return;
                                            if (role === 'client') {
                                                // For clients, department is optional and not required
                                                deptGroup.style.opacity = '0.7';
                                                deptSelect.required = false;
                                            } else {
                                                deptGroup.style.opacity = '1';
                                                deptSelect.required = false; // keep optional, admin may still choose
                                            }
                                        }

                                        // Initialize based on default selection
                                        document.addEventListener('DOMContentLoaded', function() {
                                            var roleEl = document.getElementById('role_<?= $person['id'] ?>');
                                            if (roleEl) toggleDept<?= $person['id'] ?>(roleEl.value);
                                        });
                                        </script>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Create New User Modal -->
<div id="add-user-modal" style="display: <?= $showCreateUserModal ? 'flex' : 'none' ?>; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 999; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: #fff; border-radius: 12px; width: min(720px, 96vw); max-height: 90vh; overflow-y: auto; padding: 1.5rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1rem;">
            <h3 style="margin: 0;">Add New User</h3>
        </div>
        <?php if ($showCreateUserModal && $error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="manage_users.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.9rem; align-items: end;">
            <input type="hidden" name="action" value="create_user">

            <div class="form-group" style="margin: 0;">
                <label for="add-user-first-name">First name</label>
                <input type="text" id="add-user-first-name" name="first_name" class="form-control" maxlength="100" autocomplete="given-name" value="<?= htmlspecialchars($createUserForm['first_name']) ?>" required>
            </div>

            <div class="form-group" style="margin: 0;">
                <label for="add-user-middle-name">Middle name <small>(optional)</small></label>
                <input type="text" id="add-user-middle-name" name="middle_name" class="form-control" maxlength="100" autocomplete="additional-name" value="<?= htmlspecialchars($createUserForm['middle_name']) ?>">
            </div>

            <div class="form-group" style="margin: 0;">
                <label for="add-user-last-name">Last name</label>
                <input type="text" id="add-user-last-name" name="last_name" class="form-control" maxlength="100" autocomplete="family-name" value="<?= htmlspecialchars($createUserForm['last_name']) ?>" required>
            </div>

            <div class="form-group" style="margin: 0;">
                <label for="add-user-email">Email</label>
                <input type="email" id="add-user-email" name="email" class="form-control" maxlength="100" autocomplete="email" value="<?= htmlspecialchars($createUserForm['email']) ?>" required>
                <small>Use any valid email domain; Gmail is not required.</small>
            </div>

            <div class="form-group" style="margin: 0;">
                <label for="add-user-password">Password</label>
                <input type="password" id="add-user-password" name="password" class="form-control" autocomplete="new-password" aria-describedby="add-user-password-error" required>
                <small id="add-user-password-error" role="alert" style="display:none;color:#b91c1c;">Password must be at least 8 characters long.</small>
            </div>

            <div class="form-group" style="margin: 0;">
                <label for="add-user-role">Role</label>
                <select id="add-user-role" name="role" class="form-control">
                    <?php foreach ($assignableRoleOptions as $roleOption): ?>
                        <?php if ($roleOption['slug'] === 'client') continue; ?>
                        <option value="<?= htmlspecialchars($roleOption['slug']) ?>" <?= $createUserForm['role'] === $roleOption['slug'] ? 'selected' : '' ?>><?= htmlspecialchars($roleOption['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin: 0;">
                <label for="add-user-department">Department</label>
                <select id="add-user-department" name="department_id" class="form-control" required>
                    <?php if ($isDepartmentScopedUser): ?>
                        <option value="<?= (int) ($user['department_id'] ?? 0) ?>"><?= htmlspecialchars($user['department_name'] ?? 'Own Department') ?></option>
                    <?php else: ?>
                        <option value="">Select department</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= (int) $department['id'] ?>" <?= $createUserForm['department_id'] === (string) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars($department['name']) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.6rem; grid-column:1/-1; margin-top:0.5rem;">
                <button type="button" class="admin-secondary-button" title="Cancel" onclick="document.getElementById('add-user-modal').style.display='none';">Cancel</button>
                <button type="submit" class="admin-primary-button" title="Save User"><i class="bi bi-check-lg" aria-hidden="true"></i> Save User</button>
            </div>
        </form>
    </div>
</div>
<script>
    (function () {
        var passwordInput = document.getElementById('add-user-password');
        var passwordError = document.getElementById('add-user-password-error');
        if (!passwordInput || !passwordError) return;
        function validateAddUserPassword() {
            var invalid = passwordInput.value.length < 8;
            passwordInput.setCustomValidity(invalid ? 'Password must be at least 8 characters long.' : '');
            passwordError.style.display = invalid && passwordInput.value !== '' ? 'block' : 'none';
            return !invalid;
        }
        passwordInput.addEventListener('input', validateAddUserPassword);
        passwordInput.form.addEventListener('submit', function (event) {
            if (!validateAddUserPassword()) {
                event.preventDefault();
                passwordError.style.display = 'block';
                passwordInput.reportValidity();
            }
        });
    })();
</script>

<!-- Active Users Section -->
<div class="card">
    <h2 style="margin-top: 0;">👥 <?= $statusFilter === 'active' ? 'Active' : 'Rejected' ?> Users (<?= $totalUsers ?>)</h2>

    <div class="table-responsive">
        <table class="table" data-datatable-responsive="false" data-datatable-search="true" data-datatable-paging="true" data-datatable-ordering="true" data-datatable-info="true">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Department</th>
                    <th>Google</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #94a3b8; padding: 2rem;">No active users found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $person): ?>
                        <?php
                        $baseRoleLabel = str_replace('_', ' ', ucfirst($person['role'] ?? 'user'));
                        $personRoleLabel = !empty($person['custom_role_name'])
                            ? $person['custom_role_name'] . ' (' . $baseRoleLabel . ')'
                            : $baseRoleLabel;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($person['name']) ?></strong></td>
                            <td><?= htmlspecialchars($person['email']) ?></td>
                            <td><span style="display: inline-block; background: #f3f4f6; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;"><?= htmlspecialchars($personRoleLabel) ?></span></td>
                            <td><?= htmlspecialchars($person['department_name'] ?? '—') ?></td>
                            <td>
                                <?php if ($person['google_id']): ?>
                                    <span style="display: inline-block; background: #e0f2fe; color: #0369a1; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">Google</span>
                                <?php else: ?>
                                    <span style="color: #9ca3af; font-size: 0.85rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.85rem; color: #64748b;">
                                <?php if ($person['last_login']): ?>
                                    <?= date('m/d/Y h:i A', strtotime($person['last_login'])) ?>
                                <?php else: ?>
                                    Never
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-menu-shell">
                                    <button type="button" class="action-more-toggle" aria-label="More actions" title="More actions" aria-expanded="false">
                                        <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                    </button>
                                    <div class="action-menu-panel">
                                        <button type="button" class="action-menu-item" data-user-view="<?= (int) $person['id'] ?>">
                                            <i class="bi bi-eye-fill" aria-hidden="true"></i>
                                            View
                                        </button>
                                        <?php if ((int) $person['id'] !== (int) $user['id']): ?>
                                            <button type="button" class="action-menu-item" onclick="document.getElementById('edit_<?= $person['id'] ?>').style.display = 'flex'; this.closest('.action-menu-shell').classList.remove('is-open');">
                                                <i class="bi bi-pencil-fill" aria-hidden="true"></i>
                                                Edit
                                            </button>
                                            <form method="POST" action="manage_users.php" data-user-confirm="suspend" style="margin: 0;">
                                                <input type="hidden" name="action" value="suspend_account">
                                                <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                                                <button type="button" class="action-menu-item" style="color: #ea580c;" data-user-trigger="suspend">
                                                    <i class="bi bi-pause-fill" aria-hidden="true"></i>
                                                    Suspend
                                                </button>
                                            </form>
                                            <form method="POST" action="manage_users.php" data-user-confirm="delete" style="margin: 0;">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                                                <button type="button" class="action-menu-item" style="color: #dc2626;" data-user-trigger="delete">
                                                    <i class="bi bi-trash3" aria-hidden="true"></i>
                                                    Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- User details modal -->
                                <div id="view_user_<?= (int) $person['id'] ?>" role="dialog" aria-modal="true" aria-labelledby="view_user_title_<?= (int) $person['id'] ?>" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:100000; align-items:center; justify-content:center; padding:1rem;">
                                    <div style="background:#fff; border-radius:12px; width:min(620px,96vw); max-height:90vh; overflow-y:auto; padding:1.5rem; box-shadow:0 20px 50px rgba(15,23,42,.25);">
                                        <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1rem;">
                                            <div>
                                                <h3 id="view_user_title_<?= (int) $person['id'] ?>" style="margin:0;">User Details</h3>
                                                <small style="color:#64748b;">Account #<?= (int) $person['id'] ?></small>
                                            </div>
                                            <button type="button" class="btn btn-light" title="Close details" aria-label="Close user details" data-user-view-close><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                        </div>
                                        <dl style="display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:.85rem; margin:0;">
                                            <div><dt style="color:#64748b;font-size:.8rem;">Full name</dt><dd style="margin:.15rem 0 0;font-weight:600;"><?= htmlspecialchars($person['name'] ?? '—') ?></dd></div>
                                            <div><dt style="color:#64748b;font-size:.8rem;">Email</dt><dd style="margin:.15rem 0 0;overflow-wrap:anywhere;"><?= htmlspecialchars($person['email'] ?? '—') ?></dd></div>
                                            <div><dt style="color:#64748b;font-size:.8rem;">Contact number</dt><dd style="margin:.15rem 0 0;"><?= htmlspecialchars($person['contact_number'] ?? '—') ?></dd></div>
                                            <div><dt style="color:#64748b;font-size:.8rem;">Role</dt><dd style="margin:.15rem 0 0;"><?= htmlspecialchars($personRoleLabel) ?></dd></div>
                                            <div><dt style="color:#64748b;font-size:.8rem;">Department</dt><dd style="margin:.15rem 0 0;"><?= htmlspecialchars($person['department_name'] ?? '—') ?></dd></div>
                                            <div><dt style="color:#64748b;font-size:.8rem;">Account status</dt><dd style="margin:.15rem 0 0;"><?= htmlspecialchars(ucfirst((string) ($person['account_status'] ?? 'unknown'))) ?></dd></div>
                                            <div><dt style="color:#64748b;font-size:.8rem;">Email verification</dt><dd style="margin:.15rem 0 0;"><?= !empty($person['email_verified']) ? 'Verified' : 'Not verified' ?></dd></div>
                                            <div><dt style="color:#64748b;font-size:.8rem;">Sign-in method</dt><dd style="margin:.15rem 0 0;"><?= !empty($person['google_id']) ? 'Google' : 'Email and password' ?></dd></div>
                                            <div><dt style="color:#64748b;font-size:.8rem;">Created</dt><dd style="margin:.15rem 0 0;"><?= !empty($person['created_at']) ? htmlspecialchars(date('M j, Y g:i A', strtotime($person['created_at']))) : '—' ?></dd></div>
                                            <div><dt style="color:#64748b;font-size:.8rem;">Last login</dt><dd style="margin:.15rem 0 0;"><?= !empty($person['last_login']) ? htmlspecialchars(date('M j, Y g:i A', strtotime($person['last_login']))) : 'Never' ?></dd></div>
                                        </dl>
                                    </div>
                                </div>

                                <!-- Edit Modal -->
                                <div id="edit_<?= $person['id'] ?>" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 999; align-items: center; justify-content: center;">
                                    <div style="background: white; padding: 2rem; border-radius: 12px; max-width: 500px; width: 90%;">
                                        <h3 style="margin-top: 0;">Edit User</h3>
                                        <p style="color: #64748b;">Choose a built-in account type or a custom permission role for <?= htmlspecialchars($person['name']) ?>. Custom roles keep the user's Client/Department Admin/Department Staff account type. Assigning a custom role requires Role Management permission; document-viewing roles require a department except for Clients, who can view only their own documents.</p>

                                        <form method="POST" action="manage_users.php">
                                            <input type="hidden" name="action" value="update_user_role">
                                            <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">

                                            <div class="form-group">
                                                <label for="department_edit_<?= $person['id'] ?>">Department</label>
                                                <select id="department_edit_<?= $person['id'] ?>" name="department_id" class="form-control">
                                                    <option value="">None</option>
                                                    <?php foreach ($departments as $dept): ?>
                                                        <option value="<?= (int) $dept['id'] ?>" <?= ((int) $person['department_id'] === (int) $dept['id']) ? 'selected' : '' ?>><?= htmlspecialchars($dept['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="form-group">
                                                <label for="role_edit_<?= $person['id'] ?>">Account Type / Permission Role</label>
                                                <select id="role_edit_<?= $person['id'] ?>" name="role" class="form-control" required>
                                                    <?php $personRoleOptions = $assignableUserRoleOptions; ?>
                                                    <?php if (!empty($person['custom_role_slug'])
                                                        && !in_array($person['custom_role_slug'], array_column($personRoleOptions, 'slug'), true)): ?>
                                                        <?php array_unshift($personRoleOptions, ['slug' => $person['custom_role_slug'], 'name' => $person['custom_role_name']]); ?>
                                                    <?php endif; ?>
                                                    <?php if (!in_array((string) ($person['role'] ?? ''), array_column($personRoleOptions, 'slug'), true)): ?>
                                                        <?php array_unshift($personRoleOptions, ['slug' => $person['role'], 'name' => str_replace('_', ' ', ucfirst($person['role']))]); ?>
                                                    <?php endif; ?>
                                                    <?php foreach ($personRoleOptions as $roleOption): ?>
                                                        <?php $isSelectedRole = !empty($person['custom_role_slug'])
                                                            ? $person['custom_role_slug'] === $roleOption['slug']
                                                            : ($person['role'] ?? '') === $roleOption['slug']; ?>
                                                        <option value="<?= htmlspecialchars($roleOption['slug']) ?>" <?= $isSelectedRole ? 'selected' : '' ?>><?= htmlspecialchars($roleOption['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                                                <button type="button" class="icon-circle-button secondary" title="Cancel" aria-label="Cancel" onclick="document.getElementById('edit_<?= $person['id'] ?>').style.display = 'none';">✕</button>
                                                <button type="submit" class="icon-circle-button primary" title="Save Changes" aria-label="Save changes">✓</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="user-confirm-modal" hidden style="position:fixed; inset:0; z-index:2000; display:flex; align-items:center; justify-content:center; padding:1rem; background:rgba(15,23,42,.55);">
    <div role="dialog" aria-modal="true" aria-labelledby="user-confirm-title" style="width:min(100%,340px); border-radius:14px; background:#fff; padding:1.5rem; text-align:center; box-shadow:0 20px 50px rgba(15,23,42,.25);">
        <div data-user-icon-wrap style="width:48px; height:48px; margin:0 auto .8rem; display:flex; align-items:center; justify-content:center; border-radius:50%; background:#fff1f2; color:#ef4444; font-size:1.3rem;">
            <i class="bi bi-trash3" data-user-icon aria-hidden="true"></i>
        </div>
        <h3 id="user-confirm-title" style="margin:0 0 .45rem; font-size:1.15rem;">Delete?</h3>
        <p id="user-confirm-message" style="margin:0 0 1.15rem; color:#64748b; font-size:.88rem;">Are you sure you want to delete this item?</p>
        <div style="display:flex; justify-content:center; gap:.65rem;">
            <button type="button" class="btn btn-light" data-user-cancel aria-label="Cancel"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            <button type="button" class="btn btn-primary" data-user-submit aria-label="Confirm delete"><i class="bi bi-trash3" aria-hidden="true"></i></button>
        </div>
    </div>
</div>

<script>
(() => {
    document.addEventListener('click', (event) => {
        const viewButton = event.target.closest('[data-user-view]');
        if (viewButton) {
            const detailsModal = document.getElementById(`view_user_${viewButton.dataset.userView}`);
            if (detailsModal) {
                document.body.appendChild(detailsModal);
                detailsModal.style.display = 'flex';
            }
            const actionMenu = viewButton.closest('.action-menu-shell');
            if (actionMenu) actionMenu.classList.remove('is-open');
            return;
        }

        const closeButton = event.target.closest('[data-user-view-close]');
        if (closeButton) {
            const detailsModal = closeButton.closest('[role="dialog"]');
            if (detailsModal) detailsModal.style.display = 'none';
            return;
        }

        if (event.target.matches('[id^="view_user_"]')) {
            event.target.style.display = 'none';
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll('[id^="view_user_"]').forEach((detailsModal) => {
                detailsModal.style.display = 'none';
            });
        }
    });
})();

(() => {
    const modal = document.getElementById('user-confirm-modal');
    let pendingForm = null;
    if (!modal) return;
    const title = document.getElementById('user-confirm-title');
    const message = document.getElementById('user-confirm-message');
    const submit = modal.querySelector('[data-user-submit]');
    const icon = modal.querySelector('[data-user-icon]');
    const iconWrap = modal.querySelector('[data-user-icon-wrap]');
    const close = () => { modal.hidden = true; pendingForm = null; };

    document.querySelectorAll('[data-user-trigger]').forEach((button) => {
        button.addEventListener('click', () => {
            pendingForm = button.closest('form');
            const action = button.dataset.userTrigger;
            title.textContent = action === 'delete' ? 'Delete?' : (action === 'suspend' ? 'Suspend?' : 'Reject?');
            message.textContent = action === 'delete'
                ? 'Are you sure you want to delete this item?'
                : (action === 'suspend' ? 'Are you sure you want to suspend this account?' : 'Are you sure you want to reject this account?');
            const isSuspend = action === 'suspend';
            const isDelete = action === 'delete';
            const iconClass = isSuspend ? 'bi bi-pause-fill' : (isDelete ? 'bi bi-trash3' : 'bi bi-x-lg');
            const iconColor = isSuspend ? '#ea580c' : '#ef4444';
            const iconBackground = isSuspend ? '#fff7ed' : '#fff1f2';
            if (icon) icon.className = iconClass;
            if (iconWrap) {
                iconWrap.style.color = iconColor;
                iconWrap.style.background = iconBackground;
            }
            submit.setAttribute('aria-label', `Confirm ${action}`);
            submit.innerHTML = `<i class="${iconClass}" aria-hidden="true"></i>`;
            submit.style.backgroundColor = isSuspend ? '#ea580c' : '#2563eb';
            submit.style.borderColor = isSuspend ? '#ea580c' : '#2563eb';
            modal.hidden = false;
        });
    });

    modal.querySelector('[data-user-cancel]').addEventListener('click', close);
    submit.addEventListener('click', () => { if (pendingForm) pendingForm.submit(); });
    modal.addEventListener('click', (event) => { if (event.target === modal) close(); });
})();
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
