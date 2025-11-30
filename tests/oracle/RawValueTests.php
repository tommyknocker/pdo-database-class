<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\helpers\Db;

/**
 * RawValueTests tests for Oracle.
 */
final class RawValueTests extends BaseOracleTestCase
{
    public function testRawValueWithParameters(): void
    {
        $db = self::$db;

        // 1. INSERT with RawValue containing parameters
        $id = $db->find()->table('users')->insert([
            'name' => Db::raw('CONCAT(:prefix, :name)', [
                ':prefix' => 'Mr_',
                ':name' => 'John',
            ]),
            'age' => 30,
        ]);

        $this->assertIsInt($id);
        $row = $db->find()->from('users')->where('id', $id)->getOne();
        // Oracle uses || for concatenation, but CONCAT also works
        $this->assertStringContainsString('John', $row['NAME']);

        // 2. UPDATE with RawValue containing parameters
        $rowCount = $db->find()
            ->table('users')
            ->where('id', $id)
            ->update([
                'age' => Db::raw('age + :inc', [':inc' => 5]),
            ]);

        $this->assertEquals(1, $rowCount);
        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertEquals(35, (int)$row['AGE']);
    }
}

