<?php

declare(strict_types=1);

/**
 * Outbox publisher that POSTs every message to a webhook.
 *
 *   WEBHOOK_URL=https://billing.internal/events WEBHOOK_SECRET=change-me \
 *     vendor/bin/pdodb outbox work --publisher=examples/12-reliability/outbox/webhook-publisher.php
 *
 * The HTTP status decides what happens to the message:
 *   2xx                         delivered
 *   408, 425, 429, 5xx, no answer   try again later (exponential backoff)
 *   other 4xx                   will never be accepted: marked failed, kept for `pdodb outbox retry`
 *
 * `Idempotency-Key` carries the message id, so the receiver can drop a repeat (see webhook-receiver.php).
 * `X-Signature` is an HMAC-SHA256 of the body, so the receiver knows the call came from this service.
 */

use tommyknocker\pdodb\exceptions\OutboxPermanentFailureException;
use tommyknocker\pdodb\exceptions\OutboxRetryableException;
use tommyknocker\pdodb\outbox\OutboxMessage;

return static function (OutboxMessage $message): void {
    $url = getenv('WEBHOOK_URL');
    $secret = getenv('WEBHOOK_SECRET');
    if ($url === false || $url === '' || $secret === false || $secret === '') {
        // A configuration error is not the message's fault: stop the worker instead of failing every message
        throw new RuntimeException('Set WEBHOOK_URL and WEBHOOK_SECRET.');
    }

    $body = json_encode(['type' => $message->type, 'payload' => $message->payload], JSON_THROW_ON_ERROR);
    $headers = [
        'Content-Type: application/json',
        'Idempotency-Key: ' . $message->id,
        'X-Signature: ' . hash_hmac('sha256', $body, $secret),
    ];
    foreach ($message->headers as $name => $value) {
        // message headers travel as X-Event-* HTTP headers (trace ids, tenant, schema version)
        $headers[] = 'X-Event-' . preg_replace('/[^A-Za-z0-9-]/', '-', (string)$name) . ': ' . preg_replace('/[\r\n]+/', ' ', (string)(is_scalar($value) ? $value : json_encode($value)));
    }

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'timeout' => 10,
        'ignore_errors' => true, // read the status of 4xx/5xx answers instead of a warning
    ]]);

    $answer = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int)$m[1];
        }
    }

    if ($answer === false && $status === 0) {
        throw new OutboxRetryableException('Webhook did not answer: ' . (error_get_last()['message'] ?? 'no connection'));
    }
    if ($status >= 200 && $status < 300) {
        return;
    }
    if (in_array($status, [408, 425, 429], true) || $status >= 500) {
        throw new OutboxRetryableException("Webhook answered HTTP {$status}");
    }

    throw new OutboxPermanentFailureException("Webhook refused the message with HTTP {$status}");
};
