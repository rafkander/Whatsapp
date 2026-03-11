<?php
/**
 * GET /api/admin/sms_log.php
 * Returns outbound SMS messages with agent, contact and account info.
 *
 * Query params:
 *   page    int   (default 1)
 *   limit   int   (default 50, max 200)
 *   agent   int   agent ID filter
 *   account int   sms_account ID filter
 *   search  str   phone / contact name search
 *   from    date  YYYY-MM-DD
 *   to      date  YYYY-MM-DD
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');
require_admin();
$pdo = db();

$page    = max(1, (int)($_GET['page']   ?? 1));
$limit   = min(200, max(1, (int)($_GET['limit'] ?? 50)));
$offset  = ($page - 1) * $limit;
$agentId = isset($_GET['agent'])   ? (int)$_GET['agent']   : null;
$accId   = isset($_GET['account']) ? (int)$_GET['account'] : null;
$search  = trim($_GET['search'] ?? '');
$from    = $_GET['from'] ?? null;
$to      = $_GET['to']   ?? null;

$where  = ["m.sender_type = 'agent'", "c.channel = 'sms'"];
$params = [];

if ($agentId) { $where[] = 'm.sender_id = ?'; $params[] = $agentId; }
if ($accId)   { $where[] = 'c.sms_account_id = ?'; $params[] = $accId; }
if ($search)  {
    $where[]  = '(ct.name LIKE ? OR ct.phone LIKE ? OR ct.sms_number LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like]);
}
if ($from) { $where[] = 'DATE(m.created_at) >= ?'; $params[] = $from; }
if ($to)   { $where[] = 'DATE(m.created_at) <= ?'; $params[] = $to; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Total count
$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM messages m
    JOIN conversations c  ON c.id = m.conversation_id
    JOIN contacts ct      ON ct.id = c.contact_id
    LEFT JOIN agents a    ON a.id = m.sender_id
    LEFT JOIN sms_accounts sa ON sa.id = c.sms_account_id
    $whereSQL
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Data
$stmt = $pdo->prepare("
    SELECT
        m.id,
        m.content,
        m.created_at,
        a.id   AS agent_id,
        a.name AS agent_name,
        a.email AS agent_email,
        ct.id   AS contact_id,
        ct.name AS contact_name,
        ct.phone AS contact_phone,
        ct.sms_number AS contact_sms,
        sa.name AS account_name,
        sa.sender_id AS sender_id,
        c.id AS conv_id
    FROM messages m
    JOIN conversations c  ON c.id = m.conversation_id
    JOIN contacts ct      ON ct.id = c.contact_id
    LEFT JOIN agents a    ON a.id = m.sender_id
    LEFT JOIN sms_accounts sa ON sa.id = c.sms_account_id
    $whereSQL
    ORDER BY m.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Agents & accounts for filter dropdowns
$agents   = $pdo->query("SELECT id, name FROM agents ORDER BY name")->fetchAll();
$accounts = $pdo->query("SELECT id, name, sender_id FROM sms_accounts WHERE is_enabled=1 ORDER BY name")->fetchAll();

echo json_encode([
    'success'  => true,
    'rows'     => $rows,
    'total'    => $total,
    'page'     => $page,
    'limit'    => $limit,
    'agents'   => $agents,
    'accounts' => $accounts,
]);
