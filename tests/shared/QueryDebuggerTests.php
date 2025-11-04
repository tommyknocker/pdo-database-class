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
}
