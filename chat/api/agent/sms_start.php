<?php
/**
 * POST /api/agent/sms_start.php
 * Agent-initiated outbound SMS conversation.
 *
 * Body: { phone: string, message: string, account_id?: int, name?: string }
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

$anyEnabled = (bool)$pdo->query('SELECT COUNT(*) FROM sms_accounts WHERE is_enabled = 1')->fetchColumn();
if (!$anyEnabled) {
    json_error('No SMS sender IDs are configured', 400);
}
if (!get_setting('alfonica_sms_token')) {
    json_error('Alfonica API token is not configured', 400);
}

$body      = request_body();
$rawPhone  = trim($body['phone']      ?? '');
$message   = trim($body['message']   ?? '');
$accountId = isset($body['account_id']) ? (int)$body['account_id'] : null;
$name      = trim($body['name']       ?? '');

if (!$rawPhone) json_error('Phone number required');
if (!$message)  json_error('Message required');

// Normalise phone to full international format
$phone = normalize_phone($rawPhone);
if (!$phone) json_error('Invalid phone number');

// Resolve SMS account to send from
$smsCreds    = null;
$smsAccountId = null;

if ($accountId) {
    $stmt = $pdo->prepare('SELECT * FROM sms_accounts WHERE id = ? AND is_enabled = 1');
    $stmt->execute([$accountId]);
    $account = $stmt->fetch();
    if ($account) {
        $smsCreds     = ['sender_id' => $account['sender_id']];
        $smsAccountId = (int)$account['id'];
    }
}
if (!$smsCreds) {
    $stmt = $pdo->prepare('SELECT * FROM sms_accounts WHERE is_enabled = 1 ORDER BY id LIMIT 1');
    $stmt->execute();
    $account = $stmt->fetch();
    if ($account) {
        $smsCreds     = ['sender_id' => $account['sender_id']];
        $smsAccountId = (int)$account['id'];
    }
}

if (!$smsCreds) json_error('No SMS account available', 400);

// Get-or-create contact by sms_number
$stmt = $pdo->prepare('SELECT * FROM contacts WHERE sms_number = ?');
$stmt->execute([$phone]);
$contact = $stmt->fetch();

if (!$contact) {
    $uid         = 'sms_' . $phone;
    $displayName = $name ?: '+' . $phone;
    // uid might conflict — append suffix if needed
    try {
        $pdo->prepare('INSERT INTO contacts (uid, name, sms_number, ip) VALUES (?, ?, ?, NULL)')
            ->execute([$uid, $displayName, $phone]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            $uid = 'sms_' . $phone . '_' . time();
            $pdo->prepare('INSERT INTO contacts (uid, name, sms_number, ip) VALUES (?, ?, ?, NULL)')
                ->execute([$uid, $displayName, $phone]);
        } else {
            throw $e;
        }
    }
    $contactId = (int)$pdo->lastInsertId();
} else {
    $contactId = (int)$contact['id'];
}

// Create conversation as 'pending' — hidden from dashboard until the contact replies
$pdo->prepare("INSERT INTO conversations (contact_id, channel, sms_account_id, status, assigned_agent_id, bot_state, unread_agent)
               VALUES (?, 'sms', ?, 'pending', ?, 'done', 0)")
    ->execute([$contactId, $smsAccountId, (int)$agent['id']]);

$convId = (int)$pdo->lastInsertId();

// Insert the outbound message record
$pdo->prepare("INSERT INTO messages (conversation_id, sender_type, sender_id, content, type)
               VALUES (?, 'agent', ?, ?, 'text')")
    ->execute([$convId, (int)$agent['id'], $message]);

$pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?')->execute([$convId]);

// Send via Alfonica SMS API
$smsRes = sms_send($phone, $message, $smsCreds);

$warning = null;
if ($smsRes === null) {
    $warning = 'SMS credentials not configured. Message saved but not delivered.';
} elseif ($smsRes['status'] !== 200 && $smsRes['status'] !== 201) {
    $errMsg = $smsRes['body']['message'] ?? ('HTTP ' . $smsRes['status']);
    $warning = "SMS delivery failed: {$errMsg}";
}

json_success(['conversation_id' => $convId, 'sms_warning' => $warning]);
