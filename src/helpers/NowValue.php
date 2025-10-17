<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * NOW() value representation
 */
class NowValue extends RawValue
{
    public function __construct(?string $value)
    {
        parent::__construct($value ?: '');
    }
}