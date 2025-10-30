<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * LPAD/RPAD value for padding strings (dialect-specific).
 */
class PadValue extends RawValue
{
    /** @var string|RawValue Source string */
    protected string|RawValue $sourceValue;

    /** @var int Target length */
    protected int $length;

    /** @var string Padding string */
    protected string $padString;

    /** @var bool Whether to pad on the left (true) or right (false) */
    protected bool $isLeft;

    /**
     * Constructor.
     *
     * @param string|RawValue $value Source string
     * @param int $length Target length
     * @param string $padString Padding string
     * @param bool $isLeft Whether to pad on the left
     */
    public function __construct(string|RawValue $value, int $length, string $padString, bool $isLeft)
    {
        parent::__construct('');
        $this->sourceValue = $value;
        $this->length = $length;
        $this->padString = $padString;
        $this->isLeft = $isLeft;
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
     * Get target length.
     *
     * @return int
     */
    public function getLength(): int
    {
        return $this->length;
    }

    /**
     * Get padding string.
     *
     * @return string
     */
    public function getPadString(): string
    {
        return $this->padString;
    }

    /**
     * Check if padding is on the left.
     *
     * @return bool
     */
    public function isLeft(): bool
    {
        return $this->isLeft;
    }
}
