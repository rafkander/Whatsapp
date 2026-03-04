<?php
/**
 * POST /api/widget/init.php
 * Create or resume a visitor session → returns conv_id + settings
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body     = request_body();
$uid      = trim($body['uid'] ?? '');
$name     = trim($body['name'] ?? '');
$email    = trim($body['email'] ?? '');
$phone    = trim($body['phone'] ?? '');
$deptId   = !empty($body['dept_id']) ? (int)$body['dept_id'] : null;
$pageUrl  = trim($body['page_url'] ?? '');
$convId   = !empty($body['conv_id']) ? (int)$body['conv_id'] : null;

// Generate UID if not provided
if (!$uid) {
    $uid = bin2hex(random_bytes(16));
}

$pdo     = db();
$contact = get_or_create_contact($uid, compact('name', 'email', 'phone'));

// Try to resume existing open conversation
$conv = null;
if ($convId) {
    $stmt = $pdo->prepare("SELECT * FROM conversations WHERE id = ? AND contact_id = ? AND channel = 'widget'");
    $stmt->execute([$convId, $contact['id']]);
    $conv = $stmt->fetch();
}

// Or find latest widget conversation for this contact (open or closed — closed convs reopen when visitor sends a message)
if (!$conv) {
    $stmt = $pdo->prepare("SELECT * FROM conversations WHERE contact_id = ? AND channel = 'widget' ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([$contact['id']]);
    $conv = $stmt->fetch();
}

// Create new conversation if none found — no pre-chat form required, bot handles routing
$isNew = false;
if (!$conv) {
    $pdo->prepare("INSERT INTO conversations (contact_id, channel, status, page_url, unread_agent) VALUES (?, 'widget', 'open', ?, 1)")
        ->execute([$contact['id'], $pageUrl]);
    $convId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT * FROM conversations WHERE id = ?');
    $stmt->execute([$convId]);
    $conv  = $stmt->fetch();
    $isNew = true;

    notify_new_conversation($conv, $contact);

    // Run widget bot — sends greeting + dept selection buttons
    try {
        require_once dirname(__DIR__) . '/bot/widget.php';
        wb_bot_process($pdo, $convId, '', null);
    } catch (\Throwable $e) {
        error_log('widget_bot init error: ' . $e->getMessage());
    }
}

// Fetch departments
$depts = $pdo->query('SELECT id, name, color FROM departments WHERE is_active = 1 ORDER BY sort_order ASC')->fetchAll();

// Fetch settings for widget
$settings = [
    'color'       => get_setting('widget_color', '#2563eb'),
    'greeting'    => get_setting('widget_greeting', 'Hi! How can we help you today?'),
    'position'    => get_setting('widget_position', 'bottom-right'),
    'name'        => get_setting('widget_name', 'Support Chat'),
    'avatar'      => get_setting('widget_avatar', ''),
    'welcome_delay' => (int)get_setting('welcome_trigger_delay', 5),
];

// ── Bitrix24 deferred lookup ───────────────────────────────────
// Decide whether a lookup is needed BEFORE flushing the response.
$b24Enabled = (bool)(int)get_setting('bitrix24_enabled', '0');
$doB24Lookup = false;
if ($b24Enabled && ($contact['phone'] || $contact['email'])) {
    $ttl     = (int)get_setting('bitrix24_cache_ttl', '3600');
    $synced  = $contact['bitrix24_synced_at'] ?? null;
    $expired = !$synced || (time() - strtotime($synced) > $ttl);
    $doB24Lookup = $expired;
}

// Send response to browser first
$responsePayload = json_encode(['success' => true,
    'uid'          => $uid,
    'conv_id'      => $conv ? (int)$conv['id'] : null,
    'is_new'       => $isNew,
    'contact'      => ['name' => $contact['name'], 'email' => $contact['email']],
    'departments'  => $depts,
    'settings'     => $settings,
    'agents_online' => any_agent_online() && is_within_business_hours(),
]);
http_response_code(200);
header('Content-Length: ' . strlen($responsePayload));
echo $responsePayload;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level()) { ob_end_flush(); }
    flush();
}

// ── Background: Bitrix24 lookup (runs after response sent) ───
if ($doB24Lookup) {
    try {
        $b24result = bitrix24_lookup($contact['phone'] ?? null, $contact['email'] ?? null);
        bitrix24_cache_contact((int)$contact['id'], $b24result);
    } catch (\Throwable $e) {
        error_log('bitrix24 lookup error: ' . $e->getMessage());
    }
}
exit;
