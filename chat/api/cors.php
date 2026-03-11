<?php
/**
 * CORS Headers
 * Include at the top of every API endpoint.
 */

if (!defined('APP_URL')) {
    require_once dirname(__DIR__) . '/config.php';
}

// Security headers for all responses
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : '*';

if ($allowed === '*') {
    // Wildcard CORS — compatible with unauthenticated widget endpoints.
    // Note: Access-Control-Allow-Credentials cannot be true with wildcard origin,
    // so we reflect the origin when one is present to support credentialed requests.
    if ($origin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    } else {
        header('Access-Control-Allow-Origin: *');
    }
} else {
    $allowedList = array_map('trim', explode(',', $allowed));
    if (in_array($origin, $allowedList, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }
}

header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header('Access-Control-Max-Age: 86400');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
