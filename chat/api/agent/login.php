<?php
/**
 * POST /api/agent/login.php
 * Authenticate agent → return JWT
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body  = request_body();
$email = trim($body['email'] ?? '');
$pass  = $body['password'] ?? '';

if (!$email || !$pass) {
    json_error('Email and password required');
}

$stmt = db()->prepare('SELECT * FROM agents WHERE email = ?');
$stmt->execute([$email]);
$agent = $stmt->fetch();

if (!$agent || !password_verify($pass, $agent['password_hash'])) {
    json_error('Invalid credentials', 401);
}

// Mark agent online
db()->prepare("UPDATE agents SET status = 'online', updated_at = NOW() WHERE id = ?")->execute([$agent['id']]);

$exp   = time() + JWT_TTL;
$token = jwt_encode(['agent_id' => $agent['id'], 'role' => $agent['role'], 'exp' => $exp]);

json_success([
    'token' => $token,
    'expires_at' => $exp,
    'agent' => [
        'id'     => (int)$agent['id'],
        'name'   => $agent['name'],
        'email'  => $agent['email'],
        'role'   => $agent['role'],
        'status' => 'online',
        'avatar' => $agent['avatar'],
    ],
]);
