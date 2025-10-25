<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * $pdo->quote() value representation.
 */
class EscapeValue extends RawValue
{
    /**
     * Constructor.
     *
     * @param string $value The value to be escaped.
     */
    public function __construct(string $value)
    {
        parent::__construct($value);
    }
}
