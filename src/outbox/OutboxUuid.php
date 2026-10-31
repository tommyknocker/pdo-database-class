<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

/**
 * Generate UUID v4 for outbox message ids.
 */
final class OutboxUuid
{
    public static function v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($data), 4)
        );
    }
}
