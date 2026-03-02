<?php
/**
 * POST /api/widget/typing.php
 * Visitor typing heartbeat
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body   = request_body();
$convId = (int)($body['conv_id'] ?? 0);
$uid    = trim($body['uid'] ?? '');

if (!$convId || !$uid) {
    json_error('Missing parameters');
}

$pdo = db();

// Validate
$stmt = $pdo->prepare('SELECT c.id FROM conversations c JOIN contacts co ON co.id = c.contact_id WHERE c.id = ? AND co.uid = ?');
$stmt->execute([$convId, $uid]);
if (!$stmt->fetch()) {
    json_error('Not found', 404);
}

$pdo->prepare("INSERT INTO typing_status (conversation_id, sender_type, updated_at) VALUES (?, 'visitor', NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()")
    ->execute([$convId]);

json_success();
