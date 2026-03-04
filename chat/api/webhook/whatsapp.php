<?php
/**
 * WhatsApp Cloud API Webhook
 * GET  → verification challenge
 * POST → incoming messages / status updates
 */

// Log all PHP errors to a file next to this script for easy diagnosis
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/webhook_error.log');

set_exception_handler(function (Throwable $e) {
    error_log('WA webhook uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(200); // Always 200 to WhatsApp
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    exit;
});

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

$pdo = db();

// ── GET: Webhook Verification ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verifyToken = defined('WA_VERIFY_TOKEN') && WA_VERIFY_TOKEN ? WA_VERIFY_TOKEN : get_setting('wa_verify_token');

    $mode      = $_GET['hub_mode']          ?? '';
    $token     = $_GET['hub_verify_token']  ?? '';
    $challenge = $_GET['hub_challenge']     ?? '';

    if ($mode === 'subscribe' && hash_equals((string)$verifyToken, $token)) {
        http_response_code(200);
        echo $challenge;
    } else {
        http_response_code(403);
        echo 'Forbidden';
    }
    exit;
}

// ── POST: Incoming Webhook ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Gate: bail early if WA integration is disabled
if (get_setting('wa_enabled') !== '1') {
    http_response_code(200);
    echo json_encode(['status' => 'disabled']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(200); // Always 200 to WA
    exit;
}

$object = $data['object'] ?? '';
if ($object !== 'whatsapp_business_account') {
    http_response_code(200);
    exit;
}

foreach ($data['entry'] ?? [] as $entry) {
    foreach ($entry['changes'] ?? [] as $change) {
        $value = $change['value'] ?? [];

        // ── Incoming Messages ─────────────────────────────────
        foreach ($value['messages'] ?? [] as $msg) {
            handle_incoming_message($pdo, $msg, $value['contacts'] ?? []);
        }

        // ── Status Updates ────────────────────────────────────
        foreach ($value['statuses'] ?? [] as $status) {
            handle_status_update($pdo, $status);
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
exit;

// ─────────────────────────────────────────────────────────────

function handle_incoming_message(PDO $pdo, array $msg, array $waContacts): void {
    $waId    = $msg['id']   ?? '';
    $from    = $msg['from'] ?? ''; // phone number e.g. 447911123456
    $type    = $msg['type'] ?? 'text';
    $ts      = $msg['timestamp'] ?? time();

    if (!$from) return;

    // Deduplicate
    $stmt = $pdo->prepare('SELECT id FROM messages WHERE wa_message_id = ?');
    $stmt->execute([$waId]);
    if ($stmt->fetch()) return;

    // Get or create contact
    $waContactName = '';
    foreach ($waContacts as $wc) {
        if (($wc['wa_id'] ?? '') === $from) {
            $waContactName = $wc['profile']['name'] ?? '';
            break;
        }
    }

    $uid = 'wa_' . $from;

    // Check if contact exists by whatsapp_number
    $stmt = $pdo->prepare('SELECT * FROM contacts WHERE whatsapp_number = ?');
    $stmt->execute([$from]);
    $contact = $stmt->fetch();

    if (!$contact) {
        $pdo->prepare('INSERT INTO contacts (uid, name, whatsapp_number, ip) VALUES (?, ?, ?, NULL)')
            ->execute([$uid, $waContactName ?: $from, $from]);
        $contactId = (int)$pdo->lastInsertId();
        $stmt->execute([$from]);
        $contact = $stmt->fetch();
    } else {
        $contactId = (int)$contact['id'];
        // Update name if we got one
        if ($waContactName && !$contact['name']) {
            $pdo->prepare('UPDATE contacts SET name = ? WHERE id = ?')->execute([$waContactName, $contactId]);
        }
    }

    // Find open conversation, or fall back to most recent closed one to reopen
    $stmt = $pdo->prepare("SELECT * FROM conversations WHERE contact_id = ? AND channel = 'whatsapp' ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([$contactId]);
    $conv = $stmt->fetch();

    if (!$conv) {
        // No conversation at all — create one
        $pdo->prepare("INSERT INTO conversations (contact_id, channel, status, unread_agent) VALUES (?, 'whatsapp', 'open', 1)")
            ->execute([$contactId]);
        $convId = (int)$pdo->lastInsertId();
        $isNew  = true;
    } elseif ($conv['status'] === 'closed') {
        // Reopen the closed conversation, unassign it so it lands in the unassigned queue
        $convId = (int)$conv['id'];
        $pdo->prepare("UPDATE conversations SET status = 'open', assigned_agent_id = NULL, bot_state = NULL, bot_data = NULL, unread_agent = unread_agent + 1, updated_at = NOW() WHERE id = ?")
            ->execute([$convId]);
        $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?, 'system', 'Conversation reopened by contact', 'system')")
            ->execute([$convId]);
        $isNew = true; // treat reopen as new so the bot restarts the greeting flow
    } else {
        $convId = (int)$conv['id'];
        $isNew  = false;
    }

    // Process message content
    $content       = '';
    $msgType       = 'text';
    $fileUrl       = null;
    $fileName      = null;
    $interactiveId = null;

    switch ($type) {
        case 'text':
            $content = $msg['text']['body'] ?? '';
            break;

        case 'interactive':
            $iType = $msg['interactive']['type'] ?? '';
            if ($iType === 'button_reply') {
                $interactiveId = $msg['interactive']['button_reply']['id']    ?? null;
                $content       = $msg['interactive']['button_reply']['title'] ?? '';
            } elseif ($iType === 'list_reply') {
                $interactiveId = $msg['interactive']['list_reply']['id']    ?? null;
                $content       = $msg['interactive']['list_reply']['title'] ?? '';
            }
            break;

        case 'image':
        case 'document':
        case 'audio':
        case 'video':
            $mediaId  = $msg[$type]['id'] ?? null;
            $caption  = $msg[$type]['caption'] ?? null;
            $filename = $msg[$type]['filename'] ?? ($type . '_' . $waId);
            $msgType  = in_array($type, ['image']) ? 'image' : 'file';
            $content  = $caption ?: $filename;
            $fileName = $filename;

            if ($mediaId) {
                $fileUrl = wa_download_media($mediaId);
            }
            break;

        case 'location':
            $lat  = $msg['location']['latitude']  ?? '';
            $lng  = $msg['location']['longitude'] ?? '';
            $content = "Location: {$lat}, {$lng}";
            break;

        case 'sticker':
            $content = '[Sticker]';
            break;

        default:
            $content = "[{$type} message]";
    }

    // Insert message
    $created = date('Y-m-d H:i:s', (int)$ts);
    $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type, file_url, file_name, wa_message_id, created_at) VALUES (?, 'visitor', ?, ?, ?, ?, ?, ?)")
        ->execute([$convId, $content, $msgType, $fileUrl, $fileName, $waId, $created]);

    // Update conversation
    $pdo->prepare('UPDATE conversations SET updated_at = NOW(), unread_agent = unread_agent + 1 WHERE id = ?')
        ->execute([$convId]);

    if ($isNew) {
        notify_new_conversation(['id' => $convId, 'channel' => 'whatsapp', 'page_url' => ''], $contact);
    }

    // ── Bot: run state machine if enabled and not already assigned ──
    $botState        = $isNew ? 'start' : ($conv['bot_state'] ?? null);
    $alreadyAssigned = !$isNew && !empty($conv['assigned_agent_id']);

    if (get_setting('wa_bot_enabled', '1') === '1'
        && $botState !== null
        && $botState !== 'done'
        && !$alreadyAssigned
    ) {
        require_once dirname(__DIR__) . '/bot/whatsapp.php';
        wa_bot_process($pdo, $convId, $from, $content, $interactiveId);
    }
}

function handle_status_update(PDO $pdo, array $status): void {
    $waId     = $status['id']     ?? '';
    $statusV  = $status['status'] ?? ''; // sent, delivered, read, failed

    if (!$waId) return;

    if ($statusV === 'read') {
        $pdo->prepare('UPDATE messages SET read_at = NOW() WHERE wa_message_id = ? AND read_at IS NULL')
            ->execute([$waId]);
    }
}
