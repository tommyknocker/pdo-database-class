<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use tommyknocker\pdodb\dialects\DialectInterface;

/**
 * JSON access and modification expressions.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectJsonInterface
{
    /**
     * Format JSON_GET expression.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param bool $asText
     *
     * @return string
     */
    public function formatJsonGet(string $col, array|string $path, bool $asText = true): string;

    /**
     * Format JSON_CONTAINS expression.
     *
     * @param string $col
     * @param mixed $value
     * @param array<int, string|int>|string|null $path
     *
     * @return array<int|string, mixed>|string
     */
    public function formatJsonContains(string $col, mixed $value, array|string|null $path = null): array|string;

    /**
     * Format JSON_SET expression.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param mixed $value
     *
     * @return array<int|string, mixed>
     */
    public function formatJsonSet(string $col, array|string $path, mixed $value): array;

    /**
     * Format JSON_REMOVE expression.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     *
     * @return string
     */
    public function formatJsonRemove(string $col, array|string $path): string;

    /**
     * Format JSON_REPLACE expression (only replaces if path exists).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param mixed $value
     *
     * @return array<int|string, mixed> [sql, [param => value]]
     */
    public function formatJsonReplace(string $col, array|string $path, mixed $value): array;

    /**
     * Format JSON_EXISTS expression.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     *
     * @return string
     */
    public function formatJsonExists(string $col, array|string $path): string;

    /**
     * Format JSON order expression.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     *
     * @return string
     */
    public function formatJsonOrderExpr(string $col, array|string $path): string;

    /**
     * Format JSON_LENGTH expression.
     *
     * @param string $col
     * @param array<int, string|int>|string|null $path
     *
     * @return string
     */
    public function formatJsonLength(string $col, array|string|null $path = null): string;

    /**
     * Format JSON_KEYS expression.
     *
     * @param string $col
     * @param array<int, string|int>|string|null $path
     *
     * @return string
     */
    public function formatJsonKeys(string $col, array|string|null $path = null): string;

    /**
     * Format JSON_TYPE expression.
     *
     * @param string $col
     * @param array<int, string|int>|string|null $path
     *
     * @return string
     */
    public function formatJsonType(string $col, array|string|null $path = null): string;
}
