<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';

requireLogin();
$user = currentUser();
requireAnyPermission(['manage_roles', 'manage_settings'], $user);

$error = '';
$success = '';
$permissions = [
    'view_dashboard' => 'View Dashboard',
    'view_documents' => 'View Documents',
    'create_reports' => 'Submit Document',
    'manage_reports' => 'Manage Documents',
    'view_workflow' => 'View Workflow',
    'view_archive' => 'View Archived Documents',
    'view_trash_bin' => 'View Trash Bin',
    'view_reports' => 'View Reports',
    'route_reports' => 'Route Documents',
    'approve_reports' => 'Approve Documents',
    'manage_users' => 'User Management',
    'create_department_staff' => 'Create Department Staff Accounts',
    'manage_departments' => 'Departments',
    'manage_services' => 'Activities',
    'manage_roles' => 'Role Management',
    'manage_settings' => 'System Settings (All Settings)',
    'manage_landing_settings' => 'Landing Settings',
    'manage_contact_settings' => 'Contact Settings',
    'manage_maintenance_settings' => 'Maintenance Settings',
    'manage_retention_settings' => 'Data Retention Settings',
    'manage_sidebar_settings' => 'Sidebar Settings',
    'view_audit_logs' => 'Audit Logs',
    'manage_notifications' => 'System Notifications and Messages',
    'manage_announcements' => 'Announcements',
    'permanent_delete' => 'Permanent Delete',
    'manage_account_status' => 'Account Status',
    'manage_file_revisions' => 'File Revisions',
    'manage_security_settings' => 'Security Settings',
    'export_reports' => 'Export Reports',
    'override_workflow' => 'Override Workflow',
    'override_department_privacy' => 'View Private Department Documents',
    'complete_own_documents' => 'Confirm Receipt of Own Documents',
];
$permissionGroups = [
    'MAIN' => [
        'Dashboard' => ['view_dashboard'],
        'Documents' => ['view_documents', 'create_reports', 'manage_file_revisions', 'complete_own_documents', 'override_department_privacy'],
        'Workflow' => ['view_workflow', 'manage_reports', 'override_workflow'],
        'Archived Documents' => ['view_archive'],
        'Reports & Analytics' => ['view_reports', 'export_reports'],
        'Routing' => ['route_reports'],
        'Approvals' => ['approve_reports'],
    ],
    'SYSTEM MANAGEMENT' => [
        'Departments' => ['manage_departments'],
        'Activities' => ['manage_services'],
        'Users' => ['manage_users', 'create_department_staff', 'manage_account_status'],
        'Audit Logs' => ['view_audit_logs'],
        'Trash Bin' => ['view_trash_bin', 'permanent_delete'],
        'System Notifications & Messages' => ['manage_notifications'],
        'Announcements' => ['manage_announcements'],
    ],
    'SYSTEM SETTINGS' => [
        'Role Management' => ['manage_roles'],
        'Landing' => ['manage_landing_settings'],
        'Contact' => ['manage_contact_settings'],
        'Maintenance' => ['manage_maintenance_settings'],
        'Data Retention' => ['manage_retention_settings'],
        'Session Timeout' => ['manage_security_settings'],
        'Sidebar Settings' => ['manage_sidebar_settings'],
    ],
    'OTHER SYSTEM PERMISSIONS' => [
        'System-wide Settings (legacy broad access)' => ['manage_settings'],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_role') {
        $name = trim($_POST['name'] ?? '');
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', $name), '_'));

        if ($name === '' || $slug === '') {
            $error = 'Role name is required.';
        } else {
            $duplicateStmt = $pdo->prepare('SELECT 1 FROM roles WHERE slug = :slug LIMIT 1');
            $duplicateStmt->execute(['slug' => $slug]);
        }

        if ($name !== '' && $slug !== '' && isset($duplicateStmt) && $duplicateStmt->fetchColumn()) {
            $error = 'A role with this name already exists.';
        } elseif ($name !== '' && $slug !== '') {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO roles (name, slug, description) VALUES (:name, :slug, NULL)');
                $stmt->execute(['name' => $name, 'slug' => $slug]);
                $roleId = (int) $pdo->lastInsertId();
                $permissionStmt = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_name) VALUES (:role_id, :permission_name)');
                $submittedPermissions = array_unique(array_filter((array) ($_POST['permissions'] ?? []), 'is_string'));
                foreach ($submittedPermissions as $permission) {
                    if (isset($permissions[$permission])) {
                        $permissionStmt->execute(['role_id' => $roleId, 'permission_name' => $permission]);
                    }
                }
                $pdo->commit();
                $success = 'Role created successfully.';
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Role creation failed: ' . $exception->getMessage());
                $error = 'Unable to create this role. Check that the role name is unique and try again.';
            }
        }
    } elseif ($action === 'update_role') {
        $id = (int) ($_POST['role_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($id > 0 && $name !== '') {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE roles SET name = :name WHERE id = :id');
                $stmt->execute(['name' => $name, 'id' => $id]);
                $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id')->execute(['role_id' => $id]);
                $permissionStmt = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_name) VALUES (:role_id, :permission_name)');
                $submittedPermissions = array_unique(array_filter((array) ($_POST['permissions'] ?? []), 'is_string'));
                foreach ($submittedPermissions as $permission) {
                    if (isset($permissions[$permission])) {
                        $permissionStmt->execute(['role_id' => $id, 'permission_name' => $permission]);
                    }
                }
                $pdo->commit();
                $success = 'Role updated successfully.';
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Role update failed for role_id=' . $id . ': ' . $exception->getMessage());
                $error = 'Unable to save this role and its permissions. Please try again.';
            }
        } else {
            $error = 'Role data is invalid.';
        }
    } elseif ($action === 'delete_role') {
        $id = (int) ($_POST['role_id'] ?? 0);
        if ($id > 0) {
            $roleStmt = $pdo->prepare('SELECT slug FROM roles WHERE id = :id LIMIT 1');
            $roleStmt->execute(['id' => $id]);
            $roleSlug = $roleStmt->fetchColumn();
            if ($roleSlug === false) {
                $error = 'The selected role no longer exists.';
            } elseif (in_array($roleSlug, ['admin', 'super_admin', 'client', 'department_admin', 'department_staff'], true)) {
                $error = 'Built-in system roles cannot be deleted.';
            } else {
                $customRoleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'custom_role_id'")->fetch();
                if ($customRoleColumn) {
                    $userStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = :role OR custom_role_id = :role_id');
                    $userStmt->execute(['role' => $roleSlug, 'role_id' => $id]);
                } else {
                    $userStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = :role');
                    $userStmt->execute(['role' => $roleSlug]);
                }
                if ((int) $userStmt->fetchColumn() > 0) {
                    $error = 'This role is assigned to users. Reassign those users before deleting the role.';
                } else {
                    $stmt = $pdo->prepare('DELETE FROM roles WHERE id = :id');
                    $stmt->execute(['id' => $id]);
                    $success = 'Role deleted successfully.';
                }
            }
        } else {
            $error = 'Invalid role selected.';
        }
    }
}

$roles = $pdo->query('SELECT * FROM roles ORDER BY name ASC')->fetchAll();
$assignedPermissions = [];
$assignedStmt = $pdo->query('SELECT role_id, permission_name FROM role_permissions');
foreach ($assignedStmt as $permissionRow) {
    $assignedPermissions[(int) $permissionRow['role_id']][$permissionRow['permission_name']] = true;
}

require_once APP_ROOT . '/includes/header.php';
?>

<div class="management-toolbar">
    <div>
        <h1>Roles &amp; Permissions</h1>
        <p style="color: #64748b; margin: 0;">Manage role permissions here. Assign a custom permission role from Users &mdash; the user's Client, Department Admin, or Staff account type stays intact.</p>
    </div>
    <?php if (hasPermission('manage_users', $user)): ?>
        <a href="manage_users.php" class="role-management-add-button"> <i class="bi bi-people" aria-hidden="true"></i> Assign Roles to Users</a>
    <?php endif; ?>
    <button type="button" class="role-management-add-button add-create-control" title="Add Role" aria-label="Add role" onclick="document.getElementById('add-role-modal').style.display='flex';"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Role</button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table" data-datatable-responsive="false">
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Permissions</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                    <tr>
                        <td><span class="badge badge-assigned role-management-badge"><?= htmlspecialchars($role['name']) ?></span></td>
                        <td>
                            <?php $rolePermissions = $assignedPermissions[(int) $role['id']] ?? []; ?>
                            <?php
                            $rolePermissionLabels = [];
                            foreach ($permissions as $permissionKey => $permissionLabel) {
                                if (!empty($rolePermissions[$permissionKey])) {
                                    $rolePermissionLabels[] = $permissionLabel;
                                }
                            }
                            ?>
                            <?php foreach (array_slice($rolePermissionLabels, 0, 3) as $permissionLabel): ?>
                                <span class="badge badge-assigned role-management-badge" style="margin: 0.15rem;"><?= htmlspecialchars($permissionLabel) ?></span>
                            <?php endforeach; ?>
                            <?php if (count($rolePermissionLabels) > 3): ?>
                                <span class="badge badge-secondary role-management-more-badge" style="margin: 0.15rem;">+<?= count($rolePermissionLabels) - 3 ?> more</span>
                            <?php endif; ?>
                            <?php if (empty($rolePermissionLabels)): ?><span style="color: #64748b;">No permissions assigned</span><?php endif; ?>
                        </td>
                        <td>
                            <div class="action-menu-shell">
                                <button type="button" class="action-more-toggle" aria-label="More actions" title="More actions" aria-expanded="false">
                                    <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <div class="action-menu-panel">
                                    <button type="button" class="action-menu-item" aria-label="View role" onclick="document.getElementById('view-role-<?= (int)$role['id'] ?>').style.display='flex'; this.closest('.action-menu-shell').classList.remove('is-open');">
                                        <i class="bi bi-eye-fill" aria-hidden="true"></i>
                                        View
                                    </button>
                                    <button type="button" class="action-menu-item" aria-label="Edit role" onclick="document.getElementById('edit-role-<?= (int)$role['id'] ?>').style.display='flex'; this.closest('.action-menu-shell').classList.remove('is-open');">
                                        <i class="bi bi-pencil-fill" aria-hidden="true"></i>
                                        Edit
                                    </button>
                                    <form method="POST" action="role_management.php" onsubmit="return confirm('Delete this role? Permissions assigned to this role may be affected.');" style="margin: 0;">
                                        <input type="hidden" name="action" value="delete_role">
                                        <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
                                        <button type="submit" class="action-menu-item" aria-label="Delete role" style="color: #dc2626;">
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <div id="view-role-<?= (int)$role['id'] ?>" class="role-management-form-modal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 999; align-items: center; justify-content: center;">
                        <div style="background: white; border-radius: 12px; width: min(900px, 95vw); max-height: 90vh; overflow-y: auto; padding: 1.5rem;">
                            <h3 style="margin-top: 0;">View Role Permissions</h3>
                            <div class="form-group">
                                <strong>Role Name:</strong> <?= htmlspecialchars($role['name']) ?>
                            </div>
                            <p style="margin:.35rem 0;color:#64748b;font-size:.88rem;">Checked permissions are assigned to this role. This view is read-only.</p>
                            <div class="role-management-permission-cards" style="display:grid;gap:.85rem;margin-top:1rem;">
                                <?php foreach ($permissionGroups as $permissionGroupName => $permissionCards): ?>
                                    <section>
                                        <h4 style="margin:.35rem 0;color:#334155;"><?= htmlspecialchars($permissionGroupName) ?></h4>
                                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.75rem;">
                                            <?php foreach ($permissionCards as $permissionCardName => $permissionKeys): ?>
                                                <fieldset class="role-management-permission-list" disabled style="margin:0;padding:.8rem 1rem;border:1px solid #dbe3ee;border-radius:10px;background:#f8fafc;">
                                                    <legend style="padding:0 .25rem;font-size:.93rem;font-weight:700;color:#1e293b;"><?= htmlspecialchars($permissionCardName) ?></legend>
                                                    <?php foreach ($permissionKeys as $permissionKey): ?>
                                                        <?php if (!isset($permissions[$permissionKey])) continue; ?>
                                                        <label style="display:flex;align-items:flex-start;gap:.5rem;margin:.45rem 0;">
                                                            <input type="checkbox" <?= !empty($rolePermissions[$permissionKey]) ? 'checked' : '' ?> aria-label="<?= htmlspecialchars($permissions[$permissionKey]) ?>">
                                                            <span><?= htmlspecialchars($permissions[$permissionKey]) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                            <div style="display: flex; justify-content: flex-end; margin-top: 1rem;">
                                <button type="button"class="btn btn-light" title="Close" aria-label="Close" onclick="document.getElementById('view-role-<?= (int)$role['id'] ?>').style.display='none';">
                                     <i class="bi bi-x-lg" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div id="edit-role-<?= (int)$role['id'] ?>" class="role-management-form-modal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 999; align-items: center; justify-content: center;">
                        <div style="background: white; border-radius: 12px; width: min(900px, 95vw); max-height: 90vh; overflow-y: auto; padding: 1.5rem;">
                            <h3 style="margin-top: 0;">Edit Role</h3>
                            <form class="role-management-form" method="POST" action="role_management.php">
                                <input type="hidden" name="action" value="update_role">
                                <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
                                <div class="form-group">
                                    <label for="edit_role_name_<?= (int)$role['id'] ?>">Role Name</label>
                                    <input id="edit_role_name_<?= (int)$role['id'] ?>" type="text" name="name" class="form-control" value="<?= htmlspecialchars($role['name']) ?>" required>
                                </div>
                                <div class="role-management-permission-cards" style="display:grid;gap:.85rem;margin-top:1rem;">
                                    <?php foreach ($permissionGroups as $permissionGroupName => $permissionCards): ?>
                                        <section>
                                            <h4 style="margin:.35rem 0;color:#334155;"><?= htmlspecialchars($permissionGroupName) ?></h4>
                                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.75rem;">
                                                <?php foreach ($permissionCards as $permissionCardName => $permissionKeys): ?>
                                                    <fieldset class="role-management-permission-list" style="margin:0;padding:.8rem 1rem;border:1px solid #dbe3ee;border-radius:10px;background:#f8fafc;">
                                                        <legend style="padding:0 .25rem;font-size:.93rem;font-weight:700;color:#1e293b;"><?= htmlspecialchars($permissionCardName) ?></legend>
                                                        <?php if ($permissionCardName === 'Trash Bin'): ?><p style="margin:.1rem 0 .6rem;color:#64748b;font-size:.82rem;">Restore access follows the permission for each item type above; permanent deletion is controlled here.</p><?php endif; ?>
                                                        <?php foreach ($permissionKeys as $permissionKey): ?>
                                                            <?php if (!isset($permissions[$permissionKey])) continue; ?>
                                                            <label style="display:flex;align-items:flex-start;gap:.5rem;margin:.45rem 0;">
                                                                <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($permissionKey) ?>" <?= !empty($assignedPermissions[(int) $role['id']][$permissionKey]) ? 'checked' : '' ?>>
                                                                <span><?= htmlspecialchars($permissions[$permissionKey]) ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </fieldset>
                                                <?php endforeach; ?>
                                            </div>
                                        </section>
                                    <?php endforeach; ?>
                                </div>
                                <div class="role-management-form-actions" style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                                    <button type="button" class="icon-circle-button secondary" title="Cancel" aria-label="Cancel" onclick="document.getElementById('edit-role-<?= (int)$role['id'] ?>').style.display='none';"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m4 4 8 8M12 4l-8 8"/></svg></button>
                                    <button type="submit" class="icon-circle-button primary" title="Save Changes" aria-label="Save changes">✓</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="add-role-modal" class="role-management-form-modal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 999; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; width: min(900px, 95vw); max-height: 90vh; overflow-y: auto; padding: 1.5rem;">
        <h3 style="margin-top: 0;">Add Role</h3>
        <form class="role-management-form" method="POST" action="role_management.php">
            <input type="hidden" name="action" value="create_role">
            <div class="form-group">
                <label for="add_role_name">Role Name</label>
                <input id="add_role_name" type="text" name="name" class="form-control" required>
            </div>
            <div class="role-management-permission-cards" style="display:grid;gap:.85rem;margin-top:1rem;">
                <?php foreach ($permissionGroups as $permissionGroupName => $permissionCards): ?>
                    <section>
                        <h4 style="margin:.35rem 0;color:#334155;"><?= htmlspecialchars($permissionGroupName) ?></h4>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.75rem;">
                            <?php foreach ($permissionCards as $permissionCardName => $permissionKeys): ?>
                                <fieldset class="role-management-permission-list" style="margin:0;padding:.8rem 1rem;border:1px solid #dbe3ee;border-radius:10px;background:#f8fafc;">
                                    <legend style="padding:0 .25rem;font-size:.93rem;font-weight:700;color:#1e293b;"><?= htmlspecialchars($permissionCardName) ?></legend>
                                    <?php if ($permissionCardName === 'Trash Bin'): ?><p style="margin:.1rem 0 .6rem;color:#64748b;font-size:.82rem;">Restore access follows the permission for each item type above; permanent deletion is controlled here.</p><?php endif; ?>
                                    <?php foreach ($permissionKeys as $permissionKey): ?>
                                        <?php if (!isset($permissions[$permissionKey])) continue; ?>
                                        <label style="display:flex;align-items:flex-start;gap:.5rem;margin:.45rem 0;">
                                            <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($permissionKey) ?>">
                                            <span><?= htmlspecialchars($permissions[$permissionKey]) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
            <div class="role-management-form-actions" style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                <button type="button" class="icon-circle-button secondary" title="Cancel" aria-label="Cancel" onclick="document.getElementById('add-role-modal').style.display='none';"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m4 4 8 8M12 4l-8 8"/></svg></button>
                <button type="submit" class="icon-circle-button primary" title="Save Role" aria-label="Save role">✓</button>
            </div>
        </form>
    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
