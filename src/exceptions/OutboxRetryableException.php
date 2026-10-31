<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

use RuntimeException;

/**
 * Publish failed temporarily; the outbox worker will retry with backoff.
 */
class OutboxRetryableException extends RuntimeException
{
}
