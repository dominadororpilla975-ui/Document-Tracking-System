<?php
/**
 * Google OAuth 2.0 Configuration
 * 
 * To enable Google authentication:
 * 1. Go to https://console.cloud.google.com/
 * 2. Create a new project
 * 3. Enable Google+ API
 * 4. Create OAuth 2.0 credentials (Web application)
 * 5. Add the exact callback URL for this installation as an authorized redirect URI.
 * 6. Configure GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, and optionally GOOGLE_REDIRECT_URI.
 */

// Google OAuth Configuration
// Local development fallback for this project.
$googleClientId = getenv('GOOGLE_CLIENT_ID') ?: '';
$googleClientSecret = getenv('GOOGLE_CLIENT_SECRET') ?: '';
if ($googleClientId === '' && defined('GOOGLE_CLIENT_ID')) {
    $googleClientId = GOOGLE_CLIENT_ID;
}
if ($googleClientSecret === '' && defined('GOOGLE_CLIENT_SECRET')) {
    $googleClientSecret = GOOGLE_CLIENT_SECRET;
}

if ($googleClientId === '' && !empty($_ENV['GOOGLE_CLIENT_ID'])) {
    $googleClientId = $_ENV['GOOGLE_CLIENT_ID'];
}
if ($googleClientSecret === '' && !empty($_ENV['GOOGLE_CLIENT_SECRET'])) {
    $googleClientSecret = $_ENV['GOOGLE_CLIENT_SECRET'];
}

define('GOOGLE_CLIENT_ID', $googleClientId !== '' ? $googleClientId : '');
define('GOOGLE_CLIENT_SECRET', $googleClientSecret !== '' ? $googleClientSecret : '');

// Use an explicit URI when the public URL differs from the URL seen by PHP (for example,
// when TLS terminates at a reverse proxy). Otherwise, derive the app base path from the
// current script so root and subdirectory installations both use the correct callback.
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$redirectUri = getenv('GOOGLE_REDIRECT_URI') ?: ($_ENV['GOOGLE_REDIRECT_URI'] ?? ($_SERVER['GOOGLE_REDIRECT_URI'] ?? ''));

$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$scriptDir = trim(str_replace('\\', '/', dirname($scriptPath)), '/');

if ($scriptDir === '' || $scriptDir === '.') {
    $appBasePath = '';
} else {
    $dirParts = explode('/', $scriptDir);
    if (end($dirParts) === 'auth') {
        array_pop($dirParts);
    }
    $appBasePath = count($dirParts) > 0 ? '/' . implode('/', $dirParts) : '';
}

$callbackPath = $appBasePath . '/auth/google_callback.php';
define('GOOGLE_REDIRECT_URI', $redirectUri !== ''
    ? $redirectUri
    : $protocol . '://' . $host . $callbackPath);

function isGoogleOAuthConfigured(): bool
{
    return !empty(GOOGLE_CLIENT_ID)
        && GOOGLE_CLIENT_ID !== 'YOUR_GOOGLE_CLIENT_ID_HERE'
        && !empty(GOOGLE_CLIENT_SECRET)
        && GOOGLE_CLIENT_SECRET !== 'YOUR_GOOGLE_CLIENT_SECRET_HERE';
}

// Google OAuth endpoints
define('GOOGLE_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token');
define('GOOGLE_USERINFO_URL', 'https://www.googleapis.com/oauth2/v2/userinfo');

/**
 * Generate Google OAuth login URL
 * @return string Google login URL
 */
function getGoogleLoginURL() {
    if (!isGoogleOAuthConfigured()) {
        return null;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $state = bin2hex(random_bytes(32));
    $_SESSION['google_oauth_state'] = $state;

    $params = [
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'access_type' => 'online',
        'prompt' => 'consent',
        'state' => $state,
    ];
    return GOOGLE_AUTH_URL . '?' . http_build_query($params);
}

/**
 * Exchange authorization code for access token
 * @param string $code Authorization code from Google
 * @return array|false Token data or false on failure
 */
function exchangeGoogleCode($code) {
    $postData = [
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => GOOGLE_REDIRECT_URI
    ];

    $curl = curl_init(GOOGLE_TOKEN_URL);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    unset($curl);

    if ($response === false) {
        return false;
    }

    $tokenData = json_decode($response, true);
    if (!is_array($tokenData)) {
        return false;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return $tokenData;
    }

    return $tokenData;
}

/**
 * Get user info from Google using access token
 * @param string $accessToken Access token from Google
 * @return array|false User info or false on failure
 */
function getGoogleUserInfo($accessToken) {
    $curl = curl_init(GOOGLE_USERINFO_URL);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    unset($curl);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return false;
    }

    $userInfo = json_decode($response, true);
    return is_array($userInfo) ? $userInfo : false;
}
