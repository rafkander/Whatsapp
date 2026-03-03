<?php
/**
 * GET /api/agent/messages.php?conv_id=X&last_id=Y  — poll messages
 * POST /api/agent/messages.php                      — send message
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

$agent = require_agent();
$pdo   = db();

// ── GET: poll ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $convId = (int)($_GET['conv_id'] ?? 0);
    $lastId = (int)($_GET['last_id'] ?? 0);

    if (!$convId) json_error('Missing conv_id');

    $stmt = $pdo->prepare('SELECT id FROM conversations WHERE id = ?');
    $stmt->execute([$convId]);
    if (!$stmt->fetch()) json_error('Not found', 404);

    $stmt = $pdo->prepare("
        SELECT m.id, m.sender_type, m.sender_id, m.content, m.type, m.file_url, m.file_name, m.file_size, m.read_at, m.created_at,
               CASE WHEN m.sender_type = 'agent' THEN a.name ELSE NULL END AS sender_name,
               CASE WHEN m.sender_type = 'agent' THEN a.avatar ELSE NULL END AS sender_avatar
        FROM messages m
        LEFT JOIN agents a ON a.id = m.sender_id AND m.sender_type = 'agent'
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.id ASC
        LIMIT 100
    ");
    $stmt->execute([$convId, $lastId]);
    $messages = $stmt->fetchAll();

    // Check visitor typing
    $stmt = $pdo->prepare("SELECT updated_at FROM typing_status WHERE conversation_id = ? AND sender_type = 'visitor' AND updated_at > DATE_SUB(NOW(), INTERVAL 4 SECOND)");
    $stmt->execute([$convId]);
    $visitorTyping = (bool)$stmt->fetch();

    json_success([
        'messages'        => $messages,
        'visitor_typing'  => $visitorTyping,
    ]);
}

// ── POST: send ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = request_body();
    $convId  = (int)($body['conv_id'] ?? 0);
    $content = trim($body['content'] ?? '');
    $type    = in_array($body['type'] ?? 'text', ['text', 'note', 'file', 'image']) ? $body['type'] : 'text';
    $fileUrl = trim($body['file_url'] ?? '');

    if (!$convId) json_error('Missing conv_id');
    if (!$content && !$fileUrl) json_error('Content required');

    // Verify conversation exists
    $stmt = $pdo->prepare('SELECT * FROM conversations WHERE id = ?');
    $stmt->execute([$convId]);
    $conv = $stmt->fetch();
    if (!$conv) json_error('Not found', 404);

    // Must be the assigned agent to reply — no role bypass
    if ((int)$conv['assigned_agent_id'] !== (int)$agent['id']) {
        json_error('Take this conversation before replying', 403);
    }

    // Insert message
    $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, sender_id, content, type, file_url) VALUES (?, 'agent', ?, ?, ?, ?)")
        ->execute([$convId, $agent['id'], $content ?: null, $type, $fileUrl ?: null]);

    $msgId = (int)$pdo->lastInsertId();

    // Update conversation
    if ($type !== 'note') {
        $pdo->prepare('UPDATE conversations SET updated_at = NOW(), unread_visitor = unread_visitor + 1, status = IF(status = "closed", "open", status) WHERE id = ?')
            ->execute([$convId]);

        // Clear agent typing
        $pdo->prepare("DELETE FROM typing_status WHERE conversation_id = ? AND sender_type = 'agent'")->execute([$convId]);

        // If WhatsApp conversation, send via WA API
        $waWarning = null;
        if ($conv['channel'] === 'whatsapp') {
            $stmt = $pdo->prepare('SELECT whatsapp_number FROM contacts WHERE id = ?');
            $stmt->execute([$conv['contact_id']]);
            $contact = $stmt->fetch();

            if ($contact && $contact['whatsapp_number']) {
                $waPhone = $contact['whatsapp_number'];
                if ($type === 'image' && $fileUrl) {
                    $waRes = wa_send_media($waPhone, 'image', $fileUrl, $content ?: null);
                } elseif ($type === 'file' && $fileUrl) {
                    $waRes = wa_send_media($waPhone, 'document', $fileUrl, $content ?: null, basename($fileUrl));
                } else {
                    $waRes = wa_send_text($waPhone, $content);
                }

                if ($waRes === null) {
                    $waWarning = 'WhatsApp credentials not configured. Message saved but not delivered to WhatsApp.';
                } elseif ($waRes['status'] !== 200) {
                    $errMsg = $waRes['body']['error']['message'] ?? 'Unknown error';
                    $errCode = $waRes['body']['error']['code'] ?? $waRes['status'];
                    $waWarning = "WhatsApp delivery failed (#{$errCode}): {$errMsg}";
                } else {
                    // Store WA message ID if returned
                    if (!empty($waRes['body']['messages'][0]['id'])) {
                        $pdo->prepare('UPDATE messages SET wa_message_id = ? WHERE id = ?')
                            ->execute([$waRes['body']['messages'][0]['id'], $msgId]);
                    }
                }
            } else {
                $waWarning = 'No WhatsApp number on file for this contact.';
            }
        }
    }

    json_success([
        'message_id' => $msgId,
        'sent_at'    => date('Y-m-d H:i:s'),
        'wa_warning' => $waWarning ?? null,
    ]);
}

json_error('Method not allowed', 405);
