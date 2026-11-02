<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysqlfamily;

/**
 * The alias MySQL 8.0.19+ gives the inserted row in `INSERT ... VALUES (...) AS pdodb_new (pdodb_c0, pdodb_c1)
 * ON DUPLICATE KEY UPDATE col = pdodb_new.pdodb_c0`, which replaces the deprecated `VALUES(col)`.
 *
 * The alias gets column names of its own. Without them `col` in the update expressions would exist twice (in the table
 * and in the alias) and any unqualified `col` the caller writes, as in `Db::raw('age + 5')`, would be ambiguous.
 */
final class MySQLRowAlias
{
    public const NAME = 'pdodb_new';

    /** @var list<string> */
    private array $columns;

    /**
     * @param list<string> $quotedColumns The inserted columns, quoted, in insert order
     */
    public function __construct(array $quotedColumns)
    {
        $this->columns = array_values($quotedColumns);
    }

    /**
     * The `AS ...` part that follows the VALUES list.
     */
    public function declaration(): string
    {
        $names = [];
        foreach (array_keys($this->columns) as $i) {
            $names[] = $this->columnName($i);
        }

        return 'AS ' . self::NAME . ' (' . implode(', ', $names) . ')';
    }

    /**
     * The new row's value of a column. A column that is not inserted has no alias column and keeps `VALUES(col)`.
     */
    public function value(string $quotedColumn): string
    {
        $position = array_search($quotedColumn, $this->columns, true);

        return $position === false
            ? "VALUES({$quotedColumn})"
            : self::NAME . '.' . $this->columnName($position);
    }

    private function columnName(int $position): string
    {
        return 'pdodb_c' . $position;
    }
}
