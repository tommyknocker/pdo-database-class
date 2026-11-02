<?php

declare(strict_types=1);

/**
 * Webhook receiver that applies every message exactly once (the other end of webhook-publisher.php).
 *
 *   WEBHOOK_SECRET=change-me RECEIVER_DB=/tmp/receiver.sqlite php -S 127.0.0.1:8080 examples/12-reliability/outbox/webhook-receiver.php
 *
 * It answers 2xx when the message is applied or was applied before, so the sender stops retrying; 4xx when the
 * request is wrong and will stay wrong; 5xx when it failed and should be tried again.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use tommyknocker\pdodb\inbox\Inbox;
use tommyknocker\pdodb\PdoDb;

$reply = static function (int $status, string $text): never {
    http_response_code($status);
    header('Content-Type: text/plain');
    echo $text;
    exit;
};

$secret = (string)getenv('WEBHOOK_SECRET');
$body = (string)file_get_contents('php://input');
$messageId = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';

if ($secret === '' || !hash_equals(hash_hmac('sha256', $body, $secret), $_SERVER['HTTP_X_SIGNATURE'] ?? '')) {
    $reply(401, 'bad signature');
}
$event = json_decode($body, true);
if ($messageId === '' || !is_array($event) || !isset($event['type'], $event['payload'])) {
    $reply(400, 'not an event');
}

try {
    $db = new PdoDb('sqlite', ['path' => getenv('RECEIVER_DB') ?: sys_get_temp_dir() . '/webhook-receiver.sqlite']);
    $db->rawQuery('CREATE TABLE IF NOT EXISTS received_events (id INTEGER PRIMARY KEY AUTOINCREMENT, message_id TEXT NOT NULL, type TEXT NOT NULL)');
    $inbox = new Inbox($db, 'webhook-receiver');
    $inbox->ensureSchema();

    // The id is claimed and the work is done in one transaction: a crash in between repeats both, never one of them
    $applied = $inbox->once($messageId, static function () use ($db, $messageId, $event): void {
        $db->find()->table('received_events')->insert(['message_id' => $messageId, 'type' => (string)$event['type']]);
    });
} catch (Throwable $e) {
    error_log('webhook receiver: ' . $e->getMessage());
    $reply(503, 'try again');
}

$reply(200, $applied ? 'applied' : 'already applied');
