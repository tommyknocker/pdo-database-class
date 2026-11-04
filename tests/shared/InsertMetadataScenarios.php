<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\PdoDb;

/**
 * What insert() asks the database about the table it writes to is remembered for the connection and dropped when that
 * connection changes the table: a hot path must not read the catalog on every row, and must not trust it after DDL.
 *
 * Used by the per-dialect InsertMetadataTests, which provide the connection and the dialect's DDL.
 */
trait InsertMetadataScenarios
{
    abstract protected function recordingPdo(): QueryRecordingPdo;

    abstract protected function driverName(): string;

    /** A table with a text primary key `code` and a unique text column `alt`, named insert_meta_keyed. */
    abstract protected function createKeyedTableSql(): string;

    /** Statements that make `alt` the primary key instead of `code`. */
    abstract protected function movePrimaryKeyToAltSql(): string;

    protected function tearDown(): void
    {
        self::$db->schema()->dropTableIfExists('insert_meta_counted');
        self::$db->schema()->dropTableIfExists('insert_meta_keyed');
        parent::tearDown();
    }

    public function testTableMetadataIsReadOncePerConnection(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('insert_meta_counted');
        $schema->createTable('insert_meta_counted', ['id' => $schema->primaryKey(), 'amount' => $schema->integer()->notNull()]);

        $pdo = $this->recordingPdo();
        $db = new PdoDb($this->driverName(), ['pdo' => $pdo]);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame($i, $db->find()->table('insert_meta_counted')->insert(['amount' => $i]));
        }
        $afterFive = count($pdo->metadataStatements());
        for ($i = 6; $i <= 30; $i++) {
            $this->assertSame($i, $db->find()->table('insert_meta_counted')->insert(['amount' => $i]));
        }

        $this->assertGreaterThan(0, $afterFive, 'the key of the table has to be learned once');
        $this->assertCount($afterFive, $pdo->metadataStatements(), 'twenty-five more inserts asked nothing more about the table');
    }

    public function testAChangedPrimaryKeyIsSeenAfterDdlOnTheSameConnection(): void
    {
        self::$db->schema()->dropTableIfExists('insert_meta_keyed');
        self::$db->rawQuery($this->createKeyedTableSql());

        $db = new PdoDb($this->driverName(), ['pdo' => $this->recordingPdo()]);
        $this->assertSame('first', $db->find()->table('insert_meta_keyed')->insert(['code' => 'first', 'alt' => 'one']));

        $db->rawQuery($this->movePrimaryKeyToAltSql());

        $this->assertSame('two', $db->find()->table('insert_meta_keyed')->insert(['code' => 'second', 'alt' => 'two']), 'the key is now `alt`');
    }
}
