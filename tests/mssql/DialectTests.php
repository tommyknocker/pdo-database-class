<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * DialectTests tests for mssql.
 */
final class DialectTests extends BaseMSSQLTestCase
{
    public function testDialectSpecificDifferences(): void
    {
        $db = self::$db;
        $connection = self::$db->connection;
        assert($connection !== null);

        $connection->query('IF OBJECT_ID(\'t_dialect\', \'U\') IS NOT NULL DROP TABLE t_dialect');
        $connection->query('CREATE TABLE t_dialect (id INT IDENTITY(1,1) PRIMARY KEY, str NVARCHAR(255), num INT, dt DATETIME2)');

        // Test SUBSTRING (MSSQL uses 1-based indexing)
        $id1 = $db->find()->table('t_dialect')->insert(['str' => 'MSSQL']);
        $row = $db->find()->table('t_dialect')
            ->select(['sub' => Db::substring('str', 1, 3)])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals('MSS', $row['sub']);
        // Should use SUBSTRING in MSSQL
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('SUBSTRING', $lastQuery);

        // Test MOD (MSSQL uses % operator)
        $id2 = $db->find()->table('t_dialect')->insert(['num' => 10]);
        $row = $db->find()->table('t_dialect')
            ->select(['mod_result' => Db::mod('num', '3')])
            ->where('id', $id2)
            ->getOne();
        $this->assertEquals(1, (int)$row['mod_result']);
        // Should use % in MSSQL
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('%', $lastQuery);

        // Test ISNULL (MSSQL) vs IFNULL (MySQL) vs COALESCE (PostgreSQL)
        $id3 = $db->find()->table('t_dialect')->insert(['str' => null]);
        $row = $db->find()->table('t_dialect')
            ->select(['result' => Db::ifNull('str', 'default')])
            ->where('id', $id3)
            ->getOne();
        $this->assertEquals('default', $row['result']);
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('ISNULL', $lastQuery);

        // Test GREATEST/LEAST (MSSQL supports these)
        $row = $db->find()->table('t_dialect')
            ->select([
                'max_val' => Db::greatest('5', '10', '3'),
                'min_val' => Db::least('5', '10', '3'),
            ])
            ->getOne();
        $this->assertEquals(10, (int)$row['max_val']);
        $this->assertEquals(3, (int)$row['min_val']);
        // Should use GREATEST/LEAST in MSSQL
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('GREATEST', $lastQuery);
    }

    public function testConcatUsesPlusOperator(): void
    {
        $db = self::$db;
        // Insert a row so we have data to query
        $id = $db->find()->table('users')->insert(['name' => 'TestConcat', 'age' => 25]);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        // Check last insert ID from connection
        $connection = $db->connection;
        assert($connection !== null);
        $lastInsertId = $connection->getLastInsertId();
        $this->assertNotFalse($lastInsertId, 'Last insert ID should be available');

        // Try to find row using the actual last insert ID instead of returned ID
        $checkRow = $db->rawQueryOne('SELECT * FROM [users] WHERE [id] = ?', [$lastInsertId]);
        if ($checkRow === false && $lastInsertId !== (string)$id) {
            // If lastInsertId differs from returned ID, try returned ID
            $checkRow = $db->rawQueryOne('SELECT * FROM [users] WHERE [id] = ?', [$id]);
        }
        $this->assertNotFalse($checkRow, 'Row should exist after insert');
        $this->assertIsArray($checkRow);

        // Use the ID that actually exists
        $actualId = $checkRow['id'] ?? $id;

        $row = $db->find()
            ->from('users')
            ->where('id', $actualId ?? $id)
            ->select([
                'concat_result' => Db::concat(Db::raw("'Hello'"), Db::raw("' '"), Db::raw("'World'")),
            ])
            ->getOne();
        $this->assertNotFalse($row, 'Row should be found');
        $this->assertIsArray($row);
        $this->assertEquals('Hello World', $row['concat_result']);
        $lastQuery = $db->lastQuery ?? '';
        // MSSQL uses + for concatenation
        $this->assertStringContainsString('+', $lastQuery);
    }

    public function testFormatSelectOptions(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $baseSql = 'SELECT * FROM users';

        // MSSQL doesn't support SELECT options like MySQL
        $withOptions = $dialect->formatSelectOptions($baseSql, ['FOR UPDATE']);
        $this->assertStringContainsString('FOR UPDATE', $withOptions);
    }

    public function testQuoteIdentifier(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        // Test simple identifier
        $this->assertEquals('[column_name]', $dialect->quoteIdentifier('column_name'));

        // Test identifier with brackets (note: quoteIdentifier doesn't escape)
        $quoted = $dialect->quoteIdentifier('test]column');
        $this->assertEquals('[test]column]', $quoted);

        // Test RawValue (should return as-is)
        $rawValue = new RawValue('COUNT(*)');
        $this->assertEquals('COUNT(*)', $dialect->quoteIdentifier($rawValue));

        // Test identifier with numbers
        $this->assertEquals('[column123]', $dialect->quoteIdentifier('column123'));

        // Test identifier with special characters
        $this->assertEquals('[column-name]', $dialect->quoteIdentifier('column-name'));
    }

    public function testQuoteTable(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        // Test simple table name
        $this->assertEquals('[users]', $dialect->quoteTable('users'));

        // Test schema-qualified table
        $quoted = $dialect->quoteTable('schema.users');
        $this->assertStringContainsString('[schema]', $quoted);
        $this->assertStringContainsString('[users]', $quoted);

        // Test table with alias (MSSQL quoteTable adds alias as-is after first space)
        $quoted = $dialect->quoteTable('users AS u');
        $this->assertStringContainsString('[users]', $quoted);
        $this->assertStringContainsString('AS u', $quoted);

        // Test table with database and schema
        $quoted = $dialect->quoteTable('database.schema.users');
        $this->assertStringContainsString('[database]', $quoted);
        $this->assertStringContainsString('[schema]', $quoted);
        $this->assertStringContainsString('[users]', $quoted);
    }

    public function testBuildDescribeSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $sql = $dialect->buildDescribeSql('users');
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('INFORMATION_SCHEMA', $sql);
        $this->assertStringContainsString('users', $sql);
    }

    public function testBuildShowIndexesSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $sql = $dialect->buildShowIndexesSql('users');
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('sys.indexes', $sql);
        $this->assertStringContainsString('users', $sql);
    }

    public function testBuildShowForeignKeysSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $sql = $dialect->buildShowForeignKeysSql('users');
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('sys.foreign_keys', $sql);
        $this->assertStringContainsString('users', $sql);
    }

    public function testBuildShowConstraintsSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $sql = $dialect->buildShowConstraintsSql('users');
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('INFORMATION_SCHEMA', $sql);
        $this->assertStringContainsString('users', $sql);
    }
}
