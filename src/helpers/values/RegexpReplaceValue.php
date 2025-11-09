<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * REGEXP replace value for pattern-based replacement (dialect-specific).
 */
class RegexpReplaceValue extends RawValue
{
    /** @var string|RawValue Source string */
    protected string|RawValue $sourceValue;

    /** @var string Regex pattern */
    protected string $pattern;

    /** @var string Replacement string */
    protected string $replacement;

    /**
     * Constructor.
     *
     * @param string|RawValue $value Source string
     * @param string $pattern Regex pattern
     * @param string $replacement Replacement string
     */
    public function __construct(string|RawValue $value, string $pattern, string $replacement)
    {
        parent::__construct('');
        $this->sourceValue = $value;
        $this->pattern = $pattern;
        $this->replacement = $replacement;
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
     * Get replacement string.
     *
     * @return string
     */
    public function getReplacement(): string
    {
        return $this->replacement;
    }

    /**
     * Override getValue() to return a basic expression.
     * The actual dialect-specific formatting is done by RawValueResolver.
     *
     * @return string
     */
    public function getValue(): string
    {
        // Return a placeholder expression that will be replaced by RawValueResolver
        $val = $this->sourceValue instanceof RawValue ? $this->sourceValue->getValue() : $this->sourceValue;
        $pat = str_replace("'", "''", $this->pattern);
        $rep = str_replace("'", "''", $this->replacement);
        return "REGEXP_REPLACE($val, '$pat', '$rep')";
    }
}
