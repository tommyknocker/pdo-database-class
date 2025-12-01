<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * REGEXP_LIKE boolean expression for use in CASE statements (dialect-specific).
 * Returns a boolean expression that can be used directly in CASE WHEN conditions.
 */
class RegexpLikeValue extends RawValue
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
     * Override getValue() to return a basic expression.
     * The actual dialect-specific formatting is done by RawValueResolver.
     * For use in Db::case() keys, we need to return a usable SQL expression.
     * This will be properly resolved by RawValueResolver when used in queries.
     *
     * @return string
     */
    public function getValue(): string
    {
        // Return a placeholder expression that will be replaced by RawValueResolver
        // For nested RawValue (like ToCharValue), we need to resolve it recursively
        // Note: This is used in Db::case() keys, which are resolved later by RawValueResolver
        // when the CASE expression is used in SELECT. The placeholder will be replaced
        // with dialect-specific SQL (e.g., REGEXP_LIKE for Oracle, REGEXP for MySQL).
        // However, since Db::case() uses getValue() directly, we need to return valid SQL
        // that can be used in CASE WHEN conditions. The SQL will be properly resolved
        // when the CASE expression is used in SELECT through RawValueResolver.
        if ($this->sourceValue instanceof RawValue) {
            // For ToCharValue and similar, getValue() returns the SQL expression
            // But ToCharValue::getValue() returns empty string, so we need to handle it differently
            // We'll return a placeholder that will be resolved by RawValueResolver
            if ($this->sourceValue instanceof \tommyknocker\pdodb\helpers\values\ToCharValue) {
                $sourceVal = $this->sourceValue->getSourceValue();
                // For column names, quote them; for RawValue, use getValue()
                if ($sourceVal instanceof RawValue) {
                    $val = $sourceVal->getValue();
                } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$sourceVal)) {
                    // Simple column name - quote it for Oracle (uppercase)
                    $val = '"' . strtoupper((string)$sourceVal) . '"';
                } else {
                    $val = (string)$sourceVal;
                }
                $val = 'TO_CHAR(' . $val . ')';
            } else {
                $val = $this->sourceValue->getValue();
            }
        } else {
            // For string column names, quote them for Oracle (uppercase)
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$this->sourceValue)) {
                $val = '"' . strtoupper((string)$this->sourceValue) . '"';
            } else {
                $val = (string)$this->sourceValue;
            }
        }
        $pat = str_replace("'", "''", $this->pattern);
        // Return a generic REGEXP expression that will be replaced by dialect-specific formatter
        // Note: This is a placeholder - actual resolution happens in RawValueResolver
        // For Oracle, this will be replaced with REGEXP_LIKE by RawValueResolver
        // But for use in Db::case() keys, we need a valid SQL expression
        // Oracle doesn't support REGEXP operator, so we use REGEXP_LIKE directly
        // This will be properly resolved when the CASE expression is used in SELECT
        // However, since Db::case() uses getValue() directly, we need to return valid SQL
        // For Oracle, we'll use REGEXP_LIKE directly here
        return "REGEXP_LIKE($val, '$pat')";
    }
}
