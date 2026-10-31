<?php

declare(strict_types=1);

/**
 * Example outbox publisher for CLI and documentation.
 *
 * Return a callable that receives each claimed OutboxMessage.
 * Throw OutboxRetryableException or OutboxPermanentFailureException to control retries.
 */

use tommyknocker\pdodb\outbox\OutboxMessage;

return static function (OutboxMessage $message): void {
    // Replace with HTTP, AMQP, Kafka, etc. Idempotent consumers should key on $message->id.
    fwrite(STDOUT, sprintf(
        "[outbox] publish type=%s id=%s payload=%s\n",
        $message->type,
        $message->id,
        json_encode($message->payload, JSON_THROW_ON_ERROR)
    ));
};
