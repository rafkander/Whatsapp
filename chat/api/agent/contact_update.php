<?php
/**
 * PATCH /api/agent/contact_update.php
 * Body: { contact_id: int, name?: string, email?: string, phone?: string }
 * Updates contact details and optionally triggers a Bitrix24 re-lookup.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/cors.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') json_error('Method not allowed', 405);

require_agent();

$body      = request_body();
$contactId = (int)($body['contact_id'] ?? 0);
if (!$contactId) json_error('Missing contact_id');

$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
$stmt->execute([$contactId]);
$contact = $stmt->fetch();
if (!$contact) json_error('Contact not found', 404);

// Only update fields that were explicitly passed
$fields = [];
$params = [];
foreach (['name', 'email', 'phone'] as $f) {
    if (array_key_exists($f, $body)) {
        $fields[] = "`$f` = ?";
        $params[]  = trim($body[$f]);
    }
}

if ($fields) {
    $params[] = $contactId;
    $pdo->prepare('UPDATE contacts SET ' . implode(', ', $fields) . ' WHERE id = ?')
        ->execute($params);
}

// Re-fetch updated contact
$stmt->execute([$contactId]);
$contact = $stmt->fetch();

// Trigger Bitrix24 lookup if email/phone changed and integration is enabled
$doLookup = isset($body['email']) || isset($body['phone']);
$b24data  = $contact['bitrix24_data'] ? json_decode($contact['bitrix24_data'], true) : null;

if ($doLookup && get_setting('bitrix24_enabled')) {
    $result = bitrix24_lookup($contact['phone'], $contact['email']);
    bitrix24_cache_contact($contactId, $result);
    if ($result) bitrix24_write_chat_link($result, $contactId);
    $stmt->execute([$contactId]);
    $contact = $stmt->fetch();
    $b24data = $contact['bitrix24_data'] ? json_decode($contact['bitrix24_data'], true) : null;
}

json_success([
    'contact'        => $contact,
    'bitrix24'       => $b24data,
    'bitrix24_url'   => bitrix24_record_url($b24data),
    'bitrix24_synced_at' => $contact['bitrix24_synced_at'] ?? null,
]);
