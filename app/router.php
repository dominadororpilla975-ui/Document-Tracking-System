<?php
require_once __DIR__ . '/bootstrap.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPath = rawurldecode($requestPath);

if (str_contains($requestPath, "\0") || str_contains($requestPath, '\\')) {
    http_response_code(400);
    return true;
}

if (strtolower($requestPath) === '/favicon.ico') {
    header('Location: /favicon.svg', true, 302);
    return true;
}

if (preg_match('~^/(?:app|config|database|backups)(?:/|$)|^/\.|(?:^|/)email_logs\.txt$|(?:^|/)[^/]+\.sql$|^/uploads/.*\.php$~i', $requestPath)) {
    http_response_code(404);
    return true;
}

if ($requestPath === '/auth/google_callback.php' && is_file(APP_ROOT . '/auth/google_callback.php')) {
    return false;
}

$routePath = $requestPath === '/' ? '/index.php' : $requestPath;
$routeName = basename($routePath);
$routeFile = APP_ROOT . '/app/route.php';

if (str_contains($routePath, '/') && $routePath !== '/' && substr_count(trim($routePath, '/'), '/') > 0) {
    $staticPath = realpath(APP_ROOT . $routePath);
    $rootPath = realpath(APP_ROOT);
    if ($staticPath && $rootPath && str_starts_with($staticPath, $rootPath . DIRECTORY_SEPARATOR) && is_file($staticPath) && strtolower(pathinfo($staticPath, PATHINFO_EXTENSION)) !== 'php') {
        return false;
    }
    http_response_code(404);
    return true;
}

if (!str_ends_with(strtolower($routeName), '.php')) {
    $staticPath = realpath(APP_ROOT . $routePath);
    $rootPath = realpath(APP_ROOT);
    if ($staticPath && $rootPath && str_starts_with($staticPath, $rootPath . DIRECTORY_SEPARATOR) && is_file($staticPath)) {
        return false;
    }
    http_response_code(404);
    return true;
}

$_SERVER['SCRIPT_NAME'] = $routePath;
$_SERVER['PHP_SELF'] = $routePath;
$_SERVER['SCRIPT_FILENAME'] = $routeFile;
require $routeFile;
return true;