<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

/**
 * JoinTests for Oracle.
 */
final class JoinTests extends BaseOracleTestCase
{
    public function testInnerJoinAndWhere(): void
    {
        $db = self::$db;

        $uid = $db->find()
            ->table('users')
            ->insert(['name' => 'JoinUser', 'age' => 40]);
        $this->assertIsInt($uid);

        self::$db->find()
            ->table('orders')
            ->insert(['user_id' => $uid, 'amount' => 100]);

        $rows = self::$db
            ->find()
            ->from('users u')
            ->innerJoin('orders o', 'u.id = o.user_id')
            ->where('o.amount', 100)
            ->andWhere('o.amount', [100, 200], 'IN')
            ->get();

        $this->assertEquals('JoinUser', $rows[0]['NAME']);
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
        $this->assertEquals('Left Join User', $results[0]['NAME']);
        $this->assertEquals('150.5', (string)$results[0]['AMOUNT']);

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
        $this->assertEquals('Right Join User', $results[0]['NAME']);
        $this->assertEquals('200.75', (string)$results[0]['AMOUNT']);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('RIGHT JOIN', $lastQuery);
    }
}
