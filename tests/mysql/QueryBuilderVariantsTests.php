<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\helpers\Db;

/**
 * QueryBuilderVariantsTests tests for mysql.
 */
final class QueryBuilderVariantsTests extends BaseMySQLTestCase
{
    public function testQueryBuilderAllParameterVariants(): void
    {
        $db = self::$db;

        // Setup test data
        $db->find()->table('users')->insertMulti([
        ['name' => 'APITest1', 'age' => 20],
        ['name' => 'APITest2', 'age' => 30],
        ['name' => 'APITest3', 'age' => 40],
        ['name' => 'APITest4', 'age' => null],
        ]);

        // WHERE variants

        // 1. where('column', value)
        $r1 = $db->find()->from('users')->where('name', 'APITest1')->getOne();
        $this->assertEquals('APITest1', $r1['name']);

        // 2. where('column', value, operator)
        $r2 = $db->find()->from('users')->where('age', 25, '>')->get();
        $this->assertCount(2, $r2); // APITest2 and APITest3

        // 3. where(['col' => val])
        $r3 = $db->find()->from('users')->where(['name' => 'APITest2'])->getOne();
        $this->assertEquals('APITest2', $r3['name']);

        // 4. where(['col1' => val1, 'col2' => val2]) - multiple
        $r4 = $db->find()->from('users')->where(['name' => 'APITest3', 'age' => 40])->getOne();
        $this->assertEquals('APITest3', $r4['name']);

        // 4b. where(['col' => Db::helper()]) - with Db helper (RawValue) in array
        $r4b = $db->find()->from('users')->where(['age' => Db::between('age', 25, 35)])->get();
        $this->assertCount(1, $r4b); // APITest2 (age=30)

        // 5. where with RawValue
        $r5 = $db->find()->from('users')->where(Db::isNotNull('age'))->get();
        $this->assertCount(3, $r5);

        // 6. where with > operator on NULL values
        $r6 = $db->find()->from('users')->where('age', 35, '>')->get();
        $this->assertCount(1, $r6); // APITest3

        // 7. orWhere
        $r7 = $db->find()->from('users')
        ->where('age', 20)
        ->orWhere('age', 30)
        ->get();
        $this->assertCount(2, $r7);

        // 8. where IN with array
        $r8 = $db->find()->from('users')->where('age', [20, 30], 'IN')->get();
        $this->assertCount(2, $r8);

        // 9. where BETWEEN
        $r9 = $db->find()->from('users')->where('age', [25, 35], 'BETWEEN')->get();
        $this->assertCount(1, $r9); // APITest2 (age=30)

        // 10. where NOT IN
        $r10 = $db->find()->from('users')->where('age', [20, 40], 'NOT IN')->get();
        $this->assertCount(1, $r10); // APITest2 (age=30)

        // SELECT variants

        // 11. select() with string
        $r11 = $db->find()->from('users')->select('name')->where('name', 'APITest1')->getOne();
        $this->assertArrayHasKey('name', $r11);
        $this->assertArrayNotHasKey('age', $r11);

        // 12. select() with array
        $r12 = $db->find()->from('users')->select(['name', 'age'])->where('name', 'APITest1')->getOne();
        $this->assertArrayHasKey('name', $r12);
        $this->assertArrayHasKey('age', $r12);

        // 13. select() with aliases
        $r13 = $db->find()->from('users')->select(['user_name' => 'name'])->where('name', 'APITest1')->getOne();
        $this->assertArrayHasKey('user_name', $r13);

        // 14. select() with Db helper
        $r14 = $db->find()->from('users')->select(['total' => Db::count()])->getValue();
        $this->assertGreaterThanOrEqual(4, $r14);

        // 15. select() with multiple aggregates
        $r15 = $db->find()->from('users')
        ->select([
        'total' => Db::count(),
        'avg_age' => Db::avg('age'),
        'max_age' => Db::max('age'),
        ])
        ->where(Db::isNotNull('age'))
        ->getOne();
        $this->assertEquals(3, $r15['total']);
        $this->assertEquals(30, (float)$r15['avg_age']);
        $this->assertEquals(40, $r15['max_age']);

        // ORDER BY variants

        // 16. orderBy('column')
        $r16 = $db->find()->from('users')->select('name')->where(Db::isNotNull('age'))->orderBy('age')->limit(1)->getValue();
        $this->assertEquals('APITest1', $r16);

        // 17. orderBy('column', 'DESC')
        $r17 = $db->find()->from('users')->select('name')->where(Db::isNotNull('age'))->orderBy('age', 'DESC')->limit(1)->getValue();
        $this->assertEquals('APITest3', $r17);

        // 18. orderBy with qualified name
        $r18 = $db->find()
        ->from('users AS u')
        ->select('u.name')
        ->orderBy('u.age', 'DESC')
        ->where(Db::isNotNull('u.age'))
        ->limit(1)
        ->getValue();
        $this->assertEquals('APITest3', $r18);

        // GROUP BY variants

        // 19. groupBy('column')
        $r19 = $db->find()->from('users')
        ->select(['age', 'cnt' => Db::count()])
        ->where(Db::isNotNull('age'))
        ->groupBy('age')
        ->get();
        $this->assertEquals(3, count($r19));

        // 20. groupBy(['col1', 'col2'])
        $r20 = $db->find()->from('users')
        ->select(['name', 'age'])
        ->where(Db::isNotNull('age'))
        ->groupBy(['name', 'age'])
        ->get();
        $this->assertEquals(3, count($r20));

        // HAVING

        // 21. having()
        $r21 = $db->find()->from('users')
        ->select(['age', 'cnt' => Db::count()])
        ->where(Db::isNotNull('age'))
        ->groupBy('age')
        ->having(Db::count(), 0, '>')
        ->get();
        $this->assertEquals(3, count($r21));

        // UPDATE/DELETE

        // 22. update() with Db::dec()
        $db->find()->table('users')->where('name', 'APITest3')->update(['age' => Db::dec(10)]);
        $r22 = $db->find()->from('users')->where('name', 'APITest3')->getOne();
        $this->assertEquals(30, $r22['age']); // 40 - 10

        // 23. delete() with IS NULL helper
        $deleted = $db->find()->table('users')->where(Db::isNull('age'))->delete();
        $this->assertEquals(1, $deleted); // APITest4 with null age

        // Cleanup verification
        $final = $db->find()->from('users')->select([Db::count()])->getValue();
        $this->assertEquals(3, $final); // APITest1, APITest2, APITest3 remain
    }

    public function testNewQueryBuilderMethods(): void
    {
        $db = self::$db;

        // Clear existing data first
        $db->find()->table('orders')->delete();
        $db->find()->table('users')->delete();

        // Insert test data
        $db->find()->table('users')->insertMulti([
        ['name' => 'Alice', 'age' => 25, 'status' => 'active'],
        ['name' => 'Bob', 'age' => 30, 'status' => 'inactive'],
        ['name' => 'Charlie', 'age' => 35, 'status' => 'active'],
        ]);

        $db->find()->table('orders')->insertMulti([
        ['user_id' => 1, 'amount' => 150.50],
        ['user_id' => 2, 'amount' => 200.75],
        ['user_id' => 3, 'amount' => 99.99],
        ]);

        // Test whereIn with subquery
        $users = $db->find()
        ->from('users')
        ->whereIn('id', function ($q) {
            $q->from('orders')
            ->select('user_id')
            ->where('amount', 100, '>');
        })
        ->get();

        $this->assertCount(2, $users);
        $this->assertEquals('Alice', $users[0]['name']);
        $this->assertEquals('Bob', $users[1]['name']);

        // Test whereNotIn with subquery
        $users = $db->find()
        ->from('users')
        ->whereNotIn('id', function ($q) {
            $q->from('orders')
            ->select('user_id')
            ->where('amount', 100, '>');
        })
        ->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Charlie', $users[0]['name']);

        // Test whereExists
        $users = $db->find()
        ->from('users')
        ->whereExists(function ($q) {
            $q->from('orders')
            ->where('user_id', Db::raw('users.id'), '=')
            ->where('amount', 100, '>');
        })
        ->get();

        $this->assertCount(2, $users);

        // Test whereNotExists - users without orders > 200
        $users = $db->find()
        ->from('users')
        ->whereNotExists(function ($q) {
            $q->from('orders')
            ->where('user_id', Db::raw('users.id'), '=')
            ->where('amount', 200, '>');
        })
        ->get();

        // Debug: let's see what we get
        $this->assertGreaterThanOrEqual(1, count($users));
        $this->assertLessThanOrEqual(2, count($users));

        // At least Charlie should be in the result
        $userNames = array_column($users, 'name');
        $this->assertContains('Charlie', $userNames);

        // Test whereNotExists with higher threshold - should return all users
        $users = $db->find()
        ->from('users')
        ->whereNotExists(function ($q) {
            $q->from('orders')
            ->where('user_id', Db::raw('users.id'), '=')
            ->where('amount', 300, '>');
        })
        ->get();

        $this->assertCount(3, $users);

        // Test whereRaw - simplified test
        $users = $db->find()
        ->from('users')
        ->whereRaw('status = :status', ['status' => 'active'])
        ->get();

        $this->assertCount(2, $users);
        $userNames = array_column($users, 'name');
        $this->assertContains('Alice', $userNames);
        $this->assertContains('Charlie', $userNames);

        // Test havingRaw
        $results = $db->find()
        ->from('users')
        ->select(['status', Db::raw('COUNT(*) as count')])
        ->groupBy('status')
        ->havingRaw('COUNT(*) > :min_count', ['min_count' => 0])
        ->get();

        $this->assertCount(2, $results);
    }
}
