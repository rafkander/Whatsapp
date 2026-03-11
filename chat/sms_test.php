<?php
/**
 * SMS Test — sends a test message from every enabled SMS account
 * Run: php sms_test.php  (from /chat/ directory)
 * Delete this file after testing.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/helpers.php';

$recipient = '447385297011';
$message   = 'Test message from RCG Live Chat';

$pdo      = db();
$token    = get_setting('alfonica_sms_token');
$accounts = $pdo->query("SELECT id, name, sender_id FROM sms_accounts WHERE is_enabled = 1 ORDER BY id")->fetchAll();

if (!$token) {
    echo "ERROR: alfonica_sms_token not set in settings.\n";
    exit;
}

if (!$accounts) {
    echo "No enabled SMS accounts found.\n";
    exit;
}

foreach ($accounts as $acc) {
    echo "Sending from [{$acc['name']}] sender_id={$acc['sender_id']} ... ";

    $url     = 'https://messenger.alfonica.com/api/v3/sms/send';
    $payload = [
        'recipient' => $recipient,
        'sender_id' => $acc['sender_id'],
        'type'      => 'plain',
        'message'   => $message . " (from: {$acc['name']})",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: Bearer {$token}",
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);

    if ($err) {
        echo "CURL ERROR: $err\n";
    } else {
        echo "HTTP $code — $res\n";
    }
}
