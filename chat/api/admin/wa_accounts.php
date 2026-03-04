<?php
/**
 * /api/admin/wa_accounts.php
 * GET    → list all WhatsApp accounts
 * POST   → create a new account
 * PATCH  → update an account (?id=X)
 * DELETE → delete an account (?id=X)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

require_admin();
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: list ─────────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = $pdo->query('SELECT id, name, phone_number_id, verify_token, bot_flow, is_enabled, created_at FROM wa_accounts ORDER BY id');
    $rows = $stmt->fetchAll();
    // Never return access_token in GET (only confirm it's set)
    foreach ($rows as &$row) {
        $hasToken = (bool)$pdo->prepare('SELECT access_token FROM wa_accounts WHERE id = ?')
            ->execute([$row['id']]) || true;
        $raw = $pdo->prepare('SELECT access_token FROM wa_accounts WHERE id = ?');
        $raw->execute([$row['id']]);
        $tok = $raw->fetchColumn();
        $row['has_token'] = !empty($tok);
        $row['token_hint'] = $tok ? ('••••' . substr($tok, -4)) : '';
    }
    unset($row);
    json_success(['accounts' => $rows]);
}

// ── POST: create ──────────────────────────────────────────────
if ($method === 'POST') {
    $body = request_body();
    $name          = trim($body['name']            ?? '');
    $phoneNumberId = trim($body['phone_number_id'] ?? '');
    $accessToken   = trim($body['access_token']    ?? '');
    $verifyToken   = trim($body['verify_token']    ?? '');
    $botFlow       = in_array($body['bot_flow'] ?? '', ['standard', 'alfonica']) ? $body['bot_flow'] : 'standard';
    $isEnabled     = isset($body['is_enabled']) ? (int)(bool)$body['is_enabled'] : 1;

    if (!$name)          json_error('Name is required');
    if (!$phoneNumberId) json_error('Phone Number ID is required');
    if (!$accessToken)   json_error('Access Token is required');
    if (!$verifyToken)   json_error('Verify Token is required');

    try {
        $pdo->prepare('INSERT INTO wa_accounts (name, phone_number_id, access_token, verify_token, bot_flow, is_enabled) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, $phoneNumberId, $accessToken, $verifyToken, $botFlow, $isEnabled]);
        json_success(['id' => (int)$pdo->lastInsertId()]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            json_error('A WhatsApp account with that Phone Number ID already exists');
        }
        throw $e;
    }
}

// ── PATCH: update ─────────────────────────────────────────────
if ($method === 'PATCH') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id');

    $stmt = $pdo->prepare('SELECT id FROM wa_accounts WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) json_error('Not found', 404);

    $body   = request_body();
    $fields = [];
    $params = [];

    if (isset($body['name'])) {
        $fields[] = 'name = ?';
        $params[] = trim($body['name']);
    }
    if (isset($body['phone_number_id'])) {
        $fields[] = 'phone_number_id = ?';
        $params[] = trim($body['phone_number_id']);
    }
    if (isset($body['access_token']) && trim($body['access_token']) && !str_contains($body['access_token'], '••••')) {
        $fields[] = 'access_token = ?';
        $params[] = trim($body['access_token']);
    }
    if (isset($body['verify_token'])) {
        $fields[] = 'verify_token = ?';
        $params[] = trim($body['verify_token']);
    }
    if (isset($body['bot_flow']) && in_array($body['bot_flow'], ['standard', 'alfonica'])) {
        $fields[] = 'bot_flow = ?';
        $params[] = $body['bot_flow'];
    }
    if (isset($body['is_enabled'])) {
        $fields[] = 'is_enabled = ?';
        $params[] = (int)(bool)$body['is_enabled'];
    }

    if ($fields) {
        $params[] = $id;
        $pdo->prepare('UPDATE wa_accounts SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($params);
    }

    json_success();
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id');

    $pdo->prepare('DELETE FROM wa_accounts WHERE id = ?')->execute([$id]);
    json_success();
}

json_error('Method not allowed', 405);
