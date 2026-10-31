<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use tommyknocker\pdodb\exceptions\OutboxException;

/**
 * Parse relative duration strings for outbox retention (e.g. 7d, 24h, 30m).
 */
final class OutboxDuration
{
    /**
     * @return string ISO-8601 UTC timestamp: now minus the parsed duration.
     */
    public static function cutoffIso8601(string $spec): string
    {
        $seconds = self::toSeconds($spec);

        return gmdate('Y-m-d\TH:i:s\Z', time() - $seconds);
    }

    public static function toSeconds(string $spec): int
    {
        $trimmed = trim($spec);
        if ($trimmed === '') {
            throw new OutboxException('Duration must not be empty (example: 7d, 24h, 30m).');
        }

        if (!preg_match('/^(\d+)([smhd])$/i', $trimmed, $matches)) {
            throw new OutboxException(
                'Invalid duration "' . $spec . '". Use a positive number with suffix s, m, h, or d (e.g. 7d).'
            );
        }

        $amount = (int) $matches[1];
        if ($amount < 1) {
            throw new OutboxException('Duration must be at least 1 second.');
        }

        $unit = strtolower($matches[2]);

        return match ($unit) {
            's' => $amount,
            'm' => $amount * 60,
            'h' => $amount * 3600,
            'd' => $amount * 86400,
            default => throw new OutboxException('Unsupported duration unit.'),
        };
    }
}
