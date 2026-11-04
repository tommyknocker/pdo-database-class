<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\events\QueryExecutedEvent;

/**
 * insert() reports the generated key, and null when the row has no numeric id.
 */
final class InsertIdTests extends BaseMSSQLTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery("IF OBJECT_ID('insert_id_auto', 'U') IS NOT NULL DROP TABLE insert_id_auto");
        self::$db->rawQuery("IF OBJECT_ID('insert_id_text', 'U') IS NOT NULL DROP TABLE insert_id_text");
        self::$db->rawQuery('CREATE TABLE insert_id_auto (id INT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(20))');
        self::$db->rawQuery('CREATE TABLE insert_id_text (id NVARCHAR(36) PRIMARY KEY, amount INT)');
    }

    public function tearDown(): void
    {
        self::$db->rawQuery("IF OBJECT_ID('insert_id_auto', 'U') IS NOT NULL DROP TABLE insert_id_auto");
        self::$db->rawQuery("IF OBJECT_ID('insert_id_text', 'U') IS NOT NULL DROP TABLE insert_id_text");
        parent::tearDown();
    }

    public function testGeneratedKeysAreReturned(): void
    {
        $this->assertSame(1, self::$db->find()->table('insert_id_auto')->insert(['name' => 'a']));
        $this->assertSame(2, self::$db->find()->table('insert_id_auto')->insert(['name' => 'b']));
        $this->assertSame(3, self::$db->transaction(fn () => self::$db->find()->table('insert_id_auto')->insert(['name' => 'c'])));
    }

    public function testATextKeyIsReturnedAsTheKey(): void
    {
        $this->assertSame('abc', self::$db->find()->table('insert_id_text')->insert(['id' => 'abc', 'amount' => 1]));
        $this->assertSame('def', self::$db->find()->table('insert_id_text')->insert(['id' => 'def', 'amount' => 2]));
    }

    public function testAKeyTheDatabaseGeneratesIsReturned(): void
    {
        self::$db->rawQuery("IF OBJECT_ID('insert_id_guid', 'U') IS NOT NULL DROP TABLE insert_id_guid");
        self::$db->rawQuery('CREATE TABLE insert_id_guid (id UNIQUEIDENTIFIER DEFAULT NEWID() PRIMARY KEY, note NVARCHAR(20))');

        try {
            $id = self::$db->find()->table('insert_id_guid')->insert(['note' => 'x']);
            $stored = self::$db->rawQueryValue('SELECT CAST(id AS NVARCHAR(36)) FROM insert_id_guid');
        } finally {
            self::$db->rawQuery("IF OBJECT_ID('insert_id_guid', 'U') IS NOT NULL DROP TABLE insert_id_guid");
        }

        $this->assertMatchesRegularExpression('/^[0-9A-F]{8}-[0-9A-F]{4}-/i', (string)$id);
        $this->assertSame(strtolower((string)$stored), strtolower((string)$id));
    }

    public function testATableWithATriggerStillInsertsAndKeepsTheConnectionsAnswer(): void
    {
        self::$db->rawQuery("IF OBJECT_ID('insert_id_trigger', 'U') IS NOT NULL DROP TABLE insert_id_trigger");
        self::$db->rawQuery('CREATE TABLE insert_id_trigger (id INT IDENTITY(1,1) PRIMARY KEY, note NVARCHAR(20))');
        self::$db->rawQuery('CREATE TRIGGER insert_id_trigger_trg ON insert_id_trigger AFTER INSERT AS BEGIN SET NOCOUNT ON; END');
        self::$db->rawQuery("IF OBJECT_ID('insert_id_text_trigger', 'U') IS NOT NULL DROP TABLE insert_id_text_trigger");
        self::$db->rawQuery('CREATE TABLE insert_id_text_trigger (id NVARCHAR(10) PRIMARY KEY, note NVARCHAR(20))');
        self::$db->rawQuery('CREATE TRIGGER insert_id_text_trigger_trg ON insert_id_text_trigger AFTER INSERT AS BEGIN SET NOCOUNT ON; END');

        try {
            $identity = self::$db->find()->table('insert_id_trigger')->insert(['note' => 'x']);
            // A plain OUTPUT is an error on a table with a trigger: the insert must still succeed
            $text = self::$db->find()->table('insert_id_text_trigger')->insert(['id' => 'k1', 'note' => 'y']);
            $rows = (int)self::$db->rawQueryValue('SELECT COUNT(*) FROM insert_id_text_trigger');
        } finally {
            self::$db->rawQuery("IF OBJECT_ID('insert_id_trigger', 'U') IS NOT NULL DROP TABLE insert_id_trigger");
            self::$db->rawQuery("IF OBJECT_ID('insert_id_text_trigger', 'U') IS NOT NULL DROP TABLE insert_id_text_trigger");
        }

        $this->assertSame(1, $identity);
        $this->assertNull($text, 'a trigger rules out OUTPUT and the connection knows no text key');
        $this->assertSame(1, $rows);
    }

    public function testACompositeKeyHasNoSingleId(): void
    {
        self::$db->rawQuery("IF OBJECT_ID('insert_id_composite', 'U') IS NOT NULL DROP TABLE insert_id_composite");
        self::$db->rawQuery('CREATE TABLE insert_id_composite (a INT, b INT, counter INT IDENTITY(100,1), PRIMARY KEY (a, b))');

        try {
            $this->assertNull(self::$db->find()->table('insert_id_composite')->insert(['a' => 1, 'b' => 2]));
        } finally {
            self::$db->rawQuery("IF OBJECT_ID('insert_id_composite', 'U') IS NOT NULL DROP TABLE insert_id_composite");
        }
    }

    public function testAnIdentityZeroIsReturnedAndIsNotAnAbsentId(): void
    {
        static::$db->rawQuery("IF OBJECT_ID('insert_id_zero', 'U') IS NOT NULL DROP TABLE insert_id_zero");
        static::$db->rawQuery('CREATE TABLE insert_id_zero (id INT IDENTITY(0,1) PRIMARY KEY, note NVARCHAR(20))');

        try {
            $this->assertSame(0, static::$db->find()->table('insert_id_zero')->insert(['note' => 'zero']));
            $this->assertSame(1, static::$db->find()->table('insert_id_zero')->insert(['note' => 'one']));
        } finally {
            static::$db->rawQuery("IF OBJECT_ID('insert_id_zero', 'U') IS NOT NULL DROP TABLE insert_id_zero");
        }
    }

    public function testRecreatedTablesDoNotReuseIdentityMetadata(): void
    {
        $this->assertSame(1, static::$db->find()->table('insert_id_auto')->insert(['name' => 'before']));
        static::$db->rawQuery('DROP TABLE insert_id_auto');
        static::$db->rawQuery('CREATE TABLE insert_id_auto (slug NVARCHAR(20) PRIMARY KEY, counter INT IDENTITY(100,1))');
        $this->assertSame('real-key', static::$db->find()->table('insert_id_auto')->insert(['slug' => 'real-key']));
    }

    public function testAnIdentityInsertKeepsItsAffectedRowCountInQueryEvents(): void
    {
        $dispatcher = new class () implements EventDispatcherInterface {
            /** @var list<int> */
            public array $affectedRows = [];

            public function dispatch(object $event): object
            {
                if ($event instanceof QueryExecutedEvent) {
                    $this->affectedRows[] = $event->getRowsAffected();
                }

                return $event;
            }
        };
        static::$db->setEventDispatcher($dispatcher);

        try {
            $this->assertSame(1, static::$db->find()->table('insert_id_auto')->insert(['name' => 'one']));
            $this->assertSame([1], $dispatcher->affectedRows);
        } finally {
            static::$db->setEventDispatcher(null);
        }
    }

    public function testATemporaryTableReportsItsSingleKey(): void
    {
        // Preparing the DDL would create the TEMP table inside SQL Server's prepared-statement scope.
        $pdo = static::$db->find()->getConnection()->getPdo();
        $pdo->exec('CREATE TABLE #insert_key_temp (id INT PRIMARY KEY, note NVARCHAR(20))');

        try {
            $this->assertSame(77, static::$db->find()->table('#insert_key_temp')->insert(['id' => 77, 'note' => 'temp']));
        } finally {
            $pdo->exec('DROP TABLE #insert_key_temp');
        }
    }
}
