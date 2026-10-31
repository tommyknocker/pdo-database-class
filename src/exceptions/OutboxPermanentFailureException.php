<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

use RuntimeException;

/**
 * Publish failed permanently; the outbox worker marks the message as failed.
 */
class OutboxPermanentFailureException extends RuntimeException
{
}
