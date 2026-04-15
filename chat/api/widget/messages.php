<?php
/**
 * GET /api/widget/messages.php?conv_id=X&last_id=Y
 * Poll new messages for visitor since last_id
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$convId = (int)($_GET['conv_id'] ?? 0);
$lastId = (int)($_GET['last_id'] ?? 0);
$uid    = trim($_GET['uid'] ?? '');

if (!$convId || !$uid) {
    json_error('Missing conv_id or uid');
}

$pdo = db();

// Validate conversation belongs to this visitor
$stmt = $pdo->prepare('SELECT c.*, co.uid FROM conversations c JOIN contacts co ON co.id = c.contact_id WHERE c.id = ? AND co.uid = ?');
$stmt->execute([$convId, $uid]);
$conv = $stmt->fetch();

if (!$conv) {
    json_error('Conversation not found', 404);
}

// Fetch messages since last_id (exclude internal notes)
$stmt = $pdo->prepare("
    SELECT m.id, m.sender_type, m.sender_id, m.content, m.type, m.file_url, m.file_name, m.created_at,
           CASE WHEN m.sender_type = 'agent' THEN a.name ELSE NULL END AS sender_name,
           CASE WHEN m.sender_type = 'agent' THEN a.avatar ELSE NULL END AS sender_avatar
    FROM messages m
    LEFT JOIN agents a ON a.id = m.sender_id AND m.sender_type = 'agent'
    WHERE m.conversation_id = ? AND m.id > ? AND m.type NOT IN ('note', 'system') AND m.sender_type != 'system'
    ORDER BY m.id ASC
    LIMIT 50
");
$stmt->execute([$convId, $lastId]);
$messages = $stmt->fetchAll();

// Mark agent messages as read
if ($messages) {
    $pdo->prepare("UPDATE messages SET read_at = NOW() WHERE conversation_id = ? AND sender_type = 'agent' AND read_at IS NULL")
        ->execute([$convId]);
    $pdo->prepare("UPDATE conversations SET unread_visitor = 0 WHERE id = ?")
        ->execute([$convId]);
}

// Check typing status (agent typing)
$stmt = $pdo->prepare("SELECT updated_at FROM typing_status WHERE conversation_id = ? AND sender_type = 'agent' AND updated_at > DATE_SUB(NOW(), INTERVAL 4 SECOND)");
$stmt->execute([$convId]);
$agentTyping = (bool)$stmt->fetch();

// Conversation status
$stmt = $pdo->prepare('SELECT status, rating FROM conversations WHERE id = ?');
$stmt->execute([$convId]);
$convStatus = $stmt->fetch();

json_success([
    'messages'      => $messages,
    'agent_typing'  => $agentTyping,
    'conv_status'   => $convStatus['status'],
    'can_rate'      => ($convStatus['status'] === 'closed' && $convStatus['rating'] === null),
]);
