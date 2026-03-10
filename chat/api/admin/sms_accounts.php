<?php
/**
 * /api/admin/sms_accounts.php
 * Manages SMS sender IDs (numbers/names).
 * All senders share the single Alfonica API token stored in settings.
 *
 * GET    → list all senders
 * POST   → create a sender
 * PATCH  → update a sender (?id=X)
 * DELETE → delete a sender (?id=X)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

require_admin();
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT id, name, sender_id, is_enabled, created_at FROM sms_accounts ORDER BY id');
    json_success(['accounts' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $body      = request_body();
    $name      = trim($body['name']      ?? '');
    $senderId  = trim($body['sender_id'] ?? '');
    $isEnabled = isset($body['is_enabled']) ? (int)(bool)$body['is_enabled'] : 1;

    if (!$name)     json_error('Name is required');
    if (!$senderId) json_error('Sender ID is required');

    $pdo->prepare('INSERT INTO sms_accounts (name, sender_id, is_enabled) VALUES (?, ?, ?)')
        ->execute([$name, $senderId, $isEnabled]);
    json_success(['id' => (int)$pdo->lastInsertId()]);
}

if ($method === 'PATCH') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id');

    $stmt = $pdo->prepare('SELECT id FROM sms_accounts WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) json_error('Not found', 404);

    $body   = request_body();
    $fields = [];
    $params = [];

    if (isset($body['name']))       { $fields[] = 'name = ?';      $params[] = trim($body['name']); }
    if (isset($body['sender_id']))  { $fields[] = 'sender_id = ?'; $params[] = trim($body['sender_id']); }
    if (isset($body['is_enabled'])) { $fields[] = 'is_enabled = ?'; $params[] = (int)(bool)$body['is_enabled']; }

    if ($fields) {
        $params[] = $id;
        $pdo->prepare('UPDATE sms_accounts SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    }
    json_success();
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id');
    $pdo->prepare('DELETE FROM sms_accounts WHERE id = ?')->execute([$id]);
    json_success();
}

json_error('Method not allowed', 405);
