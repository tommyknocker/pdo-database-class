<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm;

use tommyknocker\pdodb\query\pagination\PaginationResult;

/**
 * A page of models with pagination metadata.
 *
 * @template TModel of Model
 */
final class ModelPage
{
    /** @param array<int, TModel> $items */
    public function __construct(private array $items, private PaginationResult $pagination)
    {
    }

    /** @return array<int, TModel> */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->pagination->total();
    }

    public function perPage(): int
    {
        return $this->pagination->perPage();
    }

    public function currentPage(): int
    {
        return $this->pagination->currentPage();
    }

    public function lastPage(): int
    {
        return $this->pagination->lastPage();
    }

    public function hasMorePages(): bool
    {
        return $this->pagination->hasMorePages();
    }

    /** @return array<string, string|null> */
    public function links(): array
    {
        return $this->pagination->links();
    }
}
