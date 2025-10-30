<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * REPEAT value for repeating strings (dialect-specific).
 */
class RepeatValue extends RawValue
{
    /** @var string|RawValue String to repeat */
    protected string|RawValue $sourceValue;

    /** @var int Number of times to repeat */
    protected int $count;

    /**
     * Constructor.
     *
     * @param string|RawValue $value String to repeat
     * @param int $count Number of times to repeat
     */
    public function __construct(string|RawValue $value, int $count)
    {
        parent::__construct('');
        $this->sourceValue = $value;
        $this->count = $count;
    }

    /**
     * Get source string.
     *
     * @return string|RawValue
     */
    public function getSourceValue(): string|RawValue
    {
        return $this->sourceValue;
    }

    /**
     * Get count.
     *
     * @return int
     */
    public function getCount(): int
    {
        return $this->count;
    }
}
