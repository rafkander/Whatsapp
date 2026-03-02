<?php
/**
 * CORS Headers
 * Include at the top of every API endpoint.
 */

if (!defined('APP_URL')) {
    require_once dirname(__DIR__) . '/config.php';
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : '*';

if ($allowed === '*') {
    header('Access-Control-Allow-Origin: *');
} else {
    $allowedList = array_map('trim', explode(',', $allowed));
    if (in_array($origin, $allowedList, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}

header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
