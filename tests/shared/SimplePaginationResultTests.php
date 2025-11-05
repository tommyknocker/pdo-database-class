<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\pagination\SimplePaginationResult;

/**
 * Tests for SimplePaginationResult class.
 */
final class SimplePaginationResultTests extends BaseSharedTestCase
{
    public function testSimplePaginationResultBasic(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $result = new SimplePaginationResult($items, 10, 1, true);
        $this->assertEquals($items, $result->items());
        $this->assertEquals(10, $result->perPage());
        $this->assertEquals(1, $result->currentPage());
        $this->assertTrue($result->hasMorePages());
    }

    public function testSimplePaginationResultHasMorePages(): void
    {
        $result = new SimplePaginationResult([], 10, 1, true);
        $this->assertTrue($result->hasMorePages());
    }

    public function testSimplePaginationResultNoMorePages(): void
    {
        $result = new SimplePaginationResult([], 10, 1, false);
        $this->assertFalse($result->hasMorePages());
    }

    public function testSimplePaginationResultOnFirstPage(): void
    {
        $result = new SimplePaginationResult([], 10, 1, true);
        $this->assertTrue($result->onFirstPage());
    }

    public function testSimplePaginationResultNotOnFirstPage(): void
    {
        $result = new SimplePaginationResult([], 10, 2, true);
        $this->assertFalse($result->onFirstPage());
    }

    public function testSimplePaginationResultUrl(): void
    {
        $result = new SimplePaginationResult([], 10, 1, true);
        $url = $result->url(2);
        $this->assertEquals('?page=2', $url);
    }

    public function testSimplePaginationResultUrlWithPath(): void
    {
        $result = new SimplePaginationResult([], 10, 1, true, ['path' => '/users']);
        $url = $result->url(2);
        $this->assertStringContainsString('/users', $url);
        $this->assertStringContainsString('page=2', $url);
    }

    public function testSimplePaginationResultUrlWithQuery(): void
    {
        $result = new SimplePaginationResult([], 10, 1, true, ['query' => ['filter' => 'active'], 'path' => '/users']);
        $url = $result->url(2);
        $this->assertStringContainsString('page=2', $url);
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);
        $this->assertArrayHasKey('filter', $params);
        $this->assertEquals('active', $params['filter']);
    }

    public function testSimplePaginationResultUrlZeroPage(): void
    {
        $result = new SimplePaginationResult([], 10, 1, true);
        $url = $result->url(0);
        $this->assertStringContainsString('page=1', $url);
    }

    public function testSimplePaginationResultNextPageUrl(): void
    {
        $result = new SimplePaginationResult([], 10, 1, true);
        $url = $result->nextPageUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString('page=2', $url);
    }

    public function testSimplePaginationResultNextPageUrlNull(): void
    {
        $result = new SimplePaginationResult([], 10, 1, false);
        $this->assertNull($result->nextPageUrl());
    }

    public function testSimplePaginationResultPreviousPageUrl(): void
    {
        $result = new SimplePaginationResult([], 10, 2, true);
        $url = $result->previousPageUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString('page=1', $url);
    }

    public function testSimplePaginationResultPreviousPageUrlNull(): void
    {
        $result = new SimplePaginationResult([], 10, 1, true);
        $this->assertNull($result->previousPageUrl());
    }

    public function testSimplePaginationResultLinks(): void
    {
        $result = new SimplePaginationResult([], 10, 2, true);
        $links = $result->links();
        $this->assertArrayHasKey('prev', $links);
        $this->assertArrayHasKey('next', $links);
        $this->assertNotNull($links['prev']);
        $this->assertNotNull($links['next']);
    }

    public function testSimplePaginationResultJsonSerialize(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $result = new SimplePaginationResult($items, 10, 1, true);
        $json = $result->jsonSerialize();
        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('meta', $json);
        $this->assertArrayHasKey('links', $json);
        $this->assertEquals($items, $json['data']);
        $this->assertTrue($json['meta']['has_more']);
    }

    public function testSimplePaginationResultToArray(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $result = new SimplePaginationResult($items, 10, 1, true);
        $array = $result->toArray();
        $this->assertArrayHasKey('data', $array);
        $this->assertArrayHasKey('meta', $array);
        $this->assertArrayHasKey('links', $array);
    }
}
