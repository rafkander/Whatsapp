<?php
/**
 * WhatsApp Cloud API Webhook
 * GET  → verification challenge
 * POST → incoming messages / status updates
 *
 * Supports multiple WhatsApp accounts (wa_accounts table).
 * Each incoming change is matched to an account by phone_number_id.
 */

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/webhook_error.log');

set_exception_handler(function (Throwable $e) {
    error_log('WA webhook uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(200); // Return 200 to Meta so it doesn't keep retrying
    echo json_encode(['status' => 'error']);
    exit;
});

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';

// ── Raw request log (debug) ───────────────────────────────────
$_rawLog = __DIR__ . '/webhook_requests.log';
$_logLine = '[' . date('Y-m-d H:i:s') . '] '
    . $_SERVER['REQUEST_METHOD'] . ' '
    . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '')
    . ' body=' . substr(file_get_contents('php://input'), 0, 300)
    . PHP_EOL;
file_put_contents($_rawLog, $_logLine, FILE_APPEND | LOCK_EX);
unset($_rawLog, $_logLine);

header('Content-Type: application/json');

$pdo = db();

// ── GET: Webhook Verification ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? '';

    if ($mode !== 'subscribe') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    // Check against all enabled accounts' verify tokens
    $stmt = $pdo->prepare('SELECT verify_token FROM wa_accounts WHERE is_enabled = 1');
    $stmt->execute();
    $verifyTokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Also accept the legacy global verify token
    $legacyToken = get_setting('wa_verify_token');
    if ($legacyToken) $verifyTokens[] = $legacyToken;

    $verified = false;
    foreach ($verifyTokens as $vt) {
        if ($vt && hash_equals((string)$vt, $token)) {
            $verified = true;
            break;
        }
    }

    if ($verified) {
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

// Gate: bail if WA integration is globally disabled AND no accounts are enabled
$anyAccountEnabled = (bool)$pdo->query('SELECT COUNT(*) FROM wa_accounts WHERE is_enabled = 1')->fetchColumn();
if (!$anyAccountEnabled && get_setting('wa_enabled') !== '1') {
    http_response_code(200);
    echo json_encode(['status' => 'disabled']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(200);
    exit;
}

if (($data['object'] ?? '') !== 'whatsapp_business_account') {
    http_response_code(200);
    exit;
}

foreach ($data['entry'] ?? [] as $entry) {
    foreach ($entry['changes'] ?? [] as $change) {
        $value = $change['value'] ?? [];

        // Identify which account this message came in on
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        $account       = null;
        if ($phoneNumberId) {
            $stmt = $pdo->prepare('SELECT * FROM wa_accounts WHERE phone_number_id = ? AND is_enabled = 1');
            $stmt->execute([$phoneNumberId]);
            $account = $stmt->fetch() ?: null;
        }

        $creds     = $account ? ['phone_number_id' => $account['phone_number_id'], 'access_token' => $account['access_token']] : null;
        $accountId = $account ? (int)$account['id'] : null;
        $botFlow   = $account['bot_flow'] ?? 'standard';

        // ── Incoming Messages ─────────────────────────────────
        foreach ($value['messages'] ?? [] as $msg) {
            handle_incoming_message($pdo, $msg, $value['contacts'] ?? [], $creds, $accountId, $botFlow);
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

function handle_incoming_message(PDO $pdo, array $msg, array $waContacts, ?array $creds, ?int $waAccountId, string $botFlow): void {
    $waId = $msg['id']   ?? '';
    $from = $msg['from'] ?? '';
    $type = $msg['type'] ?? 'text';
    $ts   = $msg['timestamp'] ?? time();

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

    $contact   = find_or_create_contact($pdo, $from, $waContactName ?: null, 'whatsapp');
    $contactId = (int)$contact['id'];

    // Find open or most recent conversation — filter by account if known
    $convQuery = "SELECT * FROM conversations WHERE contact_id = ? AND channel = 'whatsapp'";
    $convParams = [$contactId];
    if ($waAccountId) {
        $convQuery .= ' AND (wa_account_id = ? OR wa_account_id IS NULL)';
        $convParams[] = $waAccountId;
    }
    $convQuery .= ' ORDER BY updated_at DESC LIMIT 1';

    $stmt = $pdo->prepare($convQuery);
    $stmt->execute($convParams);
    $conv = $stmt->fetch();

    if (!$conv) {
        $pdo->prepare("INSERT INTO conversations (contact_id, channel, wa_account_id, status, unread_agent) VALUES (?, 'whatsapp', ?, 'open', 1)")
            ->execute([$contactId, $waAccountId]);
        $convId = (int)$pdo->lastInsertId();
        $isNew  = true;
    } elseif ($conv['status'] === 'closed') {
        $convId = (int)$conv['id'];
        $pdo->prepare("UPDATE conversations SET status = 'open', assigned_agent_id = NULL, dept_id = NULL, bot_state = NULL, bot_data = NULL, wa_account_id = COALESCE(wa_account_id, ?), unread_agent = unread_agent + 1, updated_at = NOW() WHERE id = ?")
            ->execute([$waAccountId, $convId]);
        $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?, 'system', 'Conversation reopened by contact', 'system')")
            ->execute([$convId]);
        $isNew = true;
    } else {
        $convId = (int)$conv['id'];
        // Update account_id if it wasn't set yet
        if ($waAccountId && !$conv['wa_account_id']) {
            $pdo->prepare('UPDATE conversations SET wa_account_id = ? WHERE id = ?')->execute([$waAccountId, $convId]);
        }
        $isNew = false;
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
                $fileUrl = wa_download_media($mediaId, $creds);
            }
            break;

        case 'location':
            $lat     = $msg['location']['latitude']  ?? '';
            $lng     = $msg['location']['longitude'] ?? '';
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

    $pdo->prepare('UPDATE conversations SET updated_at = NOW(), unread_agent = unread_agent + 1 WHERE id = ?')
        ->execute([$convId]);

    if ($isNew) {
        notify_new_conversation(['id' => $convId, 'channel' => 'whatsapp', 'page_url' => ''], $contact);
    }

    // ── Bot ──────────────────────────────────────────────────
    $botState        = $isNew ? 'start' : ($conv['bot_state'] ?? null);
    $alreadyAssigned = !$isNew && !empty($conv['assigned_agent_id']);

    if (get_setting('wa_bot_enabled', '1') === '1'
        && $botState !== null
        && $botState !== 'done'
        && !$alreadyAssigned
    ) {
        require_once dirname(__DIR__) . '/bot/whatsapp.php';
        wa_bot_process($pdo, $convId, $from, $content, $interactiveId, $creds, $botFlow);
    }
}

function handle_status_update(PDO $pdo, array $status): void {
    $waId    = $status['id']     ?? '';
    $statusV = $status['status'] ?? '';

    if (!$waId) return;

    if ($statusV === 'read') {
        $pdo->prepare('UPDATE messages SET read_at = NOW() WHERE wa_message_id = ? AND read_at IS NULL')
            ->execute([$waId]);
    }
}
