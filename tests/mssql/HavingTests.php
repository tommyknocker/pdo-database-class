<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;

/**
 * HavingTests for MSSQL.
 */
final class HavingTests extends BaseMSSQLTestCase
{
    public function test01HavingAndOrHaving(): void
    {
        $db = self::$db;
        $connection = $db->connection;
        assert($connection !== null);

        // Verify clean state from setUp()
        $existingUsers = $connection->query('SELECT COUNT(*) as cnt FROM users')->fetch(\PDO::FETCH_ASSOC);
        $existingOrders = $connection->query('SELECT COUNT(*) as cnt FROM orders')->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(0, (int)$existingUsers['cnt'], 'Users table should be empty before test');
        $this->assertEquals(0, (int)$existingOrders['cnt'], 'Orders table should be empty before test');

        $db->find()->table('users')->insert(['name' => 'H1']);
        $db->find()->table('users')->insert(['name' => 'H2']);
        $db->find()->table('users')->insert(['name' => 'H3']);

        // Get actual IDs from database
        $u1 = (int)$db->find()->table('users')->select('id')->where('name', 'H1')->getValue();
        $u2 = (int)$db->find()->table('users')->select('id')->where('name', 'H2')->getValue();
        $u3 = (int)$db->find()->table('users')->select('id')->where('name', 'H3')->getValue();

        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 100]);
        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 200]); // total 300
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 300]);
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 400]); // total 700
        $db->find()->table('orders')->insert(['user_id' => $u3, 'amount' => 500]); // total 500

        // Verify all data exists first
        $allRows = $db->find()
            ->from('orders')
            ->groupBy('user_id')
            ->select(['user_id', 'SUM(amount) AS total'])
            ->get();
        $allTotals = array_map('floatval', array_column($allRows, 'total'));
        sort($allTotals);
        $this->assertEquals([300.0, 500.0, 700.0], $allTotals, 'All data should exist before HAVING filter. Got: ' . json_encode($allTotals));

        $rows = $db->find()
            ->from('orders')
            ->groupBy('user_id')
            ->having(Db::raw('SUM(amount)'), 300, '=')
            ->orHaving(Db::raw('SUM(amount)'), 500, '=')
            ->orHaving(Db::raw('SUM(amount)'), 700, '=')
            ->select(['user_id', 'SUM(amount) AS total'])
            ->get();

        $totals = array_map('floatval', array_column($rows, 'total'));
        sort($totals);
        // MSSQL may return results in different order, so check each value individually
        $this->assertCount(3, $totals, 'Expected 3 results but got: ' . json_encode($totals));
        $this->assertContains(300.0, $totals);
        $this->assertContains(500.0, $totals);
        $this->assertContains(700.0, $totals);
    }

    public function test02ComplexHavingAndOrHaving(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'User1', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'User2', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'User3', 'age' => 40]);

        // Get actual IDs from database
        $u1 = (int)$db->find()->table('users')->select('id')->where('name', 'User1')->getValue();
        $u2 = (int)$db->find()->table('users')->select('id')->where('name', 'User2')->getValue();
        $u3 = (int)$db->find()->table('users')->select('id')->where('name', 'User3')->getValue();

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

        $totals = array_map('floatval', array_column($rows, 'total'));
        sort($totals);
        $this->assertEquals([300.0, 500.0, 700.0], $totals);
    }
}
