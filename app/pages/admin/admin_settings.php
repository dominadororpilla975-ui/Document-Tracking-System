<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/upload_security.php';

requireLogin();
$user = currentUser();
$settingsSection = $settingsSection ?? 'overview';
$settingsPermissionBySection = [
    'landing' => 'manage_landing_settings',
    'appearance' => 'manage_landing_settings',
    'contact' => 'manage_contact_settings',
    'maintenance' => 'manage_maintenance_settings',
    'retention' => 'manage_retention_settings',
    'sidebar' => 'manage_sidebar_settings',
];
$sectionPermission = $settingsPermissionBySection[$settingsSection] ?? 'manage_settings';
if ($settingsSection === 'retention' && isSuperAdmin($user)) {
    // Super Admin always controls system-wide data retention.
} else {
    requireAnyPermission([$sectionPermission, 'manage_settings'], $user);
}

$message = '';
$error = '';
if ($settingsSection === 'access') {
    header('Location: role_management.php');
    exit();
}
$sectionSettingKeys = [
    'landing' => ['municipality_name', 'system_name', 'brand_logo_url', 'landing_background_url', 'landing_title', 'landing_subtitle'],
    'appearance' => ['brand_logo_url'],
    'maintenance' => ['maintenance_mode', 'notification_enabled'],
    'security' => ['session_timeout_hours', 'session_warning_minutes'],
    'contact' => ['contact_address', 'contact_number', 'contact_email', 'contact_website', 'office_hours_days', 'office_hours_time', 'office_hours_note'],
];
$sidebarSections = [];
$systemSettings = [
    'municipality_name' => ['Municipality Name', 'text'],
    'system_name' => ['System Name', 'text'],
    'landing_title' => ['Landing Page Title', 'text'],
    'landing_subtitle' => ['Landing Page Subtitle', 'text'],
    'brand_logo_url' => ['System Logo', 'file'],
    'landing_background_url' => ['Landing Background Image', 'file'],
    'contact_email' => ['Contact Email', 'email'],
    'contact_number' => ['Contact Number', 'text'],
    'contact_address' => ['Contact Address', 'text'],
    'contact_website' => ['Contact Website', 'text'],
    'office_hours_days' => ['Office Hours Days', 'text'],
    'office_hours_time' => ['Office Hours Time', 'text'],
    'office_hours_note' => ['Office Hours Note', 'text'],
    'dark_mode' => ['Dark Mode', 'boolean'],
    'maintenance_mode' => ['Maintenance Mode', 'boolean'],
    'notification_enabled' => ['Notifications Enabled', 'boolean'],
    'session_timeout_hours' => ['Inactivity Timeout (Hours before auto-logout)', 'number'],
    'session_warning_minutes' => ['Countdown Warning Popup (Minutes before timeout)', 'number'],
    'sidebar_extra_label' => ['Custom Sidebar Label', 'text'],
    'sidebar_extra_url' => ['Custom Sidebar URL', 'text'],
];
$permissions = [
    'view_dashboard' => 'View Dashboard',
    'view_documents' => 'View Documents',
    'create_reports' => 'Create Reports',
    'manage_reports' => 'Manage Reports',
    'view_workflow' => 'View Workflow',
    'view_archive' => 'View Archived Documents',
    'view_trash_bin' => 'View Trash Bin',
    'view_reports' => 'View Reports',
    'route_reports' => 'Route Reports',
    'approve_reports' => 'Approve Reports',
    'manage_users' => 'Manage Users',
    'manage_departments' => 'Manage Departments',
    'manage_services' => 'Manage Activities',
    'manage_roles' => 'Manage Roles',
    'manage_settings' => 'Manage Settings',
    'manage_landing_settings' => 'Landing Settings',
    'manage_contact_settings' => 'Contact Settings',
    'manage_maintenance_settings' => 'Maintenance Settings',
    'manage_retention_settings' => 'Data Retention Settings',
    'manage_sidebar_settings' => 'Sidebar Settings',
    'view_audit_logs' => 'View Audit Logs',
    'manage_notifications' => 'Manage Notifications',
    'manage_announcements' => 'Manage Announcements',
    'permanent_delete' => 'Permanent Delete',
    'manage_account_status' => 'Suspend / Reactivate Accounts',
    'manage_file_revisions' => 'File Revision Management',
    'create_department_staff' => 'Create Department Staff Accounts',
    'export_reports' => 'Export Reports',
    'override_workflow' => 'Override Workflow',
    'override_department_privacy' => 'View Private Department Documents',
    'complete_own_documents' => 'Confirm Receipt of Own Documents',
    'manage_security_settings' => 'Security Settings',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'permissions';
    $hasSettingsUpload = hasUploadAttempt($_FILES['icon_image'] ?? null)
        || hasUploadAttempt($_FILES['brand_logo'] ?? null)
        || hasUploadAttempt($_FILES['landing_background'] ?? null);
    $retryAfter = $hasSettingsUpload ? enforceUploadCooldown($pdo, (int) $user['id']) : 0;

    if ($retryAfter > 0) {
        $error = uploadCooldownMessage($retryAfter);
    } else {
    try {
        $pdo->beginTransaction();
        if ($action === 'section_add') {
            $sectionName = trim((string) ($_POST['section_name'] ?? ''));
            if ($sectionName === '') {
                throw new InvalidArgumentException('Sidebar section name is required.');
            }
            $insertSection = $pdo->prepare('INSERT INTO sidebar_sections (name, sort_order, created_by) VALUES (:name, :sort_order, :created_by)');
            $insertSection->execute([
                'name' => mb_substr($sectionName, 0, 100),
                'sort_order' => max(0, (int) ($_POST['section_sort_order'] ?? 0)),
                'created_by' => (int) $user['id'],
            ]);
            $message = 'Sidebar section added successfully.';
        } elseif ($action === 'section_update') {
            $sectionId = (int) ($_POST['section_id'] ?? 0);
            $sectionName = trim((string) ($_POST['section_name'] ?? ''));
            $sectionOrder = max(0, (int) ($_POST['section_sort_order'] ?? 0));
            $sectionActive = !empty($_POST['section_active']) ? 1 : 0;
            if ($sectionId <= 0 || $sectionName === '') {
                throw new InvalidArgumentException('Sidebar section data is invalid.');
            }
            $updateSection = $pdo->prepare(
                'UPDATE sidebar_sections
                 SET name = :name, sort_order = :sort_order, is_active = :is_active
                 WHERE id = :id'
            );
            $updateSection->execute([
                'id' => $sectionId,
                'name' => mb_substr($sectionName, 0, 100),
                'sort_order' => $sectionOrder,
                'is_active' => $sectionActive,
            ]);
            $message = 'Sidebar section settings updated successfully.';
        } elseif ($action === 'sidebar_add') {
            $label = trim((string) ($_POST['label'] ?? ''));
            $url = trim((string) ($_POST['url'] ?? ''));
            $icon = trim((string) ($_POST['icon'] ?? '★')) ?: '★';
            $iconPath = null;
            $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
            $sectionId = max(0, (int) ($_POST['section_id'] ?? 0));
            if ($label === '' || $url === '') {
                throw new InvalidArgumentException('Sidebar label and URL are required.');
            }
            if (!preg_match('/^(https?:\/\/|\/|[A-Za-z0-9_.-]+\.php(?:[?#].*)?$)/i', $url)) {
                throw new InvalidArgumentException('Sidebar URL must be an internal page or an HTTPS/HTTP URL.');
            }
            if (isset($_FILES['icon_image']) && $_FILES['icon_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $iconFile = $_FILES['icon_image'];
                $allowedIconTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                $iconInfo = $iconFile['error'] === UPLOAD_ERR_OK ? getimagesize($iconFile['tmp_name']) : false;
                $iconMime = is_array($iconInfo) ? ($iconInfo['mime'] ?? '') : '';
                if ($iconFile['error'] !== UPLOAD_ERR_OK || $iconFile['size'] > 1024 * 1024 || !isset($allowedIconTypes[$iconMime])) {
                    throw new InvalidArgumentException('Sidebar icon must be a JPG, PNG, GIF, or WEBP image up to 1 MB.');
                }
                $iconDirectory = APP_ROOT . '/image/uploads/sidebar';
                if (!is_dir($iconDirectory) && !mkdir($iconDirectory, 0755, true) && !is_dir($iconDirectory)) {
                    throw new RuntimeException('Unable to create the sidebar icon upload directory.');
                }
                $iconFileName = 'icon_' . bin2hex(random_bytes(10)) . '.' . $allowedIconTypes[$iconMime];
                if (!move_uploaded_file($iconFile['tmp_name'], $iconDirectory . '/' . $iconFileName)) {
                    throw new RuntimeException('Unable to save the sidebar icon.');
                }
                $iconPath = 'image/uploads/sidebar/' . $iconFileName;
            }
            $insertSidebar = $pdo->prepare(
                'INSERT INTO sidebar_items (section_id, label, url, icon, icon_path, sort_order, created_by)
                 VALUES (:section_id, :label, :url, :icon, :icon_path, :sort_order, :created_by)'
            );
            $insertSidebar->execute([
                'section_id' => $sectionId > 0 ? $sectionId : null,
                'label' => $label,
                'url' => $url,
                'icon' => mb_substr($icon, 0, 20),
                'icon_path' => $iconPath,
                'sort_order' => $sortOrder,
                'created_by' => (int) $user['id'],
            ]);
            $message = 'Custom sidebar item added successfully.';
        } elseif ($action === 'sidebar_update') {
            $id = (int) ($_POST['sidebar_id'] ?? 0);
            $label = trim((string) ($_POST['label'] ?? ''));
            $url = trim((string) ($_POST['url'] ?? ''));
            $icon = trim((string) ($_POST['icon'] ?? '★')) ?: '★';
            $iconPath = null;
            $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            $sectionId = max(0, (int) ($_POST['section_id'] ?? 0));
            if ($id <= 0 || $label === '' || $url === '' || !preg_match('/^(https?:\/\/|\/|[A-Za-z0-9_.-]+\.php(?:[?#].*)?$)/i', $url)) {
                throw new InvalidArgumentException('Sidebar item data is invalid.');
            }
            if (isset($_FILES['icon_image']) && $_FILES['icon_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $iconFile = $_FILES['icon_image'];
                $allowedIconTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                $iconInfo = $iconFile['error'] === UPLOAD_ERR_OK ? getimagesize($iconFile['tmp_name']) : false;
                $iconMime = is_array($iconInfo) ? ($iconInfo['mime'] ?? '') : '';
                if ($iconFile['error'] !== UPLOAD_ERR_OK || $iconFile['size'] > 1024 * 1024 || !isset($allowedIconTypes[$iconMime])) {
                    throw new InvalidArgumentException('Sidebar icon must be a JPG, PNG, GIF, or WEBP image up to 1 MB.');
                }
                $iconDirectory = APP_ROOT . '/image/uploads/sidebar';
                if (!is_dir($iconDirectory) && !mkdir($iconDirectory, 0755, true) && !is_dir($iconDirectory)) {
                    throw new RuntimeException('Unable to create the sidebar icon upload directory.');
                }
                $iconFileName = 'icon_' . bin2hex(random_bytes(10)) . '.' . $allowedIconTypes[$iconMime];
                if (!move_uploaded_file($iconFile['tmp_name'], $iconDirectory . '/' . $iconFileName)) {
                    throw new RuntimeException('Unable to save the sidebar icon.');
                }
                $iconPath = 'image/uploads/sidebar/' . $iconFileName;
            }
            $updateSidebar = $pdo->prepare(
                'UPDATE sidebar_items
                 SET section_id = :section_id, label = :label, url = :url, icon = :icon,
                     icon_path = COALESCE(:icon_path, icon_path), sort_order = :sort_order, is_active = :is_active
                 WHERE id = :id'
            );
            $updateSidebar->execute([
                'id' => $id,
                'section_id' => $sectionId > 0 ? $sectionId : null,
                'label' => $label,
                'url' => $url,
                'icon' => mb_substr($icon, 0, 20),
                'icon_path' => $iconPath,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
            ]);
            $message = 'Custom sidebar item updated successfully.';
        } elseif ($action === 'sidebar_delete') {
            $id = (int) ($_POST['sidebar_id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('Invalid sidebar item.');
            }
            $deleteSidebar = $pdo->prepare('DELETE FROM sidebar_items WHERE id = :id');
            $deleteSidebar->execute(['id' => $id]);
            $message = 'Custom sidebar item deleted successfully.';
        } elseif ($action === 'retention') {
            $monthsInput = (string) ($_POST['retention_months'] ?? '');
            $months = filter_var($monthsInput, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 120],
            ]);
            if ($months === false) {
                throw new InvalidArgumentException('Retention period must be a whole number from 1 to 120 months.');
            }
            $saveRetention = $pdo->prepare(
                "INSERT INTO system_settings (setting_key, setting_label, setting_value, setting_type, description, updated_by)
                 VALUES ('document_retention_months', 'Document and Record Retention (Months)', :value, 'number', 'Age before documents, uploaded files, audit logs, system notifications, and announcements are automatically deleted.', :updated_by)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
            );
            $saveRetention->execute([
                'value' => (string) $months,
                'updated_by' => (int) $user['id'],
            ]);
            $message = 'Retention period saved successfully.';
        } elseif ($action === 'branding') {
            $uploadedLogoPath = null;
            $uploadedBackgroundPath = null;
            if (isset($_FILES['brand_logo']) && $_FILES['brand_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $logoFile = $_FILES['brand_logo'];
                if ($logoFile['error'] !== UPLOAD_ERR_OK || $logoFile['size'] > 2 * 1024 * 1024) {
                    throw new InvalidArgumentException('Logo upload failed or is larger than 2 MB.');
                }
                $imageInfo = getimagesize($logoFile['tmp_name']);
                $allowedMimeTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                ];
                $mimeType = $imageInfo['mime'] ?? '';
                if ($imageInfo === false || !isset($allowedMimeTypes[$mimeType])) {
                    throw new InvalidArgumentException('Logo must be a JPG, PNG, GIF, or WEBP image.');
                }
                $uploadDirectory = APP_ROOT . '/image/uploads/branding';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException('Unable to create the branding upload directory.');
                }
                $fileName = 'logo_' . bin2hex(random_bytes(12)) . '.' . $allowedMimeTypes[$mimeType];
                $targetPath = $uploadDirectory . '/' . $fileName;
                if (!move_uploaded_file($logoFile['tmp_name'], $targetPath)) {
                    throw new RuntimeException('Unable to save the uploaded logo.');
                }
                $uploadedLogoPath = 'image/uploads/branding/' . $fileName;
            }
            if (isset($_FILES['landing_background']) && $_FILES['landing_background']['error'] !== UPLOAD_ERR_NO_FILE) {
                $backgroundFile = $_FILES['landing_background'];
                if ($backgroundFile['error'] !== UPLOAD_ERR_OK || $backgroundFile['size'] > 5 * 1024 * 1024) {
                    throw new InvalidArgumentException('Background image upload failed or is larger than 5 MB.');
                }
                $imageInfo = getimagesize($backgroundFile['tmp_name']);
                $allowedMimeTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                ];
                $mimeType = $imageInfo['mime'] ?? '';
                if ($imageInfo === false || !isset($allowedMimeTypes[$mimeType])) {
                    throw new InvalidArgumentException('Background image must be a JPG, PNG, GIF, or WEBP image.');
                }
                $uploadDirectory = APP_ROOT . '/image/uploads/branding';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException('Unable to create the branding upload directory.');
                }
                $fileName = 'background_' . bin2hex(random_bytes(12)) . '.' . $allowedMimeTypes[$mimeType];
                if (!move_uploaded_file($backgroundFile['tmp_name'], $uploadDirectory . '/' . $fileName)) {
                    throw new RuntimeException('Unable to save the uploaded background image.');
                }
                $uploadedBackgroundPath = 'image/uploads/branding/' . $fileName;
            }
            $update = $pdo->prepare('UPDATE system_settings SET setting_value = :value, updated_by = :updated_by WHERE setting_key = :key');
            $settingsToUpdate = $settingsSection === 'overview'
                ? array_keys($systemSettings)
                : ($sectionSettingKeys[$settingsSection] ?? []);
            foreach ($systemSettings as $key => $definition) {
                if (!in_array($key, $settingsToUpdate, true)) {
                    continue;
                }
                $value = $_POST['settings'][$key] ?? '0';
                if ($key === 'brand_logo_url') {
                    $value = $uploadedLogoPath ?? (trim((string) getSystemSetting('brand_logo_url', '')) ?: 'image/carranglan-seal.png');
                }
                if ($key === 'landing_background_url') {
                    $value = $uploadedBackgroundPath ?? getSystemSetting('landing_background_url', '');
                }
                $update->execute(['key' => $key, 'value' => trim((string) $value), 'updated_by' => (int) $user['id']]);
            }
            $message = 'System appearance and branding saved successfully.';
        } else {
            $submittedPermissions = $_POST['permissions'] ?? [];
            $pdo->exec('DELETE FROM role_permissions');
            $insert = $pdo->prepare(
                'INSERT INTO role_permissions (role_id, permission_name)
                 VALUES (:role_id, :permission_name)'
            );
            foreach ($submittedPermissions as $roleId => $rolePermissions) {
                $roleId = (int) $roleId;
                if ($roleId <= 0 || !is_array($rolePermissions)) {
                    continue;
                }
                foreach ($permissions as $permission => $label) {
                    if (!empty($rolePermissions[$permission])) {
                        $insert->execute([
                            'role_id' => $roleId,
                            'permission_name' => $permission,
                        ]);
                    }
                }
            }
            $message = 'Role permissions saved successfully.';
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('System settings update failed: ' . $exception->getMessage());
        $error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to save settings. Please try again.';
    }
    }
}

$roles = $pdo->query('SELECT id, name, slug, description FROM roles ORDER BY id ASC')->fetchAll();
$assigned = [];
$assignedStmt = $pdo->query('SELECT role_id, permission_name FROM role_permissions');
foreach ($assignedStmt as $row) {
    $assigned[(int) $row['role_id']][$row['permission_name']] = true;
}
$sidebarSections = $pdo->query('SELECT id, name, sort_order, is_active FROM sidebar_sections ORDER BY sort_order ASC, id ASC')->fetchAll();
$sidebarItems = $pdo->query('SELECT * FROM sidebar_items ORDER BY section_id ASC, sort_order ASC, id ASC')->fetchAll();
$settingValues = [];
$settingStmt = $pdo->query('SELECT setting_key, setting_value FROM system_settings');
foreach ($settingStmt as $row) {
    $settingValues[$row['setting_key']] = (string) $row['setting_value'];
}
$settingValues['brand_logo_url'] = trim($settingValues['brand_logo_url'] ?? '') ?: 'image/carranglan-seal.png';

require_once APP_ROOT . '/includes/header.php';
?>
<div class="admin-module-page">
    <div class="admin-module-heading">
        <div>
            <span class="admin-eyebrow">SYSTEM CONFIGURATION</span>
            <h2><?= $settingsSection === 'overview' ? 'System Settings' : htmlspecialchars(ucfirst($settingsSection) . ' Settings') ?></h2>
            <p>Manage this system configuration area from its dedicated settings page.</p>
        </div>
    </div>

    <?php if ($settingsSection === 'overview'): ?><nav class="settings-section-nav" aria-label="System settings sections">
        <a href="#landing-branding"><span>🖼️</span><strong>Landing & Branding</strong><small>Logo, title, contact, theme</small></a>
    </nav><?php endif; ?>
    <?php if ($settingsSection === 'overview'): ?>
        <div class="settings-access-links">
            <a href="admin_settings_landing.php"><span>🏠</span><strong>Landing Page</strong><small>Public title, municipality, and contact details</small></a>
            <a href="admin_settings_maintenance.php"><span>🔧</span><strong>Maintenance</strong><small>Temporarily pause public access</small></a>
            <a href="admin_settings_retention.php"><span>🗃️</span><strong>Data Retention</strong><small>Automatically remove old documents and records</small></a>
        </div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="admin-alert admin-alert-success"><?= htmlspecialchars($message) ?></div>
    <?php elseif ($error !== ''): ?>
        <div class="admin-alert admin-alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (in_array($settingsSection, ['landing', 'appearance', 'maintenance', 'contact', 'security'], true)): ?>
    <form method="POST" enctype="multipart/form-data" class="admin-panel" id="landing-branding">
        <input type="hidden" name="action" value="branding">
        <div class="settings-panel-heading">
            <div><span class="settings-panel-icon"><?= $settingsSection === 'contact' ? '☎' : ($settingsSection === 'security' ? '🔒' : '🖼️') ?></span><div><h3><?= $settingsSection === 'contact' ? 'Contact Us' : ($settingsSection === 'security' ? 'Session Timeout & Inactivity Security' : 'Landing Page & Branding') ?></h3><p><?= $settingsSection === 'contact' ? 'Update the contact details and office hours shown on the landing page.' : ($settingsSection === 'security' ? 'Configure automatic logout after inactivity and countdown warning duration.' : 'Customize what visitors see and how the system looks.') ?></p></div></div>
            <span class="settings-section-badge"><?= $settingsSection === 'contact' ? 'CONTACT SETTINGS' : ($settingsSection === 'security' ? 'SECURITY SETTINGS' : 'PUBLIC & APPEARANCE') ?></span>
        </div>
        <div class="admin-settings-form-grid" id="appearance-branding">
            <?php foreach ($systemSettings as $key => [$label, $type]): ?>
                <?php if ($settingsSection !== 'overview' && !in_array($key, $sectionSettingKeys[$settingsSection] ?? [], true)) continue; ?>
                <label>
                    <span><?= htmlspecialchars($label) ?></span>
                    <?php if ($type === 'boolean'): ?>
                        <input type="hidden" name="settings[<?= htmlspecialchars($key) ?>]" value="0">
                        <input type="checkbox" name="settings[<?= htmlspecialchars($key) ?>]" value="1" <?= ($settingValues[$key] ?? '0') === '1' ? 'checked' : '' ?>>
                    <?php elseif ($key === 'brand_logo_url'): ?>
                        <input type="file" name="brand_logo" accept="image/jpeg,image/png,image/gif,image/webp">
                        <?php if (($settingValues[$key] ?? '') !== ''): ?>
                            <small>Current logo:</small>
                            <img class="admin-logo-preview" src="<?= htmlspecialchars($settingValues[$key]) ?>" alt="Current system logo">
                        <?php endif; ?>
                    <?php elseif ($key === 'landing_background_url'): ?>
                        <input type="file" name="landing_background" accept="image/jpeg,image/png,image/gif,image/webp">
                        <small>JPG, PNG, GIF, or WEBP up to 5 MB.</small>
                        <?php if (($settingValues[$key] ?? '') !== ''): ?>
                            <small>Current background:</small>
                            <img class="admin-logo-preview" src="<?= htmlspecialchars($settingValues[$key]) ?>" alt="Current landing background">
                        <?php endif; ?>
                    <?php else: ?>
                        <input type="<?= $type ?>" name="settings[<?= htmlspecialchars($key) ?>]" value="<?= htmlspecialchars($settingValues[$key] ?? '') ?>">
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>
        <div id="maintenance-settings" class="settings-subsection-anchor" aria-hidden="true"></div>
        <div class="admin-settings-actions">
            <button type="submit" class="admin-primary-button admin-icon-control" title="Save branding settings" aria-label="Save branding settings"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($settingsSection === 'retention'): ?>
    <form method="POST" class="admin-panel" id="data-retention-settings">
        <input type="hidden" name="action" value="retention">
        <div class="settings-panel-heading">
            <div><span class="settings-panel-icon">🗃️</span><div><h3>Data Retention</h3><p>Automatically delete old documents and their uploaded files, audit logs, system notifications, and announcements. Users and departments are not deleted.</p></div></div>
            <span class="settings-section-badge">RETENTION POLICY</span>
        </div>
        <div class="admin-settings-form-grid">
            <label>
                <span>Retention period (months)</span>
                <input type="number" name="retention_months" min="1" max="120" step="1" required value="<?= htmlspecialchars($settingValues['document_retention_months'] ?? '3') ?>">
                <small>Allowed range: 1 to 120 months. Cleanup runs automatically during system requests, in batches. Eligible existing records are included.</small>
            </label>
        </div>
        <div class="admin-settings-actions">
            <button type="submit" class="admin-primary-button admin-icon-control" title="Save retention settings" aria-label="Save retention settings"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($settingsSection === 'sidebar'): ?>
    <section class="admin-panel" id="sidebar-management">
        <div class="settings-panel-heading">
            <div><span class="settings-panel-icon">☰</span><div><h3>Sidebar Management</h3><p>Create additional navigation links or temporarily hide existing custom items.</p></div></div>
            <span class="settings-section-badge">NAVIGATION</span>
        </div>
        <form method="POST" class="admin-settings-form-grid sidebar-section-form">
            <input type="hidden" name="action" value="section_add">
            <label><span>New Sidebar Section</span><input type="text" name="section_name" maxlength="100" placeholder="Examples: PAGES, SITES, QUICK LINKS" required></label>
            <label><span>Section Order</span><input type="number" name="section_sort_order" min="0" value="0"></label>
            <div class="admin-settings-actions"><button type="submit" class="admin-primary-button add-create-control" title="Add sidebar section" aria-label="Add sidebar section"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Sidebar Section</button></div>
        </form>
        <div class="admin-table-wrap sidebar-table-wrap" style="margin-top: 1rem;">
            <table class="admin-table sidebar-sections-table">
                <thead><tr><th>Section Name</th><th>Order</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($sidebarSections as $section): ?>
                    <tr>
                        <td><input class="admin-setting-input" type="text" name="section_name" value="<?= htmlspecialchars($section['name']) ?>" form="section-edit-<?= (int) $section['id'] ?>"></td>
                        <td><input class="admin-setting-input sidebar-order-input" type="number" name="section_sort_order" min="0" value="<?= (int) $section['sort_order'] ?>" form="section-edit-<?= (int) $section['id'] ?>"></td>
                        <td>
                            <input type="hidden" name="section_active" value="0" form="section-edit-<?= (int) $section['id'] ?>">
                            <input type="checkbox" name="section_active" value="1" form="section-edit-<?= (int) $section['id'] ?>" <?= (int) $section['is_active'] === 1 ? 'checked' : '' ?>>
                            <?= (int) $section['is_active'] === 1 ? 'Active' : 'Paused' ?>
                        </td>
                        <td>
                            <form id="section-edit-<?= (int) $section['id'] ?>" method="POST">
                                <input type="hidden" name="action" value="section_update">
                                <input type="hidden" name="section_id" value="<?= (int) $section['id'] ?>">
                                <button type="submit" class="btn-action btn-action-success" title="Save section settings" aria-label="Save section settings"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="POST" enctype="multipart/form-data" class="admin-settings-form-grid sidebar-add-form">
            <input type="hidden" name="action" value="sidebar_add">
            <label><span>Label</span><input type="text" name="label" maxlength="100" required></label>
            <label><span>URL</span><input type="text" name="url" maxlength="255" placeholder="custom_page.php or https://..." required></label>
            <label><span>Icon fallback</span><input type="text" name="icon" maxlength="20" value="★" placeholder="Emoji or symbol"></label>
            <label><span>Upload icon</span><input type="file" name="icon_image" accept="image/jpeg,image/png,image/gif,image/webp"><small>JPG, PNG, GIF, or WEBP up to 1 MB.</small></label>
            <label><span>Section</span><select name="section_id"><?php foreach ($sidebarSections as $section): ?><option value="<?= (int) $section['id'] ?>"><?= htmlspecialchars($section['name']) ?></option><?php endforeach; ?></select></label>
            <label><span>Order</span><input type="number" name="sort_order" min="0" value="0"></label>
            <div class="admin-settings-actions"><button type="submit" class="admin-primary-button add-create-control" title="Add sidebar item" aria-label="Add sidebar item"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Sidebar Item</button></div>
        </form>
        <?php if (!empty($sidebarItems)): ?>
            <div class="admin-table-wrap sidebar-table-wrap" style="margin-top: 1rem;">
                <table class="admin-table sidebar-items-table">
                    <thead><tr><th>Section</th><th>Icon</th><th>Label</th><th>URL</th><th>Order</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($sidebarItems as $item): ?>
                        <tr>
                            <td>
                                <select class="admin-setting-input" name="section_id" form="sidebar-edit-<?= (int) $item['id'] ?>">
                                    <?php foreach ($sidebarSections as $section): ?>
                                        <option value="<?= (int) $section['id'] ?>" <?= (int) ($item['section_id'] ?? 0) === (int) $section['id'] ? 'selected' : '' ?>><?= htmlspecialchars($section['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <div class="sidebar-icon-editor">
                                    <span class="sidebar-icon-preview"><?php if (!empty($item['icon_path'])): ?><img src="<?= htmlspecialchars($item['icon_path']) ?>" alt=""><?php else: ?><?= htmlspecialchars($item['icon']) ?><?php endif; ?></span>
                                    <input class="admin-setting-input sidebar-icon-input" type="text" name="icon" maxlength="20" value="<?= htmlspecialchars($item['icon']) ?>" form="sidebar-edit-<?= (int) $item['id'] ?>">
                                    <input type="file" name="icon_image" accept="image/jpeg,image/png,image/gif,image/webp" form="sidebar-edit-<?= (int) $item['id'] ?>">
                                </div>
                            </td>
                            <td>
                                <input class="admin-setting-input" type="text" name="label" value="<?= htmlspecialchars($item['label']) ?>" form="sidebar-edit-<?= (int) $item['id'] ?>">
                            </td>
                            <td>
                                <input class="admin-setting-input" type="text" name="url" value="<?= htmlspecialchars($item['url']) ?>" form="sidebar-edit-<?= (int) $item['id'] ?>">
                            </td>
                            <td>
                                <input class="admin-setting-input sidebar-order-input" type="number" name="sort_order" min="0" value="<?= (int) $item['sort_order'] ?>" form="sidebar-edit-<?= (int) $item['id'] ?>">
                            </td>
                            <td>
                                <input type="hidden" name="is_active" value="0" form="sidebar-edit-<?= (int) $item['id'] ?>">
                                <input type="checkbox" name="is_active" value="1" form="sidebar-edit-<?= (int) $item['id'] ?>" <?= (int) $item['is_active'] === 1 ? 'checked' : '' ?>>
                            </td>
                            <td>
                                <form id="sidebar-edit-<?= (int) $item['id'] ?>" method="POST" enctype="multipart/form-data" style="display:inline;">
                                    <input type="hidden" name="action" value="sidebar_update">
                                    <input type="hidden" name="sidebar_id" value="<?= (int) $item['id'] ?>">
                                    <button type="submit" class="btn-action btn-action-success" title="Save sidebar item" aria-label="Save sidebar item"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this sidebar item?');">
                                    <input type="hidden" name="action" value="sidebar_delete">
                                    <input type="hidden" name="sidebar_id" value="<?= (int) $item['id'] ?>">
                                    <button type="submit" class="btn-action btn-action-danger" title="Delete sidebar item" aria-label="Delete sidebar item">                                    <i class="bi bi-trash3" aria-hidden="true"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

</div>
<?php require_once APP_ROOT . '/includes/footer.php'; ?>
