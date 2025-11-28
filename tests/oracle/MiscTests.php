<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use RuntimeException;
use tommyknocker\pdodb\helpers\Db;

/**
 * MiscTests for Oracle.
 */
final class MiscTests extends BaseOracleTestCase
{
    public function testEscape(): void
    {
        $id = self::$db->find()
            ->table('users')
            ->insert([
                'name' => Db::escape("O'Reilly"),
                'age' => 30,
            ]);
        $this->assertIsInt($id);

        $row = self::$db->find()
            ->from('users')
            ->where('id', $id)
            ->getOne();
        $this->assertEquals("O'Reilly", $row['NAME']);
        $this->assertEquals(30, (int)$row['AGE']);
    }

    public function testExistsAndNotExists(): void
    {
        $db = self::$db;

        // Insert one record
        $db->find()->table('users')->insert([
            'name' => 'Existy',
            'company' => 'CheckCorp',
            'age' => 42,
            'status' => 'active',
        ]);

        // Check exists() - should return true
        $exists = $db->find()
            ->from('users')
            ->where(['name' => 'Existy'])
            ->exists();

        $this->assertTrue($exists, 'Expected exists() to return true for matching row');

        // Check notExists() - should return false
        $notExists = $db->find()
            ->from('users')
            ->where(['name' => 'Existy'])
            ->notExists();

        $this->assertFalse($notExists, 'Expected notExists() to return false for matching row');
    }

    public function testDistinctOnThrowsExceptionOnOracle(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DISTINCT ON is not supported');

        self::$db->find()
            ->from('users')
            ->distinctOn('name')
            ->get();
    }
}
