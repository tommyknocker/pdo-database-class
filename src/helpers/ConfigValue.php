<?php

namespace tommyknocker\pdodb\helpers;

class ConfigValue extends RawValue
{
    protected bool $useEqualSign = true;
    protected bool $quouteValue = true;

    /**
     * Returns a ConfigValue instance representing a SET statement.
     * e.g. SET FOREIGN_KEY_CHECKS = 1 OR SET NAMES 'utf8mb4' in MySQL and PostgreSQL or PRAGMA statements in SQLite
     * @param string $key The column name.
     * @param mixed $value The value to set.
     * @param bool $useEqualSign Whether to use an equal sign (=) in the statement. Default is true.
     * @param bool $quouteValue Whether to prepare the value for binding. Default is false.
     */
    public function __construct(string $key, mixed $value, bool $useEqualSign = true, bool $quouteValue = false)
    {
        $this->value = $key;
        $this->params = [$value];
        $this->useEqualSign = $useEqualSign;
        $this->quouteValue = $quouteValue;
    }

    public function getUseEqualSign(): bool
    {
        return $this->useEqualSign;
    }

    public function getQuoteValue(): bool
    {
        return $this->quouteValue;
    }
}