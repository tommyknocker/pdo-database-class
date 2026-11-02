<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\helpers\Db;

/**
 * On MySQL 8.0.19+ upserts read the inserted row through an alias; they must run, keep their meaning and not raise
 * the deprecation warning that `VALUES(column)` raises from 8.0.20.
 */
final class RowAliasUpsertTests extends BaseMySQLTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!self::$db->schema()->getDialect()->supportsRowAlias()) {
            $this->markTestSkipped('The test server is older than MySQL 8.0.19');
        }
    }

    /**
     * @return list<string> messages of the warnings of the last statement (read without preparing, which SHOW
     *                      WARNINGS does not allow)
     */
    private function warnings(): array
    {
        $pdo = self::$db->connection->getPdo();
        $emulated = $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);

        try {
            $statement = $pdo->query('SHOW WARNINGS');
            $this->assertNotFalse($statement);

            return array_column($statement->fetchAll(\PDO::FETCH_ASSOC), 'Message');
        } finally {
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $emulated);
        }
    }

    public function testTheOldFormReallyWarnsOnThisServer(): void
    {
        // guards the warning check below: if VALUES() did not warn here, "no warning" would prove nothing
        $db = self::$db;
        $db->find()->table('users')->insert(['name' => 'Ada', 'age' => 30]);
        $db->connection->getPdo()->exec('INSERT INTO users (name, age) VALUES (\'Ada\', 31) ON DUPLICATE KEY UPDATE age = VALUES(age)');

        $warnings = $this->warnings();
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('is deprecated and will be removed', implode(' ', $warnings));
    }

    public function testSingleRowUpsertUsesTheAliasAndRaisesNoWarning(): void
    {
        $db = self::$db;
        $db->find()->table('users')->insert(['name' => 'Ada', 'age' => 30]);
        $db->find()->table('users')->onDuplicate(['age'])->insert(['name' => 'Ada', 'age' => 31]);

        $this->assertStringContainsString('AS pdodb_new (pdodb_c0, pdodb_c1)', $db->lastQuery ?? '');
        $this->assertSame([], $this->warnings());
        $this->assertEquals(31, $db->find()->from('users')->where('name', 'Ada')->getOne()['age']);
    }

    public function testMultiRowUpsertUpdatesExistingRowsAndInsertsNewOnes(): void
    {
        $db = self::$db;
        $db->find()->table('users')->insert(['name' => 'Ada', 'age' => 30]);
        $db->find()->table('users')->onDuplicate(['age'])->insertMulti([
            ['name' => 'Ada', 'age' => 40],
            ['name' => 'Bob', 'age' => 25],
        ]);

        $this->assertStringContainsString('AS pdodb_new (pdodb_c0, pdodb_c1)', $db->lastQuery ?? '');
        $this->assertSame([], $this->warnings());
        $ages = array_column($db->find()->from('users')->orderBy('name')->get(), 'age', 'name');
        $this->assertEquals(['Ada' => 40, 'Bob' => 25], $ages);
    }

    public function testRawAndCounterExpressionsStillMeanTheTableColumn(): void
    {
        $db = self::$db;
        $db->find()->table('users')->insert(['name' => 'Ada', 'age' => 10]);

        // `age` without a prefix would be ambiguous if the alias carried the table's column names
        $db->find()->table('users')->onDuplicate(['age' => Db::raw('age + 5')])->insert(['name' => 'Ada', 'age' => 99]);
        $this->assertEquals(15, $db->find()->from('users')->where('name', 'Ada')->getOne()['age']);

        $db->find()->table('users')->onDuplicate(['age' => Db::inc(3)])->insert(['name' => 'Ada', 'age' => 99]);
        $this->assertEquals(18, $db->find()->from('users')->where('name', 'Ada')->getOne()['age']);
        $this->assertSame([], $this->warnings());
    }

    public function testAColumnThatIsUpdatedButNotInsertedKeepsValues(): void
    {
        $db = self::$db;
        $db->find()->table('users')->insert(['name' => 'Ada', 'age' => 30, 'status' => 'old']);
        $db->find()->table('users')->onDuplicate(['age', 'status'])->insert(['name' => 'Ada', 'age' => 31]);

        $row = $db->find()->from('users')->where('name', 'Ada')->getOne();
        $this->assertEquals(31, $row['age']);
        $this->assertNull($row['status'], 'status is not in the insert, so its new value is the default');
    }

    public function testFirstOrCreateAndUpdateOrCreateStillWork(): void
    {
        $db = self::$db;
        [$sql, $params] = $db->schema()->getDialect()->buildIdempotentUpsertSql('users', ['name' => 'Cy', 'age' => 5], ['name'], ['age']);
        $db->rawQuery($sql, $params);
        [$sql, $params] = $db->schema()->getDialect()->buildIdempotentUpsertSql('users', ['name' => 'Cy', 'age' => 6], ['name'], ['age']);
        $db->rawQuery($sql, $params);

        $this->assertStringContainsString('pdodb_new.pdodb_c1', $sql);
        $this->assertEquals(6, $db->find()->from('users')->where('name', 'Cy')->getOne()['age']);
        $this->assertSame([], $this->warnings());
    }
}
