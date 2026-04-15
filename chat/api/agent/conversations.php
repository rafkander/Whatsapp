<?php
/**
 * GET /api/agent/conversations.php
 * List conversations with filters
 * Query params: status, dept_id, channel, search, mine, page, unread
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$agent = require_agent();
$pdo   = db();

$status  = $_GET['status']  ?? '';   // open, closed, pending
$deptId  = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : null;
$channel = $_GET['channel'] ?? '';   // widget, whatsapp
$search  = trim($_GET['search'] ?? '');
$mine       = !empty($_GET['mine']);
$unread     = !empty($_GET['unread']);
$unassigned = !empty($_GET['unassigned']);
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 30;
$offset  = ($page - 1) * $limit;

$where  = ['1=1'];
$params = [];

// Dept-based visibility: agents with dept memberships only see those depts + unassigned.
// Admins and above always see every department regardless of memberships.
$isAdmin = role_level($agent['role']) >= role_level('admin');
$deptRows = $pdo->prepare('SELECT dept_id FROM agent_departments WHERE agent_id = ?');
$deptRows->execute([$agent['id']]);
$agentDepts = $deptRows->fetchAll(PDO::FETCH_COLUMN);
if ($agentDepts && !$isAdmin) {
    $ph = implode(',', array_fill(0, count($agentDepts), '?'));
    $where[] = "(c.dept_id IS NULL OR c.dept_id IN ({$ph}))";
    foreach ($agentDepts as $d) $params[] = $d;
}

if ($status) {
    $where[]  = 'c.status = ?';
    $params[] = $status;
} else {
    // Hide outbound-only pending conversations (e.g. SMS sent but not yet replied to)
    $where[] = "c.status != 'pending'";
}

if ($deptId !== null) {
    $where[]  = 'c.dept_id = ?';
    $params[] = $deptId;
}

if ($channel) {
    $where[]  = 'c.channel = ?';
    $params[] = $channel;
}

if ($mine) {
    $where[]  = 'c.assigned_agent_id = ?';
    $params[] = $agent['id'];
}

if ($unassigned) {
    $where[] = 'c.assigned_agent_id IS NULL';
}

if ($unread) {
    $where[] = 'c.unread_agent > 0';
}

if ($search) {
    $where[]  = '(co.name LIKE ? OR co.email LIKE ? OR m_last.content LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// Hide widget conversations that have no visitor message yet (ghost rows from widget init)
$where[] = "(c.channel != 'widget' OR EXISTS (SELECT 1 FROM messages mv WHERE mv.conversation_id = c.id AND mv.sender_type = 'visitor'))";

$whereStr = implode(' AND ', $where);

$sql = "
    SELECT
        c.id, c.channel, c.status, c.tags, c.rating, c.page_url,
        c.unread_agent, c.unread_visitor, c.created_at, c.updated_at,
        co.id AS contact_id, co.name AS contact_name, co.email AS contact_email,
        co.whatsapp_number, co.sms_number, co.browser, co.os, co.ip,
        d.id AS dept_id, d.name AS dept_name, d.color AS dept_color,
        a.id AS agent_id, a.name AS agent_name, a.avatar AS agent_avatar,
        m_last.content AS last_message, m_last.type AS last_message_type,
        m_last.sender_type AS last_sender_type, m_last.created_at AS last_message_at,
        wa.id AS wa_account_id, wa.name AS wa_account_name,
        sa.id AS sms_account_id, sa.name AS sms_account_name
    FROM conversations c
    JOIN contacts co ON co.id = c.contact_id
    LEFT JOIN departments d ON d.id = c.dept_id
    LEFT JOIN agents a ON a.id = c.assigned_agent_id
    LEFT JOIN wa_accounts wa ON wa.id = c.wa_account_id
    LEFT JOIN sms_accounts sa ON sa.id = c.sms_account_id
    LEFT JOIN messages m_last ON m_last.id = (
        SELECT id FROM messages WHERE conversation_id = c.id AND type != 'note' ORDER BY id DESC LIMIT 1
    )
    WHERE {$whereStr}
    ORDER BY c.updated_at DESC
    LIMIT {$limit} OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$convs = $stmt->fetchAll();

// Total count
$countSql = "SELECT COUNT(*) FROM conversations c JOIN contacts co ON co.id = c.contact_id LEFT JOIN messages m_last ON m_last.id = (SELECT id FROM messages WHERE conversation_id = c.id AND type != 'note' ORDER BY id DESC LIMIT 1) WHERE {$whereStr}";
$cstmt    = $pdo->prepare($countSql);
$cstmt->execute($params);
$total = (int)$cstmt->fetchColumn();

// Unread count — scoped to agent's visible depts, and excluding widget convs with no visitor message yet
$widgetGate = "(channel != 'widget' OR EXISTS (SELECT 1 FROM messages mv WHERE mv.conversation_id = conversations.id AND mv.sender_type = 'visitor'))";
if ($agentDepts && !$isAdmin) {
    $ph2 = implode(',', array_fill(0, count($agentDepts), '?'));
    $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM conversations WHERE unread_agent > 0 AND status = 'open' AND (dept_id IS NULL OR dept_id IN ({$ph2})) AND {$widgetGate}");
    $unreadStmt->execute($agentDepts);
} else {
    $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM conversations WHERE unread_agent > 0 AND status = 'open' AND {$widgetGate}");
    $unreadStmt->execute();
}
$unreadCount = (int)$unreadStmt->fetchColumn();

json_success([
    'conversations' => $convs,
    'total'         => $total,
    'page'          => $page,
    'pages'         => (int)ceil($total / $limit),
    'unread_count'  => $unreadCount,
]);
