<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\helpers\Db;

/**
 * HelpersTests tests for Oracle.
 */
final class HelpersTests extends BaseOracleTestCase
{
    public function testLikeAndIlikeHelpers(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'company' => 'WonderCorp', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'Bob', 'company' => 'wondercorp', 'age' => 35]);

        // LIKE
        $likeResults = $db->find()
            ->from('users')
            ->where(Db::like('company', 'Wonder%'))
            ->get();

        $this->assertCount(1, $likeResults); // Oracle LIKE is case-sensitive
        $this->assertEquals('Alice', $likeResults[0]['NAME']);

        // ILIKE (case-insensitive LIKE)
        $ilikeResults = $db->find()
            ->from('users')
            ->where(Db::ilike('company', 'Wonder%'))
            ->get();

        $this->assertCount(2, $ilikeResults);
    }

    public function testInAndNotInHelpers(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Dana', 'company' => 'X', 'age' => 40]);
        $db->find()->table('users')->insert(['name' => 'Eve', 'company' => 'Y', 'age' => 45]);
        $db->find()->table('users')->insert(['name' => 'Frank', 'company' => 'Z', 'age' => 50]);

        $inResults = $db->find()
            ->from('users')
            ->where(Db::in('name', ['Dana', 'Eve']))
            ->get();

        $this->assertCount(2, $inResults);

        $notInResults = $db->find()
            ->from('users')
            ->where(Db::not(Db::in('name', ['Dana', 'Eve'])))
            ->get();

        $this->assertCount(1, $notInResults);
        $this->assertEquals('Frank', $notInResults[0]['NAME']);
    }
}



