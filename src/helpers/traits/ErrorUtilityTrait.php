<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

/**
 * Trait for common error utility methods.
 */
trait ErrorUtilityTrait
{
    /**
     * Get retryable error codes for a specific driver.
     *
     * @param string $driver
     *
     * @return array<int|string>
     */
    public static function getRetryableErrors(string $driver): array
    {
        return match (strtolower($driver)) {
            'mysql' => self::getMysqlRetryableErrors(),
            'pgsql' => self::getPostgresqlRetryableErrors(),
            'sqlite' => self::getSqliteRetryableErrors(),
            default => [],
        };
    }

    /**
     * Check if an error code is retryable for a specific driver.
     *
     * @param int|string $errorCode
     * @param string $driver
     *
     * @return bool
     */
    public static function isRetryable(int|string $errorCode, string $driver): bool
    {
        return in_array($errorCode, self::getRetryableErrors($driver), true);
    }

    /**
     * Get human-readable error description.
     *
     * @param int|string $errorCode
     * @param string $driver
     *
     * @return string
     */
    public static function getDescription(int|string $errorCode, string $driver): string
    {
        $descriptions = match (strtolower($driver)) {
            'mysql' => self::getMysqlDescriptions(),
            'pgsql' => self::getPostgresqlDescriptions(),
            'sqlite' => self::getSqliteDescriptions(),
            default => [],
        };

        return $descriptions[$errorCode] ?? 'Unknown error';
    }
}
