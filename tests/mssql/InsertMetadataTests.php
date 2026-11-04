<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use PDO;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\shared\InsertMetadataScenarios;
use tommyknocker\pdodb\tests\shared\QueryRecordingPdo;

/**
 * Table metadata for insert() on SQL Server is read once per connection and again after this connection changes the table.
 */
final class InsertMetadataTests extends BaseMSSQLTestCase
{
    use InsertMetadataScenarios;

    protected function driverName(): string
    {
        return 'sqlsrv';
    }

    protected function recordingPdo(): QueryRecordingPdo
    {
        $username = getenv('PDODB_USERNAME') ?: self::DB_USER;
        $password = getenv('PDODB_PASSWORD') ?: self::DB_PASSWORD;

        return new QueryRecordingPdo(
            'sqlsrv:Server=' . self::host() . ',' . self::port() . ';Database=' . self::DB_NAME . ';TrustServerCertificate=yes;Encrypt=yes',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    protected function createKeyedTableSql(): string
    {
        return 'CREATE TABLE insert_meta_keyed (code NVARCHAR(20) NOT NULL, alt NVARCHAR(20) NOT NULL, CONSTRAINT pk_insert_meta_keyed PRIMARY KEY (code), CONSTRAINT uq_insert_meta_keyed UNIQUE (alt))';
    }

    protected function movePrimaryKeyToAltSql(): string
    {
        return 'ALTER TABLE insert_meta_keyed DROP CONSTRAINT pk_insert_meta_keyed; ALTER TABLE insert_meta_keyed ADD CONSTRAINT pk_insert_meta_keyed2 PRIMARY KEY (alt)';
    }

    public function testRolledBackDdlIsNotRemembered(): void
    {
        self::$db->schema()->dropTableIfExists('insert_meta_keyed');
        self::$db->rawQuery($this->createKeyedTableSql());
        $db = new PdoDb('sqlsrv', ['pdo' => $this->recordingPdo()]);

        $db->startTransaction();
        $db->rawQuery($this->movePrimaryKeyToAltSql());
        $this->assertSame('two', $db->find()->table('insert_meta_keyed')->insert(['code' => 'second', 'alt' => 'two']));
        $db->rollback();

        $this->assertSame('third', $db->find()->table('insert_meta_keyed')->insert(['code' => 'third', 'alt' => 'four']), 'the key is `code` again');
    }
}
