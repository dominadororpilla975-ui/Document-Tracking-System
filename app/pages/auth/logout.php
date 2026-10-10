<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/includes/auth.php';

logoutUser();
header('Location: login.php');
exit();