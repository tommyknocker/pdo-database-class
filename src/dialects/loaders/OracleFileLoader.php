<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\loaders;

/**
 * Oracle has no multi-row VALUES (before 23ai) and rejects a trailing semicolon, so a batch is one INSERT ALL.
 */
class OracleFileLoader extends FileLoader
{
    /**
     * {@inheritDoc}
     */
    protected function insertStatement(string $tableQuoted, array $columnsQuoted, array $rows): string
    {
        $into = ' INTO ' . $tableQuoted . ' (' . implode(', ', $columnsQuoted) . ') VALUES ';

        return 'INSERT ALL' . $into . implode($into, $rows) . ' SELECT 1 FROM DUAL';
    }
}
