<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\helpers\Db;

/**
 * BasicOperationsTests tests for Oracle.
 */
final class BasicOperationsTests extends BaseOracleTestCase
{
    public function testInsertWithQueryOption(): void
    {
        $db = self::$db;
        $id = $db->find()
            ->table('users')
            ->option('FOR UPDATE')
            ->insert(['name' => 'Alice']);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testInsertWithRawValue(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert([
            'name' => 'raw_now_user',
            'age' => 21,
            'created_at' => Db::now(),
        ]);

        $this->assertIsInt($id);

        $row = $db->find()
            ->from('users')
            ->where('id', $id)
            ->select(['id', 'name', 'created_at', Db::raw('SYSTIMESTAMP AS nowcol')])
            ->getOne();

        $this->assertNotNull($row['CREATED_AT']);
        $this->assertArrayHasKey('NOWCOL', $row);
        $this->assertEquals('raw_now_user', $row['NAME']);
    }

    public function testInsertWithNullHelper(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert([
                'name' => 'NullUser',
                'company' => 'Acme',
                'age' => 25,
                'status' => Db::null(),
            ]);

        $this->assertIsInt($id);

        $user = $db->find()
            ->from('users')
            ->where('id', $id)
            ->getOne();

        $this->assertNull($user['STATUS']);
    }

    public function testInsertMulti(): void
    {
        $db = self::$db;

        $rows = [
            ['name' => 'multi_1', 'age' => 10],
            ['name' => 'multi_2', 'age' => 11],
        ];

        $rowCount = $db->find()->table('users')->insertMulti($rows);
        $this->assertEquals(2, $rowCount);

        $count = $db->find()->from('users')->where('name', 'multi_1')->select('COUNT(*) as cnt')->getValue('cnt');
        $this->assertEquals(1, $count);
    }

    public function testUpdate(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert(['name' => 'Vasiliy', 'age' => 30]);
        $this->assertIsInt($id);

        self::$db->find()
            ->table('users')
            ->where('id', $id)
            ->update(['age' => 31]);

        $row = $db->find()
            ->from('users')
            ->where('id', $id)
            ->getOne();
        $this->assertIsArray($row);
        $this->assertEquals('31', (string)$row['AGE']);
    }

    public function testDelete(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert(['name' => 'ToDelete', 'age' => 25]);

        $deleted = $db->find()
            ->table('users')
            ->where('id', $id)
            ->delete();

        $this->assertEquals(1, $deleted);

        $count = $db->find()->from('users')->where('id', $id)->select('COUNT(*) as cnt')->getValue('cnt');
        $this->assertEquals(0, $count);
    }

    public function testGet(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insertMulti([
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
        ]);

        $rows = $db->find()->from('users')->get();
        $this->assertCount(2, $rows);
    }

    public function testGetOne(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert(['name' => 'Single', 'age' => 20]);

        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertIsArray($row);
        $this->assertEquals('Single', $row['NAME']);
    }

    public function testGetValue(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert(['name' => 'ValueTest', 'age' => 35]);

        $name = $db->find()->from('users')->where('id', $id)->select('name')->getValue();
        $this->assertEquals('ValueTest', $name);
    }

    public function testCount(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insertMulti([
            ['name' => 'Count1', 'age' => 10],
            ['name' => 'Count2', 'age' => 20],
            ['name' => 'Count3', 'age' => 30],
        ]);

        $count = $db->find()->from('users')->select('COUNT(*) as cnt')->getValue('cnt');
        $this->assertEquals(3, $count);
    }
}
