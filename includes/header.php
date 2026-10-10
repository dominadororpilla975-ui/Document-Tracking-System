<?php
require_once __DIR__ . '/auth.php';
if (!isset($pdo) && file_exists(__DIR__ . '/../config/db.php')) {
    require_once __DIR__ . '/../config/db.php';
}

$user = currentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
$canViewDocuments = hasPermission('view_documents', $user);
$canManageNotifications = hasPermission('manage_notifications', $user);
$notificationFallbackUrl = $user && ($user['role'] ?? '') === 'client' ? 'messages.php' : 'admin_notifications.php';
$notificationFallbackUrl = $canManageNotifications ? $notificationFallbackUrl : '';
$systemName = getSystemSetting('system_name', 'Municipal Document Tracking System');
$municipalityName = getSystemSetting('municipality_name', 'Municipality of Carranglan');
$darkMode = false;
$isAuthPage = in_array($currentPage, ['login.php', 'register.php', 'forgot_password.php', 'reset_password.php']);
$usesLandingBackground = in_array($currentPage, ['login.php', 'register.php'], true);
$authBackgroundUrl = $usesLandingBackground
    ? trim((string) getSystemSetting('landing_background_url', 'image/carranglan_hall.jpg'))
    : '';
$authBackgroundStyle = $authBackgroundUrl !== ''
    ? ' style="background-image: linear-gradient(135deg, rgba(10, 24, 41, 0.5) 0%, rgba(15, 23, 42, 0.38) 45%, rgba(13, 37, 59, 0.58) 100%), url(\'' . htmlspecialchars($authBackgroundUrl, ENT_QUOTES) . '\') !important;"'
    : '';

// Fetch notifications for top header bell
$headerUnreadCount = 0;
$headerNotifications = [];

if (!$isAuthPage && $user && isset($pdo)) {
    try {
        $userId = (int) ($user['id'] ?? 0);
        $deptId = (int) ($user['department_id'] ?? 0);
        $role = $user['role'] ?? '';

        if (in_array($role, ['super_admin', 'admin'], true)) {
            $cntStmt = $pdo->query("SELECT COUNT(*) FROM notifications n LEFT JOIN reports r ON r.tracking_number = n.tracking_no WHERE n.admin_is_read = 0 AND n.tracking_no NOT LIKE 'MESSAGE-%'");
            $headerUnreadCount = (int) $cntStmt->fetchColumn();

            $listStmt = $pdo->query("SELECT n.*, r.id AS report_id, d.name AS department_name FROM notifications n LEFT JOIN reports r ON r.tracking_number = n.tracking_no LEFT JOIN departments d ON d.id = n.department_id WHERE n.tracking_no NOT LIKE 'MESSAGE-%' ORDER BY n.created_at DESC LIMIT 5");
            $headerNotifications = $listStmt->fetchAll();
        } elseif ($deptId > 0) {
            $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications n WHERE (n.department_id = :dept_id OR n.user_id = :user_id)
                AND NOT EXISTS (SELECT 1 FROM notification_reads nr WHERE nr.notification_id = n.id AND nr.user_id = :read_user_id)');
            $cntStmt->execute(['dept_id' => $deptId, 'user_id' => $userId, 'read_user_id' => $userId]);
            $headerUnreadCount = (int) $cntStmt->fetchColumn();

            $listStmt = $pdo->prepare('SELECT n.*, r.id AS report_id, d.name AS department_name,
                CASE WHEN EXISTS (SELECT 1 FROM notification_reads nr WHERE nr.notification_id = n.id AND nr.user_id = :read_user_id) THEN 1 ELSE 0 END AS viewer_is_read
                FROM notifications n LEFT JOIN reports r ON r.tracking_number = n.tracking_no LEFT JOIN departments d ON d.id = n.department_id
                WHERE n.department_id = :dept_id OR n.user_id = :user_id ORDER BY n.created_at DESC LIMIT 5');
            $listStmt->execute(['dept_id' => $deptId, 'user_id' => $userId, 'read_user_id' => $userId]);
            $headerNotifications = $listStmt->fetchAll();
        } else {
            $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND client_is_read = 0');
            $cntStmt->execute(['user_id' => $userId]);
            $headerUnreadCount = (int) $cntStmt->fetchColumn();

            $listStmt = $pdo->prepare('SELECT n.*, r.id AS report_id, d.name AS department_name FROM notifications n LEFT JOIN reports r ON r.tracking_number = n.tracking_no LEFT JOIN departments d ON d.id = n.department_id WHERE n.user_id = :user_id ORDER BY n.created_at DESC LIMIT 5');
            $listStmt->execute(['user_id' => $userId]);
            $headerNotifications = $listStmt->fetchAll();
        }
    } catch (Exception $e) {
        $headerUnreadCount = 0;
        $headerNotifications = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($systemName) ?></title>
    <link rel="icon" type="image/png" href="image/carranglan-seal.png">
    <link rel="stylesheet" href="css/style.css?v=<?= file_exists(__DIR__ . '/../css/style.css') ? (string) filemtime(__DIR__ . '/../css/style.css') : '1' ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css?v=20260908">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.1.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/3.0.3/css/responsive.bootstrap5.min.css">
    <?php
    $pageCssName = pathinfo($currentPage, PATHINFO_FILENAME) . '.css';
    if (file_exists(__DIR__ . '/../css/' . $pageCssName)):
        $pageCssPath = __DIR__ . '/../css/' . $pageCssName;
        $pageCssVersion = (string) filemtime($pageCssPath);
    ?>
    <link rel="stylesheet" href="css/<?= htmlspecialchars($pageCssName) ?>?v=<?= htmlspecialchars($pageCssVersion) ?>">
    <?php endif; ?>
    <?php if ((strpos($currentPage, 'admin_') === 0 || in_array($currentPage, ['profile.php', 'trash_bin.php', 'messages.php', 'role_management.php'], true)) && file_exists(__DIR__ . '/../css/admin_modules.css')): ?>
    <link rel="stylesheet" href="css/admin_modules.css?v=<?= (string) filemtime(__DIR__ . '/../css/admin_modules.css') ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="css/datatables.css?v=<?= file_exists(__DIR__ . '/../css/datatables.css') ? (string) filemtime(__DIR__ . '/../css/datatables.css') : '1' ?>">
    <link rel="stylesheet" href="css/status_visibility.css?v=<?= file_exists(__DIR__ . '/../css/status_visibility.css') ? (string) filemtime(__DIR__ . '/../css/status_visibility.css') : '1' ?>">
    <script defer src="includes/form_validation.js?v=<?= file_exists(__DIR__ . '/form_validation.js') ? (string) filemtime(__DIR__ . '/form_validation.js') : '1' ?>"></script>
    <script defer src="includes/selection_search.js?v=<?= file_exists(__DIR__ . '/selection_search.js') ? (string) filemtime(__DIR__ . '/selection_search.js') : '1' ?>"></script>
    <!-- Chart.js for Analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/2.1.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/3.0.3/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/3.0.3/js/responsive.bootstrap5.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var actionGroups = document.querySelectorAll('div, span, td');
        actionGroups.forEach(function (group) {
            if (!group || !group.classList) {
                return;
            }

            if (group.closest && group.closest('.processing-actions-panel')) {
                return;
            }

            if (group.closest && group.closest('.workflow-action-modal')) {
                return;
            }

            if (group.classList.contains('action-menu-shell') || group.classList.contains('action-menu-panel')) {
                return;
            }

            if (group.closest && group.closest('.action-menu-shell')) {
                return;
            }

            var actionNodes = Array.from(group.children || []).filter(function (child) {
                return child && (
                    child.classList && (
                        child.classList.contains('btn-action') ||
                        child.classList.contains('icon-action') ||
                        child.classList.contains('action-menu-item') ||
                        child.tagName === 'FORM' && child.querySelector('.btn-action, .icon-action, .action-menu-item')
                    )
                );
            });

            if (actionNodes.length < 2) {
                return;
            }

            if (!group.classList) {
                return;
            }

            var existingToggle = group.querySelector('.action-more-toggle');
            if (existingToggle) {
                return;
            }

            var shell = group;
            shell.classList.add('action-menu-shell');
            shell.setAttribute('data-auto-action-menu', 'true');

            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'action-more-toggle';
            toggle.setAttribute('aria-label', 'More actions');
            toggle.setAttribute('title', 'More actions');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.innerHTML = '<i class="bi bi-three-dots-vertical" aria-hidden="true"></i>';

            var panel = document.createElement('div');
            panel.className = 'action-menu-panel';

            Array.from(group.children).forEach(function (child) {
                if (child && child.classList && (child.classList.contains('btn-action') || child.classList.contains('icon-action') || child.classList.contains('action-menu-item'))) {
                    panel.appendChild(child);
                } else if (child && child.tagName === 'FORM' && child.querySelector('.btn-action, .icon-action')) {
                    panel.appendChild(child);
                }
            });

            shell.innerHTML = '';
            shell.appendChild(toggle);
            shell.appendChild(panel);
        });

        function closeActionMenu(shell) {
            if (!shell) return;
            shell.classList.remove('is-open');
            var toggle = shell.querySelector('.action-more-toggle');
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
            var panel = shell.querySelector('.action-menu-panel');
            if (panel) {
                panel.style.display = '';
                panel.style.visibility = '';
                panel.style.position = '';
                panel.style.top = '';
                panel.style.bottom = '';
                panel.style.left = '';
                panel.style.right = '';
                panel.style.zIndex = '';
            }
        }

        function closeAllActionMenus() {
            document.querySelectorAll('.action-menu-shell.is-open').forEach(closeActionMenu);
        }

        function positionActionPanel(shell) {
            var panel = shell.querySelector('.action-menu-panel');
            var toggle = shell.querySelector('.action-more-toggle');
            if (!panel || !toggle) return;

            panel.style.top = 'auto';
            panel.style.bottom = 'auto';
            panel.style.left = 'auto';
            panel.style.right = 'auto';
            panel.style.position = 'fixed';
            panel.style.zIndex = '99999';

            panel.style.visibility = 'hidden';
            panel.style.display = 'flex';

            var rect = toggle.getBoundingClientRect();
            var panelWidth = panel.offsetWidth || 160;
            var panelHeight = panel.offsetHeight || 110;

            var spaceBelow = window.innerHeight - rect.bottom;
            var spaceAbove = rect.top;

            if (spaceBelow < (panelHeight + 12) && spaceAbove > spaceBelow) {
                panel.style.bottom = Math.max(8, window.innerHeight - rect.top + 4) + 'px';
                panel.style.top = 'auto';
            } else {
                panel.style.top = (rect.bottom + 4) + 'px';
                panel.style.bottom = 'auto';
            }

            var rightDist = window.innerWidth - rect.right;
            var leftPos = rect.right - panelWidth;

            if (leftPos < 10) {
                panel.style.right = 'auto';
                panel.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - panelWidth - 8)) + 'px';
            } else {
                panel.style.left = 'auto';
                panel.style.right = Math.max(8, rightDist) + 'px';
            }

            panel.style.visibility = 'visible';
        }

        document.addEventListener('click', function (event) {
            var toggle = event.target.closest('.action-more-toggle');
            if (toggle) {
                event.stopPropagation();
                var shell = toggle.closest('.action-menu-shell');
                if (!shell) return;
                var wasOpen = shell.classList.contains('is-open');
                closeAllActionMenus();
                if (!wasOpen) {
                    shell.classList.add('is-open');
                    toggle.setAttribute('aria-expanded', 'true');
                    positionActionPanel(shell);
                }
                return;
            }

            var actionItem = event.target.closest('.action-menu-panel .action-menu-item');
            if (actionItem) {
                closeActionMenu(actionItem.closest('.action-menu-shell'));
                return;
            }

            if (!event.target.closest('.action-menu-panel')) {
                closeAllActionMenus();
            }
        });

        window.addEventListener('scroll', closeAllActionMenus, { passive: true, capture: true });
        window.addEventListener('resize', closeAllActionMenus, { passive: true });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAllActionMenus();
        });
    });
    </script>
</head>
<body class="<?= $isAuthPage ? 'auth-body' : '' ?><?= $darkMode ? ' theme-dark' : '' ?>"<?= $authBackgroundStyle ?>>
<div id="global-delete-confirm" hidden style="position:fixed; inset:0; z-index:100000; display:flex; align-items:center; justify-content:center; padding:1rem; background:rgba(15,23,42,.55);">
    <div role="dialog" aria-modal="true" aria-labelledby="global-delete-title" style="width:min(100%,340px); border-radius:14px; background:#fff; padding:1.5rem; text-align:center; box-shadow:0 20px 50px rgba(15,23,42,.25);">
        <div data-global-delete-icon-wrap style="width:48px; height:48px; margin:0 auto .8rem; display:flex; align-items:center; justify-content:center; border-radius:50%; background:#fff1f2; color:#ef4444; font-size:1.3rem;">
            <i class="bi bi-trash3" data-global-delete-icon aria-hidden="true"></i>
        </div>
        <h3 id="global-delete-title" style="margin:0 0 .45rem; font-size:1.15rem;">Delete?</h3>
        <p id="global-delete-message" style="margin:0 0 1.15rem; color:#64748b; font-size:.88rem;">Are you sure you want to delete this item?</p>
        <div style="display:flex; justify-content:center; gap:.65rem;">
            <button type="button" class="btn btn-light" data-global-delete-cancel aria-label="Cancel"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            <button type="button" class="btn btn-primary" data-global-delete-submit aria-label="Confirm delete"><i class="bi bi-trash3" aria-hidden="true"></i></button>
        </div>
    </div>
</div>
<?php if ($isAuthPage): ?>
    <div class="auth-shell">
<?php else: ?><div class="sidebar-overlay" onclick="closeSidebar()"></div><div class="app-wrapper"><?php include __DIR__ . '/sidebar.php'; ?><div class="main-wrapper"><header class="top-header">
                <div class="header-left">
                    <button class="sidebar-toggle-btn" type="button" data-sidebar-toggle aria-label="Toggle sidebar" aria-expanded="true"><i class="bi bi-list" aria-hidden="true"></i></button>
                    <h1 class="header-title"><?= htmlspecialchars($systemName) ?></h1>
                </div>

                <div class="header-right">
                    <button type="button" class="header-theme-btn" id="darkModeToggle" title="Toggle dark mode" aria-label="Toggle dark mode">
                        <i class="bi bi-moon-fill" aria-hidden="true"></i>
                    </button>
                    <!-- Notification Bell Dropdown -->
                    <div class="header-notif-wrapper">
                        <button type="button" class="header-bell-btn" id="headerNotifBtn" title="Notifications" aria-label="Notifications">
                            <i class="bi bi-bell-fill" aria-hidden="true"></i>
                            <span id="header-notif-badge" class="header-notif-badge"<?= $headerUnreadCount > 0 ? '' : ' hidden' ?>><?= $headerUnreadCount > 99 ? '99+' : $headerUnreadCount ?></span>
                        </button>

                        <div class="header-dropdown-menu notif-dropdown" id="headerNotifDropdown" style="display: none;">
                            <div class="dropdown-header-bar">
                                <h4>Notifications</h4>
                                <span id="header-notif-unread-count" style="font-size: 0.75rem; background: #dbeafe; color: #1e40af; padding: 0.15rem 0.45rem; border-radius: 999px; font-weight: 700;"<?= $headerUnreadCount > 0 ? '' : ' hidden' ?>><?= $headerUnreadCount ?> new</span>
                            </div>
                            <div class="notif-item-list">
                                <?php if (empty($headerNotifications)): ?>
                                    <div style="padding: 1.5rem; text-align: center; color: #64748b; font-size: 0.82rem;">No recent notifications.</div>
                                <?php else: ?>
                                    <?php foreach ($headerNotifications as $notif): ?>
                                        <?php $notificationRead = ($user && ($user['role'] === 'client'))
                                            ? (bool) $notif['client_is_read']
                                            : (isset($notif['viewer_is_read']) ? (bool) $notif['viewer_is_read'] : (bool) $notif['admin_is_read']); ?>
                                        <?php
                                        $notificationTarget = !empty($notif['report_id']) && $canViewDocuments
                                            ? 'report_details.php?id=' . (int) $notif['report_id']
                                            : $notificationFallbackUrl;
                                        ?>
                                        <a href="<?= htmlspecialchars($notificationTarget !== '' ? $notificationTarget : '#notifications') ?>" data-notification-target="<?= htmlspecialchars($notificationTarget) ?>" class="notif-item <?= !$notificationRead ? 'unread' : '' ?>" data-notification-id="<?= (int) $notif['id'] ?>">
                                            <div class="notif-item-title">
                                                <span><?= htmlspecialchars($notif['tracking_no'] ?: 'System Notice') ?></span>
                                                <span class="notif-item-time"><?= date('M d, g:i A', strtotime($notif['created_at'])) ?></span>
                                            </div>
                                            <p class="notif-item-msg"><?= htmlspecialchars($notif['message']) ?></p>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="dropdown-footer">
                                <?php if ($canManageNotifications): ?>
                                <a href="admin_notifications.php">View All Notifications &rarr;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- User Profile Dropdown -->
                    <div class="header-user-wrapper">
                        <?php 
                        $userRoleClean = ucwords(str_replace('_', ' ', $user['role'] ?? 'Staff'));
                        $userRoleSlug = strtolower(str_replace(' ', '_', $user['role'] ?? 'client'));
                        ?>
                        <button type="button" class="header-user-btn" id="headerUserBtn" title="<?= htmlspecialchars($user['name'] ?? 'Account') ?> (<?= htmlspecialchars($userRoleClean) ?>)" aria-label="Account menu">
                            <div class="header-avatar">
                                <?php if (!empty($user['profile_picture'])): ?>
                                    <img src="<?= htmlspecialchars($user['profile_picture']) ?>" alt="<?= htmlspecialchars($user['name'] ?? 'User') ?>" class="avatar-image" data-fallback="<?= htmlspecialchars(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?>" onerror="this.onerror=null; this.style.display='none'; this.parentElement.textContent=this.dataset.fallback;">
                                <?php else: ?>
                                    <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
                                <?php endif; ?>
                            </div>
                            <div class="header-user-info">
                                <span class="header-user-name"><?= htmlspecialchars($user['name'] ?? 'User') ?></span>
                                <span class="header-user-role"><?= htmlspecialchars($userRoleClean) ?></span>
                            </div>
                            <span class="header-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>

                        <div class="header-dropdown-menu user-dropdown" id="headerUserDropdown" style="display: none;">
                            <div class="user-popup-header">
                                <span class="user-popup-email" title="<?= htmlspecialchars($user['email'] ?? '') ?>"><?= htmlspecialchars($user['email'] ?? '') ?></span>
                                <?php if (!empty($user['google_id'])): ?>
                                    <span class="user-google-badge" title="Signed in with Google"><i class="bi bi-google"></i> Google</span>
                                <?php endif; ?>
                            </div>

                            <div class="user-popup-card">
                                <div class="user-popup-avatar-container">
                                    <div class="user-popup-avatar">
                                        <?php if (!empty($user['profile_picture'])): ?>
                                            <img src="<?= htmlspecialchars($user['profile_picture']) ?>" alt="<?= htmlspecialchars($user['name'] ?? 'User') ?>" class="avatar-image" data-fallback="<?= htmlspecialchars(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?>" onerror="this.onerror=null; this.style.display='none'; this.parentElement.textContent=this.dataset.fallback;">
                                        <?php else: ?>
                                            <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
                                        <?php endif; ?>
                                    </div>
                                    <a href="profile.php" class="user-popup-avatar-edit" title="Change profile photo" aria-label="Change profile photo">
                                        <i class="bi bi-camera-fill"></i>
                                    </a>
                                </div>
                                <h4 class="user-popup-name"><?= htmlspecialchars($user['name'] ?? 'User') ?></h4>
                                
                                <div class="user-popup-tags">
                                    <span class="user-role-badge role-<?= htmlspecialchars($userRoleSlug) ?>">
                                        <i class="bi bi-shield-check"></i> <?= htmlspecialchars($userRoleClean) ?>
                                    </span>
                                    <span class="user-status-badge status-active">
                                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars(ucfirst($user['account_status'] ?? 'Active')) ?>
                                    </span>
                                </div>

                                <div class="user-popup-details">
                                    <div class="user-detail-row">
                                        <i class="bi bi-building"></i>
                                        <div class="user-detail-content">
                                            <small>Department / Office</small>
                                            <strong><?= htmlspecialchars($user['department_name'] ?? 'Municipal System') ?></strong>
                                        </div>
                                    </div>
                                    <?php if (!empty($user['contact_number'])): ?>
                                        <div class="user-detail-row">
                                            <i class="bi bi-telephone"></i>
                                            <div class="user-detail-content">
                                                <small>Contact Number</small>
                                                <strong><?= htmlspecialchars($user['contact_number']) ?></strong>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="user-popup-manage-wrap">
                                    <a href="profile.php" class="user-manage-pill-btn">
                                        <i class="bi bi-person-gear"></i> Manage Account
                                    </a>
                                </div>
                            </div>

                            <div class="user-menu-list">
                                <?php if (in_array($user['role'] ?? '', ['super_admin', 'admin'], true)): ?>
                                    <a href="admin_settings_security.php" class="user-menu-item">
                                        <i class="bi bi-shield-lock"></i> Security & Session
                                    </a>
                                <?php endif; ?>
                                <a href="logout.php" class="user-menu-item logout">
                                    <i class="bi bi-box-arrow-right"></i> Sign Out
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="content-body">
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var deleteModal = document.getElementById('global-delete-confirm');
    var pendingDeleteForm = null;
    var pendingDeleteAction = 'delete';
    if (deleteModal) {
        var closeDeleteModal = function () {
            deleteModal.hidden = true;
            pendingDeleteForm = null;
        };
        var destructiveActionFor = function (form, button) {
            var buttonLabel = button
                ? [button.getAttribute('aria-label'), button.getAttribute('title'), button.textContent].filter(Boolean).join(' ')
                : '';
            var formAction = Array.from(form.querySelectorAll('input[type="hidden"], button[name]'))
                .map(function (field) { return [field.name, field.value].join(' '); })
                .join(' ');
            var actionText = [buttonLabel, formAction, form.getAttribute('data-confirmation-action'), form.getAttribute('data-trash-confirm')]
                .filter(Boolean)
                .join(' ');
            if (form.hasAttribute('data-trash-restore')
                || form.getAttribute('data-trash-confirm') === 'restore'
                || form.getAttribute('data-confirmation-action') === 'restore'
                || /\brestore\b/i.test(actionText)) {
                return 'restore';
            }
            if (!/(delete|trash|permanently|suspend|pause)/i.test(actionText) && !(button && button.hasAttribute('data-confirm-delete'))) {
                return '';
            }

            return /suspend|pause/i.test(actionText) ? 'suspend' : 'delete';
        };
        var openDeleteConfirmation = function (event, form, button) {
            var action = destructiveActionFor(form, button);
            if (!action) return false;
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                form.reportValidity();
                event.preventDefault();
                event.stopImmediatePropagation();
                return true;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            pendingDeleteForm = form;
            pendingDeleteAction = action;
            var actionMenu = button && button.closest('.action-menu-shell');
            if (actionMenu) {
                actionMenu.classList.remove('is-open');
                var actionToggle = actionMenu.querySelector('.action-more-toggle');
                var actionPanel = actionMenu.querySelector('.action-menu-panel');
                if (actionToggle) actionToggle.setAttribute('aria-expanded', 'false');
                if (actionPanel) {
                    ['display', 'visibility', 'position', 'top', 'bottom', 'left', 'right', 'zIndex'].forEach(function (property) {
                        actionPanel.style[property] = '';
                    });
                }
            }
            var title = deleteModal.querySelector('#global-delete-title');
            var message = deleteModal.querySelector('#global-delete-message');
            var submitButton = deleteModal.querySelector('[data-global-delete-submit]');
            var modalIcon = deleteModal.querySelector('[data-global-delete-icon]');
            var modalIconWrap = deleteModal.querySelector('[data-global-delete-icon-wrap]');
            var isBlueIcon = form.getAttribute('data-confirmation-icon-color') === 'blue';
            var isRestore = pendingDeleteAction === 'restore';
            var actionLabel = isRestore ? 'Restore' : (pendingDeleteAction === 'suspend' ? 'Suspend' : 'Delete');
            var actionIcon = isRestore ? 'bi-arrow-counterclockwise' : (pendingDeleteAction === 'suspend' ? 'bi-pause-fill' : 'bi-trash3');
            if (title) title.textContent = actionLabel + '?';
            if (message) message.textContent = isRestore
                ? 'Are you sure you want to restore this item?'
                : (pendingDeleteAction === 'suspend'
                    ? 'Are you sure you want to suspend this item?'
                    : 'Are you sure you want to delete this item?');
            if (submitButton) {
                submitButton.setAttribute('aria-label', 'Confirm ' + actionLabel.toLowerCase());
                submitButton.title = actionLabel;
                submitButton.innerHTML = '<i class="bi ' + actionIcon + '" aria-hidden="true"></i>';
            }
            if (modalIcon) modalIcon.className = 'bi ' + actionIcon;
            if (modalIconWrap) {
                modalIconWrap.style.background = isRestore || isBlueIcon ? '#ecfdf5' : '#fff1f2';
                modalIconWrap.style.color = isRestore || isBlueIcon ? '#059669' : '#ef4444';
            }
            deleteModal.hidden = false;
            return true;
        };
        document.addEventListener('click', function (event) {
            var button = event.target.closest('button, input[type="submit"], input[type="image"]');
            if (!button) return;
            var form = button.closest('form');
            if (form) openDeleteConfirmation(event, form, button);
        }, true);
        document.addEventListener('submit', function (event) {
            var form = event.target;
            var submitter = event.submitter || null;
            openDeleteConfirmation(event, form, submitter);
        }, true);
        deleteModal.querySelector('[data-global-delete-cancel]').addEventListener('click', closeDeleteModal);
        deleteModal.querySelector('[data-global-delete-submit]').addEventListener('click', function () {
            if (!pendingDeleteForm) return;
            pendingDeleteForm.removeAttribute('onsubmit');
            var formToSubmit = pendingDeleteForm;
            closeDeleteModal();
            formToSubmit.submit();
        });
        deleteModal.addEventListener('click', function (event) {
            if (event.target === deleteModal) closeDeleteModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !deleteModal.hidden) closeDeleteModal();
        });
    }

    var notifBtn = document.getElementById('headerNotifBtn');
    var notifDropdown = document.getElementById('headerNotifDropdown');
    var notifBadge = document.getElementById('header-notif-badge');
    var notifUnreadCount = document.getElementById('header-notif-unread-count');
    var userBtn = document.getElementById('headerUserBtn');
    var userDropdown = document.getElementById('headerUserDropdown');
    var darkModeToggle = document.getElementById('darkModeToggle');
    if (localStorage.getItem('dark_mode') === '1') {
        document.body.classList.add('theme-dark');
    }

    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', function () {
            var enabled = document.body.classList.toggle('theme-dark');
            localStorage.setItem('dark_mode', enabled ? '1' : '0');
            darkModeToggle.setAttribute('aria-label', enabled ? 'Switch to light mode' : 'Switch to dark mode');
            darkModeToggle.title = enabled ? 'Switch to light mode' : 'Switch to dark mode';
            darkModeToggle.innerHTML = enabled
                ? '<i class="bi bi-sun-fill" aria-hidden="true"></i>'
                : '<i class="bi bi-moon-fill" aria-hidden="true"></i>';
        });
    }

    function closeNotif() {
        if (notifDropdown) {
            notifDropdown.classList.remove('active');
            notifDropdown.style.display = 'none';
        }
    }

    function closeUser() {
        if (userDropdown) {
            userDropdown.classList.remove('active');
            userDropdown.style.display = 'none';
        }
    }

    if (notifBtn && notifDropdown) {
        notifBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = notifDropdown.style.display === 'flex' || notifDropdown.classList.contains('active');
            closeUser();
            if (isOpen) {
                closeNotif();
            } else {
                notifDropdown.classList.add('active');
                notifDropdown.style.display = 'flex';
            }
        });
    }

    document.querySelectorAll('.notif-item[data-notification-id]').forEach(function (item) {
        item.addEventListener('click', function (event) {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            var targetUrl = item.dataset.notificationTarget || window.location.href;
            var wasUnread = item.classList.contains('unread');
            var formData = new FormData();
            formData.append('notification_id', item.dataset.notificationId);
            formData.append('csrf_token', <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);

            fetch('notifications_read.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Notification read state could not be saved.');
                }
                return response.json();
            }).then(function (result) {
                if (!result.success) {
                    throw new Error(result.message || 'Notification read state could not be saved.');
                }
                item.classList.remove('unread');
                var unreadCount = Number(result.unread_count) || 0;
                if (notifBadge) {
                    notifBadge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
                    notifBadge.hidden = unreadCount === 0;
                }
                if (notifUnreadCount) {
                    notifUnreadCount.textContent = unreadCount + ' new';
                    notifUnreadCount.hidden = unreadCount === 0;
                }
                window.location.assign(targetUrl);
            }).catch(function (error) {
                console.error(error);
                if (wasUnread) {
                    window.location.assign(targetUrl);
                }
            });
        });
    });

    if (notifBtn && notifBadge) {
        var refreshNotificationCount = function () {
            if (document.hidden) return;
            fetch('notifications_read.php', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Notification count could not be refreshed.');
                }
                return response.json();
            }).then(function (result) {
                if (!result.success) {
                    throw new Error(result.message || 'Notification count could not be refreshed.');
                }
                var unreadCount = Number(result.unread_count) || 0;
                notifBadge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
                notifBadge.hidden = unreadCount === 0;
                if (notifUnreadCount) {
                    notifUnreadCount.textContent = unreadCount + ' new';
                    notifUnreadCount.hidden = unreadCount === 0;
                }
            }).catch(function (error) {
                console.error(error);
            });
        };
        refreshNotificationCount();
        window.setInterval(refreshNotificationCount, 10000);
        document.addEventListener('visibilitychange', refreshNotificationCount);
    }

    if (userBtn && userDropdown) {
        userBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = userDropdown.style.display === 'flex' || userDropdown.classList.contains('active');
            closeNotif();
            if (isOpen) {
                closeUser();
            } else {
                userDropdown.classList.add('active');
                userDropdown.style.display = 'flex';
            }
        });
    }

    document.addEventListener('click', function (e) {
        if (notifDropdown && !notifDropdown.contains(e.target) && !notifBtn.contains(e.target)) {
            closeNotif();
        }
        if (userDropdown && !userDropdown.contains(e.target) && !userBtn.contains(e.target)) {
            closeUser();
        }
    });

    document.querySelectorAll('table').forEach(function (table) {
        if (table.closest('.trash-page') && !table.closest('.trash-unified-table')) {
            return;
        }

        var activityModalRows = table.querySelectorAll('tbody tr.activity-modal-row');
        if (activityModalRows.length) {
            var modalContainer = document.createElement('div');
            modalContainer.className = 'activity-modal-container';
            table.parentNode.insertBefore(modalContainer, table.nextSibling);
            activityModalRows.forEach(function (row) {
                row.querySelectorAll('div[id]').forEach(function (modal) {
                    modalContainer.appendChild(modal);
                });
                row.remove();
            });
        }

        var tbody = table.querySelector('tbody');
        if (!table.querySelector('thead') || !tbody || table.dataset.datatable === 'off' || table.closest('.no-datatable')) {
            return;
        }

        var emptyStateRow = tbody.rows.length === 1 && tbody.rows[0].cells.length === 1 && tbody.rows[0].cells[0].hasAttribute('colspan')
            ? tbody.rows[0]
            : null;

        if (emptyStateRow) {
            table.dataset.datatableEmptyMessage = emptyStateRow.textContent.trim();
            emptyStateRow.remove();
        }

        var hasColspanRow = table.querySelector('tbody td[colspan], tbody th[colspan]');
        if (hasColspanRow) {
            return;
        }

        table.classList.add('table', 'table-striped', 'table-hover', 'align-middle', 'mb-0');

        table.querySelectorAll('thead th').forEach(function (headerCell, columnIndex) {
            var label = headerCell.textContent.trim().toLowerCase();
            var cells = table.querySelectorAll('tbody tr > *:nth-child(' + (columnIndex + 1) + ')');
            var isActionColumn = /^(actions?|manage|options?|controls?|operations?)$/i.test(label) || headerCell.classList.contains('dt-actions');
            var isDateColumn = /^(date|created|created at|updated|updated at|time|last login|date received|deadline)$/i.test(label);
            var isCompactColumn = /^(#|id|status|role|type|priority)$/i.test(label);

            if (isActionColumn) {
                headerCell.classList.add('dt-actions');
            }
            if (isDateColumn || isCompactColumn) {
                headerCell.classList.add('dt-nowrap');
            }
            if (isActionColumn || isCompactColumn) {
                headerCell.classList.add('dt-center');
            }

            cells.forEach(function (cell) {
                if (isActionColumn) {
                    cell.classList.add('dt-actions');
                }
                if (isDateColumn || isCompactColumn) {
                    cell.classList.add('dt-nowrap');
                }
                if (isActionColumn || isCompactColumn) {
                    cell.classList.add('dt-center');
                }
            });
        });

        if (!table.closest('.table-responsive')) {
            var wrapper = document.createElement('div');
            wrapper.className = 'table-responsive';
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        }

        if (window.DataTable) {
            if (typeof table._datatableInit === 'undefined') {
                table._datatableInit = true;
                var dataTable = new DataTable(table, {
                    responsive: table.dataset.datatableResponsive !== 'false',
                    autoWidth: false,
                    searching: table.dataset.datatableSearch !== 'false',
                    paging: table.dataset.datatablePaging !== 'false',
                    ordering: table.dataset.datatableOrdering !== 'false',
                    info: table.dataset.datatableInfo !== 'false',
                    pageLength: 10,
                    lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'All']],
                    order: (() => {
                        try {
                            return table.dataset.datatableOrder
                                ? JSON.parse(table.dataset.datatableOrder)
                                : [];
                        } catch (error) {
                            console.error('Invalid DataTable order configuration.', error);
                            return [];
                        }
                    })(),
                    language: {
                        search: 'Search:',
                        lengthMenu: 'Show _MENU_ entries',
                        info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                        emptyTable: table.dataset.datatableEmptyMessage || 'No records available.',
                        zeroRecords: 'No matching records found.',
                        paginate: {
                            previous: 'Previous',
                            next: 'Next'
                        }
                    }
                });

                if (table.dataset.datatableSearch !== 'false' && !table._columnFiltersAdded) {
                    var filterContainer = document.createElement('div');
                    filterContainer.className = 'dt-column-filters';
                    filterContainer.setAttribute('role', 'group');
                    filterContainer.setAttribute('aria-label', 'Filter table by column');

                    table.querySelectorAll('thead th').forEach(function (headerCell, columnIndex) {
                        var label = headerCell.textContent.trim();
                        if (!label || headerCell.classList.contains('dt-actions') || /^(actions?|manage|options?|controls?|operations?)$/i.test(label)) {
                            return;
                        }

                        var field = document.createElement('label');
                        field.className = 'dt-column-filter';

                        var fieldLabel = document.createElement('span');
                        fieldLabel.textContent = label;

                        var values = Array.from(new Set(dataTable.column(columnIndex).cache('search').toArray()
                            .map(function (value) {
                                return String(value).replace(/\s+/g, ' ').trim();
                            })
                            .filter(Boolean)))
                            .sort(function (a, b) {
                                return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
                            });
                        var categoricalColumn = /status|role|type|priority|active|category/i.test(label);
                        var input;

                        var trackingColumn = /tracking/i.test(label);

                        if (!trackingColumn && values.length && (values.length <= 8 || (categoricalColumn && values.length <= 20))) {
                            input = document.createElement('select');
                            input.className = 'form-select form-select-sm';

                            var allOption = document.createElement('option');
                            allOption.value = '';
                            allOption.textContent = 'All ' + label;
                            input.appendChild(allOption);

                            values.forEach(function (value) {
                                var option = document.createElement('option');
                                option.value = value;
                                option.textContent = value;
                                input.appendChild(option);
                            });

                            input.addEventListener('change', function () {
                                var selectedValue = input.value.trim();
                                var exactPattern = selectedValue
                                    ? '^\\s*' + selectedValue.split(/\s+/).map(function (part) {
                                        return part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                                    }).join('\\s+') + '\\s*$'
                                    : '';
                                dataTable.column(columnIndex).search(exactPattern, true, false).draw();
                            });
                        } else {
                            input = document.createElement('input');
                            input.type = 'search';
                            input.className = 'form-control form-control-sm';
                            input.placeholder = 'Filter ' + label;
                            input.addEventListener('input', function () {
                                dataTable.column(columnIndex).search(input.value).draw();
                            });
                        }
                        input.setAttribute('aria-label', 'Filter by ' + label);

                        field.appendChild(fieldLabel);
                        field.appendChild(input);
                        filterContainer.appendChild(field);
                    });

                    if (filterContainer.childElementCount) {
                        var tableWrapper = table.closest('.table-responsive') || table;
                        tableWrapper.parentNode.insertBefore(filterContainer, tableWrapper);
                        table._columnFiltersAdded = true;
                    }
                }
            }
        }
    });
});

document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-password-toggle]');
    if (!toggle) return;

    var input = document.getElementById(toggle.getAttribute('data-password-toggle'));
    if (!input) return;

    var isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    toggle.setAttribute('title', isHidden ? 'Hide password' : 'Show password');
    toggle.innerHTML = isHidden ? '<i class="bi bi-eye-slash" aria-hidden="true"></i>' : '<i class="bi bi-eye" aria-hidden="true"></i>';
});
</script>
