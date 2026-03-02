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
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
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
function wa_send_text(string $to, string $text): ?array {
    $phoneId = (defined('WA_PHONE_NUMBER_ID') && WA_PHONE_NUMBER_ID) ? WA_PHONE_NUMBER_ID : get_setting('wa_phone_number_id');
    $token   = (defined('WA_ACCESS_TOKEN')    && WA_ACCESS_TOKEN)    ? WA_ACCESS_TOKEN    : get_setting('wa_access_token');
    $version = (defined('WA_API_VERSION')     && WA_API_VERSION)     ? WA_API_VERSION     : 'v18.0';

    if (!$phoneId || !$token) return null;

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
    curl_close($ch);

    return ['status' => $code, 'body' => json_decode($res, true)];
}

function wa_download_media(string $mediaId): ?string {
    $token   = (defined('WA_ACCESS_TOKEN') && WA_ACCESS_TOKEN) ? WA_ACCESS_TOKEN : get_setting('wa_access_token');
    $version = (defined('WA_API_VERSION')  && WA_API_VERSION)  ? WA_API_VERSION  : 'v18.0';

    // Get media URL
    $ch = curl_init("https://graph.facebook.com/{$version}/{$mediaId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

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
    curl_close($ch);

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
function wa_api_call(string $to, array $payload): ?array {
    $phoneId = (defined('WA_PHONE_NUMBER_ID') && WA_PHONE_NUMBER_ID) ? WA_PHONE_NUMBER_ID : get_setting('wa_phone_number_id');
    $token   = (defined('WA_ACCESS_TOKEN')    && WA_ACCESS_TOKEN)    ? WA_ACCESS_TOKEN    : get_setting('wa_access_token');
    $version = (defined('WA_API_VERSION')     && WA_API_VERSION)     ? WA_API_VERSION     : 'v18.0';

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
    curl_close($ch);

    return ['status' => $code, 'body' => json_decode($res, true)];
}

// ── WhatsApp — interactive button message (max 3 buttons) ────
function wa_send_buttons(string $to, string $body, array $buttons): ?array {
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
    ]);
}

// ── WhatsApp — interactive list message (max 10 rows) ────────
function wa_send_list(string $to, string $body, string $btnLabel, array $rows): ?array {
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
    ]);
}

// ── WhatsApp — send media (image/document/video/audio) ────────
function wa_send_media(string $to, string $type, string $url, ?string $caption = null, ?string $filename = null): ?array {
    $media = ['link' => $url];
    if ($caption)  $media['caption']  = $caption;
    if ($filename) $media['filename'] = $filename;

    return wa_api_call($to, [
        'type'  => $type,
        $type   => $media,
    ]);
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

// ── Check if any agent is online ─────────────────────────────
function any_agent_online(): bool {
    $stmt = db()->prepare("SELECT COUNT(*) FROM agents WHERE status = 'online'");
    $stmt->execute();
    return (int)$stmt->fetchColumn() > 0;
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
