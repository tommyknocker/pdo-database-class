<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;

/**
 * QueryBuilderVariantsTests for MSSQL.
 */
final class QueryBuilderVariantsTests extends BaseMSSQLTestCase
{
    public function testSelectDistinct(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice1', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'Bob1', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'Alice2', 'age' => 35]);

        $results = $db->find()
            ->from('users')
            ->select('name')
            ->distinct()
            ->get();

        $this->assertCount(2, $results);
        $names = array_column($results, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }

    public function testSelectWithAlias(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);

        $result = $db->find()
            ->from('users')
            ->select(['user_name' => 'name', 'user_age' => 'age'])
            ->getOne();

        $this->assertNotFalse($result);
        $this->assertArrayHasKey('user_name', $result);
        $this->assertArrayHasKey('user_age', $result);
        $this->assertEquals('Alice', $result['user_name']);
        $this->assertEquals(25, $result['user_age']);
    }

    public function testOrderByMultiple(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice1', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'Bob1', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'Alice2', 'age' => 20]);

        $results = $db->find()
            ->from('users')
            ->orderBy('name', 'ASC')
            ->orderBy('age', 'DESC')
            ->get();

        $this->assertCount(3, $results);
        $this->assertEquals('Alice1', $results[0]['name']);
        $this->assertEquals(25, $results[0]['age']);
        $this->assertEquals('Alice2', $results[1]['name']);
        $this->assertEquals(20, $results[1]['age']);
        $this->assertEquals('Bob1', $results[2]['name']);
    }

    public function testGroupBy(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25, 'status' => 'active']);
        $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30, 'status' => 'active']);
        $db->find()->table('users')->insert(['name' => 'Charlie', 'age' => 25, 'status' => 'inactive']);

        $results = $db->find()
            ->from('users')
            ->select(['status', 'count' => Db::count()])
            ->groupBy('status')
            ->get();

        $this->assertCount(2, $results);
        $statuses = array_column($results, 'status');
        $this->assertContains('active', $statuses);
        $this->assertContains('inactive', $statuses);
    }

    public function testHaving(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25, 'status' => 'active']);
        $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30, 'status' => 'active']);
        $db->find()->table('users')->insert(['name' => 'Charlie', 'age' => 25, 'status' => 'inactive']);

        $results = $db->find()
            ->from('users')
            ->select(['status', 'count' => Db::count()])
            ->groupBy('status')
            ->having(Db::count(), 1, '>')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('active', $results[0]['status']);
        $this->assertEquals(2, $results[0]['count']);
    }

    public function testLimitAndOffset(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'User1', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'User2', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'User3', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'User4', 'age' => 35]);

        $results = $db->find()
            ->from('users')
            ->orderBy('age', 'ASC')
            ->limit(2)
            ->offset(1)
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('User2', $results[0]['name']);
        $this->assertEquals('User3', $results[1]['name']);
    }

    public function testWhereRaw(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25, 'status' => 'active']);
        $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30, 'status' => 'inactive']);

        $users = $db->find()
            ->from('users')
            ->whereRaw('[status] = :status', [':status' => 'active'])
            ->get();

        $this->assertCount(1, $users);
        $userNames = array_column($users, 'name');
        $this->assertContains('Alice', $userNames);
        $this->assertNotContains('Bob', $userNames);
    }

    public function testSubqueryInWhere(): void
    {
        $db = self::$db;

        $userId1 = $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);
        $userId2 = $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30]);
        $db->find()->table('orders')->insert(['user_id' => $userId1, 'amount' => 100]);

        $users = $db->find()
            ->from('users')
            ->where('id', function ($q) {
                $q->from('orders')
                    ->select('user_id');
            }, 'IN')
            ->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Alice', $users[0]['name']);
    }

    public function testSelectRaw(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice1', 'age' => 25]);

        // Use select with Db::raw() instead of selectRaw()
        $result = $db->find()
            ->from('users')
            ->select([
                'upper_name' => Db::raw('UPPER([name])'),
                'double_age' => Db::raw('[age] * 2'),
            ])
            ->getOne();

        $this->assertNotFalse($result);
        $this->assertArrayHasKey('upper_name', $result);
        $this->assertArrayHasKey('double_age', $result);
        $this->assertEquals('ALICE1', $result['upper_name']);
        $this->assertEquals(50, $result['double_age']);
    }

    public function testUnion(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30]);

        // MSSQL UNION ALL - test UNION functionality
        $results = $db->find()
            ->from('users')
            ->select('name')
            ->where('age', 25)
            ->unionAll(function ($q) {
                $q->from('users')
                    ->select('name')
                    ->where('age', 30);
            })
            ->get();

        $this->assertCount(2, $results);
        $names = array_column($results, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }
}
