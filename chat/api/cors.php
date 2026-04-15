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

// Admin-managed allowlist: `widget_allowed_origins` setting (comma/newline separated) overrides the
// ALLOWED_ORIGINS constant if set. Queried inline so cors.php stays free of helpers.php dependency.
$allowed = defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : '*';
try {
    $_corsPdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, PDO::ATTR_TIMEOUT => 2]);
    $_corsStmt = $_corsPdo->prepare("SELECT `value` FROM settings WHERE `key` = 'widget_allowed_origins' LIMIT 1");
    if ($_corsStmt && $_corsStmt->execute()) {
        $_corsVal = trim((string)$_corsStmt->fetchColumn());
        if ($_corsVal !== '') {
            $allowed = preg_replace('/[\s,]+/', ',', $_corsVal);
        }
    }
} catch (\Throwable $_e) { /* fall back to constant */ }

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
