<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;


use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * DialectTests tests for postgresql.
 */
final class DialectTests extends BasePostgreSQLTestCase
{
    public function testDialectSpecificDifferences(): void
    {
    $db = self::$db;
    $db->rawQuery('DROP TABLE IF EXISTS t_dialect');
    $db->rawQuery('CREATE TABLE t_dialect (id SERIAL PRIMARY KEY, str TEXT, num INTEGER, dt TIMESTAMP)');
    
    // Test SUBSTRING (PostgreSQL) with FROM...FOR syntax
    $id1 = $db->find()->table('t_dialect')->insert(['str' => 'PostgreSQL']);
    $row = $db->find()->table('t_dialect')
    ->select(['sub' => Db::substring('str', 1, 3)])
    ->where('id', $id1)
    ->getOne();
    $this->assertEquals('Pos', $row['sub']);
    // Should use SUBSTRING ... FROM ... FOR in PostgreSQL
    $this->assertStringContainsString('SUBSTRING', $db->lastQuery);
    $this->assertStringContainsString('FROM', $db->lastQuery);
    
    // Test MOD (PostgreSQL) vs % (SQLite)
    $id2 = $db->find()->table('t_dialect')->insert(['num' => 10]);
    $row = $db->find()->table('t_dialect')
    ->select(['mod_result' => Db::mod('num', '3')])
    ->where('id', $id2)
    ->getOne();
    $this->assertEquals(1, (int)$row['mod_result']);
    // Should use MOD in PostgreSQL
    $this->assertStringContainsString('MOD', $db->lastQuery);
    
    // Test COALESCE (PostgreSQL) vs IFNULL (MySQL/SQLite)
    $id3 = $db->find()->table('t_dialect')->insert(['str' => null]);
    $row = $db->find()->table('t_dialect')
    ->select(['result' => Db::ifNull('str', 'default')])
    ->where('id', $id3)
    ->getOne();
    $this->assertEquals('default', $row['result']);
    $this->assertStringContainsString('COALESCE', $db->lastQuery);
    
    // Test GREATEST/LEAST (PostgreSQL/MySQL) vs MAX/MIN (SQLite)
    $row = $db->find()->table('t_dialect')
    ->select([
    'max_val' => Db::greatest('5', '10', '3'),
    'min_val' => Db::least('5', '10', '3'),
    ])
    ->getOne();
    $this->assertEquals(10, (int)$row['max_val']);
    $this->assertEquals(3, (int)$row['min_val']);
    // Should use GREATEST/LEAST in PostgreSQL
    $this->assertStringContainsString('GREATEST', $db->lastQuery);
    
    // Test EXTRACT (PostgreSQL) vs YEAR/MONTH/DAY (MySQL) vs STRFTIME (SQLite)
    $id4 = $db->find()->table('t_dialect')->insert(['dt' => '2025-10-19 14:30:45']);
    $row = $db->find()->table('t_dialect')
    ->select([
    'y' => Db::year('dt'),
    'm' => Db::month('dt'),
    ])
    ->where('id', $id4)
    ->getOne();
    $this->assertEquals(2025, (int)$row['y']);
    $this->assertEquals(10, (int)$row['m']);
    // Should use EXTRACT in PostgreSQL
    $this->assertStringContainsString('EXTRACT', $db->lastQuery);
    }

    public function testBuildLoadCsvSql(): void
    {
    $dialect = self::$db->connection->getDialect();
    
    // Create temp file for testing
    $tempFile = tempnam(sys_get_temp_dir(), 'csv_');
    file_put_contents($tempFile, "id,name\n1,John\n");
    
    // Test basic CSV load SQL generation
    $sql = $dialect->buildLoadCsvSql('users', $tempFile, []);
    
    $this->assertNotEmpty($sql);
    $this->assertStringContainsString('COPY', $sql);
    $this->assertStringContainsString('users', $sql);
    
    // Test with options
    $tempFile2 = tempnam(sys_get_temp_dir(), 'csv_');
    file_put_contents($tempFile2, "id;name;price\n1;Product;99.99\n");
    
    $sql2 = $dialect->buildLoadCsvSql('products', $tempFile2, [
    'fieldChar' => ';',
    'fields' => ['id', 'name', 'price'],
    'linesToIgnore' => 1,
    ]);
    
    $this->assertStringContainsString('COPY', $sql2);
    $this->assertStringContainsString('DELIMITER', $sql2);
    
    // Cleanup
    unlink($tempFile);
    unlink($tempFile2);
    }

    public function testBuildLoadXmlSql(): void
    {
    $dialect = self::$db->connection->getDialect();
    
    // Create temp XML file for testing
    $tempFile = tempnam(sys_get_temp_dir(), 'xml_');
    file_put_contents($tempFile, '<users><user><id>1</id><name>John</name></user></users>');
    
    // PostgreSQL uses fallback for XML
    $sql = $dialect->buildLoadXML('users', $tempFile, [
    'rowTag' => '<user>',
    'linesToIgnore' => 0,
    ]);
    
    $this->assertNotEmpty($sql);
    // PostgreSQL fallback uses INSERT statements
    $this->assertStringContainsString('INSERT', $sql);
    
    // Cleanup
    unlink($tempFile);
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
    
    // Cleanup
    unlink($tempFile);
    unlink($tempFile2);
    unlink($tempFile3);
    unlink($tempFile4);
    unlink($tempFile5);
    }

    public function testFormatSelectOptions(): void
    {
    $dialect = self::$db->connection->getDialect();
    
    $baseSql = 'SELECT * FROM users';
    
    // Test with FOR UPDATE (PostgreSQL specific)
    $withForUpdate = $dialect->formatSelectOptions($baseSql, ['FOR UPDATE']);
    $this->assertStringContainsString('FOR UPDATE', $withForUpdate);
    
    // Test with FOR SHARE (PostgreSQL specific)
    $withForShare = $dialect->formatSelectOptions($baseSql, ['FOR SHARE']);
    $this->assertStringContainsString('FOR SHARE', $withForShare);
    
    // PostgreSQL doesn't modify SELECT for non-locking options
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
    
    // Test EXPLAIN ANALYZE
    $analyze = $dialect->buildExplainAnalyzeSql($query);
    $this->assertStringContainsString('EXPLAIN', $analyze);
    $this->assertStringContainsString('ANALYZE', $analyze);
    }

    public function testFulltextMatchHelper(): void
    {
    $fulltext = Db::fulltextMatch('title, content', 'search term', 'natural');
    $this->assertInstanceOf(\tommyknocker\pdodb\helpers\values\FulltextMatchValue::class, $fulltext);
    }
}
