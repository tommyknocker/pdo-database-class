<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\pagination;

use JsonSerializable;

/**
 * Simple pagination result without total count.
 *
 * Performs only one query (no COUNT), making it faster than full pagination.
 * Best for infinite scroll or when total count is not needed.
 */
class SimplePaginationResult implements JsonSerializable
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @param int $perPage
     * @param int $currentPage
     * @param bool $hasMore
     * @param array<string, mixed> $options
     */
    public function __construct(
        protected array $items,
        protected int $perPage,
        protected int $currentPage,
        protected bool $hasMore,
        protected array $options = []
    ) {
    }

    /**
     * Get all items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Get per page count.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Get current page number.
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Check if there are more pages.
     */
    public function hasMorePages(): bool
    {
        return $this->hasMore;
    }

    /**
     * Check if on first page.
     */
    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    /**
     * Get URL path.
     */
    protected function path(): string
    {
        return $this->options['path'] ?? '';
    }

    /**
     * Get query string parameters.
     *
     * @return array<string, mixed>
     */
    protected function query(): array
    {
        return $this->options['query'] ?? [];
    }

    /**
     * Get URL for a given page.
     */
    public function url(int $page): string
    {
        if ($page <= 0) {
            $page = 1;
        }

        $parameters = array_merge($this->query(), ['page' => $page]);
        $path = $this->path();

        if ($path === '') {
            return '?page=' . $page;
        }

        return $path . '?' . http_build_query($parameters);
    }

    /**
     * Get URL for the next page.
     */
    public function nextPageUrl(): ?string
    {
        return $this->hasMore ? $this->url($this->currentPage + 1) : null;
    }

    /**
     * Get URL for the previous page.
     */
    public function previousPageUrl(): ?string
    {
        return $this->currentPage > 1 ? $this->url($this->currentPage - 1) : null;
    }

    /**
     * Get array of links.
     *
     * @return array<string, string|null>
     */
    public function links(): array
    {
        return [
            'prev' => $this->previousPageUrl(),
            'next' => $this->nextPageUrl(),
        ];
    }

    /**
     * Get JSON serializable array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'data' => $this->items,
            'meta' => [
                'current_page' => $this->currentPage,
                'per_page' => $this->perPage,
                'has_more' => $this->hasMore,
            ],
            'links' => $this->links(),
        ];
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
