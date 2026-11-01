<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\dialects\roles\DialectDumpInterface;
use tommyknocker\pdodb\dialects\SqlScriptSplitter;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\PdoDb;

/**
 * Default implementation of the dump and restore part of the dialect ({@see DialectDumpInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait DumpDefaults
{
    /**
     * {@inheritDoc}
     */
    public function dumpSchema(PdoDb $db, ?string $table = null, bool $dropTables = true): string
    {
        throw new ResourceException(
            'Database dump is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function dumpData(PdoDb $db, ?string $table = null): string
    {
        $tables = $table !== null ? [$table] : $this->listDumpTables($db);
        $output = [];

        foreach ($tables as $tableName) {
            $quotedTable = $this->quoteTable($tableName);
            $rows = $db->rawQuery("SELECT * FROM {$quotedTable}");
            if ($rows === []) {
                continue;
            }

            $columns = array_keys($rows[0]);
            $columnsList = implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns));

            foreach (array_chunk($rows, 100) as $batch) {
                $tuples = [];
                foreach ($batch as $row) {
                    $values = array_map(fn (string $column): string => $this->dumpValue($row[$column]), $columns);
                    $tuples[] = '(' . implode(', ', $values) . ')';
                }
                $output[] = $this->dumpInsertStatement($quotedTable, $columnsList, $tuples);
            }
        }

        return implode("\n\n", $output);
    }

    /**
     * {@inheritDoc}
     */
    public function restoreFromSql(PdoDb $db, string $sql, bool $continueOnError = false): void
    {
        $statements = $this->restoreSplitter()->split($sql);

        $errors = [];
        foreach ($statements as $index => $statement) {
            try {
                $db->rawQuery($statement);
            } catch (\Throwable $e) {
                if ($this->isIgnorableRestoreError($statement, $e)) {
                    continue;
                }
                if (!$continueOnError) {
                    throw new ResourceException($this->restoreFailureMessage($index, $statements, $e));
                }
                $errors[] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new ResourceException('Restore completed with ' . count($errors) . ' errors. First error: ' . $errors[0]);
        }
    }

    /**
     * Names of the tables a full dump covers.
     *
     * @return list<string>
     */
    protected function listDumpTables(PdoDb $db): array
    {
        throw new ResourceException('Database dump is not supported for ' . $this->getDriverName());
    }

    /**
     * One value as a SQL literal.
     */
    protected function dumpValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return $this->quoteStringLiteral((string)$value);
    }

    /**
     * An INSERT for a batch of rows, each already rendered as "(v1, v2, ...)".
     *
     * @param list<string> $tuples
     */
    protected function dumpInsertStatement(string $quotedTable, string $columnsList, array $tuples): string
    {
        return "INSERT INTO {$quotedTable} ({$columnsList}) VALUES\n" . implode(",\n", $tuples) . ';';
    }

    /**
     * How a dump script is cut into statements for this dialect.
     */
    protected function restoreSplitter(): SqlScriptSplitter
    {
        return new SqlScriptSplitter();
    }

    /**
     * An error a restore skips instead of reporting (for example dropping a table that is not there).
     */
    protected function isIgnorableRestoreError(string $statement, \Throwable $error): bool
    {
        return false;
    }

    /**
     * @param list<string> $statements
     */
    protected function restoreFailureMessage(int $index, array $statements, \Throwable $error): string
    {
        return 'Failed to execute SQL statement #' . ($index + 1) . ': ' . $error->getMessage()
            . "\nStatement: " . substr($statements[$index], 0, 200);
    }
}
