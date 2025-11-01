<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\interfaces;

interface ParameterManagerInterface
{
    /**
     * Make parameter name.
     *
     * @param string $name
     *
     * @return string
     */
    public function makeParam(string $name): string;

    /**
     * Create parameter and register it in params array.
     *
     * @param string $name
     * @param mixed $value
     *
     * @return string The placeholder name
     */
    public function addParam(string $name, mixed $value): string;

    /**
     * Set parameter directly.
     *
     * @param string $name
     * @param mixed $value
     *
     * @return static
     */
    public function setParam(string $name, mixed $value): self;

    /**
     * Merge subquery parameters.
     *
     * @param array<string, string|int|float|bool|null> $subParams
     * @param string $prefix
     *
     * @return array<string, string>
     */
    public function mergeSubParams(array $subParams, string $prefix = 'sub'): array;

    /**
     * Replace placeholders in SQL.
     *
     * @param string $sql
     * @param array<string, string> $map
     *
     * @return string
     */
    public function replacePlaceholdersInSql(string $sql, array $map): string;

    /**
     * Normalize parameters.
     *
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return array<int|string, string|int|float|bool|null>
     */
    public function normalizeParams(array $params): array;

    /**
     * Get all parameters.
     *
     * @return array<string, string|int|float|bool|null>
     */
    public function getParams(): array;

    /**
     * Set parameters.
     *
     * @param array<string, string|int|float|bool|null> $params
     *
     * @return static
     */
    public function setParams(array $params): self;

    /**
     * Clear all parameters.
     *
     * @return static
     */
    public function clearParams(): self;
}
