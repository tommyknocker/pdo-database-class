<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\config;

/**
 * Parsing and display of server setting values (`512M`, `8388608`, `1GB`).
 */
final class ConfigValue
{
    /**
     * Format bytes to human-readable format.
     *
     * @param int $bytes Bytes to format
     *
     * @return string Formatted string
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Normalize value for comparison.
     *
     * @param string|null $value Value to normalize
     *
     * @return string Normalized value
     */
    public static function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        // Convert to lowercase and remove whitespace
        $value = strtolower(trim($value));

        // Try to parse as number (bytes)
        if (preg_match('/^(\d+)$/', $value, $matches)) {
            return $matches[1];
        }

        // Try to parse with units (e.g., "1G", "512M")
        if (preg_match('/^(\d+)([kmgt]?)$/i', $value, $matches)) {
            $num = (int)$matches[1];
            $unit = $matches[2] !== '' ? strtolower($matches[2]) : '';
            $multiplier = match ($unit) {
                'k' => 1024,
                'm' => 1024 * 1024,
                'g' => 1024 * 1024 * 1024,
                't' => 1024 * 1024 * 1024 * 1024,
                default => 1,
            };
            return (string)($num * $multiplier);
        }

        return $value;
    }

    /**
     * Format value for display.
     *
     * @param string $value Value to format
     *
     * @return string Formatted value
     */
    public static function format(string $value): string
    {
        // If it's a number, try to format as bytes
        if (preg_match('/^\d+$/', $value)) {
            $bytes = (int)$value;
            if ($bytes > 1024) {
                return self::formatBytes($bytes);
            }
            return $value;
        }

        return $value;
    }
}
