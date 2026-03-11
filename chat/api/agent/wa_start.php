<?php
/**
 * POST /api/agent/wa_start.php
 * Agent-initiated outbound WhatsApp conversation.
 *
 * Body: { phone: string, message: string, account_id?: int }
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

// WA must be enabled (global flag or at least one account)
$anyEnabled = (bool)$pdo->query('SELECT COUNT(*) FROM wa_accounts WHERE is_enabled = 1')->fetchColumn();
if (!$anyEnabled && get_setting('wa_enabled') !== '1') {
    json_error('WhatsApp integration is not enabled', 400);
}

$body      = request_body();
$rawPhone  = trim($body['phone']      ?? '');
$message   = trim($body['message']   ?? '');
$accountId = isset($body['account_id']) ? (int)$body['account_id'] : null;

if (!$rawPhone) json_error('Phone number required');
if (!$message)  json_error('Message required');

// Normalise phone: digits only
$phone = preg_replace('/[^\d]/', '', $rawPhone);
if (!$phone) json_error('Invalid phone number');

// Resolve WhatsApp account to send from
$waCreds = null;
$waAccountId = null;
if ($accountId) {
    $stmt = $pdo->prepare('SELECT * FROM wa_accounts WHERE id = ? AND is_enabled = 1');
    $stmt->execute([$accountId]);
    $account = $stmt->fetch();
    if ($account) {
        $waCreds     = ['phone_number_id' => $account['phone_number_id'], 'access_token' => $account['access_token']];
        $waAccountId = (int)$account['id'];
    }
}
// Fall back to first enabled account if none specified
if (!$waCreds) {
    $stmt = $pdo->prepare('SELECT * FROM wa_accounts WHERE is_enabled = 1 ORDER BY id LIMIT 1');
    $stmt->execute();
    $account = $stmt->fetch();
    if ($account) {
        $waCreds     = ['phone_number_id' => $account['phone_number_id'], 'access_token' => $account['access_token']];
        $waAccountId = (int)$account['id'];
    }
}

// Get-or-create contact — cross-channel dedup
$contact   = find_or_create_contact($pdo, $phone, null, 'whatsapp');
$contactId = (int)$contact['id'];

// Create conversation — bot_state='done' so bot never fires on outbound
$pdo->prepare("INSERT INTO conversations (contact_id, channel, wa_account_id, status, assigned_agent_id, bot_state, unread_agent)
               VALUES (?, 'whatsapp', ?, 'open', ?, 'done', 0)")
    ->execute([$contactId, $waAccountId, (int)$agent['id']]);

$convId = (int)$pdo->lastInsertId();

// Insert the outbound message
$pdo->prepare("INSERT INTO messages (conversation_id, sender_type, sender_id, content, type)
               VALUES (?, 'agent', ?, ?, 'text')")
    ->execute([$convId, (int)$agent['id'], $message]);

$msgId = (int)$pdo->lastInsertId();

// Send via WhatsApp API
$waRes = wa_send_text($phone, $message, $waCreds);

if (!empty($waRes['body']['messages'][0]['id'])) {
    $pdo->prepare('UPDATE messages SET wa_message_id = ? WHERE id = ?')
        ->execute([$waRes['body']['messages'][0]['id'], $msgId]);
}

$pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?')->execute([$convId]);

json_success(['conversation_id' => $convId]);
