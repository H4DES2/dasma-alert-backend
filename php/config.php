<?php
// Remove PHP version signature
header_remove('X-Powered-By');

if (!ob_start("ob_gzhandler")) {
    ob_start();
}

// 1. Hardened Session Cookies
if (session_status() === PHP_SESSION_NONE) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
             || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '86400');
    if ($is_https) {
        ini_set('session.cookie_secure', '1');
    }
}

// 2. Global Security Headers
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(self), microphone=(), geolocation=(self), payment=(), usb=()");

// 3. Content Security Policy
$csp = "default-src 'self'; " .
       "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://unpkg.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
       "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; " .
       "font-src 'self' https://fonts.gstatic.com https://unpkg.com data:; " .
       "img-src 'self' data: blob: https://*.tile.openstreetmap.org https://mt1.google.com https://*.google.com https://res.cloudinary.com https://api.cloudinary.com https://unpkg.com; " .
       "connect-src 'self' https://api.cloudinary.com https://router.project-osrm.org; " .
       "frame-ancestors 'self'; " .
       "base-uri 'self'; " .
       "form-action 'self';";
header("Content-Security-Policy: " . $csp);

// ==========================================
// --- .ENV LOADER ---
// ==========================================
function loadEnv($path) {
    if (!file_exists($path)) return false;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim(trim($value), "\"'");
            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }
    return true;
}

$possible_paths = [
    __DIR__ . '/.env',
    __DIR__ . '/../.env',
    __DIR__ . '/../../.env'
];

foreach ($possible_paths as $path) {
    if (loadEnv($path)) break;
}

// ==========================================
// --- CONFIGURATION CONSTANTS ---
// ==========================================
define('DB_HOST',   getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'localhost'));
define('DB_PORT',   (int)(getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? 3306)));
define('DB_USER',   getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'root'));
define('DB_PASS',   getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? ''));
define('DB_NAME',   getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'alert'));

define('SMTP_HOST', getenv('SMTP_HOST') ?: ($_ENV['SMTP_HOST'] ?? 'smtp.gmail.com'));
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: ($_ENV['SMTP_PORT'] ?? 587)));
define('SMTP_USER', getenv('SMTP_USER') ?: ($_ENV['SMTP_USER'] ?? ''));
define('SMTP_PASS', getenv('SMTP_PASS') ?: ($_ENV['SMTP_PASS'] ?? ''));
define('FROM_EMAIL',getenv('FROM_EMAIL') ?: ($_ENV['FROM_EMAIL'] ?? SMTP_USER));
define('FROM_NAME', getenv('FROM_NAME') ?: ($_ENV['FROM_NAME'] ?? 'Dasma Alert'));

define('BASE_URL',  getenv('BASE_URL') ?: ($_ENV['BASE_URL'] ?? 'https://dasma-alert-backend.onrender.com'));
define('TOKEN_EXPIRY', 3600);

// ==========================================
// --- DATABASE CONNECTION ---
// ==========================================
$conn = mysqli_init();
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);

$db_persistent_host = (strpos(DB_HOST, 'p:') === 0) ? DB_HOST : ('p:' . DB_HOST);

if (DB_PORT !== 3306 || strpos(DB_HOST, 'aivencloud.com') !== false) {
    $conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
    $connected = @$conn->real_connect($db_persistent_host, DB_USER, DB_PASS, DB_NAME, DB_PORT, NULL, MYSQLI_CLIENT_SSL);
} else {
    $connected = @$conn->real_connect($db_persistent_host, DB_USER, DB_PASS, DB_NAME, DB_PORT);
}

if (!$connected) {
    error_log("Database connection failed: " . mysqli_connect_error());
    die(json_encode(["success" => false, "message" => "Database connection unavailable."]));
}

$conn->set_charset("utf8mb4");

date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");