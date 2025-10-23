<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * CURTIME / CURRENT_TIME value (dialect-specific).
 */
class CurTimeValue extends RawValue
{
    public function __construct()
    {
        parent::__construct('');
    }
}
