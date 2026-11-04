<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use PDO\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * insert() reports the generated key, and null when the row has no numeric id.
 */
final class InsertIdTests extends BaseMySQLTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_auto');
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_text');
        self::$db->rawQuery('CREATE TABLE insert_id_auto (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20) UNIQUE)');
        self::$db->rawQuery('CREATE TABLE insert_id_text (id VARCHAR(36) PRIMARY KEY, amount INT)');
    }

    public function tearDown(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_auto');
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_text');
        parent::tearDown();
    }

    public function testGeneratedKeysAreReturned(): void
    {
        $this->assertSame(1, self::$db->find()->table('insert_id_auto')->insert(['name' => 'a']));
        $this->assertSame(2, self::$db->find()->table('insert_id_auto')->insert(['name' => 'b']));
        $this->assertSame(3, self::$db->transaction(fn () => self::$db->find()->table('insert_id_auto')->insert(['name' => 'c'])));
    }

    public function testATextKeyYouSupplyIsReturnedAsTheKey(): void
    {
        $this->assertSame('abc', self::$db->find()->table('insert_id_text')->insert(['id' => 'abc', 'amount' => 1]));
        $this->assertSame('def', self::$db->find()->table('insert_id_text')->insert(['id' => 'def', 'amount' => 2]));
    }

    public function testAKeyTheDatabaseFillsInFromADefaultCannotBeReported(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_generated');
        self::$db->rawQuery('CREATE TABLE insert_id_generated (id CHAR(36) DEFAULT (UUID()) PRIMARY KEY, note VARCHAR(20))');

        try {
            // MySQL has no RETURNING and the connection reports only AUTO_INCREMENT values
            $this->assertNull(self::$db->find()->table('insert_id_generated')->insert(['note' => 'x']));
            $this->assertSame(1, (int)self::$db->rawQueryValue('SELECT COUNT(*) FROM insert_id_generated'));
        } finally {
            self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_generated');
        }
    }

    public function testASkippedInsertOfATextKeyReportsNoId(): void
    {
        self::$db->find()->table('insert_id_text')->insert(['id' => 'abc', 'amount' => 1]);

        $this->assertNull(self::$db->find()->table('insert_id_text')->option('IGNORE')->insert(['id' => 'abc', 'amount' => 2]));
    }

    public function testACompositeKeyHasNoSingleId(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_composite');
        self::$db->rawQuery('CREATE TABLE insert_id_composite (a INT, b INT, counter INT AUTO_INCREMENT UNIQUE, PRIMARY KEY (a, b))');

        try {
            $this->assertNull(self::$db->find()->table('insert_id_composite')->insert(['a' => 1, 'b' => 2]));
        } finally {
            self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_composite');
        }
    }

    public function testTheFirstInsertIntoATableIsNotLostToTheKeyLookup(): void
    {
        // The catalog query that finds the key runs after the INSERT; the connection's id must be read before it
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_first');
        self::$db->rawQuery('CREATE TABLE insert_id_first (id INT AUTO_INCREMENT PRIMARY KEY, n INT)');

        try {
            $this->assertSame(1, self::$db->find()->table('insert_id_first')->insert(['n' => 1]));
        } finally {
            self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_first');
        }
    }

    public function testASkippedDuplicateReportsNoId(): void
    {
        self::$db->find()->table('insert_id_auto')->insert(['name' => 'a']);

        $this->assertNull(self::$db->find()->table('insert_id_auto')->option('IGNORE')->insert(['name' => 'a']));
    }

    public function testAnUnsignedBigintBeyondPhpIntIsNotClamped(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_big');
        self::$db->rawQuery('CREATE TABLE insert_id_big (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, n INT) AUTO_INCREMENT=18446744073709551000');

        try {
            $id = self::$db->find()->table('insert_id_big')->insert(['n' => 1]);
        } finally {
            self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_big');
        }

        $this->assertSame('18446744073709551000', $id, 'an int cast would have returned PHP_INT_MAX');
    }

    public function testAnUpsertThatConflictsOnAnotherUniqueKeyDoesNotReturnTheKeyItCarried(): void
    {
        self::$db->find()->table('insert_id_auto')->insert(['name' => 'a']); // id 1

        // The row is found by name and updated; the id 999 in the statement never reaches the table
        $result = self::$db->find()->table('insert_id_auto')->onDuplicate(['name'])->insert(['id' => 999, 'name' => 'a']);

        $this->assertNotSame(999, $result);
        $this->assertSame([1], array_map(static fn (array $r): int => (int)$r['id'], self::$db->find()->from('insert_id_auto')->get()));
    }

    public function testAnUpsertThatInsertsReturnsItsKey(): void
    {
        $this->assertSame(7, self::$db->find()->table('insert_id_auto')->onDuplicate(['name'])->insert(['id' => 7, 'name' => 'fresh']));
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function foundRowsModes(): array
    {
        return ['affected rows (default)' => [false], 'found rows' => [true]];
    }

    /**
     * MYSQL_ATTR_FOUND_ROWS makes rowCount() 1 for an unchanged conflict too, so the answer must not depend on it.
     */
    #[DataProvider('foundRowsModes')]
    public function testAnUpsertNamesTheRowItInsertedOrUpdatedWhateverRowCountSays(bool $foundRows): void
    {
        $db = new PdoDb('mysql', [
            'host' => self::DB_HOST,
            'port' => self::DB_PORT,
            'username' => self::DB_USER,
            'password' => self::DB_PASSWORD,
            'dbname' => self::DB_NAME,
            'charset' => self::DB_CHARSET,
        ], [Mysql::ATTR_FOUND_ROWS => $foundRows]);
        $db->rawQuery('DROP TABLE IF EXISTS insert_id_upsert');
        $db->rawQuery('CREATE TABLE insert_id_upsert (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(30) UNIQUE, note VARCHAR(20))');

        try {
            $db->find()->table('insert_id_upsert')->insert(['id' => 1, 'email' => 'a', 'note' => 'old']);
            $db->find()->table('insert_id_upsert')->insert(['id' => 42, 'email' => 'z', 'note' => 'zz']); // the previous insert
            $upsert = static fn (array $row, array $update = ['note']) => $db->find()->table('insert_id_upsert')->onDuplicate($update)->insert($row);

            $changed = $upsert(['id' => 999, 'email' => 'a', 'note' => 'new']);      // conflict on email: row 1 is updated
            $unchanged = $upsert(['id' => 998, 'email' => 'a', 'note' => 'new']);    // conflict, nothing changes
            $fresh = $upsert(['id' => 7, 'email' => 'q', 'note' => 'q']);            // a new row
            $trick = $upsert(['id' => 997, 'email' => 'a', 'note' => 'new'], ['note' => Db::raw("'new'"), 'id' => Db::raw('LAST_INSERT_ID(id)')]);
        } finally {
            $db->rawQuery('DROP TABLE IF EXISTS insert_id_upsert');
        }

        $this->assertSame(1, $changed, 'the row that was updated, not the key the statement carried (999)');
        $this->assertNull($unchanged, 'nothing was written, and rowCount() is 1 under FOUND_ROWS: it must not be read as an insert');
        $this->assertSame(7, $fresh);
        $this->assertSame(1, $trick, 'LAST_INSERT_ID(id) makes the connection name the row even when nothing changed');
    }

    public function testAnUpsertOfAKeyThatIsNotAutoIncrementHasNoAnswer(): void
    {
        self::$db->find()->table('insert_id_text')->insert(['id' => 'abc', 'amount' => 1]);

        // The key carried may or may not be the row a conflict on another column updates: null rather than a guess
        $this->assertNull(self::$db->find()->table('insert_id_text')->onDuplicate(['amount'])->insert(['id' => 'abc', 'amount' => 2]));
    }

    public function testRenamingTheKeyDoesNotReuseItsOldColumnName(): void
    {
        static::$db->rawQuery('DROP TABLE IF EXISTS insert_id_mutable');
        static::$db->rawQuery('CREATE TABLE insert_id_mutable (id INT PRIMARY KEY, note VARCHAR(20))');

        try {
            $this->assertSame(11, static::$db->find()->table('insert_id_mutable')->insert(['id' => 11, 'note' => 'before']));
            static::$db->rawQuery('ALTER TABLE insert_id_mutable CHANGE id new_id INT NOT NULL, ADD id INT');
            $this->assertSame(22, static::$db->find()->table('insert_id_mutable')->insert(['new_id' => 22, 'id' => 777, 'note' => 'after']));
            static::$db->rawQuery('DROP TABLE insert_id_mutable');
            static::$db->rawQuery('CREATE TABLE insert_id_mutable (slug VARCHAR(20) PRIMARY KEY, id INT)');
            $this->assertSame('new-key', static::$db->find()->table('insert_id_mutable')->insert(['slug' => 'new-key', 'id' => 888]));
        } finally {
            static::$db->rawQuery('DROP TABLE IF EXISTS insert_id_mutable');
        }
    }

    public function testATemporaryTableReportsItsOwnKeyWhileShadowingAnotherTable(): void
    {
        static::$db->rawQuery('DROP TABLE IF EXISTS insert_id_shadow');
        static::$db->rawQuery('CREATE TABLE insert_id_shadow (id INT PRIMARY KEY, note VARCHAR(20))');

        try {
            $this->assertSame(11, static::$db->find()->table('insert_id_shadow')->insert(['id' => 11, 'note' => 'main']));
            static::$db->rawQuery('CREATE TEMPORARY TABLE insert_id_shadow (slug VARCHAR(20) PRIMARY KEY, id INT)');
            $this->assertSame('temp-key', static::$db->find()->table('insert_id_shadow')->insert(['slug' => 'temp-key', 'id' => 777]));
            static::$db->rawQuery('DROP TEMPORARY TABLE insert_id_shadow');
            $this->assertSame(22, static::$db->find()->table('insert_id_shadow')->insert(['id' => 22, 'note' => 'main again']));
        } finally {
            static::$db->rawQuery('DROP TEMPORARY TABLE IF EXISTS insert_id_shadow');
            static::$db->rawQuery('DROP TABLE IF EXISTS insert_id_shadow');
        }
    }

    public function testAZeroTextKeyIsReturnedRatherThanTreatedAsMissing(): void
    {
        $this->assertSame(0, static::$db->find()->table('insert_id_text')->insert(['id' => '0', 'amount' => 1]));
        $this->assertSame('0', static::$db->rawQueryValue("SELECT id FROM insert_id_text WHERE id = '0'"));
    }

    public function testABeforeInsertTriggerDoesNotReturnTheUnwrittenSuppliedKey(): void
    {
        static::$db->rawQuery('DROP TABLE IF EXISTS insert_id_trigger');
        static::$db->rawQuery('CREATE TABLE insert_id_trigger (id INT PRIMARY KEY, note VARCHAR(20))');
        static::$db->rawQuery('CREATE TRIGGER insert_id_trigger_trg BEFORE INSERT ON insert_id_trigger FOR EACH ROW SET NEW.id = NEW.id + 100');

        try {
            $this->assertNull(static::$db->find()->table('insert_id_trigger')->insert(['id' => 7, 'note' => 'changed']));
            $this->assertSame(107, (int)static::$db->rawQueryValue('SELECT id FROM insert_id_trigger'));
        } finally {
            static::$db->rawQuery('DROP TABLE IF EXISTS insert_id_trigger');
        }
    }

    public function testAnAutoIncrementKeyChangedByATriggerUsesTheConnectionsAnswer(): void
    {
        static::$db->rawQuery('CREATE TRIGGER insert_id_auto_trg BEFORE INSERT ON insert_id_auto FOR EACH ROW SET NEW.id = 107');

        try {
            $this->assertSame(107, static::$db->find()->table('insert_id_auto')->insert(['id' => 7, 'name' => 'changed']));
            $this->assertSame(107, (int)static::$db->rawQueryValue('SELECT id FROM insert_id_auto'));
        } finally {
            static::$db->rawQuery('DROP TRIGGER insert_id_auto_trg');
        }
    }
}
