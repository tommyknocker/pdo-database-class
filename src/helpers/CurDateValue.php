<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * CURDATE / CURRENT_DATE value (dialect-specific).
 */
class CurDateValue extends RawValue
{
    public function __construct()
    {
        parent::__construct('');
    }
}
