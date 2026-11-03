<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

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

    public function testAStringKeyHasNoNumericIdToReport(): void
    {
        $this->assertNull(self::$db->find()->table('insert_id_text')->insert(['id' => 'abc', 'amount' => 1]));
        $this->assertNull(self::$db->find()->table('insert_id_text')->insert(['id' => 'def', 'amount' => 2]));
    }
}
