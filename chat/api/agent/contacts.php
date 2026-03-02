<?php
/**
 * GET /api/agent/contacts.php?conv_id=X  — visitor info + history
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

require_agent();
$pdo = db();

$convId    = (int)($_GET['conv_id'] ?? 0);
$contactId = (int)($_GET['contact_id'] ?? 0);

if (!$convId && !$contactId) {
    json_error('Missing conv_id or contact_id');
}

if ($convId) {
    $stmt = $pdo->prepare('SELECT contact_id FROM conversations WHERE id = ?');
    $stmt->execute([$convId]);
    $row = $stmt->fetch();
    if (!$row) json_error('Conversation not found', 404);
    $contactId = (int)$row['contact_id'];
}

$stmt = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
$stmt->execute([$contactId]);
$contact = $stmt->fetch();

if (!$contact) json_error('Contact not found', 404);

// Conversation history
$stmt = $pdo->prepare("
    SELECT c.id, c.channel, c.status, c.created_at, c.updated_at,
           d.name AS dept_name, a.name AS agent_name,
           (SELECT content FROM messages WHERE conversation_id = c.id AND type != 'note' ORDER BY id DESC LIMIT 1) AS last_message
    FROM conversations c
    LEFT JOIN departments d ON d.id = c.dept_id
    LEFT JOIN agents a ON a.id = c.assigned_agent_id
    WHERE c.contact_id = ?
    ORDER BY c.updated_at DESC
    LIMIT 20
");
$stmt->execute([$contactId]);
$history = $stmt->fetchAll();

json_success([
    'contact' => $contact,
    'history' => $history,
]);
