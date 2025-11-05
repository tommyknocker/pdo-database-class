<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\pagination\PaginationResult;

/**
 * Tests for PaginationResult class.
 */
final class PaginationResultTests extends BaseSharedTestCase
{
    public function testPaginationResultBasic(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $result = new PaginationResult($items, 100, 10, 1);
        $this->assertEquals($items, $result->items());
        $this->assertEquals(100, $result->total());
        $this->assertEquals(10, $result->perPage());
        $this->assertEquals(1, $result->currentPage());
    }

    public function testPaginationResultLastPage(): void
    {
        $result = new PaginationResult([], 100, 10, 1);
        $this->assertEquals(10, $result->lastPage());
    }

    public function testPaginationResultFrom(): void
    {
        $result = new PaginationResult([], 100, 10, 2);
        $this->assertEquals(11, $result->from());
    }

    public function testPaginationResultFromZeroTotal(): void
    {
        $result = new PaginationResult([], 0, 10, 1);
        $this->assertEquals(0, $result->from());
    }

    public function testPaginationResultTo(): void
    {
        $result = new PaginationResult([], 100, 10, 2);
        $this->assertEquals(20, $result->to());
    }

    public function testPaginationResultToLastPage(): void
    {
        $result = new PaginationResult([], 95, 10, 10);
        $this->assertEquals(95, $result->to());
    }

    public function testPaginationResultHasMorePages(): void
    {
        $result = new PaginationResult([], 100, 10, 1);
        $this->assertTrue($result->hasMorePages());
    }

    public function testPaginationResultNoMorePages(): void
    {
        $result = new PaginationResult([], 100, 10, 10);
        $this->assertFalse($result->hasMorePages());
    }

    public function testPaginationResultOnFirstPage(): void
    {
        $result = new PaginationResult([], 100, 10, 1);
        $this->assertTrue($result->onFirstPage());
    }

    public function testPaginationResultNotOnFirstPage(): void
    {
        $result = new PaginationResult([], 100, 10, 2);
        $this->assertFalse($result->onFirstPage());
    }

    public function testPaginationResultOnLastPage(): void
    {
        $result = new PaginationResult([], 100, 10, 10);
        $this->assertTrue($result->onLastPage());
    }

    public function testPaginationResultNotOnLastPage(): void
    {
        $result = new PaginationResult([], 100, 10, 5);
        $this->assertFalse($result->onLastPage());
    }

    public function testPaginationResultUrl(): void
    {
        $result = new PaginationResult([], 100, 10, 1);
        $url = $result->url(2);
        $this->assertEquals('?page=2', $url);
    }

    public function testPaginationResultUrlWithPath(): void
    {
        $result = new PaginationResult([], 100, 10, 1, ['path' => '/users']);
        $url = $result->url(2);
        $this->assertStringContainsString('/users', $url);
        $this->assertStringContainsString('page=2', $url);
    }

    public function testPaginationResultUrlWithQuery(): void
    {
        $result = new PaginationResult([], 100, 10, 1, ['query' => ['filter' => 'active'], 'path' => '/users']);
        $url = $result->url(2);
        $this->assertStringContainsString('page=2', $url);
        // Query parameters are merged with page, so filter should be present
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);
        $this->assertArrayHasKey('filter', $params);
        $this->assertEquals('active', $params['filter']);
    }

    public function testPaginationResultUrlZeroPage(): void
    {
        $result = new PaginationResult([], 100, 10, 1);
        $url = $result->url(0);
        $this->assertStringContainsString('page=1', $url);
    }

    public function testPaginationResultNextPageUrl(): void
    {
        $result = new PaginationResult([], 100, 10, 1);
        $url = $result->nextPageUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString('page=2', $url);
    }

    public function testPaginationResultNextPageUrlNull(): void
    {
        $result = new PaginationResult([], 100, 10, 10);
        $this->assertNull($result->nextPageUrl());
    }

    public function testPaginationResultPreviousPageUrl(): void
    {
        $result = new PaginationResult([], 100, 10, 2);
        $url = $result->previousPageUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString('page=1', $url);
    }

    public function testPaginationResultPreviousPageUrlNull(): void
    {
        $result = new PaginationResult([], 100, 10, 1);
        $this->assertNull($result->previousPageUrl());
    }

    public function testPaginationResultLinks(): void
    {
        $result = new PaginationResult([], 100, 10, 5);
        $links = $result->links();
        $this->assertArrayHasKey('first', $links);
        $this->assertArrayHasKey('last', $links);
        $this->assertArrayHasKey('prev', $links);
        $this->assertArrayHasKey('next', $links);
        $this->assertStringContainsString('page=1', $links['first']);
        $this->assertStringContainsString('page=10', $links['last']);
    }

    public function testPaginationResultJsonSerialize(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $result = new PaginationResult($items, 100, 10, 1);
        $json = $result->jsonSerialize();
        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('meta', $json);
        $this->assertArrayHasKey('links', $json);
        $this->assertEquals($items, $json['data']);
        $this->assertEquals(100, $json['meta']['total']);
    }

    public function testPaginationResultToArray(): void
    {
        $items = [['id' => 1, 'name' => 'Test']];
        $result = new PaginationResult($items, 100, 10, 1);
        $array = $result->toArray();
        $this->assertArrayHasKey('data', $array);
        $this->assertArrayHasKey('meta', $array);
        $this->assertArrayHasKey('links', $array);
    }
}
