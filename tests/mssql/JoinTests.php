<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

/**
 * JoinTests for MSSQL.
 */
final class JoinTests extends BaseMSSQLTestCase
{
    public function testInnerJoinAndWhere(): void
    {
        $db = self::$db;

        $uid = $db->find()
            ->table('users')
            ->insert(['name' => 'JoinUser', 'age' => 40]);
        $this->assertIsInt($uid);
        $this->assertGreaterThan(0, $uid);

        // Verify the user was inserted and get actual ID if needed
        $user = $db->find()
            ->from('users')
            ->where('name', 'JoinUser')
            ->getOne();
        $this->assertNotFalse($user);
        $actualUid = $user['id'] ?? $uid;

        self::$db->find()
            ->table('orders')
            ->insert(['user_id' => $actualUid, 'amount' => 100]);

        $rows = self::$db
            ->find()
            ->from('users u')
            ->innerJoin('orders o', 'u.id = o.user_id')
            ->where('o.amount', 100)
            ->andWhere('o.amount', [100, 200], 'IN')
            ->get();

        $this->assertNotEmpty($rows);
        $this->assertEquals('JoinUser', $rows[0]['name']);
    }

    public function testLeftJoin(): void
    {
        $db = self::$db;

        $userId = $db->find()->table('users')->insert(['name' => 'Left Join User', 'age' => 30]);
        $db->find()->table('orders')->insert(['user_id' => $userId, 'amount' => 150.50]);

        $results = $db->find()
            ->from('users u')
            ->leftJoin('orders o', 'u.id = o.user_id')
            ->select(['u.name', 'o.amount'])
            ->get();

        $this->assertNotEmpty($results);
        $this->assertEquals('Left Join User', $results[0]['name']);
        $this->assertEquals('150.50', $results[0]['amount']);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('LEFT JOIN', $lastQuery);
    }

    public function testRightJoin(): void
    {
        $db = self::$db;

        $userId = $db->find()->table('users')->insert(['name' => 'Right Join User', 'age' => 25]);
        $db->find()->table('orders')->insert(['user_id' => $userId, 'amount' => 200.75]);

        $results = $db->find()
            ->from('users u')
            ->rightJoin('orders o', 'u.id = o.user_id')
            ->select(['u.name', 'o.amount'])
            ->get();

        $this->assertNotEmpty($results);
        $this->assertEquals('Right Join User', $results[0]['name']);
        $this->assertEquals('200.75', $results[0]['amount']);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('RIGHT JOIN', $lastQuery);
    }


    public function testMultipleJoins(): void
    {
        $db = self::$db;

        $userId = $db->find()->table('users')->insert(['name' => 'MultiJoin User', 'age' => 35]);
        $orderId = $db->find()->table('orders')->insert(['user_id' => $userId, 'amount' => 250.00]);
        $db->find()->table('archive_users')->insert(['user_id' => $userId]);

        $results = $db->find()
            ->from('users u')
            ->innerJoin('orders o', 'u.id = o.user_id')
            ->leftJoin('archive_users a', 'u.id = a.user_id')
            ->select(['u.name', 'o.amount', 'a.user_id'])
            ->where('u.id', $userId)
            ->get();

        $this->assertNotEmpty($results);
        $this->assertEquals('MultiJoin User', $results[0]['name']);
        $this->assertEquals('250.00', $results[0]['amount']);
    }
}

