<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\interfaces;

use PDOStatement;
use tommyknocker\pdodb\helpers\RawValue;

interface ExecutionEngineInterface
{
    /**
     * Execute statement.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return PDOStatement
     */
    public function executeStatement(string|RawValue $sql, array $params = []): PDOStatement;

    /**
     * Fetch all rows.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string|RawValue $sql, array $params = []): array;

    /**
     * Fetch column.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return mixed
     */
    public function fetchColumn(string|RawValue $sql, array $params = []): mixed;

    /**
     * Fetch row.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return mixed
     */
    public function fetch(string|RawValue $sql, array $params = []): mixed;

    /**
     * Execute INSERT statement.
     *
     * @param string $sql
     * @param array<string, string|int|float|bool|null> $params
     * @param bool $isMulty
     *
     * @return int
     */
    public function executeInsert(string $sql, array $params, bool $isMulty = false): int;

    /**
     * Set fetch mode.
     *
     * @param int $fetchMode The fetch mode.
     *
     * @return self The current instance.
     */
    public function setFetchMode(int $fetchMode): self;
}
