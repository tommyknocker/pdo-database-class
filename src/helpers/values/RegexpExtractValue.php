<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * REGEXP extract value for extracting matched substrings (dialect-specific).
 */
class RegexpExtractValue extends RawValue
{
    /** @var string|RawValue Source string */
    protected string|RawValue $sourceValue;

    /** @var string Regex pattern */
    protected string $pattern;

    /** @var int|null Capture group index (0 = full match, 1+ = specific group) */
    protected ?int $groupIndex;

    /**
     * Constructor.
     *
     * @param string|RawValue $value Source string
     * @param string $pattern Regex pattern
     * @param int|null $groupIndex Capture group index (0 = full match, 1+ = specific group)
     */
    public function __construct(string|RawValue $value, string $pattern, ?int $groupIndex = null)
    {
        parent::__construct('');
        $this->sourceValue = $value;
        $this->pattern = $pattern;
        $this->groupIndex = $groupIndex;
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
     * Get capture group index.
     *
     * @return int|null
     */
    public function getGroupIndex(): ?int
    {
        return $this->groupIndex;
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
        return "REGEXP_SUBSTR($val, '$pat')";
    }
}
