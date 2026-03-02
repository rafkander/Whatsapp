<?php
/**
 * POST /api/widget/heartbeat.php
 * Keep visitor session alive
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

if (!$uid) {
    json_error('Missing uid');
}

// Touch contact's updated_at to keep session alive
$pdo = db();
$pdo->prepare('UPDATE contacts SET updated_at = NOW() WHERE uid = ?')->execute([$uid]);

$agentsOnline = any_agent_online() && is_within_business_hours();

json_success(['agents_online' => $agentsOnline]);
