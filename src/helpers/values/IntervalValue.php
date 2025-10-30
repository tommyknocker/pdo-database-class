<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * Interval value for DATE_ADD / DATE_SUB operations (dialect-specific).
 */
class IntervalValue extends RawValue
{
    /** @var string|RawValue Source date/datetime expression */
    protected string|RawValue $expr;

    /** @var string Interval value (e.g., "1", "7") */
    protected string $intervalValue;

    /** @var string Interval unit (e.g., "DAY", "MONTH", "HOUR") */
    protected string $unit;

    /** @var bool Whether to add (true) or subtract (false) the interval */
    protected bool $isAdd;

    /**
     * Constructor.
     *
     * @param string|RawValue $expr Source date/datetime expression
     * @param string $value Interval value
     * @param string $unit Interval unit (DAY, MONTH, YEAR, HOUR, MINUTE, SECOND, etc.)
     * @param bool $isAdd Whether to add (true) or subtract (false) the interval
     */
    public function __construct(string|RawValue $expr, string $value, string $unit, bool $isAdd = true)
    {
        parent::__construct('');
        $this->expr = $expr;
        $this->intervalValue = $value;
        $this->unit = strtoupper($unit);
        $this->isAdd = $isAdd;
    }

    /**
     * Get source expression.
     *
     * @return string|RawValue
     */
    public function getExpr(): string|RawValue
    {
        return $this->expr;
    }

    /**
     * Get interval value.
     *
     * @return string
     */
    public function getIntervalValue(): string
    {
        return $this->intervalValue;
    }

    /**
     * Get interval unit.
     *
     * @return string
     */
    public function getUnit(): string
    {
        return $this->unit;
    }

    /**
     * Check if interval should be added.
     *
     * @return bool
     */
    public function isAdd(): bool
    {
        return $this->isAdd;
    }
}
