<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\pagination\Cursor;
use tommyknocker\pdodb\query\pagination\CursorPaginationResult;

/**
 * Tests for CursorPaginationResult class.
 */
final class CursorPaginationResultTests extends BaseSharedTestCase
{
    public function testCursorPaginationResultBasic(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $nextCursor = new Cursor(['id' => 2], true);
        $result = new CursorPaginationResult($items, 10, null, $nextCursor);
        $this->assertEquals($items, $result->items());
        $this->assertEquals(10, $result->perPage());
        $this->assertTrue($result->hasMorePages());
        $this->assertFalse($result->hasPreviousPages());
    }

    public function testCursorPaginationResultWithPreviousCursor(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $prevCursor = new Cursor(['id' => 0], false);
        $nextCursor = new Cursor(['id' => 2], true);
        $result = new CursorPaginationResult($items, 10, $prevCursor, $nextCursor);
        $this->assertTrue($result->hasMorePages());
        $this->assertTrue($result->hasPreviousPages());
    }

    public function testCursorPaginationResultNoMorePages(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $result = new CursorPaginationResult($items, 10, null, null);
        $this->assertFalse($result->hasMorePages());
        $this->assertFalse($result->hasPreviousPages());
    }

    public function testCursorPaginationResultNextCursor(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $nextCursor = new Cursor(['id' => 2], true);
        $result = new CursorPaginationResult($items, 10, null, $nextCursor);
        $encoded = $result->nextCursor();
        $this->assertNotNull($encoded);
        $decoded = Cursor::decode($encoded);
        $this->assertNotNull($decoded);
        $this->assertEquals(['id' => 2], $decoded->parameters());
    }

    public function testCursorPaginationResultNextCursorNull(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $result = new CursorPaginationResult($items, 10, null, null);
        $this->assertNull($result->nextCursor());
    }

    public function testCursorPaginationResultPreviousCursor(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $prevCursor = new Cursor(['id' => 0], false);
        $result = new CursorPaginationResult($items, 10, $prevCursor, null);
        $encoded = $result->previousCursor();
        $this->assertNotNull($encoded);
        $decoded = Cursor::decode($encoded);
        $this->assertNotNull($decoded);
        $this->assertEquals(['id' => 0], $decoded->parameters());
    }

    public function testCursorPaginationResultPreviousCursorNull(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $result = new CursorPaginationResult($items, 10, null, null);
        $this->assertNull($result->previousCursor());
    }

    public function testCursorPaginationResultNextPageUrl(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $nextCursor = new Cursor(['id' => 2], true);
        $result = new CursorPaginationResult($items, 10, null, $nextCursor);
        $url = $result->nextPageUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString('cursor=', $url);
    }

    public function testCursorPaginationResultNextPageUrlNull(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $result = new CursorPaginationResult($items, 10, null, null);
        $this->assertNull($result->nextPageUrl());
    }

    public function testCursorPaginationResultNextPageUrlWithPath(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $nextCursor = new Cursor(['id' => 2], true);
        $result = new CursorPaginationResult($items, 10, null, $nextCursor, ['path' => '/users']);
        $url = $result->nextPageUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString('/users', $url);
        $this->assertStringContainsString('cursor=', $url);
    }

    public function testCursorPaginationResultNextPageUrlWithQuery(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $nextCursor = new Cursor(['id' => 2], true);
        $result = new CursorPaginationResult($items, 10, null, $nextCursor, ['query' => ['filter' => 'active'], 'path' => '/users']);
        $url = $result->nextPageUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString('cursor=', $url);
        $this->assertStringContainsString('/users', $url);
        // Parse query string to verify filter parameter is included
        $queryString = parse_url($url, PHP_URL_QUERY);
        $this->assertNotNull($queryString);
        parse_str($queryString, $params);
        $this->assertArrayHasKey('filter', $params);
        $this->assertEquals('active', $params['filter']);
        $this->assertArrayHasKey('cursor', $params);
    }

    public function testCursorPaginationResultPreviousPageUrl(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $prevCursor = new Cursor(['id' => 0], false);
        $result = new CursorPaginationResult($items, 10, $prevCursor, null);
        $url = $result->previousPageUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString('cursor=', $url);
    }

    public function testCursorPaginationResultPreviousPageUrlNull(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $result = new CursorPaginationResult($items, 10, null, null);
        $this->assertNull($result->previousPageUrl());
    }

    public function testCursorPaginationResultJsonSerialize(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $nextCursor = new Cursor(['id' => 2], true);
        $result = new CursorPaginationResult($items, 10, null, $nextCursor);
        $json = $result->jsonSerialize();
        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('meta', $json);
        $this->assertArrayHasKey('cursor', $json);
        $this->assertArrayHasKey('links', $json);
        $this->assertEquals($items, $json['data']);
        $this->assertTrue($json['meta']['has_more']);
    }

    public function testCursorPaginationResultToArray(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $nextCursor = new Cursor(['id' => 2], true);
        $result = new CursorPaginationResult($items, 10, null, $nextCursor);
        $array = $result->toArray();
        $this->assertArrayHasKey('data', $array);
        $this->assertArrayHasKey('meta', $array);
        $this->assertArrayHasKey('cursor', $array);
        $this->assertArrayHasKey('links', $array);
    }
}
