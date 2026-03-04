<?php
/**
 * POST /api/agent/bitrix_lookup.php
 * Body: { conv_id: int }
 * Force-refresh Bitrix24 data for the contact linked to a conversation.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

require_agent();

$body   = request_body();
$convId = (int)($body['conv_id'] ?? 0);
if (!$convId) json_error('Missing conv_id');

$pdo = db();

// Resolve contact
$stmt = $pdo->prepare('SELECT c.id, c.phone, c.email, c.whatsapp_number FROM contacts c JOIN conversations cv ON cv.contact_id = c.id WHERE cv.id = ?');
$stmt->execute([$convId]);
$contact = $stmt->fetch();
if (!$contact) json_error('Conversation or contact not found', 404);

if (!get_setting('bitrix24_enabled')) json_error('Bitrix24 integration is not enabled', 400);

// Use whatsapp_number as phone fallback if phone field is empty
$phone = $contact['phone'] ?: $contact['whatsapp_number'];

// Force lookup (ignore TTL)
try {
    $result = bitrix24_lookup($phone, $contact['email']);
} catch (\RuntimeException $e) {
    json_error($e->getMessage(), 502);
}
bitrix24_cache_contact((int)$contact['id'], $result);

// Fetch updated row
$stmt = $pdo->prepare('SELECT bitrix24_data, bitrix24_synced_at FROM contacts WHERE id = ?');
$stmt->execute([$contact['id']]);
$row = $stmt->fetch();

json_success([
    'found'          => $result !== null,
    'bitrix24_data'  => $row['bitrix24_data'] ? json_decode($row['bitrix24_data'], true) : null,
    'synced_at'      => $row['bitrix24_synced_at'],
]);
