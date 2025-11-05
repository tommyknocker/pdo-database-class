<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use RuntimeException;
use tommyknocker\pdodb\query\cte\CteDefinition;
use tommyknocker\pdodb\query\cte\CteManager;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Tests for CteManager class.
 */
final class CteManagerTests extends BaseSharedTestCase
{
    protected function createCteManager(): CteManager
    {
        return new CteManager(self::$db->connection);
    }

    public function testCteManagerIsEmpty(): void
    {
        $manager = $this->createCteManager();
        $this->assertTrue($manager->isEmpty());
        $this->assertEquals('', $manager->buildSql());
    }

    public function testCteManagerAddCte(): void
    {
        $manager = $this->createCteManager();
        $cte = new CteDefinition('users_cte', 'SELECT * FROM users WHERE id = 1');
        $manager->add($cte);

        $this->assertFalse($manager->isEmpty());
        $this->assertCount(1, $manager->getAll());
    }

    public function testCteManagerBuildSql(): void
    {
        $manager = $this->createCteManager();
        $cte = new CteDefinition('users_cte', 'SELECT * FROM users WHERE id = 1');
        $manager->add($cte);

        $sql = $manager->buildSql();
        $this->assertStringContainsString('WITH', $sql);
        $this->assertStringContainsString('users_cte', $sql);
    }

    public function testCteManagerBuildSqlRecursive(): void
    {
        $manager = $this->createCteManager();
        $cte = new CteDefinition('recursive_cte', 'SELECT * FROM users WHERE id = 1', true);
        $manager->add($cte);

        $sql = $manager->buildSql();
        $this->assertStringContainsString('WITH RECURSIVE', $sql);
    }

    public function testCteManagerGetParams(): void
    {
        $manager = $this->createCteManager();
        $params = $manager->getParams();
        $this->assertIsArray($params);
        $this->assertEmpty($params);
    }

    public function testCteManagerClear(): void
    {
        $manager = $this->createCteManager();
        $cte = new CteDefinition('users_cte', 'SELECT * FROM users WHERE id = 1');
        $manager->add($cte);

        $this->assertFalse($manager->isEmpty());
        $manager->clear();
        $this->assertTrue($manager->isEmpty());
        $this->assertEmpty($manager->getParams());
    }

    public function testCteManagerHasRecursive(): void
    {
        $manager = $this->createCteManager();
        $this->assertFalse($manager->hasRecursive());

        $cte = new CteDefinition('recursive_cte', 'SELECT * FROM users', true);
        $manager->add($cte);
        $this->assertTrue($manager->hasRecursive());
    }

    public function testCteManagerWithQueryBuilder(): void
    {
        $manager = $this->createCteManager();
        $qb = self::$db->find()->from('users')->select(['id', 'name'])->where('id', 1);
        $cte = new CteDefinition('users_cte', $qb);
        $manager->add($cte);

        $sql = $manager->buildSql();
        $this->assertStringContainsString('WITH', $sql);
        $this->assertStringContainsString('users_cte', $sql);

        $params = $manager->getParams();
        $this->assertNotEmpty($params);
    }

    public function testCteManagerWithClosure(): void
    {
        $manager = $this->createCteManager();
        $cte = new CteDefinition('users_cte', function (QueryBuilder $qb) {
            $qb->from('users')->select(['id', 'name'])->where('id', 1);
        });
        $manager->add($cte);

        $sql = $manager->buildSql();
        $this->assertStringContainsString('WITH', $sql);
        $this->assertStringContainsString('users_cte', $sql);

        $params = $manager->getParams();
        $this->assertNotEmpty($params);
    }

    public function testCteManagerMultipleCtes(): void
    {
        $manager = $this->createCteManager();
        $cte1 = new CteDefinition('cte1', 'SELECT * FROM users');
        $cte2 = new CteDefinition('cte2', 'SELECT * FROM orders');
        $manager->add($cte1);
        $manager->add($cte2);

        $this->assertCount(2, $manager->getAll());
        $sql = $manager->buildSql();
        $this->assertStringContainsString('cte1', $sql);
        $this->assertStringContainsString('cte2', $sql);
    }

    public function testCteManagerWithColumns(): void
    {
        $manager = $this->createCteManager();
        $cte = new CteDefinition('users_cte', 'SELECT * FROM users', false, false, ['id', 'name', 'email']);
        $manager->add($cte);

        $sql = $manager->buildSql();
        $this->assertStringContainsString('users_cte', $sql);
        $dialect = self::$db->connection->getDialect();
        $quotedId = $dialect->quoteIdentifier('id');
        $this->assertStringContainsString($quotedId, $sql);
    }

    public function testCteManagerMaterializedNotSupported(): void
    {
        $driver = getenv('PDODB_DRIVER') ?: 'sqlite';
        $manager = $this->createCteManager();
        $cte = new CteDefinition('users_cte', 'SELECT * FROM users', false, true);
        $manager->add($cte);

        if ($driver === 'pgsql') {
            // PostgreSQL supports materialized CTEs
            $sql = $manager->buildSql();
            $this->assertStringContainsString('MATERIALIZED', $sql);
        } else {
            // Other dialects should throw exception
            try {
                $manager->buildSql();
                $this->fail('Expected RuntimeException for unsupported materialized CTE');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Materialized CTE is not supported', $e->getMessage());
            }
        }
    }

    public function testCteManagerMaterializedBehavior(): void
    {
        $driver = getenv('PDODB_DRIVER') ?: 'sqlite';
        $manager = $this->createCteManager();
        $cte = new CteDefinition('users_cte', 'SELECT * FROM users', false, true);
        $manager->add($cte);

        if ($driver === 'pgsql') {
            $sql = $manager->buildSql();
            $this->assertStringContainsString('MATERIALIZED', $sql);
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            // MySQL applies materialization hint in the query itself
            $sql = $manager->buildSql();
            $this->assertStringContainsString('WITH', $sql);
        } else {
            // For other dialects, should throw exception
            $this->expectException(RuntimeException::class);
            $manager->buildSql();
        }
    }

    public function testCteManagerApplyMySQLMaterializationHint(): void
    {
        $driver = getenv('PDODB_DRIVER') ?: 'sqlite';
        $manager = $this->createCteManager();
        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('applyMySQLMaterializationHint');
        $method->setAccessible(true);

        $sql = 'SELECT * FROM users';
        $result = $method->invoke($manager, $sql);
        $this->assertStringContainsString('SELECT', $result);

        // Test with non-SELECT query
        $sql2 = 'INSERT INTO users (name) VALUES ("test")';
        $result2 = $method->invoke($manager, $sql2);
        $this->assertEquals($sql2, $result2);
    }
}
