<?php
/**
 * POST /api/agent/login.php
 * Authenticate agent → return JWT
 */
// TEMP: expose PHP errors as JSON so we can diagnose the 500
set_exception_handler(function($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
    exit;
});
set_error_handler(function($errno, $errstr, $errfile) {
    throw new \ErrorException($errstr, $errno, $errno, $errfile);
});

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

// Rate limiting: max 10 attempts per IP per 15 minutes
(function () {
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $file    = sys_get_temp_dir() . '/login_rl_' . md5($ip) . '.json';
    $now     = time();
    $window  = 900;   // 15 minutes
    $maxAttempts = 50;

    $attempts = [];
    if (file_exists($file)) {
        $attempts = json_decode(@file_get_contents($file), true) ?? [];
    }
    // Keep only attempts within the window
    $attempts = array_values(array_filter($attempts, fn($t) => ($now - $t) < $window));

    if (count($attempts) >= $maxAttempts) {
        json_error('Too many login attempts. Please try again later.', 429);
    }

    $attempts[] = $now;
    @file_put_contents($file, json_encode($attempts), LOCK_EX);
})();

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
    json_error('Invalid credentials', 400);
}

// Mark agent online
db()->prepare("UPDATE agents SET status = 'online', updated_at = NOW() WHERE id = ?")->execute([$agent['id']]);

$exp   = time() + JWT_TTL;
$token = jwt_encode(['agent_id' => $agent['id'], 'role' => $agent['role'], 'exp' => $exp]);

// Register session so it can be revoked on logout
$tokenHash = hash('sha256', $token);
db()->prepare('INSERT INTO agent_sessions (agent_id, token_hash, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))')
    ->execute([$agent['id'], $tokenHash, $exp]);
// Prune expired sessions for this agent
db()->prepare('DELETE FROM agent_sessions WHERE agent_id = ? AND expires_at < NOW()')
    ->execute([$agent['id']]);

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
