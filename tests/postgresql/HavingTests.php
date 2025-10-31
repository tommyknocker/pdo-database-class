<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\helpers\Db;

/**
 * HavingTests for postgresql.
 */
final class HavingTests extends BasePostgreSQLTestCase
{
    public function testHavingAndOrHaving(): void
    {
        $db = self::$db;

        $u1 = $db->find()->table('users')->insert(['name' => 'H1']);
        $u2 = $db->find()->table('users')->insert(['name' => 'H2']);
        $u3 = $db->find()->table('users')->insert(['name' => 'H3']);

        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 100]);
        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 200]); // total 300
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 300]);
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 400]); // total 700
        $db->find()->table('orders')->insert(['user_id' => $u3, 'amount' => 500]); // total 500

        $rows = $db->find()
            ->from('orders')
            ->groupBy('user_id')
            ->having(Db::raw('SUM(amount)'), 300, '=')
            ->orHaving(Db::raw('SUM(amount)'), 500, '=')
            ->orHaving(Db::raw('SUM(amount)'), 700, '=')
            ->select(['user_id', 'SUM(amount) AS total'])
            ->get();

        $totals = array_column($rows, 'total');
        sort($totals);
        $this->assertEquals([300, 500, 700], $totals);
    }

    public function testComplexHavingAndOrHaving(): void
    {
        $db = self::$db;

        $u1 = $db->find()->table('users')->insert(['name' => 'User1', 'age' => 20]);
        $u2 = $db->find()->table('users')->insert(['name' => 'User2', 'age' => 30]);
        $u3 = $db->find()->table('users')->insert(['name' => 'User3', 'age' => 40]);

        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 100]);
        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 200]); // total 300
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 300]);
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 400]); // total 700
        $db->find()->table('orders')->insert(['user_id' => $u3, 'amount' => 500]); // total 500

        $rows = $db->find()
            ->from('orders')
            ->select(['user_id', 'SUM(amount) AS total'])
            ->groupBy('user_id')
            ->having(Db::raw('SUM(amount)'), 300, '>=')
            ->orHaving(Db::raw('SUM(amount)'), 500, '=')
            ->orHaving(Db::raw('SUM(amount)'), 700, '=')
            ->get();

        $totals = array_column($rows, 'total');
        sort($totals);
        $this->assertEquals([300, 500, 700], $totals);
    }
}

