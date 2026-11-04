<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDO;
use PDOStatement;

/**
 * A PDO that remembers the SQL sent through it, to count what a library call really asks the database.
 */
final class QueryRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->statements[] = $query;

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->statements[] = $query;

        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;

        return parent::exec($statement);
    }

    /**
     * The statements that are not the INSERT itself: what the library asked about the table.
     *
     * @return list<string>
     */
    public function metadataStatements(): array
    {
        return array_values(array_filter($this->statements, static fn (string $sql): bool => preg_match('/^\s*INSERT\b/i', $sql) !== 1));
    }
}
