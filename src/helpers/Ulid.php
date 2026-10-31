<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/** Time-sortable ULID (26 Crockford base32 characters). */
final class Ulid
{
    protected const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $timestampMs = null): string
    {
        $time = $timestampMs ?? (int) floor(microtime(true) * 1000);
        if ($time < 0) {
            $time = 0;
        }

        $timeChars = '';
        for ($i = 0; $i < 10; $i++) {
            $timeChars = self::ALPHABET[$time % 32] . $timeChars;
            $time = intdiv($time, 32);
        }

        $random = '';
        for ($i = 0; $i < 16; $i++) {
            $random .= self::ALPHABET[random_int(0, 31)];
        }

        return $timeChars . $random;
    }
}
