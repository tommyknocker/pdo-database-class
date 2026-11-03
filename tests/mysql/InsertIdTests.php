<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

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
        self::$db->rawQuery('CREATE TABLE insert_id_composite (a INT, b INT, PRIMARY KEY (a, b))');

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
}
