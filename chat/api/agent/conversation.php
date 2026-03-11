<?php
/**
 * GET/PATCH /api/agent/conversation.php?id=X
 * Single conversation: get details or update (assign, tag, close, reopen, dept)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

$agent  = require_agent();
$convId = (int)($_GET['id'] ?? $_REQUEST['id'] ?? 0);

if (!$convId) {
    json_error('Missing conversation id');
}

$pdo = db();

/**
 * Check whether $agent has access to the given conversation.
 * Supervisors and above see everything; agents/senior_agents only see
 * conversations in their assigned departments or assigned to them.
 */
function agent_can_access_conv(array $agent, array $conv): bool {
    if (role_level($agent['role']) >= role_level('supervisor')) {
        return true;
    }
    // Assigned directly to this agent
    if ((int)($conv['assigned_agent_id'] ?? 0) === (int)$agent['id']) {
        return true;
    }
    // No department → visible to all agents (unassigned pool)
    if (!$conv['dept_id']) {
        return true;
    }
    // Check department membership
    $deptStmt = db()->prepare('SELECT 1 FROM agent_departments WHERE agent_id = ? AND dept_id = ?');
    $deptStmt->execute([$agent['id'], $conv['dept_id']]);
    return (bool)$deptStmt->fetch();
}

function fetch_conv(int $id): ?array {
    $stmt = db()->prepare("
        SELECT c.*, co.name AS contact_name, co.email AS contact_email,
               co.phone, co.whatsapp_number, co.sms_number, co.ip, co.browser, co.os,
               d.name AS dept_name, d.color AS dept_color,
               a.name AS agent_name, a.avatar AS agent_avatar,
               wa.name AS wa_account_name,
               sa.name AS sms_account_name
        FROM conversations c
        JOIN contacts co ON co.id = c.contact_id
        LEFT JOIN departments d ON d.id = c.dept_id
        LEFT JOIN agents a ON a.id = c.assigned_agent_id
        LEFT JOIN wa_accounts wa ON wa.id = c.wa_account_id
        LEFT JOIN sms_accounts sa ON sa.id = c.sms_account_id
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $conv = fetch_conv($convId);
    if (!$conv) json_error('Not found', 404);
    if (!agent_can_access_conv($agent, $conv)) json_error('Access denied', 403);

    // Mark as read
    $pdo->prepare('UPDATE conversations SET unread_agent = 0 WHERE id = ?')->execute([$convId]);
    $pdo->prepare("UPDATE messages SET read_at = NOW() WHERE conversation_id = ? AND sender_type != 'agent' AND read_at IS NULL")->execute([$convId]);

    json_success(['conversation' => $conv]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $conv = fetch_conv($convId);
    if (!$conv) json_error('Not found', 404);
    if (!agent_can_access_conv($agent, $conv)) json_error('Access denied', 403);

    $body    = request_body();
    $updates = [];
    $params  = [];

    if (array_key_exists('assigned_agent_id', $body)) {
        $newAgentId = $body['assigned_agent_id'] ? (int)$body['assigned_agent_id'] : null;
        $updates[]  = 'assigned_agent_id = ?';
        $params[]   = $newAgentId;

        // Only log system message if the agent actually changed
        if ($newAgentId && (int)($conv['assigned_agent_id'] ?? 0) !== $newAgentId) {
            $agentStmt = $pdo->prepare('SELECT name FROM agents WHERE id = ?');
            $agentStmt->execute([$newAgentId]);
            $assignedAgent = $agentStmt->fetch();
            $msg = $assignedAgent ? "Conversation assigned to {$assignedAgent['name']}" : 'Conversation assigned';
            $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?, 'system', ?, 'system')")->execute([$convId, $msg]);

            // Stop bot when agent takes any conversation
            if (($conv['bot_state'] ?? '') !== 'done') {
                $updates[] = 'bot_state = ?';
                $params[]  = 'done';
            }
        }
    }

    if (array_key_exists('dept_id', $body)) {
        $updates[] = 'dept_id = ?';
        $params[]  = $body['dept_id'] ? (int)$body['dept_id'] : null;
        if ($body['dept_id']) {
            $dStmt = $pdo->prepare('SELECT name FROM departments WHERE id = ?');
            $dStmt->execute([(int)$body['dept_id']]);
            $dept = $dStmt->fetch();
            $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?, 'system', ?, 'system')")->execute([$convId, "Transferred to " . ($dept['name'] ?? 'department')]);
        }
    }

    if (array_key_exists('status', $body)) {
        $newStatus = $body['status'];
        if (!in_array($newStatus, ['open', 'closed', 'pending'], true)) {
            json_error('Invalid status');
        }
        $updates[] = 'status = ?';
        $params[]  = $newStatus;
        if ($newStatus === 'open') {
            $updates[] = 'dept_id = ?';
            $params[]  = null;
            $updates[] = 'assigned_agent_id = ?';
            $params[]  = null;
        }
        $sysMsg    = $newStatus === 'closed' ? "Conversation closed by {$agent['name']}" : "Conversation reopened by {$agent['name']}";
        $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?, 'system', ?, 'system')")->execute([$convId, $sysMsg]);
    }

    if (array_key_exists('tags', $body)) {
        $updates[] = 'tags = ?';
        $params[]  = is_array($body['tags']) ? implode(',', $body['tags']) : (string)$body['tags'];
    }

    if (!$updates) {
        json_error('No fields to update');
    }

    $updates[] = 'updated_at = NOW()';
    $params[]  = $convId;
    $pdo->prepare('UPDATE conversations SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

    $conv = fetch_conv($convId);
    json_success(['conversation' => $conv]);
}

json_error('Method not allowed', 405);
