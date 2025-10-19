<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * SUBSTRING / SUBSTR value (dialect-specific)
 */
class SubstringValue extends RawValue
{
    protected string|RawValue $source;
    protected int $start;
    protected ?int $length;

    public function __construct(string|RawValue $source, int $start, ?int $length = null)
    {
        $this->source = $source;
        $this->start = $start;
        $this->length = $length;
    }

    public function getSource(): string|RawValue
    {
        return $this->source;
    }

    public function getStart(): int
    {
        return $this->start;
    }

    public function getLength(): ?int
    {
        return $this->length;
    }
}
