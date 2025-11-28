<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

/**
 * Environment file loader for .env files.
 *
 * Provides unified .env file parsing that can be used by both
 * CLI tools and PdoDb class directly.
 */
class EnvLoader
{
    /**
     * Load .env file and set environment variables.
     *
     * @param string|null $envPath Custom path to .env file. If null, uses current working directory.
     * @param bool $overwriteExisting Whether to overwrite existing environment variables.
     *
     * @return bool True if file was loaded, false if file doesn't exist.
     */
    public static function load(?string $envPath = null, bool $overwriteExisting = false): bool
    {
        $envFile = $envPath ?? static::getDefaultEnvPath();

        if (!file_exists($envFile)) {
            return false;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments
            if (str_starts_with($line, '#')) {
                continue;
            }
            // Parse KEY=VALUE
            $equalsPos = strpos($line, '=');
            if ($equalsPos !== false && $equalsPos > 0) {
                $keyPart = substr($line, 0, $equalsPos);
                $valuePart = substr($line, $equalsPos + 1);
                // substr can only return false if start > length, which is impossible here
                /** @var string $keyPart */
                /** @var string $valuePart */
                $key = trim($keyPart);
                $value = trim($valuePart);
                // Remove quotes if present
                $value = trim($value, '"\'');

                // Set environment variable
                if ($overwriteExisting || getenv($key) === false) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                }
            }
        }

        return true;
    }

    /**
     * Load .env file and return variables as array (without setting environment variables).
     *
     * @param string|null $envPath Custom path to .env file.
     *
     * @return array<string, string> Associative array of key-value pairs.
     */
    public static function loadAsArray(?string $envPath = null): array
    {
        $envFile = $envPath ?? static::getDefaultEnvPath();
        $result = [];

        if (!file_exists($envFile)) {
            return $result;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $result;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments
            if (str_starts_with($line, '#')) {
                continue;
            }
            // Parse KEY=VALUE
            $equalsPos = strpos($line, '=');
            if ($equalsPos !== false && $equalsPos > 0) {
                $keyPart = substr($line, 0, $equalsPos);
                $valuePart = substr($line, $equalsPos + 1);
                /** @var string $keyPart */
                /** @var string $valuePart */
                $key = trim($keyPart);
                $value = trim($valuePart);
                // Remove quotes if present
                $value = trim($value, '"\'');
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get default .env file path.
     *
     * @return string Path to .env file in current working directory.
     */
    public static function getDefaultEnvPath(): string
    {
        $customEnv = getenv('PDODB_ENV_PATH');
        return ($customEnv !== false && is_string($customEnv) && $customEnv !== '')
            ? (string)$customEnv
            : (getcwd() . '/.env');
    }
}
