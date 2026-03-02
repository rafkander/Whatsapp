<?php
/**
 * GET/POST/PATCH/DELETE /api/admin/departments.php
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

require_admin();
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT * FROM departments ORDER BY sort_order ASC, name ASC');
    json_success(['departments' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $body = request_body();
    $name = trim($body['name'] ?? '');

    if (!$name) json_error('Department name is required');

    $color = $body['color'] ?? '#2563eb';
    $desc  = trim($body['description'] ?? '');

    // Get next sort order
    $maxOrder = (int)$pdo->query('SELECT MAX(sort_order) FROM departments')->fetchColumn();

    $pdo->prepare('INSERT INTO departments (name, color, description, sort_order) VALUES (?, ?, ?, ?)')
        ->execute([$name, $color, $desc ?: null, $maxOrder + 1]);

    json_success(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PATCH') {
    $id   = (int)($_GET['id'] ?? 0);
    $body = request_body();

    if (!$id) json_error('Missing id');

    $allowed = ['name', 'color', 'description', 'sort_order', 'is_active'];
    $updates = [];
    $params  = [];

    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $updates[] = "`{$f}` = ?";
            $params[]  = $body[$f];
        }
    }

    if (!$updates) json_error('No fields to update');

    $params[] = $id;
    $pdo->prepare('UPDATE departments SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

    json_success();
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id');

    // Soft delete: set inactive
    $pdo->prepare('UPDATE departments SET is_active = 0 WHERE id = ?')->execute([$id]);
    json_success();
}

json_error('Method not allowed', 405);
