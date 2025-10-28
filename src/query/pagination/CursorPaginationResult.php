<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\pagination;

use JsonSerializable;

/**
 * Cursor pagination result.
 *
 * Best for very large datasets and infinite scroll.
 * More efficient than offset pagination for large offsets.
 */
class CursorPaginationResult implements JsonSerializable
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @param int $perPage
     * @param Cursor|null $previousCursor
     * @param Cursor|null $nextCursor
     * @param array<string, mixed> $options
     */
    public function __construct(
        protected array $items,
        protected int $perPage,
        protected ?Cursor $previousCursor,
        protected ?Cursor $nextCursor,
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
     * Check if there are more pages.
     */
    public function hasMorePages(): bool
    {
        return $this->nextCursor !== null;
    }

    /**
     * Check if there are previous pages.
     */
    public function hasPreviousPages(): bool
    {
        return $this->previousCursor !== null;
    }

    /**
     * Get next cursor as encoded string.
     */
    public function nextCursor(): ?string
    {
        return $this->nextCursor?->encode();
    }

    /**
     * Get previous cursor as encoded string.
     */
    public function previousCursor(): ?string
    {
        return $this->previousCursor?->encode();
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
     * Get URL for the next page.
     */
    public function nextPageUrl(): ?string
    {
        if (!$this->hasMorePages()) {
            return null;
        }

        $parameters = array_merge($this->query(), ['cursor' => $this->nextCursor()]);
        $path = $this->path();

        if ($path === '') {
            return '?cursor=' . $this->nextCursor();
        }

        return $path . '?' . http_build_query($parameters);
    }

    /**
     * Get URL for the previous page.
     */
    public function previousPageUrl(): ?string
    {
        if (!$this->hasPreviousPages()) {
            return null;
        }

        $parameters = array_merge($this->query(), ['cursor' => $this->previousCursor()]);
        $path = $this->path();

        if ($path === '') {
            return '?cursor=' . $this->previousCursor();
        }

        return $path . '?' . http_build_query($parameters);
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
                'per_page' => $this->perPage,
                'has_more' => $this->hasMorePages(),
                'has_previous' => $this->hasPreviousPages(),
            ],
            'cursor' => [
                'next' => $this->nextCursor(),
                'prev' => $this->previousCursor(),
            ],
            'links' => [
                'next' => $this->nextPageUrl(),
                'prev' => $this->previousPageUrl(),
            ],
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
