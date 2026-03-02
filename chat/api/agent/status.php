<?php
/**
 * POST /api/agent/status.php
 * Set agent status: online / away / offline
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
$status = $body['status'] ?? '';

if (!in_array($status, ['online', 'away', 'offline'], true)) {
    json_error('Invalid status. Must be: online, away, offline');
}

db()->prepare("UPDATE agents SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $agent['id']]);

json_success(['status' => $status]);
