<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\pagination;

use JsonSerializable;

/**
 * Pagination result with data and metadata.
 *
 * Provides full pagination information including total count and page numbers.
 * Best for traditional pagination with page numbers.
 */
class PaginationResult implements JsonSerializable
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @param int $total
     * @param int $perPage
     * @param int $currentPage
     * @param array<string, mixed> $options
     */
    public function __construct(
        protected array $items,
        protected int $total,
        protected int $perPage,
        protected int $currentPage,
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
     * Get total items count.
     */
    public function total(): int
    {
        return $this->total;
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
     * Get last page number.
     */
    public function lastPage(): int
    {
        return (int) ceil($this->total / $this->perPage);
    }

    /**
     * Get first item number on current page.
     */
    public function from(): int
    {
        return $this->total > 0 ? (($this->currentPage - 1) * $this->perPage) + 1 : 0;
    }

    /**
     * Get last item number on current page.
     */
    public function to(): int
    {
        return min($this->currentPage * $this->perPage, $this->total);
    }

    /**
     * Check if there are more pages.
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    /**
     * Check if on first page.
     */
    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    /**
     * Check if on last page.
     */
    public function onLastPage(): bool
    {
        return $this->currentPage >= $this->lastPage();
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
        if ($this->hasMorePages()) {
            return $this->url($this->currentPage + 1);
        }

        return null;
    }

    /**
     * Get URL for the previous page.
     */
    public function previousPageUrl(): ?string
    {
        if ($this->currentPage > 1) {
            return $this->url($this->currentPage - 1);
        }

        return null;
    }

    /**
     * Get array of links.
     *
     * @return array<string, string|null>
     */
    public function links(): array
    {
        return [
            'first' => $this->url(1),
            'last' => $this->url($this->lastPage()),
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
                'from' => $this->from(),
                'to' => $this->to(),
                'per_page' => $this->perPage,
                'total' => $this->total,
                'last_page' => $this->lastPage(),
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
