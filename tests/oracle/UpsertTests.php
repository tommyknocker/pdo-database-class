<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use RuntimeException;
use tommyknocker\pdodb\helpers\Db;

/**
 * UpsertTests tests for Oracle.
 */
final class UpsertTests extends BaseOracleTestCase
{
    public function testUpsertThrowsException(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Eve', 'age' => 20]);

        // Oracle doesn't support ON DUPLICATE KEY UPDATE, should throw exception
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Oracle does not support ON CONFLICT/ON DUPLICATE KEY syntax');

        $db->find()
            ->table('users')
            ->onDuplicate(['age'])
            ->insert(['name' => 'Eve', 'age' => 21]);
    }

    public function testUpsertWithMerge(): void
    {
        $db = self::$db;

        // First insert
        $db->find()->table('users')->insert(['name' => 'MergeTest', 'age' => 10]);

        // Use MERGE for UPSERT in Oracle
        $affected = $db->find()
            ->table('users')
            ->merge(
                function ($q) {
                    $q->select(['name' => Db::raw("'MergeTest'"), 'age' => Db::raw('20')])
                        ->from('DUAL');
                },
                'target.name = source.name',
                ['age' => Db::raw('source.age')],
                ['name' => Db::raw('source.name'), 'age' => Db::raw('source.age')]
            );

        $this->assertGreaterThan(0, $affected);

        $row = $db->find()->from('users')->where('name', 'MergeTest')->getOne();
        $this->assertEquals(20, (int)$row['AGE']);
    }
}
