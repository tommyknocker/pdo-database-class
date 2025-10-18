<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * NOW() value e.g. NOW(), NOW() + INTERVAL
 * or timestamp when $asTimestamp = true
 */
class NowValue extends RawValue
{
    protected bool $asTimestamp;

    public function __construct(?string $diff = '', bool $asTimestamp = false)
    {
        parent::__construct($diff ?: '');
        $this->asTimestamp = $asTimestamp;
    }

    public function getAsTimestamp(): bool
    {
        return $this->asTimestamp;
    }
}