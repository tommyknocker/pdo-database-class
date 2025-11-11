<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\values\FilterValue;
use tommyknocker\pdodb\helpers\values\FulltextMatchValue;
use tommyknocker\pdodb\helpers\values\JsonContainsValue;
use tommyknocker\pdodb\helpers\values\JsonPathValue;
use tommyknocker\pdodb\helpers\values\JsonRemoveValue;
use tommyknocker\pdodb\helpers\values\JsonReplaceValue;
use tommyknocker\pdodb\helpers\values\JsonSetValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\helpers\values\WindowFunctionValue;
use tommyknocker\pdodb\query\ParameterManager;
use tommyknocker\pdodb\query\RawValueResolver;

/**
 * Tests for RawValueResolver class.
 */
final class RawValueResolverTests extends BaseSharedTestCase
{
    protected function createResolver(): RawValueResolver
    {
        $connection = self::$db->connection;
        $parameterManager = new ParameterManager();
        return new RawValueResolver($connection, $parameterManager);
    }

    public function testResolveJsonPathValue(): void
    {
        $resolver = $this->createResolver();
        $reflection = new \ReflectionClass($resolver);
        $method = $reflection->getMethod('resolveJsonPathValue');
        $method->setAccessible(true);

        $jsonPathValue = new JsonPathValue('data', '$.name', '=', 'John');
        $result = $method->invoke($resolver, $jsonPathValue);

        $this->assertIsString($result);
        $this->assertStringContainsString('$.name', $result);
        $this->assertStringContainsString('=', $result);
    }

    public function testResolveJsonPathValueWithRawValue(): void
    {
        $resolver = $this->createResolver();
        $reflection = new \ReflectionClass($resolver);
        $method = $reflection->getMethod('resolveJsonPathValue');
        $method->setAccessible(true);

        $rawValue = new RawValue(':param', ['param' => 'John']);
        $jsonPathValue = new JsonPathValue('data', '$.name', '=', $rawValue);
        $result = $method->invoke($resolver, $jsonPathValue);

        $this->assertIsString($result);
        $this->assertStringContainsString('$.name', $result);
    }

    public function testResolveJsonContainsValue(): void
    {
        $resolver = $this->createResolver();
        $reflection = new \ReflectionClass($resolver);
        $method = $reflection->getMethod('resolveJsonContainsValue');
        $method->setAccessible(true);

        $jsonContainsValue = new JsonContainsValue('data', 'value', null);
        $result = $method->invoke($resolver, $jsonContainsValue);

        $this->assertIsString($result);
    }

    public function testResolveJsonSetValue(): void
    {
        $resolver = $this->createResolver();
        $reflection = new \ReflectionClass($resolver);
        $method = $reflection->getMethod('resolveJsonSetValue');
        $method->setAccessible(true);

        $jsonSetValue = new JsonSetValue('data', '$.name', 'John');
        $result = $method->invoke($resolver, $jsonSetValue);

        $this->assertIsString($result);
    }

    public function testResolveJsonRemoveValue(): void
    {
        $resolver = $this->createResolver();
        $reflection = new \ReflectionClass($resolver);
        $method = $reflection->getMethod('resolveJsonRemoveValue');
        $method->setAccessible(true);

        $jsonRemoveValue = new JsonRemoveValue('data', '$.name');
        $result = $method->invoke($resolver, $jsonRemoveValue);

        $this->assertIsString($result);
    }

    public function testResolveJsonReplaceValue(): void
    {
        $resolver = $this->createResolver();
        $reflection = new \ReflectionClass($resolver);
        $method = $reflection->getMethod('resolveJsonReplaceValue');
        $method->setAccessible(true);

        $jsonReplaceValue = new JsonReplaceValue('data', '$.name', 'John');
        $result = $method->invoke($resolver, $jsonReplaceValue);

        $this->assertIsString($result);
    }

    public function testResolveFulltextMatchValue(): void
    {
        $resolver = $this->createResolver();
        $reflection = new \ReflectionClass($resolver);
        $method = $reflection->getMethod('resolveFulltextMatchValue');
        $method->setAccessible(true);

        $fulltextMatchValue = new FulltextMatchValue(['title', 'content'], 'search term', 'NATURAL');
        $result = $method->invoke($resolver, $fulltextMatchValue);

        $this->assertIsString($result);
    }

    public function testResolveWindowFunctionValue(): void
    {
        $resolver = $this->createResolver();
        $reflection = new \ReflectionClass($resolver);
        $method = $reflection->getMethod('resolveWindowFunctionValue');
        $method->setAccessible(true);

        $windowFunctionValue = new WindowFunctionValue('ROW_NUMBER', [], [], [], null);
        $result = $method->invoke($resolver, $windowFunctionValue);

        $this->assertIsString($result);
    }

    public function testResolveFilterValue(): void
    {
        $resolver = $this->createResolver();
        $reflection = new \ReflectionClass($resolver);
        $method = $reflection->getMethod('resolveFilterValue');
        $method->setAccessible(true);

        $filterValue = new FilterValue('COUNT(*)', [
            ['column' => 'status', 'operator' => '=', 'value' => 'active'],
        ]);
        $result = $method->invoke($resolver, $filterValue);

        $this->assertIsString($result);
    }

    public function testResolveFilterValueWithoutFilter(): void
    {
        $resolver = $this->createResolver();
        $reflection = new \ReflectionClass($resolver);
        $method = $reflection->getMethod('resolveFilterValue');
        $method->setAccessible(true);

        $filterValue = new FilterValue('COUNT(*)', []);
        $result = $method->invoke($resolver, $filterValue);

        $this->assertEquals('COUNT(*)', $result);
    }

    public function testBuildCaseFallback(): void
    {
        $resolver = $this->createResolver();
        $reflection = new \ReflectionClass($resolver);
        $method = $reflection->getMethod('buildCaseFallback');
        $method->setAccessible(true);

        // Test COUNT function
        $result = $method->invoke($resolver, 'COUNT(*)', ':param = 1');
        $this->assertStringContainsString('CASE WHEN', $result);
        $this->assertStringContainsString('COUNT', $result);

        // Test SUM function
        $result = $method->invoke($resolver, 'SUM(amount)', ':param = 1');
        $this->assertStringContainsString('CASE WHEN', $result);
        $this->assertStringContainsString('SUM', $result);

        // Test AVG function with column
        $result = $method->invoke($resolver, 'AVG(price)', ':param = 1');
        $this->assertStringContainsString('CASE WHEN', $result);
        $this->assertStringContainsString('AVG', $result);
    }
}
