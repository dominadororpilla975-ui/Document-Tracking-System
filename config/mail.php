<?php
/**
 * Mailer Configuration
 *
 * Supports:
 * - Direct array defaults below
 * - .env file in project root (GMAIL_USER, GMAIL_APP_PASSWORD, etc.)
 * - External file via REPORT_TRACKING_MAIL_CONFIG (safely loaded if exists)
 * - Server environment variables (getenv, $_ENV, $_SERVER)
 */

$config = [
    'smtp_host' => 'smtp.gmail.com',
    'smtp_auth' => true,
    'smtp_user' => '',
    'smtp_pass' => '',
    'smtp_secure' => 'tls',
    'smtp_port' => 587,
    'from_email' => '',
    'from_name' => 'Carranglan Document Tracking System',
    'developer_mode' => false,
];

// Helper to look up env variables across getenv(), $_ENV, and $_SERVER
$getEnvVal = function(string ...$keys): ?string {
    foreach ($keys as $k) {
        $val = getenv($k);
        if ($val !== false && $val !== '') {
            return (string) $val;
        }
        if (!empty($_ENV[$k])) {
            return (string) $_ENV[$k];
        }
        if (!empty($_SERVER[$k])) {
            return (string) $_SERVER[$k];
        }
    }
    return null;
};

// Auto-load .env file if it exists in project root
$envFile = __DIR__ . '/../.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                if ($k !== '' && $v !== '') {
                    $_ENV[$k] = $v;
                }
            }
        }
    }
}

// Safely load external mail configuration if defined and exists (do not crash if missing)
$externalConfigPath = trim((string) ($getEnvVal('REPORT_TRACKING_MAIL_CONFIG') ?? ''));
if ($externalConfigPath !== '' && is_file($externalConfigPath) && is_readable($externalConfigPath)) {
    $externalConfig = require $externalConfigPath;
    if (is_array($externalConfig)) {
        $config = array_merge($config, $externalConfig);
    }
}

// Environment variables override configuration
$envUser = $getEnvVal('SMTP_USER', 'GMAIL_USER');
if ($envUser !== null) {
    $config['smtp_user'] = $envUser;
}

$envPass = $getEnvVal('SMTP_PASS', 'GMAIL_APP_PASSWORD');
if ($envPass !== null) {
    $config['smtp_pass'] = $envPass;
}

$envHost = $getEnvVal('SMTP_HOST');
if ($envHost !== null) {
    $config['smtp_host'] = $envHost;
}

$envPort = $getEnvVal('SMTP_PORT');
if ($envPort !== null) {
    $config['smtp_port'] = (int) $envPort;
}

$envSecure = $getEnvVal('SMTP_SECURE');
if ($envSecure !== null) {
    $config['smtp_secure'] = $envSecure;
}

$envFrom = $getEnvVal('FROM_EMAIL', 'MAIL_FROM');
if ($envFrom !== null) {
    $config['from_email'] = $envFrom;
}

$envName = $getEnvVal('FROM_NAME');
if ($envName !== null) {
    $config['from_name'] = $envName;
}

$envDev = $getEnvVal('MAIL_DEVELOPER_MODE');
if ($envDev !== null) {
    $config['developer_mode'] = filter_var($envDev, FILTER_VALIDATE_BOOLEAN);
}

// Sanitization & normalization
$config['smtp_user'] = trim((string) $config['smtp_user']);
$config['smtp_pass'] = preg_replace('/\s+/', '', (string) $config['smtp_pass']);
$config['smtp_host'] = trim((string) ($config['smtp_host'] ?? 'smtp.gmail.com'));
$config['smtp_secure'] = strtolower(trim((string) ($config['smtp_secure'] ?? 'tls')));
$config['smtp_port'] = (int) ($config['smtp_port'] ?: 587);
$config['from_email'] = trim((string) ($config['from_email'] ?: $config['smtp_user']));
$config['from_name'] = trim((string) ($config['from_name'] ?: 'Carranglan Document Tracking System'));

if (!in_array($config['smtp_secure'], ['tls', 'ssl'], true)) {
    $config['smtp_secure'] = 'tls';
}
if ($config['smtp_port'] < 1 || $config['smtp_port'] > 65535) {
    $config['smtp_port'] = 587;
}

return $config;
