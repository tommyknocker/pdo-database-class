<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

/**
 * Row counts grouped by outbox status.
 */
final class OutboxStatusCounts
{
    public function __construct(
        public readonly int $pending,
        public readonly int $processing,
        public readonly int $published,
        public readonly int $failed,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function toMap(): array
    {
        return [
            OutboxStatuses::PENDING => $this->pending,
            OutboxStatuses::PROCESSING => $this->processing,
            OutboxStatuses::PUBLISHED => $this->published,
            OutboxStatuses::FAILED => $this->failed,
        ];
    }

    public function total(): int
    {
        return $this->pending + $this->processing + $this->published + $this->failed;
    }
}
