<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use tommyknocker\pdodb\outbox\OutboxMessage;

/**
 * Event fired after a message was marked failed: the publisher gave up on it, or its attempts ran out.
 */
final class OutboxMessageFailedEvent
{
    public function __construct(
        private OutboxMessage $message,
        private string $error,
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
     * Why the message was given up on.
     */
    public function getError(): string
    {
        return $this->error;
    }
}
