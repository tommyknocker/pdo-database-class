<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\helpers\Db;

/**
 * RawValueTests tests for mysql.
 */
final class RawValueTests extends BaseMySQLTestCase
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
        $this->assertEquals('Mr_John', $row['name']);

        // 2. UPDATE with RawValue containing parameters
        $rowCount = $db->find()
        ->table('users')
        ->where('id', $id)
        ->update([
        'age' => Db::raw('age + :inc', [':inc' => 5]),
        'name' => Db::raw('CONCAT(name, :suffix)', [':suffix' => '_updated']),
        ]);

        $this->assertEquals(1, $rowCount);
        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertEquals(35, (int)$row['age']);
        $this->assertEquals('Mr_John_updated', $row['name']);

        // 3. WHERE condition with RawValue parameters
        $rows = $db->find()
        ->from('users')
        ->where(Db::raw('age BETWEEN :min AND :max', [
        ':min' => 30,
        ':max' => 40,
        ]))
        ->get();

        $this->assertNotEmpty($rows);
        $this->assertGreaterThanOrEqual(30, $rows[0]['age']);
        $this->assertLessThanOrEqual(40, $rows[0]['age']);

        // 4. JOIN condition with RawValue parameters
        $orderId = $db->find()->table('orders')->insert([
        'user_id' => $id,
        'amount' => 100,
        ]);
        $this->assertIsInt($orderId);

        $result = $db->find()
        ->from('users u')
        ->join('orders o', Db::raw('o.user_id = u.id AND o.amount > :min_amount', [
        ':min_amount' => 50,
        ]))
        ->where('u.id', $id)
        ->getOne();

        $this->assertNotNull($result);
        $this->assertEquals(100, (int)$result['amount']);

        // 5. HAVING clause with RawValue parameters
        $results = $db->find()
        ->from('orders')
        ->select(['user_id', 'SUM(amount) as total'])
        ->groupBy('user_id')
        ->having(Db::raw('total > :min_total AND total < :max_total', [
        ':min_total' => 50,
        ':max_total' => 150,
        ]))
        ->get();

        $this->assertNotEmpty($results);
        $this->assertGreaterThan(50, $results[0]['total']);
        $this->assertLessThan(150, $results[0]['total']);

        // 6. DELETE with RawValue parameters in WHERE
        $rowCount = $db->find()
        ->table('orders')
        ->where(Db::raw('amount BETWEEN :min AND :max', [
        ':min' => 90,
        ':max' => 110,
        ]))
        ->delete();

        $this->assertEquals(1, $rowCount);

        // 7. Multiple RawValues with overlapping parameter names
        $rows = $db->find()
        ->from('users')
        ->where(Db::raw('age > :val', [':val' => 30]))
        ->andWhere(Db::raw('name LIKE :val', [':val' => '%updated%']))
        ->get();

        $this->assertNotEmpty($rows);
        $this->assertGreaterThan(30, $rows[0]['age']);
        $this->assertStringContainsString('updated', $rows[0]['name']);
    }
}
