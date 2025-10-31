<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\helpers\Db;

/**
 * UpsertTests tests for postgresql.
 */
final class UpsertTests extends BasePostgreSQLTestCase
{
    public function testInsertOnDuplicate(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Eve', 'age' => 20]);

        // Use fluent builder to set ON DUPLICATE behavior and perform insert
        $db->find()
        ->table('users')
        ->onDuplicate(['age'])
        ->insert(['name' => 'Eve', 'age' => 21]);

        $this->assertStringContainsString(
            'INSERT INTO "users" ("name", "age") VALUES (:name, :age) ON CONFLICT ("name") DO UPDATE SET "age" = EXCLUDED."age"',
            $db->lastQuery
        );

        $row = $db->find()
        ->from('users')
        ->where('name', 'Eve')
        ->getOne();

        $this->assertEquals(21, $row['age']);
    }

    public function testUpsertWithRawIncrement(): void
    {
        $db = self::$db;

        // First insert
        $db->find()->table('users')->insert(['name' => 'UpsertTest', 'age' => 10]);

        // UPSERT with raw increment expression
        $db->find()->table('users')
        ->onDuplicate([
        'age' => Db::raw('age + 5'),
        ])
        ->insert(['name' => 'UpsertTest', 'age' => 20]);

        $row = $db->find()->from('users')->where('name', 'UpsertTest')->getOne();
        $this->assertEquals(15, $row['age'], 'Age should be incremented by 5 (10 + 5)');
    }

    public function testUpsertWithIncHelper(): void
    {
        $db = self::$db;

        // First insert
        $db->find()->table('users')->insert(['name' => 'IncTest', 'age' => 100]);

        // UPSERT with Db::inc() helper
        $db->find()->table('users')
        ->onDuplicate([
        'age' => Db::inc(25),
        ])
        ->insert(['name' => 'IncTest', 'age' => 999]);

        $row = $db->find()->from('users')->where('name', 'IncTest')->getOne();
        $this->assertEquals(125, $row['age'], 'Age should be incremented by 25 (100 + 25), not replaced with 999');
    }
}
