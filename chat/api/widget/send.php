<?php
/**
 * POST /api/widget/send.php
 * Visitor sends a message
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body          = request_body();
$convId        = (int)($body['conv_id'] ?? 0);
$uid           = trim($body['uid'] ?? '');
$content       = trim($body['content'] ?? '');
$type          = in_array($body['type'] ?? 'text', ['text', 'file', 'image']) ? ($body['type'] ?? 'text') : 'text';
$fileUrl       = trim($body['file_url'] ?? '');
$interactiveId = trim($body['interactive_id'] ?? '') ?: null;

if (!$convId || !$uid) {
    json_error('Missing conv_id or uid');
}

if (!$content && !$fileUrl) {
    json_error('Message content is required');
}

$pdo = db();

// Validate conversation
$stmt = $pdo->prepare('SELECT c.id, c.status, c.assigned_agent_id, c.bot_state, co.id AS contact_id FROM conversations c JOIN contacts co ON co.id = c.contact_id WHERE c.id = ? AND co.uid = ?');
$stmt->execute([$convId, $uid]);
$conv = $stmt->fetch();

if (!$conv) {
    json_error('Conversation not found', 404);
}

// ── Conversation reopen + message insert (transactional) ─────
$wasReopened = false;
$msgId       = 0;

$pdo->beginTransaction();
try {
    if ($conv['status'] === 'closed') {
        $pdo->prepare("UPDATE conversations SET status = 'open', assigned_agent_id = NULL, bot_state = NULL, bot_data = NULL, updated_at = NOW() WHERE id = ?")
            ->execute([$convId]);
        $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?, 'system', 'Conversation reopened by visitor', 'system')")
            ->execute([$convId]);
        $wasReopened = true;
        $conv['assigned_agent_id'] = null;
        $conv['bot_state']         = null;
    }

    $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type, file_url) VALUES (?, 'visitor', ?, ?, ?)")
        ->execute([$convId, $content ?: null, $type, $fileUrl ?: null]);

    $msgId = (int)$pdo->lastInsertId();

    $pdo->prepare('UPDATE conversations SET updated_at = NOW(), unread_agent = unread_agent + 1 WHERE id = ?')
        ->execute([$convId]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to save message', 500);
}

// Clear visitor typing (non-critical, outside transaction)
$pdo->prepare("DELETE FROM typing_status WHERE conversation_id = ? AND sender_type = 'visitor'")
    ->execute([$convId]);

// Run widget bot (outside transaction — may call DB + bot logic)
$botState = $conv['bot_state'];
if ($botState !== 'done' && empty($conv['assigned_agent_id'])) {
    try {
        require_once dirname(__DIR__) . '/bot/widget.php';
        wb_bot_process($pdo, $convId, $content, $interactiveId);
    } catch (\Throwable $e) {
        error_log('widget_bot send error: ' . $e->getMessage());
    }
}

json_success(['message_id' => $msgId, 'reopened' => $wasReopened]);
