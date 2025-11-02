<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\ParameterManager;

/**
 * Tests for ParameterManager.
 */
class ParameterManagerTests extends BaseSharedTestCase
{
    /**
     * Test makeParam.
     */
    public function testMakeParam(): void
    {
        $manager = new ParameterManager();

        $param1 = $manager->makeParam('test');
        $this->assertStringStartsWith(':', $param1);
        $this->assertStringContainsString('test', $param1);

        $param2 = $manager->makeParam('test');
        $this->assertNotEquals($param1, $param2); // Should be unique

        // Test with special characters
        $param3 = $manager->makeParam('test-param@123');
        $this->assertStringStartsWith(':', $param3);
        $this->assertStringNotContainsString('-', $param3);
        $this->assertStringNotContainsString('@', $param3);
    }

    /**
     * Test addParam.
     */
    public function testAddParam(): void
    {
        $manager = new ParameterManager();

        $placeholder = $manager->addParam('name', 'John');
        $this->assertStringStartsWith(':', $placeholder);

        $params = $manager->getParams();
        $this->assertArrayHasKey($placeholder, $params);
        $this->assertEquals('John', $params[$placeholder]);
    }

    /**
     * Test setParam.
     */
    public function testSetParam(): void
    {
        $manager = new ParameterManager();

        $manager->setParam(':name', 'John');
        $params = $manager->getParams();
        $this->assertEquals('John', $params[':name']);

        // Test fluent interface
        $this->assertInstanceOf(ParameterManager::class, $manager->setParam(':age', 25));
    }

    /**
     * Test mergeSubParams.
     */
    public function testMergeSubParams(): void
    {
        $manager = new ParameterManager();

        $subParams = [
            ':user_id' => 1,
            ':status' => 'active',
        ];

        $map = $manager->mergeSubParams($subParams, 'subquery');

        $this->assertIsArray($map);
        $this->assertArrayHasKey(':user_id', $map);
        $this->assertArrayHasKey(':status', $map);

        // Verify new placeholders exist in params
        $params = $manager->getParams();
        $this->assertArrayHasKey($map[':user_id'], $params);
        $this->assertEquals(1, $params[$map[':user_id']]);
        $this->assertArrayHasKey($map[':status'], $params);
        $this->assertEquals('active', $params[$map[':status']]);
    }

    /**
     * Test replacePlaceholdersInSql.
     */
    public function testReplacePlaceholdersInSql(): void
    {
        $manager = new ParameterManager();

        $sql = 'SELECT * FROM users WHERE id = :user_id AND status = :status';
        $map = [
            ':user_id' => ':sub_user_id',
            ':status' => ':sub_status',
        ];

        $result = $manager->replacePlaceholdersInSql($sql, $map);
        $this->assertStringContainsString(':sub_user_id', $result);
        $this->assertStringContainsString(':sub_status', $result);
        $this->assertStringNotContainsString(':user_id', $result);
        $this->assertStringNotContainsString(':status', $result);

        // Test with empty map
        $result = $manager->replacePlaceholdersInSql($sql, []);
        $this->assertEquals($sql, $result);
    }

    /**
     * Test replacePlaceholdersInSql with longer placeholders first.
     */
    public function testReplacePlaceholdersInSqlOrder(): void
    {
        $manager = new ParameterManager();

        // Test that longer placeholders are replaced first to avoid conflicts
        $sql = 'SELECT :param1, :param12 FROM table';
        $map = [
            ':param1' => ':new_param1',
            ':param12' => ':new_param12',
        ];

        $result = $manager->replacePlaceholdersInSql($sql, $map);
        // Should replace :param12 before :param1 to avoid partial replacement
        $this->assertStringContainsString(':new_param12', $result);
    }

    /**
     * Test normalizeParams with positional parameters.
     */
    public function testNormalizeParamsPositional(): void
    {
        $manager = new ParameterManager();

        $params = [1, 'test', 3.14];
        $result = $manager->normalizeParams($params);

        // Positional parameters should be returned as-is
        $this->assertEquals($params, $result);
    }

    /**
     * Test normalizeParams with named parameters.
     */
    public function testNormalizeParamsNamed(): void
    {
        $manager = new ParameterManager();

        // Named parameters without : prefix
        $params = ['name' => 'John', 'age' => 25];
        $result = $manager->normalizeParams($params);

        $this->assertArrayHasKey(':name', $result);
        $this->assertEquals('John', $result[':name']);
        $this->assertArrayHasKey(':age', $result);
        $this->assertEquals(25, $result[':age']);

        // Named parameters with : prefix
        $params = [':name' => 'John', ':age' => 25];
        $result = $manager->normalizeParams($params);

        $this->assertArrayHasKey(':name', $result);
        $this->assertEquals('John', $result[':name']);
    }

    /**
     * Test normalizeParams with numeric keys.
     */
    public function testNormalizeParamsNumericKeys(): void
    {
        $manager = new ParameterManager();

        // Non-sequential numeric keys should be normalized
        $params = [2 => 'test', 5 => 'value'];
        $result = $manager->normalizeParams($params);

        $this->assertArrayHasKey(':param_2', $result);
        $this->assertEquals('test', $result[':param_2']);
        $this->assertArrayHasKey(':param_5', $result);
        $this->assertEquals('value', $result[':param_5']);
    }

    /**
     * Test setParams.
     */
    public function testSetParams(): void
    {
        $manager = new ParameterManager();

        $params = [':name' => 'John', ':age' => 25];
        $manager->setParams($params);

        $this->assertEquals($params, $manager->getParams());

        // Test fluent interface
        $this->assertInstanceOf(ParameterManager::class, $manager->setParams($params));
    }

    /**
     * Test clearParams.
     */
    public function testClearParams(): void
    {
        $manager = new ParameterManager();

        $manager->addParam('name', 'John');
        $manager->addParam('age', 25);

        $this->assertNotEmpty($manager->getParams());

        $manager->clearParams();

        $this->assertEmpty($manager->getParams());

        // Test fluent interface
        $this->assertInstanceOf(ParameterManager::class, $manager->clearParams());
    }

    /**
     * Test getParams.
     */
    public function testGetParams(): void
    {
        $manager = new ParameterManager();

        $this->assertIsArray($manager->getParams());
        $this->assertEmpty($manager->getParams());

        $manager->addParam('name', 'John');
        $params = $manager->getParams();

        $this->assertIsArray($params);
        $this->assertNotEmpty($params);
    }
}
