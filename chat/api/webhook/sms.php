<?php
/**
 * Alfonica SMS Inbound Webhook
 * POST → incoming SMS from Alfonica
 *
 * Configure in Alfonica dashboard as:
 *   https://your-domain.com/chat/api/webhook/sms.php
 */

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/webhook_error.log');

set_exception_handler(function (Throwable $e) {
    error_log('SMS webhook uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(200);
    exit;
});

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';

// ── Log every raw request for debugging ─────────────────────
$rawBody = file_get_contents('php://input');
$logLine = '[' . date('Y-m-d H:i:s') . '] '
    . $_SERVER['REQUEST_METHOD'] . ' '
    . 'CONTENT_TYPE=' . ($_SERVER['CONTENT_TYPE'] ?? '') . ' '
    . 'body=' . $rawBody
    . PHP_EOL;
file_put_contents(__DIR__ . '/sms_webhook.log', $logLine, FILE_APPEND | LOCK_EX);

http_response_code(200);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;
}

if (get_setting('sms_enabled') !== '1') {
    echo json_encode(['status' => 'disabled']);
    exit;
}

$pdo = db();

// ── Parse payload — handle JSON or form-encoded ───────────────
$data = [];
$ct   = strtolower($_SERVER['CONTENT_TYPE'] ?? '');

if (str_contains($ct, 'application/json') || str_starts_with($rawBody, '{')) {
    $data = json_decode($rawBody, true) ?? [];
} else {
    parse_str($rawBody, $data);
    if (empty($data)) $data = $_POST;
}

// ── Extract fields ────────────────────────────────────────────
// Structure A: { sender, receiver, message, msgid, date }      (Alfonica)
// Structure B: { data: { sender, receiver, message } }          (Alfonica nested)
// Structure C: { from, to, text }                               (generic)
// Structure D: From, To, Body, MessageSid, numMedia             (Twilio-style)
$inner = $data['data'] ?? $data;

$sender  = $inner['From']     ?? $inner['sender']   ?? $inner['from']      ?? $inner['msisdn']    ?? '';
$to      = $inner['To']       ?? $inner['receiver'] ?? $inner['to']         ?? $inner['to_number'] ?? '';
$message = $inner['Body']     ?? $inner['message']  ?? $inner['text']       ?? $inner['msg']       ?? '';
$msgId   = $inner['MessageSid'] ?? $inner['msgid']  ?? $inner['message_id'] ?? $inner['id']        ?? '';
$ts      = $inner['date']     ?? $inner['timestamp']  ?? $inner['time']      ?? '';

// Normalise sender: digits only
$sender = preg_replace('/[^\d]/', '', $sender);

if (!$sender || !$message) {
    echo json_encode(['status' => 'ignored', 'reason' => 'missing sender or message']);
    exit;
}

// ── Deduplicate ───────────────────────────────────────────────
if ($msgId) {
    $stmt = $pdo->prepare('SELECT id FROM messages WHERE wa_message_id = ?');
    $stmt->execute(['sms_' . $msgId]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'duplicate']);
        exit;
    }
}

// ── Get or create contact ─────────────────────────────────────
$contact   = find_or_create_contact($pdo, $sender, null, 'sms');
$contactId = (int)$contact['id'];

// ── Match sender ID to sms_account ───────────────────────────
$smsAccountId = null;
if ($to) {
    $senderIdClean = preg_replace('/[^\w]/', '', $to);
    $stmt = $pdo->prepare('SELECT id FROM sms_accounts WHERE sender_id = ? AND is_enabled = 1 LIMIT 1');
    $stmt->execute([$senderIdClean]);
    $acc = $stmt->fetch();
    if ($acc) $smsAccountId = (int)$acc['id'];
}
// Fall back to first enabled account
if (!$smsAccountId) {
    $stmt = $pdo->prepare('SELECT id FROM sms_accounts WHERE is_enabled = 1 ORDER BY id LIMIT 1');
    $stmt->execute();
    $acc = $stmt->fetch();
    if ($acc) $smsAccountId = (int)$acc['id'];
}

// ── Conversation + message (transactional) ───────────────────
$convId = 0;
$isNew  = false;

$pdo->beginTransaction();
try {
    $convQuery  = "SELECT * FROM conversations WHERE contact_id = ? AND channel = 'sms'";
    $convParams = [$contactId];
    if ($smsAccountId) {
        $convQuery  .= ' AND (sms_account_id = ? OR sms_account_id IS NULL)';
        $convParams[] = $smsAccountId;
    }
    $convQuery .= ' ORDER BY updated_at DESC LIMIT 1';
    $stmt = $pdo->prepare($convQuery);
    $stmt->execute($convParams);
    $conv = $stmt->fetch();

    if (!$conv) {
        $pdo->prepare("INSERT INTO conversations (contact_id, channel, sms_account_id, status, unread_agent) VALUES (?, 'sms', ?, 'open', 1)")
            ->execute([$contactId, $smsAccountId]);
        $convId = (int)$pdo->lastInsertId();
        $isNew  = true;
    } elseif ($conv['status'] === 'closed') {
        $convId = (int)$conv['id'];
        $pdo->prepare("UPDATE conversations SET status = 'open', assigned_agent_id = NULL, dept_id = NULL, sms_account_id = COALESCE(sms_account_id, ?), unread_agent = unread_agent + 1, updated_at = NOW() WHERE id = ?")
            ->execute([$smsAccountId, $convId]);
        $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?, 'system', 'Conversation reopened by contact', 'system')")
            ->execute([$convId]);
        $isNew = true;
    } elseif ($conv['status'] === 'pending') {
        // First reply to an agent-initiated outbound SMS — make it visible
        $convId = (int)$conv['id'];
        $pdo->prepare("UPDATE conversations SET status = 'open', sms_account_id = COALESCE(sms_account_id, ?), unread_agent = unread_agent + 1, updated_at = NOW() WHERE id = ?")
            ->execute([$smsAccountId, $convId]);
        $isNew = true;
    } else {
        $convId = (int)$conv['id'];
        if ($smsAccountId && !$conv['sms_account_id']) {
            $pdo->prepare('UPDATE conversations SET sms_account_id = ? WHERE id = ?')->execute([$smsAccountId, $convId]);
        }
    }

    // Insert message
    $created = $ts ? date('Y-m-d H:i:s', is_numeric($ts) ? (int)$ts : strtotime($ts)) : date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type, wa_message_id, created_at) VALUES (?, 'visitor', ?, 'text', ?, ?)")
        ->execute([$convId, $message, $msgId ? 'sms_' . $msgId : null, $created]);

    $pdo->prepare('UPDATE conversations SET updated_at = NOW(), unread_agent = unread_agent + 1 WHERE id = ?')
        ->execute([$convId]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    error_log('SMS incoming msg transaction failed for sender=' . $sender . ': ' . $e->getMessage());
    echo json_encode(['status' => 'error']);
    exit;
}

if ($isNew) {
    notify_new_conversation(['id' => $convId, 'channel' => 'sms', 'page_url' => ''], $contact);
}

echo json_encode(['status' => 'ok']);
exit;
