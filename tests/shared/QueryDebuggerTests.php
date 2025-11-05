<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\debug\QueryDebugger;

/**
 * Tests for QueryDebugger helper class.
 */
final class QueryDebuggerTests extends BaseSharedTestCase
{
    public function testSanitizeParamsMasksSensitiveKeys(): void
    {
        $params = [
            'id' => 1,
            'name' => 'Alice',
            'password' => 'secret123',
            'token' => 'abc123',
            'secret' => 'mysecret',
            'api_key' => 'key123',
        ];

        $sanitized = QueryDebugger::sanitizeParams($params, ['password', 'token', 'secret', 'api_key']);

        $this->assertEquals(1, $sanitized['id']);
        $this->assertEquals('Alice', $sanitized['name']);
        $this->assertEquals('***', $sanitized['password']);
        $this->assertEquals('***', $sanitized['token']);
        $this->assertEquals('***', $sanitized['secret']);
        $this->assertEquals('***', $sanitized['api_key']);
    }

    public function testSanitizeParamsTruncatesLongStrings(): void
    {
        $longString = str_repeat('a', 200);
        $params = ['description' => $longString];

        $sanitized = QueryDebugger::sanitizeParams($params, [], 100);

        $this->assertStringStartsWith('aaa', $sanitized['description']);
        $this->assertStringEndsWith('...', $sanitized['description']);
        $this->assertEquals(103, strlen($sanitized['description']));
    }

    public function testSanitizeParamsHandlesArrays(): void
    {
        $params = ['tags' => ['php', 'mysql', 'database']];

        $sanitized = QueryDebugger::sanitizeParams($params);

        $this->assertEquals('[array(3)]', $sanitized['tags']);
    }

    public function testSanitizeParamsHandlesObjects(): void
    {
        $obj = new \stdClass();
        $params = ['user' => $obj];

        $sanitized = QueryDebugger::sanitizeParams($params);

        $this->assertStringContainsString('stdClass', $sanitized['user']);
    }

    public function testFormatContextWithTable(): void
    {
        $debugInfo = [
            'table' => 'users',
            'operation' => 'UPDATE',
        ];

        $formatted = QueryDebugger::formatContext($debugInfo);

        $this->assertStringContainsString('Table: users', $formatted);
        $this->assertStringContainsString('Operation: UPDATE', $formatted);
    }

    public function testFormatContextWithWhere(): void
    {
        $debugInfo = [
            'table' => 'users',
            'where' => ['where_count' => 2],
        ];

        $formatted = QueryDebugger::formatContext($debugInfo);

        $this->assertStringContainsString('Has WHERE conditions', $formatted);
    }

    public function testFormatContextWithJoins(): void
    {
        $debugInfo = [
            'table' => 'users',
            'joins' => ['join_count' => 2, 'joins' => []],
        ];

        $formatted = QueryDebugger::formatContext($debugInfo);

        $this->assertStringContainsString('Has JOINs: 2', $formatted);
    }

    public function testFormatContextWithParams(): void
    {
        $debugInfo = [
            'table' => 'users',
            'params' => ['id' => 1, 'name' => 'Alice'],
        ];

        $formatted = QueryDebugger::formatContext($debugInfo);

        $this->assertStringContainsString('Parameters:', $formatted);
        $this->assertStringContainsString('"id":1', $formatted);
        $this->assertStringContainsString('"name":"Alice"', $formatted);
    }

    public function testFormatContextWithEmptyDebugInfo(): void
    {
        $formatted = QueryDebugger::formatContext([]);

        $this->assertEquals('', $formatted);
    }

    public function testExtractOperationFromSelect(): void
    {
        $operation = QueryDebugger::extractOperation('SELECT * FROM users');
        $this->assertEquals('SELECT', $operation);
    }

    public function testExtractOperationFromInsert(): void
    {
        $operation = QueryDebugger::extractOperation('INSERT INTO users (name) VALUES (?)');
        $this->assertEquals('INSERT', $operation);
    }

    public function testExtractOperationFromUpdate(): void
    {
        $operation = QueryDebugger::extractOperation('UPDATE users SET name = ?');
        $this->assertEquals('UPDATE', $operation);
    }

    public function testExtractOperationFromDelete(): void
    {
        $operation = QueryDebugger::extractOperation('DELETE FROM users');
        $this->assertEquals('DELETE', $operation);
    }

    public function testExtractOperationFromNull(): void
    {
        $operation = QueryDebugger::extractOperation(null);
        $this->assertEquals('UNKNOWN', $operation);
    }

    public function testExtractOperationFromUnknown(): void
    {
        $operation = QueryDebugger::extractOperation('ALTER TABLE users');
        $this->assertEquals('UNKNOWN', $operation);
    }

    public function testExtractOperationFromReplace(): void
    {
        $operation = QueryDebugger::extractOperation('REPLACE INTO users (name) VALUES (?)');
        $this->assertEquals('REPLACE', $operation);
    }

    public function testExtractOperationFromMerge(): void
    {
        $operation = QueryDebugger::extractOperation('MERGE INTO users');
        $this->assertEquals('MERGE', $operation);
    }

    public function testExtractOperationFromEmptyString(): void
    {
        $operation = QueryDebugger::extractOperation('');
        $this->assertEquals('UNKNOWN', $operation);
    }

    public function testSanitizeParamsHandlesResources(): void
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            $this->markTestSkipped('Could not create resource');
        }
        $params = ['file' => $resource];
        $sanitized = QueryDebugger::sanitizeParams($params);
        $this->assertEquals('[resource]', $sanitized['file']);
        fclose($resource);
    }

    public function testSanitizeParamsHandlesNullValues(): void
    {
        $params = ['name' => null, 'email' => 'test@example.com'];
        $sanitized = QueryDebugger::sanitizeParams($params);
        $this->assertNull($sanitized['name']);
        $this->assertEquals('test@example.com', $sanitized['email']);
    }

    public function testSanitizeParamsHandlesBooleanValues(): void
    {
        $params = ['active' => true, 'deleted' => false];
        $sanitized = QueryDebugger::sanitizeParams($params);
        $this->assertTrue($sanitized['active']);
        $this->assertFalse($sanitized['deleted']);
    }

    public function testSanitizeParamsHandlesNumericValues(): void
    {
        $params = ['id' => 123, 'price' => 45.67, 'count' => 0];
        $sanitized = QueryDebugger::sanitizeParams($params);
        $this->assertEquals(123, $sanitized['id']);
        $this->assertEquals(45.67, $sanitized['price']);
        $this->assertEquals(0, $sanitized['count']);
    }

    public function testSanitizeParamsWithParameterPrefixes(): void
    {
        $params = [':password' => 'secret', '?password' => 'secret2'];
        $sanitized = QueryDebugger::sanitizeParams($params, ['password']);
        $this->assertEquals('***', $sanitized[':password']);
        $this->assertEquals('***', $sanitized['?password']);
    }

    public function testFormatContextWithSelectOperation(): void
    {
        $debugInfo = [
            'table' => 'users',
            'operation' => 'SELECT',
        ];
        $formatted = QueryDebugger::formatContext($debugInfo);
        $this->assertStringContainsString('Table: users', $formatted);
        $this->assertStringNotContainsString('Operation: SELECT', $formatted);
    }

    public function testFormatContextWithSensitiveParams(): void
    {
        $debugInfo = [
            'table' => 'users',
            'params' => ['id' => 1, 'password' => 'secret123', 'token' => 'abc'],
        ];
        $formatted = QueryDebugger::formatContext($debugInfo);
        $this->assertStringContainsString('Parameters:', $formatted);
        $this->assertStringNotContainsString('secret123', $formatted);
        $this->assertStringNotContainsString('abc', $formatted);
    }
}
