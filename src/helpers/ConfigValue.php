<?php

namespace tommyknocker\pdodb\helpers;

class ConfigValue extends RawValue
{
    /** @var bool Whether to use equal sign (=) in SET statement */
    protected bool $useEqualSign = true;
    
    /** @var bool Whether to quote the value */
    protected bool $quoteValue = true;

    /**
     * Returns a ConfigValue instance representing a SET statement.
     * e.g. SET FOREIGN_KEY_CHECKS = 1 OR SET NAMES 'utf8mb4' in MySQL and PostgreSQL or PRAGMA statements in SQLite
     * @param string $key The column name.
     * @param mixed $value The value to set.
     * @param bool $useEqualSign Whether to use an equal sign (=) in the statement. Default is true.
     * @param bool $quoteValue Whether to prepare the value for binding. Default is false.
     */
    public function __construct(string $key, mixed $value, bool $useEqualSign = true, bool $quoteValue = false)
    {
        $this->value = $key;
        $this->params = [$value];
        $this->useEqualSign = $useEqualSign;
        $this->quoteValue = $quoteValue;
    }

    /**
     * Indicates whether to use an equal sign (=) in the statement.
     * @return bool True if an equal sign should be used, false otherwise.
     */
    public function getUseEqualSign(): bool
    {
        return $this->useEqualSign;
    }

    /**
     * Indicates whether to prepare the value for binding.
     * @return bool True if the value should be prepared for binding, false otherwise.
     */
    public function getQuoteValue(): bool
    {
        return $this->quoteValue;
    }
}