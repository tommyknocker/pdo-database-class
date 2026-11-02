<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use tommyknocker\pdodb\outbox\OutboxMessage;

/**
 * Event fired after a worker recorded that a message was delivered (its status is now published).
 */
final class OutboxMessagePublishedEvent
{
    public function __construct(
        private OutboxMessage $message,
    ) {
    }

    /**
     * The message as it was claimed (its attempt count includes this attempt).
     */
    public function getMessage(): OutboxMessage
    {
        return $this->message;
    }
}
