<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * SUBSTRING / SUBSTR value (dialect-specific).
 */
class SubstringValue extends RawValue
{
    /** @var string|RawValue Source string */
    protected string|RawValue $source;

    /** @var int Start position */
    protected int $start;

    /** @var int|null Substring length */
    protected ?int $length;

    public function __construct(string|RawValue $source, int $start, ?int $length = null)
    {
        $this->source = $source;
        $this->start = $start;
        $this->length = $length;
        parent::__construct('');
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
