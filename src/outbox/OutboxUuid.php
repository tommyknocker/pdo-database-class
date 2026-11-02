<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use tommyknocker\pdodb\helpers\Uuid;

/**
 * Outbox message ids.
 */
final class OutboxUuid
{
    public static function v4(): string
    {
        return Uuid::v4();
    }

    /**
     * An id that sorts after every id this process generated before it. Per-key ordering of messages relies on it.
     */
    public static function ordered(): string
    {
        return Uuid::v7();
    }
}
