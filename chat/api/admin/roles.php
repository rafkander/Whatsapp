<?php
/**
 * GET/POST/PATCH/DELETE /api/admin/roles.php
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

require_admin();
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

// Ensure roles table exists and is seeded
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `roles` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(100) NOT NULL,
      `description` text DEFAULT NULL,
      `color` varchar(7) NOT NULL DEFAULT '#2563eb',
      `permissions` text DEFAULT NULL,
      `is_system` tinyint(1) NOT NULL DEFAULT 0,
      `sort_order` int(11) NOT NULL DEFAULT 0,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Seed built-in roles if table is empty
$count = (int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
if ($count === 0) {
    $seed = [
        ['Super Admin', 'Unrestricted access to all features', '#7c3aed',
            '["view_all_conversations","reply_conversations","take_conversations","assign_conversations","close_conversations","view_analytics","manage_canned","manage_agents","manage_departments","manage_roles","manage_settings"]', 1, 1],
        ['Admin', 'Full access except super-admin features', '#c0392b',
            '["view_all_conversations","reply_conversations","take_conversations","assign_conversations","close_conversations","view_analytics","manage_canned","manage_agents","manage_departments","manage_roles","manage_settings"]', 1, 2],
        ['Supervisor', 'Can view analytics and manage conversations', '#ea580c',
            '["view_all_conversations","reply_conversations","take_conversations","assign_conversations","close_conversations","view_analytics","manage_canned"]', 1, 3],
        ['Senior Agent', 'Can view all conversations and use canned responses', '#2563eb',
            '["view_all_conversations","reply_conversations","take_conversations","close_conversations","manage_canned"]', 1, 4],
        ['Agent', 'Can view and reply to assigned conversations', '#059669',
            '["reply_conversations","take_conversations","close_conversations"]', 1, 5],
    ];
    $stmt = $pdo->prepare('INSERT INTO roles (name, description, color, permissions, is_system, sort_order) VALUES (?,?,?,?,?,?)');
    foreach ($seed as $row) $stmt->execute($row);
}

if ($method === 'GET') {
    $stmt  = $pdo->query('SELECT * FROM roles ORDER BY sort_order ASC, name ASC');
    $roles = $stmt->fetchAll();
    foreach ($roles as &$r) {
        $r['permissions'] = json_decode($r['permissions'] ?? '[]', true) ?: [];
        $r['is_system']   = (bool)$r['is_system'];
    }
    json_success(['roles' => $roles]);
}

if ($method === 'POST') {
    $body = request_body();
    $name = trim($body['name'] ?? '');
    if (!$name) json_error('Role name is required');

    $color       = $body['color']       ?? '#2563eb';
    $desc        = trim($body['description'] ?? '');
    $permissions = json_encode(array_values(array_unique($body['permissions'] ?? [])));
    $maxOrder    = (int)$pdo->query('SELECT MAX(sort_order) FROM roles')->fetchColumn();

    try {
        $pdo->prepare('INSERT INTO roles (name, description, color, permissions, sort_order) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, $desc ?: null, $color, $permissions, $maxOrder + 1]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() == 23000) {
            json_error("A role named '{$name}' already exists");
        }
        json_error('Database error: ' . $e->getMessage());
    }

    json_success(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PATCH') {
    $id   = (int)($_GET['id'] ?? 0);
    $body = request_body();
    if (!$id) json_error('Missing id');

    $stmt = $pdo->prepare('SELECT is_system FROM roles WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) json_error('Role not found', 404);

    $allowed = ['name', 'description', 'color', 'permissions', 'sort_order'];
    $updates = [];
    $params  = [];

    foreach ($allowed as $f) {
        if (!array_key_exists($f, $body)) continue;
        $updates[] = "`{$f}` = ?";
        $params[]  = ($f === 'permissions') ? json_encode(array_values(array_unique($body[$f]))) : $body[$f];
    }

    if (!$updates) json_error('No fields to update');
    $params[] = $id;
    $pdo->prepare('UPDATE roles SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
    json_success();
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id');

    $stmt = $pdo->prepare('SELECT is_system FROM roles WHERE id = ?');
    $stmt->execute([$id]);
    $role = $stmt->fetch();
    if (!$role) json_error('Role not found', 404);
    if ($role['is_system']) json_error('Cannot delete a built-in role');

    $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
    json_success();
}

json_error('Method not allowed', 405);
