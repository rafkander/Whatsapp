<?php
/**
 * POST /api/agent/typing.php
 * Agent typing heartbeat
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$agent  = require_agent();
$body   = request_body();
$convId = (int)($body['conv_id'] ?? 0);

if (!$convId) {
    json_error('Missing conv_id');
}

db()->prepare("INSERT INTO typing_status (conversation_id, sender_type, updated_at) VALUES (?, 'agent', NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()")
    ->execute([$convId]);

json_success();
