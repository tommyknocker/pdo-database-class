<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\helpers\Db;

/**
 * DialectTests tests for Oracle.
 */
final class DialectTests extends BaseOracleTestCase
{
    public function testDialectSpecificDifferences(): void
    {
        $db = self::$db;

        try {
            $db->rawQuery('DROP TABLE t_dialect CASCADE CONSTRAINTS');
        } catch (\Throwable) {
            // Table doesn't exist, continue
        }

        try {
            $db->rawQuery('DROP SEQUENCE t_dialect_seq');
        } catch (\Throwable) {
            // Sequence doesn't exist, continue
        }
        $db->rawQuery('CREATE TABLE t_dialect (id NUMBER PRIMARY KEY, str VARCHAR2(255), num NUMBER)');
        $db->rawQuery('CREATE SEQUENCE t_dialect_seq START WITH 1 INCREMENT BY 1');
        $db->rawQuery('
            CREATE OR REPLACE TRIGGER t_dialect_trigger
            BEFORE INSERT ON t_dialect
            FOR EACH ROW
            BEGIN
                IF :NEW.id IS NULL THEN
                    SELECT t_dialect_seq.NEXTVAL INTO :NEW.id FROM DUAL;
                END IF;
            END;
        ');

        // Test SUBSTR (Oracle) vs SUBSTRING (MySQL)
        $id1 = $db->find()->table('t_dialect')->insert(['str' => 'Oracle']);
        $row = $db->find()->table('t_dialect')
            ->select(['sub' => Db::substring('str', 1, 3)])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals('Ora', $row['SUB']); // Oracle returns uppercase column names
        // Should use SUBSTR in Oracle
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('SUBSTR', $lastQuery);

        // Test MOD (Oracle supports MOD)
        $id2 = $db->find()->table('t_dialect')->insert(['num' => 10]);
        $row = $db->find()->table('t_dialect')
            ->select(['mod_result' => Db::mod('num', '3')])
            ->where('id', $id2)
            ->getOne();
        $this->assertEquals(1, (int)$row['MOD_RESULT']);
        // Should use MOD in Oracle
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('MOD', $lastQuery);

        // Test NVL (Oracle) vs IFNULL (MySQL) vs COALESCE (PostgreSQL)
        $id3 = $db->find()->table('t_dialect')->insert(['str' => null]);
        $row = $db->find()->table('t_dialect')
            ->select(['result' => Db::ifNull('str', 'default')])
            ->where('id', $id3)
            ->getOne();
        $this->assertEquals('default', $row['RESULT']);
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('NVL', $lastQuery);

        // Test GREATEST/LEAST (Oracle supports these)
        $row = $db->find()->table('t_dialect')
            ->select([
                'max_val' => Db::greatest('5', '10', '3'),
                'min_val' => Db::least('5', '10', '3'),
            ])
            ->getOne();
        $this->assertEquals(10, (int)$row['MAX_VAL']);
        $this->assertEquals(3, (int)$row['MIN_VAL']);
        // Should use GREATEST/LEAST in Oracle
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('GREATEST', $lastQuery);

        // Cleanup
        try {
            $db->rawQuery('DROP TABLE t_dialect CASCADE CONSTRAINTS');
        } catch (\Throwable) {
            // Ignore
        }

        try {
            $db->rawQuery('DROP SEQUENCE t_dialect_seq');
        } catch (\Throwable) {
            // Ignore
        }
    }

    public function testBuildLoadCsvSqlThrowsException(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        // Create temp file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile, "id,name\n1,John\n");

        // Oracle doesn't support direct SQL LOAD CSV
        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('Direct SQL LOAD CSV is not supported in Oracle');
        $dialect->buildLoadCsvSql('users', $tempFile, []);

        // Cleanup
        unlink($tempFile);
    }

    public function testBuildLoadXmlSqlThrowsException(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        // Create temp XML file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'xml_');
        file_put_contents($tempFile, '<users><user><id>1</id><name>John</name></user></users>');

        // Oracle doesn't support direct SQL LOAD XML
        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('Direct SQL LOAD XML is not supported in Oracle');
        $dialect->buildLoadXML('users', $tempFile, []);

        // Cleanup
        unlink($tempFile);
    }

    public function testBuildLoadJsonSqlThrowsException(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        // Create temp JSON file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'json_');
        file_put_contents($tempFile, '[{"id":1,"name":"John"}]');

        // Oracle doesn't support direct SQL LOAD JSON
        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('Direct SQL LOAD JSON is not supported in Oracle');
        $dialect->buildLoadJson('users', $tempFile, []);

        // Cleanup
        unlink($tempFile);
    }

    public function testFormatSelectOptions(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $baseSql = 'SELECT * FROM users';

        // Test with FOR UPDATE
        $withForUpdate = $dialect->formatSelectOptions($baseSql, ['FOR UPDATE']);
        $this->assertStringContainsString('FOR UPDATE', $withForUpdate);

        // Test with FOR UPDATE NOWAIT
        $withForUpdateNowait = $dialect->formatSelectOptions($baseSql, ['FOR UPDATE NOWAIT']);
        $this->assertStringContainsString('FOR UPDATE NOWAIT', $withForUpdateNowait);
    }

    public function testBuildExplainSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $query = 'SELECT * FROM users WHERE age > 18';

        // Test basic EXPLAIN PLAN
        $explain = $dialect->buildExplainSql($query);
        $this->assertStringContainsString('EXPLAIN PLAN FOR', $explain);
        $this->assertStringContainsString($query, $explain);
    }

    public function testQuoteIdentifier(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        // Test simple identifier - Oracle uses double quotes
        $this->assertEquals('"COLUMN_NAME"', $dialect->quoteIdentifier('column_name'));

        // Test identifier with special characters (Oracle escapes double quotes by doubling them)
        $quoted = $dialect->quoteIdentifier('column"name');
        $this->assertStringContainsString('"COLUMN', $quoted);
        $this->assertStringContainsString('NAME"', $quoted);

        // Test identifier with numbers
        $this->assertEquals('"COLUMN123"', $dialect->quoteIdentifier('column123'));

        // Test identifier starting with underscore
        $this->assertEquals('"_COLUMN"', $dialect->quoteIdentifier('_column'));
    }

    public function testQuoteTable(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        // Test simple table name - Oracle uses double quotes and uppercase
        $this->assertEquals('"USERS"', $dialect->quoteTable('users'));

        // Test schema-qualified table
        $quoted = $dialect->quoteTable('schema.users');
        $this->assertStringContainsString('SCHEMA', $quoted);
        $this->assertStringContainsString('USERS', $quoted);
        $this->assertStringContainsString('.', $quoted);
    }

    public function testBuildDescribeSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $sql = $dialect->buildDescribeSql('users');
        $this->assertStringContainsString('USER_TAB_COLUMNS', $sql);
        $this->assertStringContainsString('users', $sql);
    }

    public function testBuildShowIndexesSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $sql = $dialect->buildShowIndexesSql('users');
        $this->assertStringContainsString('USER_IND_COLUMNS', $sql);
        $this->assertStringContainsString('users', $sql);
    }

    public function testBuildShowForeignKeysSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $sql = $dialect->buildShowForeignKeysSql('users');
        $this->assertStringContainsString('USER_CONSTRAINTS', $sql);
        $this->assertStringContainsString('users', $sql);
    }

    public function testBuildShowConstraintsSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        $sql = $dialect->buildShowConstraintsSql('users');
        $this->assertStringContainsString('USER_CONSTRAINTS', $sql);
        $this->assertStringContainsString('users', $sql);
    }

    public function testGetExplainParser(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();

        // Oracle explain parser is now implemented
        $parser = $dialect->getExplainParser();
        $this->assertInstanceOf(\tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface::class, $parser);
    }
}
