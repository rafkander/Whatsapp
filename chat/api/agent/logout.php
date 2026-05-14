<?php
/**
 * POST /api/agent/logout.php
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$agent = require_agent();

// Revoke this specific token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$authHeader && function_exists('getallheaders')) {
    $h = getallheaders();
    $authHeader = $h['Authorization'] ?? $h['authorization'] ?? '';
}
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    db()->prepare('DELETE FROM agent_sessions WHERE token_hash = ?')
        ->execute([hash('sha256', $m[1])]);
}

db()->prepare("UPDATE agents SET status = 'offline', updated_at = NOW() WHERE id = ?")->execute([$agent['id']]);

json_success(['message' => 'Logged out']);
