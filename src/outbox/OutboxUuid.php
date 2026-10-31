<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use tommyknocker\pdodb\helpers\Uuid;

/**
 * Generate UUID v4 for outbox message ids.
 */
final class OutboxUuid
{
    public static function v4(): string
    {
        return Uuid::v4();
    }
}
