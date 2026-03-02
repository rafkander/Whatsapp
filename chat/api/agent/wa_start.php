<?php
/**
 * POST /api/agent/wa_start.php
 * Agent-initiated outbound WhatsApp conversation.
 *
 * Body: { phone: string, message: string }
 * Returns: { conversation_id: int }
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$agent = require_agent();
$pdo   = db();

// WA must be enabled
if (get_setting('wa_enabled') !== '1') {
    json_error('WhatsApp integration is not enabled', 400);
}

$body    = request_body();
$rawPhone = trim($body['phone']    ?? '');
$message  = trim($body['message']  ?? '');

if (!$rawPhone) json_error('Phone number required');
if (!$message)  json_error('Message required');

// Normalise: strip everything except digits, then remove any leading +
$phone = preg_replace('/[^\d]/', '', $rawPhone);
if (!$phone) json_error('Invalid phone number');

// Get-or-create contact by whatsapp_number
$stmt = $pdo->prepare('SELECT * FROM contacts WHERE whatsapp_number = ?');
$stmt->execute([$phone]);
$contact = $stmt->fetch();

if (!$contact) {
    $uid = 'wa_' . $phone;
    $pdo->prepare('INSERT INTO contacts (uid, name, whatsapp_number, ip) VALUES (?, ?, ?, NULL)')
        ->execute([$uid, '+' . $phone, $phone]);
    $contactId = (int)$pdo->lastInsertId();
    $stmt->execute([$phone]);
    $contact = $stmt->fetch();
} else {
    $contactId = (int)$contact['id'];
}

// Create conversation — bot_state='done' so bot never fires on outbound
$pdo->prepare("INSERT INTO conversations (contact_id, channel, status, assigned_agent_id, bot_state, unread_agent)
               VALUES (?, 'whatsapp', 'open', ?, 'done', 0)")
    ->execute([$contactId, (int)$agent['id']]);

$convId = (int)$pdo->lastInsertId();

// Insert the outbound message
$pdo->prepare("INSERT INTO messages (conversation_id, sender_type, sender_id, content, type)
               VALUES (?, 'agent', ?, ?, 'text')")
    ->execute([$convId, (int)$agent['id'], $message]);

$msgId = (int)$pdo->lastInsertId();

// Send via WhatsApp API
$waRes = wa_send_text($phone, $message);

// Store WA message ID if returned
if (!empty($waRes['body']['messages'][0]['id'])) {
    $pdo->prepare('UPDATE messages SET wa_message_id = ? WHERE id = ?')
        ->execute([$waRes['body']['messages'][0]['id'], $msgId]);
}

// Update conversation timestamp
$pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?')->execute([$convId]);

json_success(['conversation_id' => $convId]);
