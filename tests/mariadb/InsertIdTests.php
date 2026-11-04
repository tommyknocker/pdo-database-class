<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

/**
 * insert() reports the generated key, and null when the row has no numeric id.
 */
final class InsertIdTests extends BaseMariaDBTestCase
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

    public function testATextKeyIsReturnedAsTheKey(): void
    {
        $this->assertSame('abc', self::$db->find()->table('insert_id_text')->insert(['id' => 'abc', 'amount' => 1]));
        $this->assertSame('def', self::$db->find()->table('insert_id_text')->insert(['id' => 'def', 'amount' => 2]));
    }

    public function testAKeyTheDatabaseGeneratesIsReturned(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_generated');
        self::$db->rawQuery('CREATE TABLE insert_id_generated (id CHAR(36) DEFAULT (UUID()) PRIMARY KEY, note VARCHAR(20))');

        try {
            $id = self::$db->find()->table('insert_id_generated')->insert(['note' => 'x']);
            $stored = self::$db->rawQueryValue('SELECT id FROM insert_id_generated');
        } finally {
            self::$db->rawQuery('DROP TABLE IF EXISTS insert_id_generated');
        }

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-/', (string)$id);
        $this->assertSame($stored, $id);
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

    public function testAnUpsertOfAnAutoIncrementKeyReportsTheRowItWrote(): void
    {
        $first = self::$db->find()->table('insert_id_auto')->insert(['name' => 'a']);
        self::$db->find()->table('insert_id_auto')->insert(['name' => 'b']);

        $updated = self::$db->find()->table('insert_id_auto')->onDuplicate(['name'], 'name')->insert(['name' => 'a']);

        $this->assertSame(1, $first);
        $this->assertSame(1, $updated, 'the row the conflict updated, not the last auto-increment value');
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
}
