<?php
/**
 * GET /api/agent/sms_inbox.php
 * Polls Alfonica API for new inbound SMS messages and stores them.
 * Called by the dashboard every ~15 seconds.
 * Returns { new_messages: int }
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed', 405);

require_agent();

if (get_setting('sms_enabled') !== '1') {
    json_success(['new_messages' => 0]);
}

$token = get_setting('alfonica_sms_token');
if (!$token) {
    json_success(['new_messages' => 0]);
}

$pdo = db();

// Fetch latest messages from Alfonica (page 1, most recent 25)
$ch = curl_init('https://messenger.alfonica.com/api/v3/sms?page=1');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        "Authorization: Bearer {$token}",
    ],
    CURLOPT_TIMEOUT => 10,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($code !== 200 || !$res) {
    json_success(['new_messages' => 0, 'error' => "Alfonica returned HTTP {$code}"]);
}

// Log raw response so we can inspect the actual field structure
$logFile = dirname(__DIR__, 2) . '/sms_raw.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " HTTP {$code}\n" . $res . "\n\n", FILE_APPEND);

$payload  = json_decode($res, true);

// Support both response shapes: data.data (paginated) and data (flat array)
$messages = $payload['data']['data'] ?? $payload['data'] ?? [];
if (!is_array($messages)) $messages = [];

if (empty($messages)) {
    json_success(['new_messages' => 0]);
}

$newCount = 0;

foreach ($messages as $sms) {
    // Accept any key variants Alfonica might use
    $uid     = $sms['uid']       ?? $sms['id']        ?? '';
    $from    = $sms['from']      ?? $sms['sender']     ?? $sms['from_number'] ?? '';
    $to      = $sms['to']        ?? $sms['recipient']  ?? $sms['to_number']   ?? '';
    $message = $sms['message']   ?? $sms['body']       ?? $sms['text']        ?? '';
    $status  = $sms['status']    ?? '';
    $dir     = $sms['direction'] ?? $sms['type']       ?? '';

    if (!$uid || !$message) continue;

    // Skip outbound:
    // - direction/type field explicitly says outbound/MT
    if (stripos($dir, 'outbound') !== false || stripos($dir, 'MT') !== false) continue;
    // - status starts with "Sent|" (MT marker)
    if (preg_match('/^Sent\|/i', $status)) continue;
    // - "from" is one of our alphanumeric sender IDs (not a phone number)
    //   A real inbound "from" is always digits (with optional leading +)
    $fromDigits = preg_replace('/[^\d]/', '', $from);
    if (!$fromDigits) continue;                   // empty after stripping → skip
    if (strlen($fromDigits) < 7) continue;        // too short to be a phone number → skip
    // If "direction" explicitly says inbound, allow even if "from" looks odd
    $isExplicitInbound = preg_match('/inbound|MO/i', $dir);
    if (!$isExplicitInbound && !ctype_digit(ltrim($from, '+'))) continue;

    $fromClean = normalize_phone($from);

    // Deduplicate by uid
    $stmt = $pdo->prepare("SELECT id FROM messages WHERE wa_message_id = ?");
    $stmt->execute(['sms_' . $uid]);
    if ($stmt->fetch()) continue;

    // Resolve which sms_account this came in on (match by sender_id = $to)
    $smsAccountId = null;
    if ($to) {
        $toClean = preg_replace('/[^\d]/', '', $to);
        // Try exact match first, then digits-only match
        $stmt = $pdo->prepare('SELECT id FROM sms_accounts WHERE (sender_id = ? OR sender_id = ?) AND is_enabled = 1 LIMIT 1');
        $stmt->execute([$to, $toClean]);
        $acc = $stmt->fetch();
        if ($acc) $smsAccountId = (int)$acc['id'];
    }
    if (!$smsAccountId) {
        $stmt = $pdo->prepare('SELECT id FROM sms_accounts WHERE is_enabled = 1 ORDER BY id LIMIT 1');
        $stmt->execute();
        $acc = $stmt->fetch();
        if ($acc) $smsAccountId = (int)$acc['id'];
    }

    // Get or create contact — cross-channel dedup
    $contact   = find_or_create_contact($pdo, $fromClean, null, 'sms');
    $contactId = (int)$contact['id'];

    // Find or create conversation
    $stmt = $pdo->prepare("SELECT * FROM conversations WHERE contact_id = ? AND channel = 'sms' ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([$contactId]);
    $conv = $stmt->fetch();

    $isNew = false;
    if (!$conv) {
        $pdo->prepare("INSERT INTO conversations (contact_id, channel, sms_account_id, status, unread_agent) VALUES (?, 'sms', ?, 'open', 1)")
            ->execute([$contactId, $smsAccountId]);
        $convId = (int)$pdo->lastInsertId();
        $isNew  = true;
    } elseif ($conv['status'] === 'closed') {
        $convId = (int)$conv['id'];
        $pdo->prepare("UPDATE conversations SET status='open', assigned_agent_id=NULL, dept_id=NULL, sms_account_id=COALESCE(sms_account_id,?), unread_agent=unread_agent+1, updated_at=NOW() WHERE id=?")
            ->execute([$smsAccountId, $convId]);
        $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?,'system','Conversation reopened by contact','system')")
            ->execute([$convId]);
        $isNew = true;
    } else {
        $convId = (int)$conv['id'];
        $pdo->prepare('UPDATE conversations SET updated_at=NOW(), unread_agent=unread_agent+1 WHERE id=?')
            ->execute([$convId]);
    }

    // Insert message
    $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type, wa_message_id) VALUES (?, 'visitor', ?, 'text', ?)")
        ->execute([$convId, $message, 'sms_' . $uid]);

    if ($isNew) {
        notify_new_conversation(['id' => $convId, 'channel' => 'sms', 'page_url' => ''], $contact);
    }

    $newCount++;
}

json_success(['new_messages' => $newCount]);
