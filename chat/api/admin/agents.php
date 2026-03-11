<?php
/**
 * GET/POST/PATCH/DELETE /api/admin/agents.php
 * Manage agents — admin/super_admin only
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

$admin  = require_admin();
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

$valid_roles = ['super_admin', 'admin', 'supervisor', 'senior_agent', 'agent'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT id, name, email, role, status, avatar, created_at FROM agents ORDER BY FIELD(role,"super_admin","admin","supervisor","senior_agent","agent"), name ASC');
    json_success(['agents' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $body  = request_body();
    $name  = trim($body['name'] ?? '');
    $email = trim($body['email'] ?? '');
    $pass  = $body['password'] ?? '';
    $role  = in_array($body['role'] ?? 'agent', $valid_roles, true) ? $body['role'] : 'agent';

    if (!$name || !$email || !$pass) {
        json_error('Name, email and password are required');
    }
    if (strlen($name) > 100) {
        json_error('Name must be 100 characters or fewer');
    }
    if (strlen($email) > 254) {
        json_error('Email address is too long');
    }
    if (strlen($pass) < 8) {
        json_error('Password must be at least 8 characters');
    }
    if (strlen($pass) > 128) {
        json_error('Password must be 128 characters or fewer');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Invalid email address');
    }

    // Only super_admin can create other super_admins
    if ($role === 'super_admin' && $admin['role'] !== 'super_admin') {
        json_error('Only a Super Admin can create Super Admin accounts', 403);
    }

    $hash = password_hash($pass, PASSWORD_BCRYPT);

    try {
        $pdo->prepare('INSERT INTO agents (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute([$name, $email, $hash, $role]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000' || strpos($e->getMessage(), 'Duplicate') !== false) {
            json_error('That email address is already in use');
        }
        error_log('agents.php POST error: ' . $e->getMessage());
        json_error('Database error', 500);
    }

    json_success(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PATCH') {
    $id   = (int)($_GET['id'] ?? 0);
    $body = request_body();

    if (!$id) json_error('Missing id');

    $updates = [];
    $params  = [];

    if (!empty($body['name'])) {
        $updates[] = 'name = ?';
        $params[]  = trim($body['name']);
    }
    if (!empty($body['email'])) {
        if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) json_error('Invalid email');
        $updates[] = 'email = ?';
        $params[]  = trim($body['email']);
    }
    if (!empty($body['password'])) {
        if (strlen($body['password']) < 8) json_error('Password must be at least 8 characters');
        $updates[] = 'password_hash = ?';
        $params[]  = password_hash($body['password'], PASSWORD_BCRYPT);
    }
    if (!empty($body['role']) && in_array($body['role'], $valid_roles, true)) {
        if ($body['role'] === 'super_admin' && $admin['role'] !== 'super_admin') {
            json_error('Only a Super Admin can assign Super Admin role', 403);
        }
        $updates[] = 'role = ?';
        $params[]  = $body['role'];
    }
    if (!empty($body['status']) && in_array($body['status'], ['online', 'away', 'offline'], true)) {
        $updates[] = 'status = ?';
        $params[]  = $body['status'];
    }

    if (!$updates) json_error('No fields to update');

    $params[] = $id;
    $pdo->prepare('UPDATE agents SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = ?')
        ->execute($params);

    json_success();
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id');
    if ($id === (int)$admin['id']) json_error('You cannot delete your own account');

    // Check target agent role — only super_admin can delete admins
    $stmt = $pdo->prepare('SELECT role FROM agents WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if ($target && in_array($target['role'], ['super_admin', 'admin'], true) && $admin['role'] !== 'super_admin') {
        json_error('Only a Super Admin can delete Admin accounts', 403);
    }

    $pdo->prepare('DELETE FROM agents WHERE id = ?')->execute([$id]);
    json_success();
}

json_error('Method not allowed', 405);
