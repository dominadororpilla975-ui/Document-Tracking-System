<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/includes/auth.php';

requireLogin();
$user = currentUser();
requireAnyPermission(['manage_roles', 'manage_settings'], $user);

header('Location: role_management.php');
exit();
