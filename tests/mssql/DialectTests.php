<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;

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
                'concat_result' => Db::concat('Hello', ' ', 'World'),
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
}

