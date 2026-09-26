<?php
// Remove PHP version signature
header_remove('X-Powered-By');

if (!ob_start("ob_gzhandler")) {
    ob_start();
}

// 1. Hardened Session Cookies (Fixes Set-Cookie warnings)
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

// 2. Global Security Headers (A+ Standard)
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(self), microphone=(), geolocation=(self), payment=(), usb=()");

// 3. Content Security Policy (CSP tailored to your CDNs: Cloudinary, Leaflet, Google Fonts, Boxicons, ChartJS)
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