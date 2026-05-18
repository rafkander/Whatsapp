<?php
/**
 * Core Helpers: PDO, JWT, response helpers, auth middleware
 */

if (!defined('APP_URL')) {
    require_once dirname(__DIR__) . '/config.php';
}

// ── PDO Connection ────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ── JSON Responses ────────────────────────────────────────────
function json_success(array $data = [], int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => true] + $data);
    exit;
}

function json_error(string $message, int $code = 400, array $extra = []): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message] + $extra);
    exit;
}

// ── Request Body ─────────────────────────────────────────────
function request_body(): array {
    static $body = null;
    if ($body === null) {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];
    }
    return $body;
}

function param(string $key, $default = null) {
    $body = request_body();
    return $body[$key] ?? $_REQUEST[$key] ?? $default;
}

// ── JWT Implementation ────────────────────────────────────────
function jwt_encode(array $payload): string {
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

function jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $data = json_decode(base64url_decode($payload), true);
    if (!$data) return null;
    if (isset($data['exp']) && $data['exp'] < time()) return null;
    return $data;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

// ── Auth Middleware ───────────────────────────────────────────
// ── Role Helpers ──────────────────────────────────────────────
$ROLE_LEVELS = [
    'super_admin'  => 5,
    'admin'        => 4,
    'supervisor'   => 3,
    'senior_agent' => 2,
    'agent'        => 1,
];

function role_level(string $role): int {
    global $ROLE_LEVELS;
    return $ROLE_LEVELS[$role] ?? 0;
}

function role_label(string $role): string {
    $labels = [
        'super_admin'  => 'Super Admin',
        'admin'        => 'Admin',
        'supervisor'   => 'Supervisor',
        'senior_agent' => 'Senior Agent',
        'agent'        => 'Agent',
    ];
    return $labels[$role] ?? ucfirst($role);
}

function role_color(string $role): string {
    $colors = [
        'super_admin'  => '#7c3aed',
        'admin'        => '#c0392b',
        'supervisor'   => '#ea580c',
        'senior_agent' => '#2563eb',
        'agent'        => '#059669',
    ];
    return $colors[$role] ?? '#6b7280';
}

function require_agent(): array {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (!$authHeader && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        json_error('Unauthorized', 401);
    }

    $token = $m[1];
    $data  = jwt_decode($token);

    if (!$data || empty($data['agent_id'])) {
        json_error('Invalid or expired token', 401);
    }

    // Verify the session has not been revoked via logout
    $tokenHash = hash('sha256', $token);
    $sessStmt  = db()->prepare('SELECT id FROM agent_sessions WHERE token_hash = ? AND expires_at > NOW() LIMIT 1');
    $sessStmt->execute([$tokenHash]);
    if (!$sessStmt->fetch()) {
        json_error('Session expired or revoked', 401);
    }

    $stmt = db()->prepare('SELECT id, name, email, role, status, avatar FROM agents WHERE id = ?');
    $stmt->execute([$data['agent_id']]);
    $agent = $stmt->fetch();

    if (!$agent) {
        json_error('Agent not found', 401);
    }

    return $agent;
}

function require_admin(): array {
    $agent = require_agent();
    if (role_level($agent['role']) < role_level('admin')) {
        json_error('Admin access required', 403);
    }
    return $agent;
}

function require_supervisor(): array {
    $agent = require_agent();
    if (role_level($agent['role']) < role_level('supervisor')) {
        json_error('Supervisor access required', 403);
    }
    return $agent;
}

// ── Settings Helper ───────────────────────────────────────────
function get_setting(string $key, $default = null) {
    static $cache = [];
    if (!isset($cache[$key])) {
        $stmt = db()->prepare('SELECT value FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row ? $row['value'] : $default;
    }
    return $cache[$key];
}

function set_setting(string $key, $value): void {
    $stmt = db()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?');
    $stmt->execute([$key, $value, $value]);
}

// ── Visitor Session ───────────────────────────────────────────
function get_or_create_contact(string $uid, array $fields = []): array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM contacts WHERE uid = ?');
    $stmt->execute([$uid]);
    $contact = $stmt->fetch();

    if (!$contact) {
        $pdo->prepare('INSERT INTO contacts (uid, name, email, phone, ip, browser, os) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $uid,
                $fields['name'] ?? null,
                $fields['email'] ?? null,
                $fields['phone'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $fields['browser'] ?? detect_browser(),
                $fields['os'] ?? detect_os(),
            ]);
        $stmt->execute([$uid]);
        $contact = $stmt->fetch();
    } else {
        // Update name/email if provided
        if (!empty($fields['name']) || !empty($fields['email'])) {
            $pdo->prepare('UPDATE contacts SET name = COALESCE(?, name), email = COALESCE(?, email), phone = COALESCE(?, phone) WHERE uid = ?')
                ->execute([$fields['name'] ?? null, $fields['email'] ?? null, $fields['phone'] ?? null, $uid]);
            $stmt->execute([$uid]);
            $contact = $stmt->fetch();
        }
    }

    return $contact;
}

// ── WhatsApp API ──────────────────────────────────────────────
function wa_log(string $msg): void {
    $logFile = dirname(__DIR__) . '/wa_send.log';
    $line    = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// Resolve credentials: use provided $creds array or fall back to config constants / settings table
function wa_resolve_creds(?array $creds): array {
    if ($creds && !empty($creds['phone_number_id']) && !empty($creds['access_token'])) {
        return $creds;
    }
    $phoneId = (defined('WA_PHONE_NUMBER_ID') && WA_PHONE_NUMBER_ID) ? WA_PHONE_NUMBER_ID : get_setting('wa_phone_number_id');
    $token   = (defined('WA_ACCESS_TOKEN')    && WA_ACCESS_TOKEN)    ? WA_ACCESS_TOKEN    : get_setting('wa_access_token');
    return ['phone_number_id' => $phoneId, 'access_token' => $token];
}

// Look up credentials for the WhatsApp account linked to a conversation
function wa_creds_for_conv(PDO $pdo, int $convId): ?array {
    $stmt = $pdo->prepare('
        SELECT a.phone_number_id, a.access_token, a.name, a.bot_flow
        FROM wa_accounts a
        JOIN conversations c ON c.wa_account_id = a.id
        WHERE c.id = ?
    ');
    $stmt->execute([$convId]);
    return $stmt->fetch() ?: null;
}

function wa_send_text(string $to, string $text, ?array $creds = null): ?array {
    $c       = wa_resolve_creds($creds);
    $phoneId = $c['phone_number_id'];
    $token   = $c['access_token'];
    $version = (defined('WA_API_VERSION') && WA_API_VERSION) ? WA_API_VERSION : 'v18.0';

    if (!$phoneId || !$token) {
        wa_log("SEND FAILED — missing credentials. phoneId=" . ($phoneId ? 'set' : 'EMPTY') . " token=" . ($token ? 'set' : 'EMPTY'));
        return null;
    }

    $url = "https://graph.facebook.com/{$version}/{$phoneId}/messages";
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'   => $to,
        'type' => 'text',
        'text' => ['body' => $text],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$token}",
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);

    if ($err) {
        wa_log("CURL ERROR to={$to}: {$err}");
    } else {
        wa_log("SEND to={$to} status={$code} response=" . $res);
    }

    return ['status' => $code, 'body' => json_decode($res, true)];
}

function wa_download_media(string $mediaId, ?array $creds = null): ?string {
    $c       = wa_resolve_creds($creds);
    $token   = $c['access_token'];
    $version = (defined('WA_API_VERSION') && WA_API_VERSION) ? WA_API_VERSION : 'v18.0';

    // Get media URL
    $ch = curl_init("https://graph.facebook.com/{$version}/{$mediaId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = json_decode(curl_exec($ch), true);

    if (empty($res['url'])) return null;

    // Download file — use header inspection to detect MIME type
    $ch = curl_init($res['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw      = curl_exec($ch);
    $hdrSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $mimeType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    $fileData = substr($raw, $hdrSize);
    if (!$fileData) return null;

    // Derive extension from MIME type (Meta CDN URLs have no extension)
    $mimeMap = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'video/mp4'       => 'mp4',
        'video/3gpp'      => '3gp',
        'audio/ogg'       => 'ogg',
        'audio/mpeg'      => 'mp3',
        'audio/aac'       => 'aac',
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
    ];
    $mime = strtok($mimeType ?: '', ';');
    $ext  = $mimeMap[trim($mime)] ?? (pathinfo($res['url'], PATHINFO_EXTENSION) ?: 'bin');

    $filename = 'wa_' . $mediaId . '.' . $ext;
    $path     = UPLOAD_DIR . $filename;
    file_put_contents($path, $fileData);

    return UPLOAD_URL . $filename;
}

// ── WhatsApp — low-level API call ─────────────────────────────
function wa_api_call(string $to, array $payload, ?array $creds = null): ?array {
    $c       = wa_resolve_creds($creds);
    $phoneId = $c['phone_number_id'];
    $token   = $c['access_token'];
    $version = (defined('WA_API_VERSION') && WA_API_VERSION) ? WA_API_VERSION : 'v18.0';

    if (!$phoneId || !$token) return null;

    $url  = "https://graph.facebook.com/{$version}/{$phoneId}/messages";
    $body = array_merge(['messaging_product' => 'whatsapp', 'to' => $to], $payload);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$token}",
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return ['status' => $code, 'body' => json_decode($res, true)];
}

// ── WhatsApp — interactive button message (max 3 buttons) ────
function wa_send_buttons(string $to, string $body, array $buttons, ?array $creds = null): ?array {
    $btns = [];
    foreach (array_slice($buttons, 0, 3) as $btn) {
        $btns[] = [
            'type'  => 'reply',
            'reply' => [
                'id'    => $btn['id'],
                'title' => mb_substr($btn['title'], 0, 20),
            ],
        ];
    }
    return wa_api_call($to, [
        'type'        => 'interactive',
        'interactive' => [
            'type' => 'button',
            'body' => ['text' => $body],
            'action' => ['buttons' => $btns],
        ],
    ], $creds);
}

// ── WhatsApp — interactive list message (max 10 rows) ────────
function wa_send_list(string $to, string $body, string $btnLabel, array $rows, ?array $creds = null): ?array {
    $rowItems = [];
    foreach (array_slice($rows, 0, 10) as $row) {
        $rowItems[] = [
            'id'          => $row['id'],
            'title'       => mb_substr($row['title'], 0, 24),
            'description' => $row['description'] ?? '',
        ];
    }
    return wa_api_call($to, [
        'type'        => 'interactive',
        'interactive' => [
            'type' => 'list',
            'body' => ['text' => $body],
            'action' => [
                'button'   => mb_substr($btnLabel, 0, 20),
                'sections' => [[
                    'title' => 'Options',
                    'rows'  => $rowItems,
                ]],
            ],
        ],
    ], $creds);
}

// ── WhatsApp — send media (image/document/video/audio) ────────
function wa_send_media(string $to, string $type, string $url, ?string $caption = null, ?string $filename = null, ?array $creds = null): ?array {
    $media = ['link' => $url];
    if ($caption)  $media['caption']  = $caption;
    if ($filename) $media['filename'] = $filename;

    return wa_api_call($to, [
        'type'  => $type,
        $type   => $media,
    ], $creds);
}

// ── Alfonica SMS API ──────────────────────────────────────────
function sms_log(string $msg): void {
    $logFile = dirname(__DIR__) . '/sms_send.log';
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Normalize a phone number to full international digits (no + or spaces).
 * Handles UK local format: 07XXXXXXXXX → 447XXXXXXXXX
 */
function normalize_phone(string $phone): string {
    $digits = preg_replace('/[^\d]/', '', $phone);
    // UK local: 11 digits starting with 0  (e.g. 07385297011 → 447385297011)
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = '44' . substr($digits, 1);
    }
    // UK short: 10 digits starting with 7 (e.g. 7385297011 → 447385297011)
    if (strlen($digits) === 10 && $digits[0] === '7') {
        $digits = '44' . $digits;
    }
    return $digits;
}

function sms_creds_for_conv(PDO $pdo, int $convId): ?array {
    $stmt = $pdo->prepare('
        SELECT s.sender_id, s.name
        FROM sms_accounts s
        JOIN conversations c ON c.sms_account_id = s.id
        WHERE c.id = ?
    ');
    $stmt->execute([$convId]);
    return $stmt->fetch() ?: null;
}

function sms_send(string $to, string $message, ?array $creds = null): ?array {
    $token    = get_setting('alfonica_sms_token');
    $senderId = $creds['sender_id'] ?? null;

    if (!$token || !$senderId) {
        sms_log("SEND FAILED — missing credentials (token=" . ($token ? 'set' : 'EMPTY') . " sender_id=" . ($senderId ?: 'EMPTY') . ")");
        return null;
    }

    $to = normalize_phone($to);

    $url     = 'https://messenger.alfonica.com/api/v3/sms/send';
    $payload = [
        'recipient' => $to,
        'sender_id' => $senderId,
        'type'      => 'plain',
        'message'   => $message,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: Bearer {$token}",
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);

    if ($err) {
        sms_log("CURL ERROR to={$to}: {$err}");
    } else {
        sms_log("SEND to={$to} status={$code} response=" . $res);
    }

    return ['status' => $code, 'body' => json_decode($res, true)];
}

// ── User Agent Detection ──────────────────────────────────────
function detect_browser(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $k => $v) {
        if (strpos($ua, $k) !== false) return $v;
    }
    return 'Unknown';
}

function detect_os(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    foreach (['Windows' => 'Windows', 'Mac' => 'macOS', 'Linux' => 'Linux', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iOS'] as $k => $v) {
        if (strpos($ua, $k) !== false) return $v;
    }
    return 'Unknown';
}

// ── Business Hours Check ──────────────────────────────────────
function is_within_business_hours(): bool {
    if (!get_setting('business_hours_enabled')) return true;

    $hours = json_decode(get_setting('business_hours', '{}'), true);
    $day   = strtolower(date('D')); // mon, tue, ...
    $now   = date('H:i');

    // Map PHP date('D') to our keys
    $dayMap = ['Mon' => 'mon', 'Tue' => 'tue', 'Wed' => 'wed', 'Thu' => 'thu', 'Fri' => 'fri', 'Sat' => 'sat', 'Sun' => 'sun'];
    $key    = $dayMap[date('D')] ?? strtolower(date('D'));

    $todayHours = $hours[$key] ?? null;
    if (!$todayHours || empty($todayHours['enabled'])) return false;

    return ($now >= $todayHours['open'] && $now <= $todayHours['close']);
}

// ── Cross-channel contact lookup / dedup ─────────────────────
/**
 * Find an existing contact by phone number across ALL number fields
 * (whatsapp_number, sms_number, phone), normalising +44 / 0 / bare variants.
 * If found, stamps the channel-specific field if it wasn't set.
 * If not found, inserts a new contact and returns it.
 *
 * @param string $number  Raw number as received (e.g. "447385297011" or "+447385297011")
 * @param string|null $name  Display name (used only on creation / if existing has none)
 * @param string $channel  'whatsapp' | 'sms'
 */
function find_or_create_contact(PDO $pdo, string $number, ?string $name, string $channel): array {
    $stripped   = ltrim(preg_replace('/\s+/', '', $number), '+');   // 447385297011
    $plus       = '+' . $stripped;                                   // +447385297011
    $uk_local   = preg_replace('/^44/', '0', $stripped);            // 07385297011

    $stmt = $pdo->prepare("
        SELECT * FROM contacts
        WHERE whatsapp_number IN (?,?,?)
           OR sms_number       IN (?,?,?)
           OR phone            IN (?,?,?)
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([$stripped, $plus, $uk_local,
                    $stripped, $plus, $uk_local,
                    $stripped, $plus, $uk_local]);
    $contact = $stmt->fetch();

    if ($contact) {
        $contactId = (int)$contact['id'];
        $updates   = [];
        $params    = [];

        if ($channel === 'whatsapp' && !$contact['whatsapp_number']) {
            $updates[] = 'whatsapp_number = ?';
            $params[]  = $stripped;
        }
        if ($channel === 'sms' && !$contact['sms_number']) {
            $updates[] = 'sms_number = ?';
            $params[]  = $stripped;
        }
        if ($name && (!$contact['name'] || $contact['name'] === $plus || $contact['name'] === '+' . $stripped)) {
            $updates[] = 'name = ?';
            $params[]  = $name;
        }
        if ($updates) {
            $params[] = $contactId;
            $pdo->prepare('UPDATE contacts SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
        }
        $stmt2 = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
        $stmt2->execute([$contactId]);
        return $stmt2->fetch();
    }

    // Create new contact
    $displayName = $name ?: $plus;
    $uid = $channel . '_' . $stripped;
    try {
        if ($channel === 'whatsapp') {
            $pdo->prepare('INSERT INTO contacts (uid, name, whatsapp_number, ip) VALUES (?, ?, ?, NULL)')
                ->execute([$uid, $displayName, $stripped]);
        } else {
            $pdo->prepare('INSERT INTO contacts (uid, name, sms_number, phone, ip) VALUES (?, ?, ?, ?, NULL)')
                ->execute([$uid, $displayName, $stripped, $plus]);
        }
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            $uid = $channel . '_' . $stripped . '_' . time();
            if ($channel === 'whatsapp') {
                $pdo->prepare('INSERT INTO contacts (uid, name, whatsapp_number, ip) VALUES (?, ?, ?, NULL)')
                    ->execute([$uid, $displayName, $stripped]);
            } else {
                $pdo->prepare('INSERT INTO contacts (uid, name, sms_number, phone, ip) VALUES (?, ?, ?, ?, NULL)')
                    ->execute([$uid, $displayName, $stripped, $plus]);
            }
        } else { throw $e; }
    }
    $stmt3 = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
    $stmt3->execute([$pdo->lastInsertId()]);
    return $stmt3->fetch();
}

// ── Check if any agent is online ─────────────────────────────
function any_agent_online(): bool {
    $stmt = db()->prepare("SELECT COUNT(*) FROM agents WHERE status = 'online'");
    $stmt->execute();
    return (int)$stmt->fetchColumn() > 0;
}

// ── Bitrix24 CRM Integration ──────────────────────────────────
function bitrix24_lookup(?string $phone, ?string $email): ?array {
    $webhookUrl = get_setting('bitrix24_webhook_url');
    if (!$webhookUrl || !$phone) return null;

    $phoneField = _b24_item_phone_field($webhookUrl);
    if (!$phoneField) return null;

    $digits = preg_replace('/\D/', '', $phone);
    $candidates = array_unique(array_filter([
        $digits,                              // 447385297011
        '+' . $digits,                        // +447385297011
        preg_replace('/^44/', '0', $digits),  // 07385297011  (UK local)
    ]));

    $cli = null;
    foreach ($candidates as $candidate) {
        $cli = _b24_item_list($webhookUrl, $phoneField, $candidate);
        if ($cli) break;
    }

    if (!$cli) return null;

    // Fetch parent Site record (entity type 156)
    $siteId = $cli['parentId156'] ?? $cli['PARENT_ID_156'] ?? null;
    if ($siteId) {
        $site = _b24_item_get($webhookUrl, 156, $siteId);
        if ($site) {
            $site['_username']      = $cli['ufCrm47UserName']                                          ?? null;
            $site['_contractStart'] = $cli['ufCrm47StartDate']                                        ?? null;
            $site['_contractEnd']   = $cli['ufCrm47EndDate'] ?? $cli['ufCrm47ChargesEndDate'] ?? $cli['ufCrm47ContractEndDate'] ?? null;

            // Fetch linked contact for email
            $contactRes = _b24_post($webhookUrl . 'crm.contact.list', [
                'filter' => ['PARENT_ID_156' => $siteId],
                'select' => ['EMAIL'],
            ]);
            $contacts = $contactRes['result'] ?? [];
            if (!empty($contacts)) {
                $emailArr = $contacts[0]['EMAIL'] ?? [];
                $site['_email'] = $emailArr[0]['VALUE'] ?? null;
            }

            // Fetch company for account managers, gold status, security Q&A
            $companyId = $site['companyId'] ?? null;
            if ($companyId) {
                $companyRes = _b24_post($webhookUrl . 'crm.company.get', ['id' => $companyId]);
                _b24_log("COMPANY_GET id=$companyId response=" . json_encode($companyRes));
                $company = $companyRes['result'] ?? null;
                if ($company) {
                    $site['_companyName']        = $company['TITLE']                                ?? null;
                    $site['_accountManager']     = $company['UF_CRM_1765386517']                   ?: null;
                    $site['_uccAccountManager']  = $company['UF_COMPANY_ABILL_UCC_ACC_MANAGER']    ?: null;
                    $site['_mobileAccManager']   = $company['UF_COMPANY_ABILL_MOBILE_ACC_MANAGER'] ?: null;
                    $site['_gold']               = ($company['UF_CRM_1765388262'] && $company['UF_CRM_1765388262'] !== '0') ? 'Yes' : 'No';
                    $site['_securityQ']          = $company['UF_COMPANY_ABILL_SEC_Q']              ?: null;
                    $site['_securityA']          = $company['UF_COMPANY_ABILL_SEC_A']              ?: null;
                }
            }

            $site['_entity_type_id'] = 156;
            $site['_cli_id']         = $cli['id'] ?? $cli['ID'] ?? null;
            return $site;
        }
    }

    // No site linked — return CLI record as-is
    $cli['_entity_type_id'] = 148;
    return $cli;
}

function bitrix24_cache_contact(int $contactId, ?array $result): void {
    $json = $result ? json_encode($result, JSON_UNESCAPED_UNICODE) : null;
    $b24id = $result['ID'] ?? $result['id'] ?? null;
    db()->prepare('UPDATE contacts SET bitrix24_data = ?, bitrix24_id = ?, bitrix24_synced_at = NOW() WHERE id = ?')
        ->execute([$json, $b24id, $contactId]);
}

/**
 * Build a direct link to a Bitrix24 CRM record using the stored webhook URL
 * and the entity type ID embedded in the cached data.
 */
function bitrix24_record_url(?array $data): ?string {
    if (empty($data)) return null;
    $id = $data['id'] ?? $data['ID'] ?? null;
    if (!$id) return null;
    $webhookUrl = get_setting('bitrix24_webhook_url');
    if (!$webhookUrl) return null;
    $parsed = parse_url($webhookUrl);
    if (!$parsed || empty($parsed['host'])) return null;
    $base = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'];
    $entityTypeId = (int)($data['_entity_type_id'] ?? 148);
    return "{$base}/crm/type/{$entityTypeId}/details/{$id}/";
}

/**
 * Derive the dashboard base URL from the current request context.
 * e.g. https://live.rcg.com/chat/api/agent/foo.php  →  https://live.rcg.com/chat/dashboard/
 */
function get_dashboard_url(): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path  = preg_replace('#/api/.*$#', '', $_SERVER['SCRIPT_NAME'] ?? '');
    return $proto . '://' . $host . $path . '/dashboard/';
}

/**
 * Write the contact's dashboard URL back to the "Contact Chat Link" field
 * on their Bitrix24 CLI record (type 148).
 * Only runs when the bitrix24_chat_link_field setting is configured.
 */
function bitrix24_write_chat_link(?array $b24Data, int $ourContactId): void {
    if (empty($b24Data)) return;
    $fieldKey = get_setting('bitrix24_chat_link_field', '');
    if (!$fieldKey) return;
    $webhookUrl = get_setting('bitrix24_webhook_url');
    if (!$webhookUrl) return;

    // Write to the Site record (type 156)
    // If lookup returned the site, its id IS the site id
    // If lookup returned only a CLI (no site), skip — no site to write to
    $entityTypeId = (int)($b24Data['_entity_type_id'] ?? 148);
    if ($entityTypeId !== 156) return; // no site record for this contact

    $itemId = $b24Data['id'] ?? $b24Data['ID'] ?? null;
    if (!$itemId) return;

    $chatUrl = get_dashboard_url() . '?contact=' . $ourContactId;
    _b24_post($webhookUrl . 'crm.item.update', [
        'entityTypeId' => 156,
        'id'           => $itemId,
        'fields'       => [$fieldKey => $chatUrl],
    ]);
    _b24_log("WRITE_CHAT_LINK contactId=$ourContactId siteId=$itemId field=$fieldKey url=$chatUrl");
}

function bitrix24_get_fields(): ?array {
    $webhookUrl = get_setting('bitrix24_webhook_url');
    if (!$webhookUrl) return null;
    $res = _b24_post($webhookUrl . 'crm.item.fields', ['entityTypeId' => 148]);
    return $res['result']['fields'] ?? null;
}

function _b24_item_phone_field(string $webhookUrl): ?string {
    $res = _b24_post($webhookUrl . 'crm.item.fields', ['entityTypeId' => 148]);
    _b24_log("ITEM_FIELDS response=" . json_encode($res));
    $fields = $res['result']['fields'] ?? [];
    foreach ($fields as $key => $meta) {
        $type       = strtolower($meta['type'] ?? '');
        $title      = strtolower($meta['title'] ?? '');
        $upperName  = strtoupper($meta['upperName'] ?? $key);
        if (
            $type === 'phone' ||
            stripos($key, 'phone') !== false ||
            stripos($upperName, 'PHONE') !== false ||
            $title === 'cli' ||
            stripos($upperName, '_CLI') !== false
        ) {
            return $key;
        }
    }
    return null;
}

function _b24_item_get(string $webhookUrl, int $entityTypeId, $id): ?array {
    $res = _b24_post($webhookUrl . 'crm.item.get', ['entityTypeId' => $entityTypeId, 'id' => $id]);
    _b24_log("ITEM_GET entityTypeId=$entityTypeId id=$id response=" . json_encode($res));
    return $res['result']['item'] ?? null;
}

function _b24_item_list(string $webhookUrl, string $phoneField, string $phone): ?array {
    $payload = [
        'entityTypeId' => 148,
        'filter' => [$phoneField => $phone],
        'select' => ['*'],
        'order'  => ['ufCrm47StartDate' => 'DESC'],
    ];
    $res = _b24_post($webhookUrl . 'crm.item.list', $payload);
    _b24_log("ITEM_LIST field=$phoneField value=$phone response=" . json_encode($res));
    if (isset($res['error'])) throw new \RuntimeException('Bitrix24 API error: ' . ($res['error_description'] ?? $res['error']));
    $items = $res['result']['items'] ?? [];
    if (empty($items)) return null;

    // Prefer a CLI that has a start date populated
    $best = null;
    foreach ($items as $item) {
        if (!empty($item['ufCrm47StartDate'])) { $best = $item; break; }
    }
    $best = $best ?? $items[0];

    $id = $best['id'] ?? $best['ID'];
    return _b24_item_get($webhookUrl, 148, $id) ?? $best;
}

function _b24_log(string $msg): void {
    $logFile = dirname(__DIR__) . '/b24.log';
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function _b24_company_get(string $webhookUrl, string $companyId): ?array {
    $res = _b24_post($webhookUrl . 'crm.company.get', ['id' => $companyId]);
    return $res['result'] ?? null;
}

function _b24_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'ChatSystem/1.0',
    ]);
    $raw = curl_exec($ch);
    if (!$raw) return [];
    return json_decode($raw, true) ?? [];
}

function _b24_post(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'ChatSystem/1.0',
    ]);
    $raw = curl_exec($ch);
    if (!$raw) return [];
    return json_decode($raw, true) ?? [];
}

// ── Giacom Mobile API ─────────────────────────────────────────
// Base URL: https://comms-api.cloud.market/
// All calls are XML POST with auth block in every request.
// Docs: https://comms-api.cloud.market/documentation

function giacom_log(string $msg): void {
    $logFile = dirname(__DIR__) . '/giacom.log';
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Send an XML request to the Giacom dwapi.
 *
 * @param string $call   API method name e.g. 'mobile_check_billing_services'
 * @param array  $fields flat key→value <a> elements
 * @param array  $blocks named <block> elements: ['block-name' => ['key' => 'value']]
 */
function giacom_request(string $call, array $fields = [], array $blocks = []): array {
    $baseUrl  = get_setting('giacom_api_url') ?: (defined('GIACOM_API_URL') ? GIACOM_API_URL : 'https://comms-api.cloud.market/');
    $username = get_setting('giacom_username') ?: (defined('GIACOM_USERNAME') ? GIACOM_USERNAME : '');
    $password = get_setting('giacom_password') ?: (defined('GIACOM_PASSWORD') ? GIACOM_PASSWORD : '');

    if (!$username || !$password) {
        giacom_log("REQUEST FAILED — missing credentials");
        return ['error' => 'Giacom credentials not configured'];
    }

    $requestId = md5(uniqid($call, true));

    $xml  = "<?xml version='1.0'?>\n";
    $xml .= "<Request module='dwapi' call='" . htmlspecialchars($call, ENT_XML1) . "' id='" . $requestId . "' version='1.0'>\n";
    $xml .= "  <block name='auth'>\n";
    $xml .= "    <a name='username' format='text'>" . htmlspecialchars($username, ENT_XML1) . "</a>\n";
    $xml .= "    <a name='password' format='password'>" . htmlspecialchars($password, ENT_XML1) . "</a>\n";
    $xml .= "  </block>\n";
    foreach ($fields as $name => $value) {
        $xml .= "  <a name='" . htmlspecialchars($name, ENT_XML1) . "' format='text'>" . htmlspecialchars((string)$value, ENT_XML1) . "</a>\n";
    }
    foreach ($blocks as $blockName => $blockFields) {
        $xml .= "  <block name='" . htmlspecialchars($blockName, ENT_XML1) . "'>\n";
        foreach ($blockFields as $k => $v) {
            $xml .= "    <a name='" . htmlspecialchars($k, ENT_XML1) . "' format='text'>" . htmlspecialchars((string)$v, ENT_XML1) . "</a>\n";
        }
        $xml .= "  </block>\n";
    }
    $xml .= "</Request>";

    giacom_log("CALL=$call id=$requestId fields=" . json_encode($fields));

    $ch = curl_init(rtrim($baseUrl, '/') . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'ChatSystem/1.0',
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);

    if ($err) {
        giacom_log("CURL ERROR call=$call: $err");
        return ['error' => $err];
    }

    giacom_log("RESPONSE code=$code body=" . substr($raw ?: '', 0, 800));

    libxml_use_internal_errors(true);
    $dom = simplexml_load_string($raw ?: '');
    if ($dom === false) {
        return ['error' => 'Invalid XML response', 'http_code' => $code];
    }

    $parsed = _giacom_xml_to_array($dom);
    $parsed['http_code'] = $code;

    // Surface API-level error from <status no="X" description="..."/>
    $statusNo = $parsed['status']['@no'] ?? null;
    if ($statusNo !== null && (string)$statusNo !== '0') {
        $parsed['error'] = $parsed['status']['@description'] ?? "Giacom API error (status $statusNo)";
    }

    return $parsed;
}

/** Recursively convert a SimpleXMLElement to a plain array */
function _giacom_xml_to_array(\SimpleXMLElement $xml): array {
    $out = [];
    foreach ($xml->attributes() as $k => $v) {
        $out['@' . $k] = (string)$v;
    }
    foreach ($xml->children() as $child) {
        $tag  = $child->getName();
        $name = (string)($child->attributes()['name'] ?? '');
        if ($tag === 'a' && $name !== '') {
            $out[$name] = (string)$child;
        } elseif (($tag === 'block' || $tag === 'status') && $name !== '') {
            $out[$name] = _giacom_xml_to_array($child);
        } elseif ($tag === 'status') {
            $out['status'] = _giacom_xml_to_array($child);
        } else {
            $arr = _giacom_xml_to_array($child);
            if (isset($out[$tag])) {
                if (!isset($out[$tag][0]) || !is_array($out[$tag][0])) $out[$tag] = [$out[$tag]];
                $out[$tag][] = $arr;
            } else {
                $out[$tag] = $arr;
            }
        }
    }
    if (empty($out)) {
        $text = trim((string)$xml);
        if ($text !== '') return ['_text' => $text];
    }
    return $out;
}

/** Check which CLI a SIM serial is assigned to (confirms SIM swap) */
function giacom_sim_lookup(string $network, string $simSerial): array {
    return giacom_request('mobile_lookup_sim_cli', [
        'network'    => $network,
        'sim-serial' => $simSerial,
    ]);
}

/** Current billing services (tariff + bolt-ons) on a CLI */
function giacom_billing_services(string $cli): array {
    return giacom_request('mobile_check_billing_services', ['cli' => $cli]);
}

/** Full attributes/services on a mobile number — includes data allowance, active services */
function giacom_number_attributes(string $mobileNumber, bool $refresh = false): array {
    $fields = ['mobile-number' => $mobileNumber];
    if ($refresh) $fields['refresh'] = '1';
    return giacom_request('mobile_number_attributes', $fields);
}

/** Spend cap / bill limit on a CLI */
function giacom_bill_limit(string $mobileNumber): array {
    return giacom_request('mobile_bill_limits', ['mobile-number' => $mobileNumber]);
}

/** All shared bundles on the account with pool-level usage (units-used-this-month/last-month) */
function giacom_shared_bundles(bool $showInactive = false): array {
    return giacom_request('mobile_shared_bundles', ['show-inactive' => $showInactive ? '1' : '0']);
}

/** Per-CLI usage within a shared bundle. Filter by $cli to get one number's usage */
function giacom_shared_bundle_clis(string $bundleId, ?string $cli = null): array {
    $fields = ['shared-bundle-id' => $bundleId];
    if ($cli) $fields['cli'] = $cli;
    return giacom_request('mobile_shared_bundle_clis', $fields);
}

/** List all CLIs on the account (paginated, 100/page) */
function giacom_account_clis(int $page = 1, bool $liveOnly = true): array {
    return giacom_request('account_mobile_clis', [
        'live-only' => $liveOnly ? '1' : '0',
        'page'      => (string)$page,
    ]);
}

/** Network a mobile number is on */
function giacom_number_network(string $mobileNumber): array {
    return giacom_request('mobile_number_network', ['mobile-number' => $mobileNumber]);
}

// ── aBillity CDR API ──────────────────────────────────────────
/**
 * Authenticate to aBillity and return session cookies as a header string.
 * Cookies are cached in a temp file (keyed by credentials hash) and reused
 * until they expire or validation fails.
 */
function _abillity_cookie_file(): string {
    $hash = substr(md5(ABILLITY_USERNAME . ABILLITY_PASSWORD), 0, 8);
    return sys_get_temp_dir() . '/abillity_session_' . $hash . '.txt';
}

function _abillity_authenticate(): bool {
    $cookieFile = _abillity_cookie_file();
    $baseUrl    = 'https://web.abillity.co.uk/ROS03TES';

    // 1. Fetch login page for CSRF token
    $ch = curl_init("$baseUrl/Account/Login");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    $loginHtml = curl_exec($ch);
    if (!$loginHtml) return false;

    // Extract CSRF token
    if (!preg_match('/name="__RequestVerificationToken"[^>]+value="([^"]+)"/', $loginHtml, $m)) return false;
    $csrf = $m[1];

    // 2. POST credentials
    $ch = curl_init("$baseUrl/Account/Login");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            '__RequestVerificationToken' => $csrf,
            'UserName'                   => ABILLITY_USERNAME,
            'Password'                   => ABILLITY_PASSWORD,
            'RememberMe'                 => 'false',
        ]),
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return $http === 302; // success = redirect to home
}

/**
 * Discover the current (most recent, non-closed) billing period ID from
 * the CallAnalysis page.  Returns period id or null.
 */
function _abillity_current_period(): ?int {
    $cookieFile = _abillity_cookie_file();
    $baseUrl    = 'https://web.abillity.co.uk/ROS03TES';

    $ch = curl_init("$baseUrl/RevenueAssurance/callanalysis");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code !== 200 || !$html) return null;

    // Find first <option data-closed="False" ... value="N">
    if (preg_match('/<option[^>]+data-closed="False"[^>]+value="(\d+)"/', $html, $m)) {
        return (int)$m[1];
    }
    return null;
}

/**
 * Fetch CDR data usage for a CLI in the current billing period.
 * Returns array with keys: total_mb, total_gb, data_records, voice_sms_records,
 *   billing_period_id, period_label, call_cost.
 */
function abillity_cdr_usage(string $cli): array {
    $cookieFile = _abillity_cookie_file();

    // Ensure we have a valid session (authenticate if cookie file is missing/old)
    $needAuth = !file_exists($cookieFile) || (time() - filemtime($cookieFile) > 3600 * 7);
    if ($needAuth) {
        if (!_abillity_authenticate()) {
            return ['error' => 'aBillity authentication failed'];
        }
    }

    // Discover current billing period
    $periodId = _abillity_current_period();
    if (!$periodId) {
        // Re-auth once and retry
        if (!_abillity_authenticate()) return ['error' => 'aBillity auth failed'];
        $periodId = _abillity_current_period();
        if (!$periodId) return ['error' => 'Could not determine billing period'];
    }

    // Fetch CDR records (up to 2000 to cover heavy users)
    $url = 'https://web.abillity.co.uk/ROS03TES/revenueassurance/callanalysis/SearchCallAnalysis?' . http_build_query([
        'draw'                   => 1,
        'start'                  => 0,
        'length'                 => 2000,
        'Filter[billingperiod]'  => $periodId,
        'Filter[calledfrom]'     => $cli,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_HTTPHEADER     => [
            'X-Requested-With: XMLHttpRequest',
            'Referer: https://web.abillity.co.uk/ROS03TES/RevenueAssurance/callanalysis',
        ],
    ]);
    $json = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code !== 200) {
        // Session may have expired — re-auth once
        if (!_abillity_authenticate()) return ['error' => 'aBillity re-auth failed'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_HTTPHEADER     => [
                'X-Requested-With: XMLHttpRequest',
                'Referer: https://web.abillity.co.uk/ROS03TES/RevenueAssurance/callanalysis',
            ],
        ]);
        $json = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code !== 200) return ['error' => "aBillity CDR query failed ($code)"];
    }

    $result = json_decode($json, true);
    if (!isset($result['data'])) return ['error' => 'Unexpected aBillity response'];

    $records       = $result['data'];
    $totalMb       = 0.0;
    $totalCost     = 0.0;
    $dataCount     = 0;
    $voiceSmsCount = 0;
    foreach ($records as $r) {
        if (!empty($r['Gprs'])) {
            $totalMb += (float)($r['Usage'] ?? 0);
            $dataCount++;
        } else {
            $voiceSmsCount++;
        }
        $totalCost += (float)($r['Cost'] ?? 0);
    }

    return [
        'total_mb'          => round($totalMb, 2),
        'total_gb'          => round($totalMb / 1024, 3),
        'data_records'      => $dataCount,
        'voice_sms_records' => $voiceSmsCount,
        'billing_period_id' => $periodId,
        'call_cost'         => round($totalCost, 4),
    ];
}

// ── Email Notification ────────────────────────────────────────
function notify_new_conversation(array $conv, array $contact): void {
    if (!get_setting('email_notify_new_chat')) return;
    $addresses = get_setting('email_notify_addresses');
    if (!$addresses) return;

    $smtpHost = get_setting('smtp_host');
    if (!$smtpHost) return; // no SMTP configured, skip

    // Basic mail() fallback
    $to      = $addresses;
    $subject = 'New Chat from ' . ($contact['name'] ?? 'Visitor');
    $body    = "A new chat has started.\n\nVisitor: " . ($contact['name'] ?? 'Unknown') . "\nEmail: " . ($contact['email'] ?? '-') . "\nPage: " . ($conv['page_url'] ?? '-');

    @mail($to, $subject, $body, 'From: ' . get_setting('smtp_from_name') . ' <' . get_setting('smtp_from') . '>');
}
