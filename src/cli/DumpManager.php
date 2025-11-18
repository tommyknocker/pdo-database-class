<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\PdoDb;

/**
 * Facade for database dump and restore operations.
 */
class DumpManager
{
    /**
     * Generate SQL dump of database or table.
     *
     * @param PdoDb $db Database instance
     * @param string|null $table Table name (null = entire database)
     * @param bool $schemaOnly Only dump schema (no data)
     * @param bool $dataOnly Only dump data (no schema)
     * @param bool $dropTables Whether to add DROP TABLE IF EXISTS before CREATE TABLE
     *
     * @return string SQL dump content
     * @throws ResourceException If operation is not supported or fails
     */
    public static function dump(PdoDb $db, ?string $table = null, bool $schemaOnly = false, bool $dataOnly = false, bool $dropTables = true): string
    {
        $dialect = $db->schema()->getDialect();
        $driver = $dialect->getDriverName();

        $output = [];
        $output[] = '-- PDOdb Database Dump';
        $output[] = '-- Generated: ' . date('Y-m-d H:i:s');
        $output[] = '-- Driver: ' . $driver;
        if ($table !== null) {
            $output[] = '-- Table: ' . $table;
        }
        if ($schemaOnly) {
            $output[] = '-- Mode: Schema only';
        } elseif ($dataOnly) {
            $output[] = '-- Mode: Data only';
        }
        $output[] = '';

        if (!$dataOnly) {
            $schema = $dialect->dumpSchema($db, $table, $dropTables);
            if ($schema !== '') {
                $output[] = $schema;
                $output[] = '';
            }
        }

        if (!$schemaOnly) {
            $data = $dialect->dumpData($db, $table);
            if ($data !== '') {
                $output[] = $data;
            }
        }

        return implode("\n", $output);
    }

    /**
     * Restore database from SQL dump file.
     *
     * @param PdoDb $db Database instance
     * @param string $filePath Path to SQL dump file
     * @param bool $continueOnError Continue on errors (skip failed statements)
     *
     * @throws ResourceException If file not found or operation fails
     */
    public static function restore(PdoDb $db, string $filePath, bool $continueOnError = false): void
    {
        if (!file_exists($filePath)) {
            throw new ResourceException("Dump file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new ResourceException("Failed to read dump file: {$filePath}");
        }

        $dialect = $db->schema()->getDialect();
        $dialect->restoreFromSql($db, $content, $continueOnError);
    }
}
