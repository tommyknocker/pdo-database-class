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

    public function testAStringKeyHasNoNumericIdToReport(): void
    {
        $this->assertNull(self::$db->find()->table('insert_id_text')->insert(['id' => 'abc', 'amount' => 1]));
        $this->assertNull(self::$db->find()->table('insert_id_text')->insert(['id' => 'def', 'amount' => 2]));
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
