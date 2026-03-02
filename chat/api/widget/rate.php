<?php
/**
 * POST /api/widget/rate.php
 * Visitor rates the conversation (1-5 stars)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body    = request_body();
$convId  = (int)($body['conv_id'] ?? 0);
$uid     = trim($body['uid'] ?? '');
$rating  = (int)($body['rating'] ?? 0);
$comment = trim($body['comment'] ?? '');

if (!$convId || !$uid) {
    json_error('Missing parameters');
}

if ($rating < 1 || $rating > 5) {
    json_error('Rating must be between 1 and 5');
}

$pdo = db();

$stmt = $pdo->prepare('SELECT c.id, c.status, c.rating FROM conversations c JOIN contacts co ON co.id = c.contact_id WHERE c.id = ? AND co.uid = ?');
$stmt->execute([$convId, $uid]);
$conv = $stmt->fetch();

if (!$conv) {
    json_error('Conversation not found', 404);
}

if ($conv['rating'] !== null) {
    json_error('Already rated');
}

$pdo->prepare('UPDATE conversations SET rating = ?, rating_comment = ? WHERE id = ?')
    ->execute([$rating, $comment ?: null, $convId]);

// Insert system message
$pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?, 'system', ?, 'system')")
    ->execute([$convId, "Visitor rated this conversation {$rating}/5" . ($comment ? ": \"{$comment}\"" : '')]);

json_success(['rating' => $rating]);
