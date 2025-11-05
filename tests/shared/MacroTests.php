<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use RuntimeException;
use tommyknocker\pdodb\query\MacroRegistry;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * MacroTests for macro functionality.
 */
final class MacroTests extends BaseSharedTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        // Clear all macros after each test to avoid interference
        MacroRegistry::clear();
    }

    public function testRegisterMacro(): void
    {
        QueryBuilder::macro('testMacro', function (QueryBuilder $query) {
            return $query->where('name', 'test');
        });

        $this->assertTrue(QueryBuilder::hasMacro('testMacro'));
    }

    public function testHasMacroReturnsFalseForNonExistentMacro(): void
    {
        $this->assertFalse(QueryBuilder::hasMacro('nonExistentMacro'));
    }

    public function testCallMacro(): void
    {
        QueryBuilder::macro('whereActive', function (QueryBuilder $query) {
            return $query->where('name', 'active');
        });

        $query = self::$db->find()->table('test_coverage');
        $result = $query->whereActive();

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testMacroWithArguments(): void
    {
        QueryBuilder::macro('whereName', function (QueryBuilder $query, string $name) {
            return $query->where('name', $name);
        });

        $query = self::$db->find()->table('test_coverage');
        $result = $query->whereName('test');

        $this->assertInstanceOf(QueryBuilder::class, $result);

        // Verify the condition was applied
        $sql = $result->toSQL();
        $this->assertStringContainsString('name', $sql['sql']);
    }

    public function testMacroWithMultipleArguments(): void
    {
        QueryBuilder::macro('whereBetweenValues', function (QueryBuilder $query, string $column, int $min, int $max) {
            return $query->whereBetween($column, $min, $max);
        });

        $query = self::$db->find()->table('test_coverage');
        $result = $query->whereBetweenValues('value', 1, 10);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testMacroReturnsQueryBuilderForChaining(): void
    {
        QueryBuilder::macro('activeOnly', function (QueryBuilder $query) {
            return $query->where('name', 'active');
        });

        $result = self::$db->find()
            ->table('test_coverage')
            ->activeOnly()
            ->orderBy('id')
            ->limit(10);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testMacroCanReturnNonQueryBuilderValue(): void
    {
        QueryBuilder::macro('getTableName', function (QueryBuilder $query) {
            // Use toSQL to get table name from SQL
            $sql = $query->toSQL();
            // Extract table name from SQL (simplified - just for testing)
            return 'test_coverage';
        });

        $query = self::$db->find()->table('test_coverage');
        $tableName = $query->getTableName();

        $this->assertEquals('test_coverage', $tableName);
    }

    public function testMacroCanModifyQueryAndReturnIt(): void
    {
        QueryBuilder::macro('recent', function (QueryBuilder $query, int $days = 7) {
            return $query->where('created_at', '>=', date('Y-m-d', strtotime("-{$days} days")));
        });

        $query = self::$db->find()->table('test_coverage');
        $result = $query->recent(30);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testCallNonExistentMacroThrowsException(): void
    {
        $query = self::$db->find()->table('test_coverage');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Call to undefined method');

        $query->nonExistentMacro();
    }

    public function testMacroCanUseQueryBuilderMethods(): void
    {
        QueryBuilder::macro('published', function (QueryBuilder $query) {
            return $query
                ->where('name', 'published')
                ->orderBy('created_at', 'DESC');
        });

        $query = self::$db->find()->table('test_coverage');
        $result = $query->published();

        $this->assertInstanceOf(QueryBuilder::class, $result);

        $sql = $result->toSQL();
        $this->assertStringContainsString('ORDER BY', $sql['sql']);
    }

    public function testMultipleMacrosCanBeRegistered(): void
    {
        QueryBuilder::macro('macro1', function (QueryBuilder $query) {
            return $query->where('name', 'value1');
        });

        QueryBuilder::macro('macro2', function (QueryBuilder $query) {
            return $query->where('value', 100);
        });

        $this->assertTrue(QueryBuilder::hasMacro('macro1'));
        $this->assertTrue(QueryBuilder::hasMacro('macro2'));
    }

    public function testMacroCanBeOverwritten(): void
    {
        QueryBuilder::macro('testMacro', function (QueryBuilder $query) {
            return $query->where('name', 'first');
        });

        QueryBuilder::macro('testMacro', function (QueryBuilder $query) {
            return $query->where('name', 'second');
        });

        $query = self::$db->find()->table('test_coverage');
        $result = $query->testMacro();

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testMacroWithComplexLogic(): void
    {
        QueryBuilder::macro('whereStatus', function (QueryBuilder $query, string $status) {
            if ($status === 'active') {
                return $query->where('name', 'active');
            } elseif ($status === 'inactive') {
                return $query->where('name', 'inactive');
            }

            return $query;
        });

        $query = self::$db->find()->table('test_coverage');
        $result = $query->whereStatus('active');

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testMacroRegistryClear(): void
    {
        QueryBuilder::macro('testMacro', function (QueryBuilder $query) {
            return $query;
        });

        $this->assertTrue(QueryBuilder::hasMacro('testMacro'));

        MacroRegistry::clear();

        $this->assertFalse(QueryBuilder::hasMacro('testMacro'));
    }

    public function testMacroRegistryGetAll(): void
    {
        QueryBuilder::macro('macro1', function (QueryBuilder $query) {
            return $query;
        });

        QueryBuilder::macro('macro2', function (QueryBuilder $query) {
            return $query;
        });

        $allMacros = MacroRegistry::getAll();

        $this->assertContains('macro1', $allMacros);
        $this->assertContains('macro2', $allMacros);
    }

    public function testMacroRegistryRemove(): void
    {
        QueryBuilder::macro('testMacro', function (QueryBuilder $query) {
            return $query;
        });

        $this->assertTrue(QueryBuilder::hasMacro('testMacro'));

        MacroRegistry::remove('testMacro');

        $this->assertFalse(QueryBuilder::hasMacro('testMacro'));
    }

    public function testMacroCanExecuteQuery(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 1]);

        QueryBuilder::macro('findByName', function (QueryBuilder $query, string $name) {
            return $query->where('name', $name)->getOne();
        });

        $result = self::$db->find()->table('test_coverage')->findByName('test');

        $this->assertIsArray($result);
        $this->assertEquals('test', $result['name']);
    }

    public function testMacroIntegrationWithRealQuery(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'active', 'value' => 1]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'inactive', 'value' => 2]);

        QueryBuilder::macro('active', function (QueryBuilder $query) {
            return $query->where('name', 'active');
        });

        $results = self::$db->find()
            ->table('test_coverage')
            ->active()
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('active', $results[0]['name']);
    }
}
