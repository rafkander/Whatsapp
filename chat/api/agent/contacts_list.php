<?php
/**
 * GET /api/agent/contacts_list.php  — paginated contacts with channel counts
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

require_agent();
$pdo = db();

$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(50, max(10, (int)($_GET['limit'] ?? 25)));
$offset = ($page - 1) * $limit;

$where  = '';
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where  = "WHERE (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.whatsapp_number LIKE ? OR c.sms_number LIKE ?)";
    $params = [$like, $like, $like, $like, $like];
}

// Total count
$countSql = "SELECT COUNT(*) FROM contacts c $where";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// Fetch contacts + per-channel conversation counts + last activity
$sql = "
    SELECT
        c.id, c.name, c.email, c.phone, c.whatsapp_number, c.sms_number,
        c.ip, c.country, c.browser, c.os, c.created_at, c.updated_at,
        (SELECT COUNT(*) FROM conversations cv WHERE cv.contact_id = c.id) AS conv_total,
        (SELECT COUNT(*) FROM conversations cv WHERE cv.contact_id = c.id AND cv.channel = 'widget') AS conv_widget,
        (SELECT COUNT(*) FROM conversations cv WHERE cv.contact_id = c.id AND cv.channel = 'whatsapp') AS conv_wa,
        (SELECT COUNT(*) FROM conversations cv WHERE cv.contact_id = c.id AND cv.channel = 'sms') AS conv_sms,
        (SELECT MAX(cv.updated_at) FROM conversations cv WHERE cv.contact_id = c.id) AS last_activity
    FROM contacts c
    $where
    ORDER BY last_activity DESC, c.updated_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contacts = $stmt->fetchAll();

json_success([
    'contacts' => $contacts,
    'total'    => $total,
    'page'     => $page,
    'pages'    => (int)ceil($total / $limit),
    'limit'    => $limit,
]);
