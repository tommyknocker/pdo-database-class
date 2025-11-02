<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\helpers\Db;

/**
 * DialectTests tests for mysql.
 */
final class DialectTests extends BaseMySQLTestCase
{
    public function testDialectSpecificDifferences(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS t_dialect');
        $db->rawQuery('CREATE TABLE t_dialect (id INT AUTO_INCREMENT PRIMARY KEY, str VARCHAR(255), num INT)');

        // Test SUBSTRING (MySQL) vs SUBSTR (SQLite)
        $id1 = $db->find()->table('t_dialect')->insert(['str' => 'MySQL']);
        $row = $db->find()->table('t_dialect')
        ->select(['sub' => Db::substring('str', 1, 3)])
        ->where('id', $id1)
        ->getOne();
        $this->assertEquals('MyS', $row['sub']);
        // Should use SUBSTRING in MySQL
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('SUBSTRING', $lastQuery);

        // Test MOD (MySQL) vs % (SQLite)
        $id2 = $db->find()->table('t_dialect')->insert(['num' => 10]);
        $row = $db->find()->table('t_dialect')
        ->select(['mod_result' => Db::mod('num', '3')])
        ->where('id', $id2)
        ->getOne();
        $this->assertEquals(1, (int)$row['mod_result']);
        // Should use MOD in MySQL
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('MOD', $lastQuery);

        // Test IFNULL (MySQL) vs COALESCE (PostgreSQL)
        $id3 = $db->find()->table('t_dialect')->insert(['str' => null]);
        $row = $db->find()->table('t_dialect')
        ->select(['result' => Db::ifNull('str', 'default')])
        ->where('id', $id3)
        ->getOne();
        $this->assertEquals('default', $row['result']);
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('IFNULL', $lastQuery);

        // Test GREATEST/LEAST (MySQL/PostgreSQL) vs MAX/MIN (SQLite)
        $row = $db->find()->table('t_dialect')
        ->select([
        'max_val' => Db::greatest('5', '10', '3'),
        'min_val' => Db::least('5', '10', '3'),
        ])
        ->getOne();
        $this->assertEquals(10, (int)$row['max_val']);
        $this->assertEquals(3, (int)$row['min_val']);
        // Should use GREATEST/LEAST in MySQL
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('GREATEST', $lastQuery);
    }

    public function testBuildLoadCsvSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        // Create temp file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile, "id,name\n1,John\n");

        // Test basic CSV load SQL generation
        $sql = $dialect->buildLoadCsvSql('users', $tempFile, []);

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('LOAD DATA', $sql);
        $this->assertStringContainsString('users', $sql);

        // Test with options
        $tempFile2 = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile2, "id;name;price\n1;Product;99.99\n");

        $sql2 = $dialect->buildLoadCsvSql('products', $tempFile2, [
        'fieldChar' => ';',
        'fieldEnclosure' => '"',
        'fields' => ['id', 'name', 'price'],
        'local' => true,
        'linesToIgnore' => 1,
        ]);

        $this->assertStringContainsString('LOAD DATA LOCAL INFILE', $sql2);
        $this->assertStringContainsString('FIELDS TERMINATED BY', $sql2);
        $this->assertStringContainsString('IGNORE 1 LINES', $sql2);

        // Cleanup
        unlink($tempFile);
        unlink($tempFile2);
    }

    public function testBuildLoadXmlSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        // Create temp XML file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'xml_');
        file_put_contents($tempFile, '<users><user><id>1</id><name>John</name></user></users>');

        $sql = $dialect->buildLoadXML('users', $tempFile, [
        'rowTag' => '<user>',
        'linesToIgnore' => 0,
        ]);

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('LOAD XML', $sql);
        $this->assertStringContainsString('users', $sql);

        // Test with different options
        $tempFile2 = tempnam(sys_get_temp_dir(), 'xml_');
        file_put_contents($tempFile2, '<products><product><id>1</id></product></products>');

        $sql2 = $dialect->buildLoadXML('products', $tempFile2, [
        'rowTag' => '<product>',
        'linesToIgnore' => 2,
        ]);

        $this->assertStringContainsString('LOAD XML', $sql2);

        // Cleanup
        unlink($tempFile);
        unlink($tempFile2);
    }

    public function testBuildLoadJsonSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        // Test 1: Basic JSON array format
        $tempFile = tempnam(sys_get_temp_dir(), 'json_');
        file_put_contents($tempFile, '[{"id":1,"name":"John","age":30},{"id":2,"name":"Alice","age":25}]');

        $sql = $dialect->buildLoadJson('users', $tempFile, []);

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('INSERT', $sql);
        $this->assertStringContainsString('users', $sql);

        // Test 2: NDJSON format (newline-delimited)
        $tempFile2 = tempnam(sys_get_temp_dir(), 'json_');
        file_put_contents($tempFile2, '{"id":1,"name":"Bob","age":35}' . "\n" . '{"id":2,"name":"Carol","age":28}');

        $sql2 = $dialect->buildLoadJson('users', $tempFile2, [
        'format' => 'lines',
        ]);

        $this->assertNotEmpty($sql2);
        $this->assertStringContainsString('INSERT', $sql2);

        // Test 3: JSON with nested objects
        $tempFile3 = tempnam(sys_get_temp_dir(), 'json_');
        file_put_contents($tempFile3, '[{"id":1,"name":"Test","meta":{"city":"NYC","status":"active"}}]');

        $sql3 = $dialect->buildLoadJson('users', $tempFile3, []);

        $this->assertNotEmpty($sql3);

        // Test 4: Empty JSON file
        $tempFile4 = tempnam(sys_get_temp_dir(), 'json_');
        file_put_contents($tempFile4, '[]');

        $sql4 = $dialect->buildLoadJson('users', $tempFile4, []);

        $this->assertEquals('', $sql4);

        // Test 5: JSON with specified columns
        $tempFile5 = tempnam(sys_get_temp_dir(), 'json_');
        file_put_contents($tempFile5, '[{"id":1,"extra":"ignored"},{"id":2,"extra":"ignored"}]');

        $sql5 = $dialect->buildLoadJson('users', $tempFile5, [
        'columns' => ['id'],
        ]);

        $this->assertNotEmpty($sql5);
        $this->assertStringContainsString('`id`', $sql5);

        // Cleanup
        unlink($tempFile);
        unlink($tempFile2);
        unlink($tempFile3);
        unlink($tempFile4);
        unlink($tempFile5);
    }

    public function testFormatSelectOptions(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $baseSql = 'SELECT * FROM users';

        // Test with DISTINCT
        $withDistinct = $dialect->formatSelectOptions($baseSql, ['DISTINCT']);
        $this->assertStringContainsString('DISTINCT', $withDistinct);

        // Test with SQL_NO_CACHE
        $withNoCache = $dialect->formatSelectOptions($baseSql, ['SQL_NO_CACHE']);
        $this->assertStringContainsString('SQL_NO_CACHE', $withNoCache);

        // Test with multiple options
        $withMultiple = $dialect->formatSelectOptions($baseSql, ['DISTINCT', 'SQL_CALC_FOUND_ROWS']);
        $this->assertStringContainsString('DISTINCT', $withMultiple);
        $this->assertStringContainsString('SQL_CALC_FOUND_ROWS', $withMultiple);
    }

    public function testBuildExplainSqlVariations(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $query = 'SELECT * FROM users WHERE age > 18';

        // Test basic EXPLAIN
        $explain = $dialect->buildExplainSql($query, false);
        $this->assertStringContainsString('EXPLAIN', $explain);
        $this->assertStringContainsString($query, $explain);

        // Test with analyze flag (MySQL currently ignores it for compatibility)
        $analyze = $dialect->buildExplainSql($query, true);
        $this->assertStringContainsString('EXPLAIN', $analyze);
        // MySQL EXPLAIN ANALYZE returns tree format which is incompatible with table format
        // So we just verify EXPLAIN is present
        $this->assertStringContainsString($query, $analyze);
    }

    public function testFulltextMatchHelper(): void
    {
        $fulltext = Db::match('title, content', 'search term', 'natural');
        $this->assertInstanceOf(\tommyknocker\pdodb\helpers\values\FulltextMatchValue::class, $fulltext);
    }

    public function testDistinctOnThrowsExceptionOnMySQL(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DISTINCT ON is not supported');

        self::$db->find()
        ->from('test_users')
        ->distinctOn('email')
        ->get();
    }
}
