<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\connection\DialectRegistry;

/**
 * Trait for common error utility methods.
 */
trait ErrorUtilityTrait
{
    /**
     * @return array<int|string>
     */
    public static function getRetryableErrors(string $driver): array
    {
        $normalizedDriver = mb_strtolower($driver, 'UTF-8');

        try {
            return DialectRegistry::resolve($normalizedDriver)->getRetryableErrorCodes();
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    public static function isRetryable(int|string $errorCode, string $driver): bool
    {
        return in_array($errorCode, self::getRetryableErrors($driver), true);
    }

    public static function getDescription(int|string $errorCode, string $driver): string
    {
        $normalizedDriver = mb_strtolower($driver, 'UTF-8');

        try {
            return DialectRegistry::resolve($normalizedDriver)->getErrorDescription($errorCode);
        } catch (\InvalidArgumentException) {
            return 'Unknown error';
        }
    }
}
