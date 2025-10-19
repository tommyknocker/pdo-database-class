<?php

namespace tommyknocker\pdodb\helpers;

/**
 * $pdo->quote() value representation
 */
class EscapeValue extends RawValue
{
    /**
     * Constructor
     *
     * @param string $value The value to be escaped.
     */
    public function __construct(string $value)
    {
        parent::__construct($value);
    }
}