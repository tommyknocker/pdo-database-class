<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use tommyknocker\pdodb\outbox\OutboxMessage;

/**
 * Event fired after a worker scheduled a message for another delivery attempt.
 */
final class OutboxMessageRetryEvent
{
    public function __construct(
        private OutboxMessage $message,
        private string $error,
        private int $delaySeconds,
    ) {
    }

    /**
     * The message as it was claimed (its attempt count includes this attempt).
     */
    public function getMessage(): OutboxMessage
    {
        return $this->message;
    }

    /**
     * The error that made the attempt fail.
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * Seconds until the message is due again.
     */
    public function getDelaySeconds(): int
    {
        return $this->delaySeconds;
    }
}
