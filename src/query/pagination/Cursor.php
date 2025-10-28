<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\pagination;

use InvalidArgumentException;

/**
 * Cursor for cursor-based pagination.
 *
 * Encodes position parameters for efficient pagination of large datasets.
 */
class Cursor
{
    /**
     * @param array<string, mixed> $parameters
     * @param bool $pointsToNextItems
     */
    public function __construct(
        protected array $parameters,
        protected bool $pointsToNextItems = true
    ) {
    }

    /**
     * Get cursor parameters.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * Check if cursor points to next items.
     */
    public function pointsToNextItems(): bool
    {
        return $this->pointsToNextItems;
    }

    /**
     * Check if cursor points to previous items.
     */
    public function pointsToPreviousItems(): bool
    {
        return !$this->pointsToNextItems;
    }

    /**
     * Encode cursor to string.
     */
    public function encode(): string
    {
        $json = json_encode([
            'params' => $this->parameters,
            'direction' => $this->pointsToNextItems ? 'next' : 'prev',
        ]);

        if ($json === false) {
            throw new InvalidArgumentException('Failed to encode cursor');
        }

        return base64_encode($json);
    }

    /**
     * Decode cursor from string.
     */
    public static function decode(?string $encoded): ?self
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid cursor format');
        }

        $data = json_decode($decoded, true);
        if (!is_array($data) || !isset($data['params'], $data['direction'])) {
            throw new InvalidArgumentException('Invalid cursor data');
        }

        return new self(
            $data['params'],
            $data['direction'] === 'next'
        );
    }

    /**
     * Create cursor from last item.
     *
     * @param array<string, mixed> $item
     * @param array<int, string> $columns
     */
    public static function fromItem(array $item, array $columns): self
    {
        $parameters = [];
        foreach ($columns as $column) {
            if (!isset($item[$column])) {
                throw new InvalidArgumentException("Column {$column} not found in item");
            }
            $parameters[$column] = $item[$column];
        }

        return new self($parameters);
    }
}
