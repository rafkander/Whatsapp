<?php
/**
 * Admin: Bitrix24 CRM Integration Configuration
 * GET  ?action=credentials   — masked webhook URL + enabled + TTL
 * GET  ?action=fields         — live crm.contact.fields from Bitrix24
 * GET  ?action=field_config   — saved bitrix24_field_config rows
 * POST ?action=credentials   — save webhook URL, enabled, TTL
 * POST ?action=field_config   — replace all field config rows
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {

    if ($action === 'credentials') {
        $url = get_setting('bitrix24_webhook_url', '');
        // Mask everything except protocol + first 20 chars of path
        $masked = '';
        if ($url) {
            $masked = strlen($url) > 30
                ? substr($url, 0, 30) . str_repeat('*', max(0, strlen($url) - 30))
                : $url;
        }
        json_success([
            'enabled'     => (bool)(int)get_setting('bitrix24_enabled', '0'),
            'webhook_url' => $masked,
            'cache_ttl'   => (int)get_setting('bitrix24_cache_ttl', '3600'),
        ]);
    }

    if ($action === 'fields') {
        $fields = bitrix24_get_fields();
        if ($fields === null) json_error('Bitrix24 not configured or unreachable', 503);
        json_success(['fields' => $fields]);
    }

    if ($action === 'field_config') {
        $rows = db()->query('SELECT * FROM bitrix24_field_config ORDER BY sort_order ASC, id ASC')->fetchAll();
        json_success(['fields' => $rows]);
    }

    json_error('Unknown action', 400);
}

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = request_body();

    if ($action === 'credentials') {
        $url     = trim($body['webhook_url'] ?? '');
        $enabled = isset($body['enabled']) ? (int)(bool)$body['enabled'] : null;
        $ttl     = isset($body['cache_ttl']) ? (int)$body['cache_ttl'] : null;

        // Allow empty string to clear the URL
        if ($url !== '') set_setting('bitrix24_webhook_url', rtrim($url, '/') . '/');
        if ($enabled !== null) set_setting('bitrix24_enabled', (string)$enabled);
        if ($ttl !== null)     set_setting('bitrix24_cache_ttl', (string)max(60, $ttl));

        json_success(['message' => 'Bitrix24 credentials saved']);
    }

    if ($action === 'field_config') {
        $fields = $body['fields'] ?? [];
        if (!is_array($fields)) json_error('fields must be an array');

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM bitrix24_field_config');
            $stmt = $pdo->prepare(
                'INSERT INTO bitrix24_field_config (field_key, label, field_type, is_enabled, sort_order) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($fields as $i => $f) {
                $stmt->execute([
                    $f['field_key']  ?? '',
                    $f['label']      ?? $f['field_key'] ?? '',
                    $f['field_type'] ?? 'string',
                    isset($f['is_enabled']) ? (int)(bool)$f['is_enabled'] : 1,
                    (int)($f['sort_order'] ?? $i),
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            json_error('DB error: ' . $e->getMessage(), 500);
        }

        json_success(['message' => 'Field configuration saved', 'count' => count($fields)]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
