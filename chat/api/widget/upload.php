<?php
/**
 * POST /api/widget/upload.php
 * Visitor file/image upload
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$convId = (int)($_POST['conv_id'] ?? 0);
$uid    = trim($_POST['uid'] ?? '');

if (!$convId || !$uid) {
    json_error('Missing parameters');
}

if (empty($_FILES['file'])) {
    json_error('No file uploaded');
}

$pdo = db();

// Validate conversation
$stmt = $pdo->prepare('SELECT c.id FROM conversations c JOIN contacts co ON co.id = c.contact_id WHERE c.id = ? AND co.uid = ? AND c.status != "closed"');
$stmt->execute([$convId, $uid]);
if (!$stmt->fetch()) {
    json_error('Conversation not found or closed', 404);
}

$file    = $_FILES['file'];
$maxSize = (MAX_UPLOAD_MB * 1024 * 1024);

if ($file['size'] > $maxSize) {
    json_error("File too large (max " . MAX_UPLOAD_MB . "MB)");
}

// Allowed types
$allowed = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'text/plain',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/zip',
];

// Detect MIME from content
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);

if (!in_array($mime, $allowed, true)) {
    json_error('File type not allowed');
}

$ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'upload_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . strtolower($ext);
$dest     = UPLOAD_DIR . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    json_error('Failed to save file');
}

$fileUrl  = UPLOAD_URL . $filename;
$isImage  = str_starts_with($mime, 'image/');
$msgType  = $isImage ? 'image' : 'file';

// Insert message
$pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type, file_url, file_name, file_size) VALUES (?, 'visitor', ?, ?, ?, ?, ?)")
    ->execute([$convId, $file['name'], $msgType, $fileUrl, $file['name'], $file['size']]);

$msgId = (int)$pdo->lastInsertId();

$pdo->prepare('UPDATE conversations SET updated_at = NOW(), unread_agent = unread_agent + 1 WHERE id = ?')
    ->execute([$convId]);

json_success([
    'message_id' => $msgId,
    'file_url'   => $fileUrl,
    'file_name'  => $file['name'],
    'type'       => $msgType,
]);
