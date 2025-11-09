<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * REGEXP match value for pattern matching (dialect-specific).
 */
class RegexpMatchValue extends RawValue
{
    /** @var string|RawValue Source string */
    protected string|RawValue $sourceValue;

    /** @var string Regex pattern */
    protected string $pattern;

    /**
     * Constructor.
     *
     * @param string|RawValue $value Source string
     * @param string $pattern Regex pattern
     */
    public function __construct(string|RawValue $value, string $pattern)
    {
        parent::__construct('');
        $this->sourceValue = $value;
        $this->pattern = $pattern;
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
     * Get regex pattern.
     *
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * Override getValue() to return a basic expression for use with Db::not().
     * The actual dialect-specific formatting is done by RawValueResolver.
     *
     * @return string
     */
    public function getValue(): string
    {
        // Return a placeholder expression that will be replaced by RawValueResolver
        // This allows Db::not() to work correctly
        $val = $this->sourceValue instanceof RawValue ? $this->sourceValue->getValue() : $this->sourceValue;
        $pat = str_replace("'", "''", $this->pattern);
        return "($val REGEXP '$pat')";
    }
}
