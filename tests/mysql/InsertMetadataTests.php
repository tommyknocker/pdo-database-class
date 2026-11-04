<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use PDO;
use tommyknocker\pdodb\tests\shared\InsertMetadataScenarios;
use tommyknocker\pdodb\tests\shared\QueryRecordingPdo;

/**
 * Table metadata for insert() on MySQL is read once per connection and again after this connection changes the table.
 */
final class InsertMetadataTests extends BaseMySQLTestCase
{
    use InsertMetadataScenarios;

    protected function driverName(): string
    {
        return 'mysql';
    }

    protected function recordingPdo(): QueryRecordingPdo
    {
        return new QueryRecordingPdo(
            'mysql:host=' . self::DB_HOST . ';port=' . self::DB_PORT . ';dbname=' . self::DB_NAME . ';charset=' . self::DB_CHARSET,
            self::DB_USER,
            self::DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    protected function createKeyedTableSql(): string
    {
        return 'CREATE TABLE insert_meta_keyed (code VARCHAR(20) NOT NULL, alt VARCHAR(20) NOT NULL, PRIMARY KEY (code), UNIQUE (alt))';
    }

    protected function movePrimaryKeyToAltSql(): string
    {
        return 'ALTER TABLE insert_meta_keyed DROP PRIMARY KEY, ADD PRIMARY KEY (alt)';
    }
}
