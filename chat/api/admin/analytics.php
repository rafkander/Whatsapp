<?php
/**
 * GET /api/admin/analytics.php
 * Stats: total chats, response time, ratings, per-dept
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

require_admin();
$pdo = db();

$from = $_GET['from'] ?? date('Y-m-01'); // default: start of month
$to   = $_GET['to']   ?? date('Y-m-d');

// Total conversations
$stmt = $pdo->prepare('SELECT COUNT(*) FROM conversations WHERE DATE(created_at) BETWEEN ? AND ?');
$stmt->execute([$from, $to]);
$totalConvs = (int)$stmt->fetchColumn();

// By status
$stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM conversations WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status");
$stmt->execute([$from, $to]);
$byStatus = [];
foreach ($stmt->fetchAll() as $row) {
    $byStatus[$row['status']] = (int)$row['cnt'];
}

// By channel
$stmt = $pdo->prepare("SELECT channel, COUNT(*) as cnt FROM conversations WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY channel");
$stmt->execute([$from, $to]);
$byChannel = [];
foreach ($stmt->fetchAll() as $row) {
    $byChannel[$row['channel']] = (int)$row['cnt'];
}

// Average rating
$stmt = $pdo->prepare('SELECT AVG(rating), COUNT(rating) FROM conversations WHERE rating IS NOT NULL AND DATE(created_at) BETWEEN ? AND ?');
$stmt->execute([$from, $to]);
$ratingRow   = $stmt->fetch(PDO::FETCH_NUM);
$avgRating   = $ratingRow[0] ? round((float)$ratingRow[0], 2) : null;
$ratedCount  = (int)$ratingRow[1];

// Average first response time (seconds): time from first visitor message to first agent message
$stmt = $pdo->prepare("
    SELECT AVG(TIMESTAMPDIFF(SECOND, vm.created_at, am.created_at)) AS avg_rt
    FROM conversations c
    JOIN messages vm ON vm.id = (
        SELECT id FROM messages WHERE conversation_id = c.id AND sender_type = 'visitor' ORDER BY id ASC LIMIT 1
    )
    JOIN messages am ON am.id = (
        SELECT id FROM messages WHERE conversation_id = c.id AND sender_type = 'agent' ORDER BY id ASC LIMIT 1
    )
    WHERE DATE(c.created_at) BETWEEN ? AND ?
");
$stmt->execute([$from, $to]);
$avgResponse = $stmt->fetchColumn();
$avgResponseSeconds = $avgResponse ? (int)$avgResponse : null;

// By department
$stmt = $pdo->prepare("
    SELECT d.id, d.name, d.color,
           COUNT(c.id) AS total,
           SUM(CASE WHEN c.status = 'closed' THEN 1 ELSE 0 END) AS closed,
           AVG(c.rating) AS avg_rating
    FROM departments d
    LEFT JOIN conversations c ON c.dept_id = d.id AND DATE(c.created_at) BETWEEN ? AND ?
    GROUP BY d.id, d.name, d.color
    ORDER BY d.sort_order ASC
");
$stmt->execute([$from, $to]);
$byDept = $stmt->fetchAll();

// By agent
$stmt = $pdo->prepare("
    SELECT a.id, a.name, a.avatar,
           COUNT(c.id) AS total,
           SUM(CASE WHEN c.status = 'closed' THEN 1 ELSE 0 END) AS closed,
           AVG(c.rating) AS avg_rating
    FROM agents a
    LEFT JOIN conversations c ON c.assigned_agent_id = a.id AND DATE(c.created_at) BETWEEN ? AND ?
    GROUP BY a.id, a.name, a.avatar
    ORDER BY total DESC
    LIMIT 10
");
$stmt->execute([$from, $to]);
$byAgent = $stmt->fetchAll();

// Daily trend (last 30 days)
$stmt = $pdo->prepare("
    SELECT DATE(created_at) AS date, COUNT(*) AS total
    FROM conversations
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$stmt->execute([$from, $to]);
$dailyTrend = $stmt->fetchAll();

json_success([
    'period'               => ['from' => $from, 'to' => $to],
    'total_conversations'  => $totalConvs,
    'by_status'            => $byStatus,
    'by_channel'           => $byChannel,
    'avg_rating'           => $avgRating,
    'rated_count'          => $ratedCount,
    'avg_response_seconds' => $avgResponseSeconds,
    'by_department'        => $byDept,
    'by_agent'             => $byAgent,
    'daily_trend'          => $dailyTrend,
]);
