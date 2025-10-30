<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * TRUNCATE / TRUNC value for truncating numbers (dialect-specific).
 */
class TruncValue extends RawValue
{
    /** @var string|RawValue Value to truncate */
    protected string|RawValue $truncateValue;

    /** @var int Precision (number of decimal places) */
    protected int $precision;

    /**
     * Constructor.
     *
     * @param string|RawValue $value Value to truncate
     * @param int $precision Precision (number of decimal places)
     */
    public function __construct(string|RawValue $value, int $precision = 0)
    {
        parent::__construct('');
        $this->truncateValue = $value;
        $this->precision = $precision;
    }

    /**
     * Get value to truncate.
     *
     * @return string|RawValue
     */
    public function getTruncateValue(): string|RawValue
    {
        return $this->truncateValue;
    }

    /**
     * Get precision.
     *
     * @return int
     */
    public function getPrecision(): int
    {
        return $this->precision;
    }
}
