<?php
if (!function_exists('currentUser')) {
    require_once __DIR__ . '/auth.php';
}
$user = currentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
$isSuperAdmin = $user && ($user['role'] === 'super_admin' || $user['role'] === 'admin');
$normalizedSidebarRole = strtolower(trim((string) ($user['role'] ?? '')));
$isDepartmentAdmin = $user && $normalizedSidebarRole === 'department_admin';
$isDepartmentStaff = $user && in_array($normalizedSidebarRole, ['department_staff', 'staff'], true);
$sidebarExtraLabel = getSystemSetting('sidebar_extra_label', '');
$sidebarExtraUrl = getSystemSetting('sidebar_extra_url', '');
$municipalityName = getSystemSetting('municipality_name', 'Municipality of Carranglan');
$systemName = getSystemSetting('system_name', 'Document Tracking System');
$brandLogoUrl = trim((string) getSystemSetting('brand_logo_url', '')) ?: 'image/carranglan-seal.png';
$customSidebarSections = $pdo->query('SELECT id, name FROM sidebar_sections WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
$customSidebarItems = $pdo->query('SELECT section_id, label, url, icon, icon_path FROM sidebar_items WHERE is_active = 1 ORDER BY section_id ASC, sort_order ASC, id ASC')->fetchAll();
$builtInSidebarPaths = [
    'dashboard.php', 'documents.php', 'submit_report.php', 'records_monitor.php',
    'messages.php', 'admin_notifications.php', 'admin_system_notifications.php',
    'department_management.php', 'service_management.php', 'manage_users.php',
    'admin_audit_logs.php', 'admin_archive.php', 'trash_bin.php', 'admin_settings_landing.php',
    'admin_settings_contact.php', 'admin_settings_maintenance.php',
    'admin_settings_retention.php', 'admin_settings_security.php',
    'admin_settings_sidebar.php', 'admin_settings.php', 'admin_reports.php',
    'admin_routing.php', 'admin_approvals.php', 'admin_settings_access.php',
    'role_management.php',
];
?>
<?php if ($user): ?>
<?php
// detect special sidebar layouts
$isDepartmentView = $user && !in_array($user['role'], ['super_admin', 'admin', 'client'], true) && !empty($user['department_id']);
$isClient = $user && ($user['role'] ?? '') === 'client';
$extraClasses = '';
if ($isDepartmentView) $extraClasses .= ' department-view';
if ($isClient) $extraClasses .= ' client-view';
?>
<aside class="sidebar<?= $extraClasses ?>" id="sidebar" aria-label="Sidebar navigation">
    <div class="sidebar-brand">
        
            <img src="<?= htmlspecialchars($brandLogoUrl) ?>" alt="<?= htmlspecialchars($municipalityName) ?> logo" class="sidebar-brand-image">
        
        <div class="brand-text">
            <span class="brand-title"><?= htmlspecialchars($municipalityName) ?></span>
        </div>
        <button class="sidebar-close-btn" type="button" data-sidebar-close aria-label="Close sidebar" title="Close sidebar">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>

    <nav class="sidebar-nav" role="navigation" aria-label="Primary navigation">
        <button type="button" class="nav-section-toggle" data-nav-section-toggle="main" aria-expanded="true">
            <span class="nav-section-toggle-name">MAIN</span><span class="nav-section-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
        </button>
        <div class="nav-section-content is-open" data-nav-section="main">

        <?php if (hasPermission('view_dashboard', $user)): ?>
        <a href="dashboard.php" class="nav-item <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" title="Dashboard" onclick="enterFullscreen(event)">
            <span class="nav-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </span>
            <span class="nav-text">Dashboard</span>
        </a>
        <?php endif; ?>
        <?php foreach ($customSidebarSections as $customSection): ?>
            <?php $sectionItems = array_filter($customSidebarItems, static fn (array $item): bool => (int) ($item['section_id'] ?? 0) === (int) $customSection['id']); ?>
            <?php if ($sectionItems): ?>
                <div class="nav-section-label"><?= htmlspecialchars($customSection['name']) ?></div>
                <?php foreach ($sectionItems as $customItem): ?>
                    <?php
                    $customItemPath = basename((string) (parse_url((string) $customItem['url'], PHP_URL_PATH) ?: ''));
                    $customItemLabel = strtolower(trim((string) $customItem['label']));
                    if (in_array($customItemPath, $builtInSidebarPaths, true)
                        || $isClient && str_contains($customItemLabel, 'notification')) {
                        continue;
                    }
                    $departmentStaffRestrictedItems = [
                        'manage_users.php',
                        'service_management.php',
                        'trash_bin.php',
                    ];
                    $departmentStaffRestrictedLabels = [
                        'staff users',
                        'department activities',
                        'department trash bin',
                    ];
                    if ($isDepartmentStaff && (
                        in_array($customItemPath, $departmentStaffRestrictedItems, true)
                        || in_array($customItemLabel, $departmentStaffRestrictedLabels, true)
                    )) {
                        continue;
                    }
                    ?>
                    <a href="<?= htmlspecialchars($customItem['url']) ?>" class="nav-item" title="<?= htmlspecialchars($customItem['label']) ?>"><span class="nav-icon"><?php if (!empty($customItem['icon_path'])): ?><img src="<?= htmlspecialchars($customItem['icon_path']) ?>" alt="" class="custom-sidebar-icon"><?php else: ?><?= htmlspecialchars($customItem['icon']) ?><?php endif; ?></span><span class="nav-text"><?= htmlspecialchars($customItem['label']) ?></span></a>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($isClient): ?>
            <?php if (hasPermission('manage_notifications', $user)): ?>
            <a href="messages.php" class="nav-item <?= $currentPage === 'messages.php' ? 'active' : '' ?>" title="Messages">
                <span class="nav-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </span>
                <span class="nav-text">Messages</span>
            </a>
            <?php endif; ?>
            <?php if ($isDepartmentStaff && hasPermission('create_reports', $user)): ?>
                <a href="submit_report.php" class="nav-item <?= $currentPage === 'submit_report.php' ? 'active' : '' ?>" title="Submit Document">
                    <span class="nav-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    </span>
                    <span class="nav-text">Submit Document</span>
                </a>
            <?php endif; ?>

            <?php if (!$isDepartmentAdmin && !$isDepartmentStaff && hasPermission('create_reports', $user)): ?>
                <a href="submit_report.php" class="nav-item <?= $currentPage === 'submit_report.php' ? 'active' : '' ?>" title="Submit Document">
                    <span class="nav-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    </span>
                    <span class="nav-text">Submit Document</span>
                </a>
            <?php endif; ?>
            <?php if (hasPermission('view_documents', $user)): ?><a href="documents.php" class="nav-item <?= $currentPage === 'documents.php' ? 'active' : '' ?>" title="Documents">
                <span class="nav-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </span>
                <span class="nav-text">Documents</span>
            </a><?php endif; ?>
                <?php if (hasPermission('view_workflow', $user)): ?><a href="records_monitor.php" class="nav-item <?= $currentPage === 'records_monitor.php' ? 'active' : '' ?>" title="Workflow"><span class="nav-icon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span><span class="nav-text">Workflow</span></a><?php endif; ?>
        <?php elseif ($isSuperAdmin): ?>
            <?php if (hasPermission('view_documents', $user)): ?><a href="documents.php" class="nav-item <?= $currentPage === 'documents.php' ? 'active' : '' ?>" title="Documents">
                <span class="nav-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </span>
                <span class="nav-text">Documents</span>
            </a><?php endif; ?>
            <?php if (hasPermission('create_reports', $user)): ?><a href="submit_report.php" class="nav-item <?= $currentPage === 'submit_report.php' ? 'active' : '' ?>" title="Submit Document">
                <span class="nav-icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span>
                <span class="nav-text">Submit Document</span>
            </a><?php endif; ?>
            <?php if (hasPermission('view_workflow', $user)): ?><a href="records_monitor.php" class="nav-item <?= $currentPage === 'records_monitor.php' ? 'active' : '' ?>" title="Workflow">
                <span class="nav-icon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
                <span class="nav-text">Workflow</span>
            </a><?php endif; ?>
        <?php else: ?>
            <?php if (($isDepartmentAdmin || $isDepartmentStaff) && hasPermission('manage_notifications', $user)): ?>
                <a href="messages.php" class="nav-item <?= $currentPage === 'messages.php' ? 'active' : '' ?>" title="Messages">
                    <span class="nav-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </span>
                    <span class="nav-text">Messages</span>
                </a>
            <?php endif; ?>
            <?php if (hasPermission('view_documents', $user)): ?><a href="documents.php" class="nav-item <?= $currentPage === 'documents.php' ? 'active' : '' ?>" title="Documents">
                <span class="nav-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </span>
                <span class="nav-text">Documents</span>
            </a><?php endif; ?>

            <?php if (hasPermission('create_reports', $user)): ?><a href="submit_report.php" class="nav-item <?= $currentPage === 'submit_report.php' ? 'active' : '' ?>" title="Submit Document">
                <span class="nav-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                </span>
                <span class="nav-text">Submit Document</span>
            </a><?php endif; ?>

            <?php if (hasPermission('view_workflow', $user)): ?><a href="records_monitor.php" class="nav-item <?= $currentPage === 'records_monitor.php' ? 'active' : '' ?>" title="Workflow">
                <span class="nav-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                </span>
                <span class="nav-text">Workflow</span>
            </a><?php endif; ?>
            <?php if (hasPermission('manage_notifications', $user)): ?><a href="admin_notifications.php" class="nav-item <?= $currentPage === 'admin_notifications.php' ? 'active' : '' ?>" title="Notifications">
                <span class="nav-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>
                </span>
                <span class="nav-text">Notifications</span>
            </a><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (hasAnyPermission(['manage_departments', 'manage_services', 'manage_users', 'view_audit_logs', 'view_archive', 'view_trash_bin', 'manage_notifications', 'manage_settings', 'manage_roles', 'manage_account_status', 'manage_announcements'], $user)): ?>
        <button type="button" class="nav-section-toggle" data-nav-section-toggle="management" aria-expanded="true">
            <span class="nav-section-toggle-name">SYSTEM MANAGEMENT</span><span class="nav-section-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
        </button>
        <div class="nav-section-content is-open" data-nav-section="management">
            <?php if (hasPermission('manage_departments', $user)): ?><a href="department_management.php" class="nav-item <?= $currentPage === 'department_management.php' ? 'active' : '' ?>" title="Departments"><span class="nav-icon"><i class="bi bi-building" aria-hidden="true"></i></span><span class="nav-text">Departments</span></a><?php endif; ?>
            <?php if (hasPermission('manage_services', $user)): ?><a href="service_management.php" class="nav-item <?= $currentPage === 'service_management.php' ? 'active' : '' ?>" title="Activities"><span class="nav-icon"><i class="bi bi-grid" aria-hidden="true"></i></span><span class="nav-text">Activities</span></a><?php endif; ?>
            <?php if (hasPermission('manage_users', $user)): ?><a href="manage_users.php" class="nav-item <?= $currentPage === 'manage_users.php' ? 'active' : '' ?>" title="Users"><span class="nav-icon"><i class="bi bi-people" aria-hidden="true"></i></span><span class="nav-text">Users</span></a><?php endif; ?>
            <?php if (hasPermission('view_audit_logs', $user)): ?><a href="admin_audit_logs.php" class="nav-item <?= $currentPage === 'admin_audit_logs.php' ? 'active' : '' ?>" title="Activity Logs"><span class="nav-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span><span class="nav-text">Audit Logs</span></a><?php endif; ?>
            <?php if (hasPermission('view_trash_bin', $user)): ?><a href="trash_bin.php" class="nav-item <?= $currentPage === 'trash_bin.php' ? 'active' : '' ?>" title="Trash Bin"><span class="nav-icon"><i class="bi bi-trash3" aria-hidden="true"></i></span><span class="nav-text">Trash Bin</span></a><?php endif; ?>
            <?php if (hasPermission('view_archive', $user)): ?><a href="admin_archive.php" class="nav-item <?= $currentPage === 'admin_archive.php' ? 'active' : '' ?>" title="Archived Documents"><span class="nav-icon"><i class="bi bi-archive" aria-hidden="true"></i></span><span class="nav-text">Archived Documents</span></a><?php endif; ?>
            <?php if (hasPermission('manage_notifications', $user)): ?><a href="admin_system_notifications.php" class="nav-item <?= $currentPage === 'admin_system_notifications.php' ? 'active' : '' ?>" title="System Notifications"><span class="nav-icon"><i class="bi bi-bell" aria-hidden="true"></i></span><span class="nav-text">System Notifications</span></a><?php endif; ?>
            <?php if (hasAnyPermission(['manage_announcements', 'manage_settings'], $user)): ?><a href="announcements.php" class="nav-item <?= $currentPage === 'announcements.php' ? 'active' : '' ?>" title="Announcements"><span class="nav-icon"><i class="bi bi-megaphone" aria-hidden="true"></i></span><span class="nav-text">Announcements</span></a><?php endif; ?>
        <?php endif; ?>
        </div>

        <?php if (hasAnyPermission(['manage_roles', 'manage_settings', 'manage_landing_settings', 'manage_contact_settings', 'manage_maintenance_settings', 'manage_retention_settings', 'manage_sidebar_settings', 'manage_security_settings'], $user) || hasRole($user, ['super_admin'])): ?>
        <button type="button" class="nav-section-toggle" data-nav-section-toggle="settings" aria-expanded="true">
            <span class="nav-section-toggle-name">SYSTEM SETTINGS</span><span class="nav-section-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
        </button>
        <div class="nav-section-content is-open" data-nav-section="settings">
            <?php if (hasAnyPermission(['manage_roles', 'manage_settings'], $user)): ?><a href="role_management.php" class="nav-item <?= $currentPage === 'role_management.php' ? 'active' : '' ?>" title="Role Management">
                <span class="nav-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><span class="nav-text">Role Management</span>
            </a><?php endif; ?>
            <?php if (hasPermission('manage_settings', $user)): ?>
            <a href="admin_settings.php" class="nav-item <?= $currentPage === 'admin_settings.php' ? 'active' : '' ?>" title="System Settings">
                <span class="nav-icon"><i class="bi bi-gear" aria-hidden="true"></i></span><span class="nav-text">System Settings</span>
            </a>
            <?php endif; ?>
            <?php if (hasAnyPermission(['manage_settings', 'manage_landing_settings'], $user)): ?>
            <a href="admin_settings_landing.php" class="nav-item <?= $currentPage === 'admin_settings_landing.php' ? 'active' : '' ?>" title="Landing">
                <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5L12 3l9 6.5"/><path d="M7 10v9h10v-9"/><path d="M10 19v-6h4v6"/></svg></span><span class="nav-text">Landing</span>
            </a>
            <?php endif; ?>
            <?php if (hasAnyPermission(['manage_settings', 'manage_contact_settings'], $user)): ?>
            <a href="admin_settings_contact.php" class="nav-item <?= $currentPage === 'admin_settings_contact.php' ? 'active' : '' ?>" title="Contact">
                <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07A19.5 19.5 0 0 1 3.07 9.81 19.8 19.8 0 0 1 0 1.18 2 2 0 0 1 2 0h3a2 2 0 0 1 2 1.72c.12.9.31 1.77.56 2.61A2 2 0 0 1 7.45 6L6 7.45A16.2 16.2 0 0 0 16.55 18L18 16.55a2 2 0 0 1 1.67-.93c.84.25 1.71.44 2.61.56A2 2 0 0 1 22 16.92z"/></svg></span><span class="nav-text">Contact</span>
            </a>
            <?php endif; ?>
            <?php if (hasAnyPermission(['manage_settings', 'manage_maintenance_settings'], $user)): ?>
            <a href="admin_settings_maintenance.php" class="nav-item <?= $currentPage === 'admin_settings_maintenance.php' ? 'active' : '' ?>" title="Maintenance">
                <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 1 1.4 0l1.6 1.6a1 1 0 0 1 0 1.4l-5.4 5.4-3 1.4 1.4-3 5.4-5.4z"/><path d="M4 20l3.5-1.5"/></svg></span><span class="nav-text">Maintenance</span>
            </a>
            <?php endif; ?>
            <?php if (hasAnyPermission(['manage_settings', 'manage_retention_settings'], $user) || hasRole($user, ['super_admin'])): ?>
            <a href="admin_settings_retention.php" class="nav-item <?= $currentPage === 'admin_settings_retention.php' ? 'active' : '' ?>" title="Data Retention">
                <span class="nav-icon"><i class="bi bi-archive" aria-hidden="true"></i></span><span class="nav-text">Data Retention</span>
            </a>
            <?php endif; ?>
            <?php if (hasAnyPermission(['manage_settings', 'manage_security_settings'], $user)): ?>
            <a href="admin_settings_security.php" class="nav-item <?= $currentPage === 'admin_settings_security.php' ? 'active' : '' ?>" title="Session Security">
                <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span><span class="nav-text">Session Timeout</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </nav>

</aside>
<?php endif; ?>

<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    if (!sidebar || !toggle) return;

    if (window.innerWidth <= 767) {
        const shouldOpen = !sidebar.classList.contains('open');
        sidebar.classList.toggle('open', shouldOpen);
        document.body.classList.toggle('sidebar-open', shouldOpen);
        toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        return;
    }

    // Desktop: toggle collapsed state (compact icons)
    const isCollapsed = sidebar.classList.toggle('collapsed');
    toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
    document.body.classList.remove('sidebar-open');
    sidebar.classList.remove('open');

    // When collapsing the sidebar, ensure any open submenus close to avoid overflow
    if (isCollapsed) {
        document.querySelectorAll('.submenu.open').forEach(function (sm) {
            sm.classList.remove('open');
            const parentBtn = sm.closest('.nav-group') ? sm.closest('.nav-group').querySelector('[data-submenu-toggle]') : null;
            if (parentBtn) {
                parentBtn.setAttribute('aria-expanded', 'false');
                parentBtn.classList.remove('open');
            }
        });
    }
}

function closeSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    if (!sidebar) return;
    sidebar.classList.remove('open');
    document.body.classList.remove('sidebar-open');
    if (toggle) {
        toggle.setAttribute('aria-expanded', 'false');
    }
}

function syncSidebarViewportState() {
    const sidebar = document.querySelector('.sidebar');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    if (!sidebar || !toggle) return;

    if (window.innerWidth <= 767) {
        sidebar.classList.remove('collapsed');
        const isOpen = sidebar.classList.contains('open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        return;
    }

    sidebar.classList.remove('open');
    document.body.classList.remove('sidebar-open');
    const isCollapsed = sidebar.classList.contains('collapsed');
    toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
}

document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.sidebar');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const collapseBtn = document.querySelector('[data-sidebar-collapse]');
    const closeBtn = document.querySelector('[data-sidebar-close]');
    document.querySelectorAll('[data-nav-section-toggle]').forEach(function (sectionButton) {
        sectionButton.addEventListener('click', function () {
            const sectionName = sectionButton.getAttribute('data-nav-section-toggle');
            const sectionContent = document.querySelector('[data-nav-section="' + sectionName + '"]');
            if (!sectionContent) return;
            const isOpen = sectionButton.getAttribute('aria-expanded') === 'true';
            sectionButton.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            sectionContent.classList.toggle('is-open', !isOpen);
        });
    });

    if (toggle && sidebar) {
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            toggleSidebar();
        });
    }

    if (collapseBtn && sidebar) {
        collapseBtn.addEventListener('click', function (event) {
            event.preventDefault();
            toggleSidebar();
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function (event) {
            event.preventDefault();
            closeSidebar();
        });
    }

    document.querySelectorAll('.sidebar a, .sidebar button').forEach(function (item) {
        item.addEventListener('click', function () {
            if (window.innerWidth <= 767) {
                closeSidebar();
            }
        });
    });

    document.querySelectorAll('[data-submenu-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const group = button.closest('.nav-group');
            const submenu = group ? group.querySelector('.submenu') : null;
            if (!submenu) return;
            const isOpen = submenu.classList.toggle('open');
            if (isOpen) {
                document.querySelectorAll('.nav-group .submenu.open').forEach(function (otherSubmenu) {
                    if (otherSubmenu === submenu) return;
                    otherSubmenu.classList.remove('open');
                    const otherButton = otherSubmenu.closest('.nav-group') ? otherSubmenu.closest('.nav-group').querySelector('[data-submenu-toggle]') : null;
                    if (otherButton) {
                        otherButton.setAttribute('aria-expanded', 'false');
                        otherButton.classList.remove('open');
                    }
                });
            }
            button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            // also toggle an "open" class on the button for CSS fallback/animations
            button.classList.toggle('open', isOpen);
        });
    });

    // Auto-open submenu if any child submenu-item is active (server-side active classes)
    document.querySelectorAll('.nav-group').forEach(function (group) {
        const submenu = group.querySelector('.submenu');
        if (!submenu) return;
        if (submenu.querySelector('.submenu-item.active')) {
            submenu.classList.add('open');
            const button = group.querySelector('[data-submenu-toggle]');
            if (button) {
                button.setAttribute('aria-expanded', 'true');
                button.classList.add('open');
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    syncSidebarViewportState();

    window.addEventListener('resize', function () {
        syncSidebarViewportState();
    });
});

</script>
