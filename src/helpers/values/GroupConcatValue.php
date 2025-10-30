<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * GROUP_CONCAT / STRING_AGG value for aggregating values into strings (dialect-specific).
 */
class GroupConcatValue extends RawValue
{
    /** @var string|RawValue Column or expression to concatenate */
    protected string|RawValue $column;

    /** @var string Separator (default: ',') */
    protected string $separator;

    /** @var bool Whether to use DISTINCT */
    protected bool $distinct;

    /**
     * Constructor.
     *
     * @param string|RawValue $column Column or expression to concatenate
     * @param string $separator Separator string (default: ',')
     * @param bool $distinct Whether to use DISTINCT
     */
    public function __construct(string|RawValue $column, string $separator = ',', bool $distinct = false)
    {
        parent::__construct('');
        $this->column = $column;
        $this->separator = $separator;
        $this->distinct = $distinct;
    }

    /**
     * Get column or expression.
     *
     * @return string|RawValue
     */
    public function getColumn(): string|RawValue
    {
        return $this->column;
    }

    /**
     * Get separator.
     *
     * @return string
     */
    public function getSeparator(): string
    {
        return $this->separator;
    }

    /**
     * Check if DISTINCT is enabled.
     *
     * @return bool
     */
    public function isDistinct(): bool
    {
        return $this->distinct;
    }
}
