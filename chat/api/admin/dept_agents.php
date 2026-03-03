<?php
/**
 * POST/DELETE /api/admin/dept_agents.php
 * Add or remove an agent from a department
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

require_admin();
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $body    = request_body();
    $deptId  = (int)($body['dept_id']  ?? 0);
    $agentId = (int)($body['agent_id'] ?? 0);
    if (!$deptId || !$agentId) json_error('Missing dept_id or agent_id');

    $pdo->prepare('INSERT IGNORE INTO agent_departments (agent_id, dept_id) VALUES (?, ?)')
        ->execute([$agentId, $deptId]);
    json_success();
}

if ($method === 'DELETE') {
    $deptId  = (int)($_GET['dept_id']  ?? 0);
    $agentId = (int)($_GET['agent_id'] ?? 0);
    if (!$deptId || !$agentId) json_error('Missing dept_id or agent_id');

    $pdo->prepare('DELETE FROM agent_departments WHERE agent_id = ? AND dept_id = ?')
        ->execute([$agentId, $deptId]);
    json_success();
}

json_error('Method not allowed', 405);
