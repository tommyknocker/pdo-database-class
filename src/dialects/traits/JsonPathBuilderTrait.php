<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use JsonException;

trait JsonPathBuilderTrait
{
    /**
     * Build JSON path string for MySQL/SQLite format.
     *
     * @param array<int, string|int> $parts
     */
    protected function buildJsonPathString(array $parts): string
    {
        $jsonPath = '$';
        foreach ($parts as $p) {
            if (preg_match('/^\d+$/', (string)$p)) {
                $jsonPath .= '[' . $p . ']';
            } else {
                $jsonPath .= '.' . $p;
            }
        }
        return $jsonPath;
    }

    /**
     * Build PostgreSQL JSON path array.
     *
     * @param array<int, string|int> $parts
     */
    protected function buildPostgreSqlJsonPath(array $parts): string
    {
        return 'ARRAY[' . implode(',', array_map(fn ($p) => "'" . str_replace("'", "''", (string)$p) . "'", $parts)) . ']';
    }

    /**
     * Build PostgreSQL JSON path for jsonb_set operations.
     *
     * @param array<int, string|int> $parts
     */
    protected function buildPostgreSqlJsonbPath(array $parts): string
    {
        return '{' . implode(',', array_map(fn ($p) => str_replace('}', '\\}', (string)$p), $parts)) . '}';
    }

    /**
     * Generate unique parameter name.
     */
    protected function generateParameterName(string $prefix, string $context): string
    {
        return ':' . $prefix . '_' . substr(md5($context), 0, 8);
    }

    /**
     * Encode value to JSON with error handling.
     *
     * @throws JsonException
     */
    protected function encodeToJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return json_encode((string)$value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Check if string looks like JSON.
     */
    protected function looksLikeJson(string $value): bool
    {
        $trim = trim($value);
        return $trim === 'null'
            || $trim === 'true'
            || $trim === 'false'
            || (strlen($trim) > 0 && ($trim[0] === '{' || $trim[0] === '[' || $trim[0] === '"'));
    }

    /**
     * Check if path segment is numeric index.
     */
    protected function isNumericIndex(string|int $segment): bool
    {
        return preg_match('/^\d+$/', (string)$segment) === 1;
    }

    /**
     * Get last segment from path parts.
     *
     * @param array<int, string|int> $parts
     */
    protected function getLastSegment(array $parts): string|int
    {
        $last = end($parts);
        if ($last === false) {
            throw new \InvalidArgumentException('Cannot get last segment from empty array');
        }
        return $last;
    }

    /**
     * Get parent path parts (all except last).
     *
     * @param array<int, string|int> $parts
     * @return array<int, string|int>
     */
    protected function getParentPathParts(array $parts): array
    {
        if (count($parts) <= 1) {
            return [];
        }
        return array_slice($parts, 0, -1);
    }
}
