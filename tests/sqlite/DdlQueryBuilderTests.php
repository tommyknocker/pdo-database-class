<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\exceptions\QueryException;

/**
 * SQLite-specific DDL Query Builder tests.
 *
 * These tests verify SQLite-specific behavior and limitations.
 */
final class DdlQueryBuilderTests extends BaseSqliteTestCase
{
    /**
     * Test drop column with SQLite version check.
     *
     * SQLite 3.35.0+ supports DROP COLUMN.
     * Older versions will throw an exception.
     */
    public function testDropColumnWithVersionCheck(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $version = $db->rawQueryValue('SELECT sqlite_version()');
        $this->assertNotNull($version, 'SQLite version should be retrievable');

        $schema->dropTableIfExists('test_ddl_drop_col_sqlite');

        // Create table with column
        $schema->createTable('test_ddl_drop_col_sqlite', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        if (version_compare($version, '3.35.0', '<')) {
            // Older SQLite versions don't support DROP COLUMN
            // Verify that an exception is thrown
            $this->expectException(QueryException::class);
            $schema->dropColumn('test_ddl_drop_col_sqlite', 'name');
        } else {
            // SQLite 3.35.0+ supports DROP COLUMN
            $schema->dropColumn('test_ddl_drop_col_sqlite', 'name');

            $columns = $db->describe('test_ddl_drop_col_sqlite');
            $columnNames = array_column($columns, 'Field') ?: array_column($columns, 'column_name') ?: array_column($columns, 'name');
            $this->assertNotContains('name', $columnNames);
        }

        // Cleanup
        $schema->dropTable('test_ddl_drop_col_sqlite');
    }
}
