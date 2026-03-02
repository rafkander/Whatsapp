<?php
/**
 * GET/POST /api/admin/settings.php
 * Get or update all settings
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

require_admin();
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

// Keys agents can read (non-sensitive)
$publicKeys = [
    'widget_color', 'widget_greeting', 'widget_position', 'widget_name', 'widget_avatar',
    'offline_message', 'business_hours_enabled', 'business_hours',
    'welcome_trigger_enabled', 'welcome_trigger_delay', 'welcome_trigger_message',
    'widget_allowed_origins',
];

// Sensitive keys only returned for admin GET
$adminKeys = [
    'wa_phone_number_id', 'wa_access_token', 'wa_verify_token', 'wa_enabled',
    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name',
    'email_notify_new_chat', 'email_notify_addresses',
];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT `key`, `value` FROM settings');
    $rows = $stmt->fetchAll();

    $all = [];
    foreach ($rows as $row) {
        $all[$row['key']] = $row['value'];
    }

    // Mask sensitive
    if (isset($all['wa_access_token']) && $all['wa_access_token']) {
        $all['wa_access_token'] = str_repeat('*', 20) . substr($all['wa_access_token'], -4);
    }
    if (isset($all['smtp_pass']) && $all['smtp_pass']) {
        $all['smtp_pass'] = '••••••••';
    }

    json_success(['settings' => $all]);
}

if ($method === 'POST') {
    $body = request_body();

    $allowedAll = array_merge($publicKeys, $adminKeys);

    $updated = [];
    foreach ($body as $key => $value) {
        if (!in_array($key, $allowedAll, true)) continue;

        // Don't overwrite masked values
        if ($key === 'wa_access_token' && str_contains((string)$value, '*')) continue;
        if ($key === 'smtp_pass' && $value === '••••••••') continue;

        set_setting($key, (string)$value);
        $updated[] = $key;

        // Also update config constants for WA if changed
        if ($key === 'wa_phone_number_id' && defined('WA_PHONE_NUMBER_ID')) {
            // Runtime update not possible for constants, values are read from DB via get_setting()
        }
    }

    json_success(['updated' => $updated]);
}

json_error('Method not allowed', 405);
