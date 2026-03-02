<?php
/**
 * GET/POST/PATCH/DELETE /api/agent/canned.php
 * Canned responses management
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

$agent  = require_agent();
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $search = trim($_GET['search'] ?? '');
    $deptId = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : null;

    $where  = ['1=1'];
    $params = [];

    if ($search) {
        $where[]  = '(shortcut LIKE ? OR title LIKE ? OR content LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($deptId !== null) {
        $where[]  = '(dept_id = ? OR dept_id IS NULL)';
        $params[] = $deptId;
    }

    $stmt = $pdo->prepare('SELECT cr.*, d.name AS dept_name, a.name AS created_by_name FROM canned_responses cr LEFT JOIN departments d ON d.id = cr.dept_id LEFT JOIN agents a ON a.id = cr.created_by WHERE ' . implode(' AND ', $where) . ' ORDER BY shortcut ASC');
    $stmt->execute($params);

    json_success(['canned_responses' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $body     = request_body();
    $shortcut = trim($body['shortcut'] ?? '');
    $title    = trim($body['title'] ?? '');
    $content  = trim($body['content'] ?? '');
    $deptId   = !empty($body['dept_id']) ? (int)$body['dept_id'] : null;

    if (!$shortcut || !$title || !$content) {
        json_error('shortcut, title and content are required');
    }

    $pdo->prepare('INSERT INTO canned_responses (shortcut, title, content, dept_id, created_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$shortcut, $title, $content, $deptId, $agent['id']]);

    json_success(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PATCH') {
    $id   = (int)($_GET['id'] ?? 0);
    $body = request_body();

    if (!$id) json_error('Missing id');

    $allowed  = ['shortcut', 'title', 'content', 'dept_id'];
    $updates  = [];
    $params   = [];

    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $updates[] = "`{$f}` = ?";
            $params[]  = $f === 'dept_id' ? (!empty($body[$f]) ? (int)$body[$f] : null) : trim((string)$body[$f]);
        }
    }

    if (!$updates) json_error('No fields to update');

    $params[] = $id;
    $pdo->prepare('UPDATE canned_responses SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

    json_success();
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id');

    $pdo->prepare('DELETE FROM canned_responses WHERE id = ?')->execute([$id]);
    json_success();
}

json_error('Method not allowed', 405);
