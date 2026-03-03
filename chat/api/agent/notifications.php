<?php
/**
 * GET /api/agent/notifications.php
 * Unread count + new conversation notifications for agent
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$agent  = require_agent();
$pdo    = db();
$lastId = (int)($_GET['last_conv_id'] ?? 0);

// Agent's dept restrictions
$deptRows = $pdo->prepare('SELECT dept_id FROM agent_departments WHERE agent_id = ?');
$deptRows->execute([$agent['id']]);
$agentDepts = $deptRows->fetchAll(PDO::FETCH_COLUMN);
$deptFilter = '';
$deptParams = [];
if ($agentDepts) {
    $ph = implode(',', array_fill(0, count($agentDepts), '?'));
    $deptFilter = " AND (dept_id IS NULL OR dept_id IN ({$ph}))";
    $deptParams = $agentDepts;
}

// Total unread conversations (scoped to visible depts)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM conversations WHERE unread_agent > 0 AND status = 'open'" . $deptFilter);
$stmt->execute($deptParams);
$unreadCount = (int)$stmt->fetchColumn();

// New conversations since last poll (scoped to visible depts)
$stmt = $pdo->prepare("
    SELECT c.id, c.channel, c.created_at,
           co.name AS contact_name, co.email AS contact_email,
           d.name AS dept_name
    FROM conversations c
    JOIN contacts co ON co.id = c.contact_id
    LEFT JOIN departments d ON d.id = c.dept_id
    WHERE c.id > ? AND c.status = 'open'" . ($deptFilter ? str_replace('dept_id', 'c.dept_id', $deptFilter) : '') . "
    ORDER BY c.id DESC
    LIMIT 10
");
$stmt->execute(array_merge([$lastId], $deptParams));
$newConvs = $stmt->fetchAll();

// My assigned unread
$stmt = $pdo->prepare("SELECT COUNT(*) FROM conversations WHERE assigned_agent_id = ? AND unread_agent > 0 AND status = 'open'");
$stmt->execute([$agent['id']]);
$myUnread = (int)$stmt->fetchColumn();

// Conversations recently assigned to me by someone else (within last 10 seconds, by ID > last seen)
$stmt = $pdo->prepare("
    SELECT c.id, c.channel,
           co.name AS contact_name
    FROM conversations c
    JOIN contacts co ON co.id = c.contact_id
    WHERE c.assigned_agent_id = ?
      AND c.id > ?
      AND c.status = 'open'
    ORDER BY c.id DESC
    LIMIT 5
");
$stmt->execute([$agent['id'], $lastId]);
$assignedToMe = $stmt->fetchAll();

json_success([
    'unread_total'      => $unreadCount,
    'my_unread'         => $myUnread,
    'new_conversations' => $newConvs,
    'assigned_to_me'    => $assignedToMe,
]);
