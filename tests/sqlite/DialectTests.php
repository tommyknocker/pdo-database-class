<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\helpers\values\FulltextMatchValue;

/**
 * DialectTests tests for sqlite.
 */
final class DialectTests extends BaseSqliteTestCase
{
    public function testDialectSpecificDifferences(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS t_dialect');
        $db->rawQuery('CREATE TABLE t_dialect (id INTEGER PRIMARY KEY AUTOINCREMENT, str TEXT, num INTEGER)');

        // Test SUBSTR (SQLite) vs SUBSTRING (MySQL/PostgreSQL)
        $id1 = $db->find()->table('t_dialect')->insert(['str' => 'SQLite']);
        $row = $db->find()->table('t_dialect')
        ->select(['sub' => Db::substring('str', 1, 3)])
        ->where('id', $id1)
        ->getOne();
        $this->assertEquals('SQL', $row['sub']);
        // Should use SUBSTR in SQLite
        $this->assertStringContainsString('SUBSTR', $db->lastQuery);

        // Test % (SQLite) vs MOD (MySQL/PostgreSQL)
        $id2 = $db->find()->table('t_dialect')->insert(['num' => 10]);
        $row = $db->find()->table('t_dialect')
        ->select(['mod_result' => Db::mod('num', '3')])
        ->where('id', $id2)
        ->getOne();
        $this->assertEquals(1, (int)$row['mod_result']);
        // Should use % in SQLite
        $this->assertStringContainsString('%', $db->lastQuery);

        // Test IFNULL (SQLite/MySQL) - same in SQLite
        $id3 = $db->find()->table('t_dialect')->insert(['str' => null]);
        $row = $db->find()->table('t_dialect')
        ->select(['result' => Db::ifNull('str', 'default')])
        ->where('id', $id3)
        ->getOne();
        $this->assertEquals('default', $row['result']);
        $this->assertStringContainsString('IFNULL', $db->lastQuery);

        // Test MIN/MAX (SQLite) vs LEAST/GREATEST (MySQL/PostgreSQL)
        $row = $db->find()->table('t_dialect')
        ->select([
        'max_val' => Db::greatest('5', '10', '3'),
        'min_val' => Db::least('5', '10', '3'),
        ])
        ->getOne();
        $this->assertEquals(10, (int)$row['max_val']);
        $this->assertEquals(3, (int)$row['min_val']);
        // Should use MAX/MIN in SQLite
        $this->assertStringContainsString('MAX', $db->lastQuery);
    }

    public function testBuildLoadCsvSql(): void
    {
        $dialect = self::$db->connection->getDialect();

        // Test 1: Basic CSV
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile, "id,name\n1,John\n");

        $sql = $dialect->buildLoadCsvSql('users', $tempFile, []);

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('INSERT', $sql);
        $this->assertStringContainsString('users', $sql);

        // Test 2: CSV with options (fieldChar, linesToIgnore)
        $tempFile2 = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile2, "id;name;price\n1;Product;99.99\n");

        $sql2 = $dialect->buildLoadCsvSql('products', $tempFile2, [
        'fieldChar' => ';',
        'fields' => ['id', 'name', 'price'],
        'linesToIgnore' => 1,
        ]);

        $this->assertStringContainsString('INSERT', $sql2);

        // Test 3: CSV with empty values (treated as empty strings by CSV parser)
        $tempFile3 = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile3, "id,name,value\n1,Test,\n2,,100\n");

        $sql3 = $dialect->buildLoadCsvSql('test_table', $tempFile3, [
        'fields' => ['id', 'name', 'value'],
        ]);

        $this->assertStringContainsString('INSERT', $sql3);
        // Empty CSV cells are treated as empty strings, not NULL
        $this->assertStringContainsString("''", $sql3);

        // Test 4: CSV with more columns than expected
        $tempFile4 = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile4, "id,name,extra1,extra2\n1,John,X,Y\n");

        $sql4 = $dialect->buildLoadCsvSql('users', $tempFile4, [
        'fields' => ['id', 'name'],
        ]);

        $this->assertStringContainsString('INSERT', $sql4);

        // Test 5: CSV with less columns than expected
        $tempFile5 = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile5, "id\n1\n");

        $sql5 = $dialect->buildLoadCsvSql('users', $tempFile5, [
        'fields' => ['id', 'name', 'age'],
        ]);

        $this->assertStringContainsString('INSERT', $sql5);

        // Test 6: Empty CSV file (returns empty string)
        $tempFile6 = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile6, '');

        $sql6 = $dialect->buildLoadCsvSql('users', $tempFile6, [
        'fields' => ['id', 'name'],
        ]);

        $this->assertEquals('', $sql6);

        // Test 7: CSV with only blank lines
        $tempFile7 = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile7, "\n\n\n");

        $sql7 = $dialect->buildLoadCsvSql('users', $tempFile7, [
        'fields' => ['id', 'name'],
        ]);

        $this->assertEquals('', $sql7);

        // Cleanup
        unlink($tempFile);
        unlink($tempFile2);
        unlink($tempFile3);
        unlink($tempFile4);
        unlink($tempFile5);
        unlink($tempFile6);
        unlink($tempFile7);
    }

    public function testBuildLoadXmlSql(): void
    {
        $dialect = self::$db->connection->getDialect();

        // Test 1: Basic XML
        $tempFile = tempnam(sys_get_temp_dir(), 'xml_');
        file_put_contents($tempFile, '<users><user><id>1</id><name>John</name></user></users>');

        $sql = $dialect->buildLoadXML('users', $tempFile, [
        'rowTag' => '<user>',
        'linesToIgnore' => 0,
        ]);

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('INSERT', $sql);
        $this->assertStringContainsString('users', $sql);

        // Test 2: XML with attributes
        $tempFile2 = tempnam(sys_get_temp_dir(), 'xml_');
        file_put_contents($tempFile2, '<users><user id="1" name="Alice"><age>30</age></user></users>');

        $sql2 = $dialect->buildLoadXML('users', $tempFile2, [
        'rowTag' => '<user>',
        ]);

        $this->assertNotEmpty($sql2);
        $this->assertStringContainsString('INSERT', $sql2);

        // Test 3: XML with empty elements
        $tempFile3 = tempnam(sys_get_temp_dir(), 'xml_');
        file_put_contents($tempFile3, '<users><user><id>1</id><name></name></user></users>');

        $sql3 = $dialect->buildLoadXML('users', $tempFile3, [
        'rowTag' => '<user>',
        ]);

        $this->assertNotEmpty($sql3);

        // Test 4: Empty XML file
        $tempFile4 = tempnam(sys_get_temp_dir(), 'xml_');
        file_put_contents($tempFile4, '<?xml version="1.0"?><users></users>');

        $sql4 = $dialect->buildLoadXML('users', $tempFile4, [
        'rowTag' => '<user>',
        ]);

        $this->assertEquals('', $sql4);

        // Test 5: Unreadable file
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not readable');

        $dialect->buildLoadXML('users', '/nonexistent/path/file.xml', []);

        // Cleanup (after exception, these won't run, but PHPUnit handles it)
        @unlink($tempFile);
        @unlink($tempFile2);
        @unlink($tempFile3);
        @unlink($tempFile4);
    }

    public function testBuildLoadJsonSql(): void
    {
        $dialect = self::$db->connection->getDialect();

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
        $this->assertStringContainsString('"id"', $sql5);

        // Test 6: Unreadable file
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not readable');

        $dialect->buildLoadJson('users', '/nonexistent/path/file.json', []);

        // Cleanup (after exception, these won't run, but PHPUnit handles it)
        @unlink($tempFile);
        @unlink($tempFile2);
        @unlink($tempFile3);
        @unlink($tempFile4);
        @unlink($tempFile5);
    }

    public function testFormatSelectOptions(): void
    {
        $dialect = self::$db->connection->getDialect();

        $baseSql = 'SELECT * FROM users';

        // Test with DISTINCT
        $withDistinct = $dialect->formatSelectOptions($baseSql, ['DISTINCT']);
        $this->assertStringContainsString('DISTINCT', $withDistinct);

        // SQLite handles basic options
        $withOther = $dialect->formatSelectOptions($baseSql, ['SOME_OPTION']);
        $this->assertNotEmpty($withOther);
    }

    public function testBuildExplainSqlVariations(): void
    {
        $dialect = self::$db->connection->getDialect();

        $query = 'SELECT * FROM users WHERE age > 18';

        // Test basic EXPLAIN
        $explain = $dialect->buildExplainSql($query);
        $this->assertStringContainsString('EXPLAIN', $explain);
        $this->assertStringContainsString($query, $explain);

        // Test EXPLAIN QUERY PLAN
        $analyze = $dialect->buildExplainAnalyzeSql($query);
        $this->assertStringContainsString('EXPLAIN QUERY PLAN', $analyze);
    }

    public function testBuildLockSqlThrowsException(): void
    {
        $dialect = self::$db->connection->getDialect();

        // SQLite doesn't support LOCK TABLES
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not supported');
        $dialect->buildLockSql(['users'], '', 'READ');
    }

    public function testBuildUnlockSqlThrowsException(): void
    {
        $dialect = self::$db->connection->getDialect();

        // SQLite doesn't support UNLOCK TABLES
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not supported');
        $dialect->buildUnlockSql();
    }

    public function testFulltextMatchHelper(): void
    {
        $fulltext = Db::match('title, content', 'search term', 'natural');
        $this->assertInstanceOf(FulltextMatchValue::class, $fulltext);
    }

    public function testDistinctOnThrowsExceptionOnSQLite(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DISTINCT ON is not supported');

        self::$db->find()
        ->from('test_users')
        ->distinctOn('email')
        ->get();
    }
}
