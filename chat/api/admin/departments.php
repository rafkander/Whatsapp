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
    $stmt  = $pdo->query('SELECT * FROM departments ORDER BY sort_order ASC, name ASC');
    $depts = $stmt->fetchAll();

    // Attach member agents to each department
    $mStmt = $pdo->query('
        SELECT ad.dept_id, a.id, a.name, a.email, a.role, a.status
        FROM agent_departments ad
        JOIN agents a ON a.id = ad.agent_id
        ORDER BY a.name
    ');
    $membersByDept = [];
    foreach ($mStmt->fetchAll() as $m) {
        $membersByDept[$m['dept_id']][] = $m;
    }
    foreach ($depts as &$d) {
        $d['members'] = $membersByDept[$d['id']] ?? [];
    }
    json_success(['departments' => $depts]);
}

if ($method === 'POST') {
    $body = request_body();
    $name = trim($body['name'] ?? '');

    if (!$name) json_error('Department name is required');

    $color = $body['color'] ?? '#2563eb';
    $desc  = trim($body['description'] ?? '');

    // Get next sort order
    $maxOrder = (int)$pdo->query('SELECT MAX(sort_order) FROM departments')->fetchColumn();

    try {
        $pdo->prepare('INSERT INTO departments (name, color, description, sort_order) VALUES (?, ?, ?, ?)')
            ->execute([$name, $color, $desc ?: null, $maxOrder + 1]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() == 23000) {
            json_error("A department named '{$name}' already exists");
        }
        json_error('Database error: ' . $e->getMessage());
    }

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
