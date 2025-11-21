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
     * Get retryable error codes for a specific driver.
     *
     * @param string $driver
     *
     * @return array<int|string>
     */
    public static function getRetryableErrors(string $driver): array
    {
        // First try to use trait methods if available (for backward compatibility)
        $normalizedDriver = strtolower($driver);

        $methodMap = [
            'mysql' => 'getMysqlRetryableErrors',
            'mariadb' => 'getMysqlRetryableErrors',
            'pgsql' => 'getPostgresqlRetryableErrors',
            'sqlite' => 'getSqliteRetryableErrors',
            'sqlsrv' => 'getMssqlRetryableErrors',
        ];

        if (isset($methodMap[$normalizedDriver])) {
            $method = $methodMap[$normalizedDriver];
            if (method_exists(static::class, $method)) {
                return static::$method();
            }
        }

        // Fallback to dialect methods
        try {
            $dialect = DialectRegistry::resolve($normalizedDriver);
            return $dialect->getRetryableErrorCodes();
        } catch (\InvalidArgumentException $e) {
            // Driver not supported, return empty array
            return [];
        }
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
        // First try to use trait methods if available (for backward compatibility)
        $normalizedDriver = strtolower($driver);

        $methodMap = [
            'mysql' => 'getMysqlDescriptions',
            'mariadb' => 'getMysqlDescriptions',
            'pgsql' => 'getPostgresqlDescriptions',
            'sqlite' => 'getSqliteDescriptions',
            'sqlsrv' => 'getMssqlDescriptions',
        ];

        if (isset($methodMap[$normalizedDriver])) {
            $method = $methodMap[$normalizedDriver];
            if (method_exists(static::class, $method)) {
                $descriptions = static::$method();
                return $descriptions[$errorCode] ?? 'Unknown error';
            }
        }

        // Fallback to dialect methods
        try {
            $dialect = DialectRegistry::resolve($normalizedDriver);
            return $dialect->getErrorDescription($errorCode);
        } catch (\InvalidArgumentException $e) {
            // Driver not supported, return default message
            return 'Unknown error';
        }
    }
}
