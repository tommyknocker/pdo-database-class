<?php

namespace tommyknocker\pdodb\helpers;

/**
 * $pdo->quote() value representation
 */
class EscapeValue extends RawValue
{
    public function __construct(string $value)
    {
        parent::__construct($value);
    }
}