<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * NOW() value e.g. NOW(), NOW() + INTERVAL
 * or timestamp when $asTimestamp = true.
 */
class NowValue extends RawValue
{
    /** @var bool Whether to return as timestamp instead of NOW() expression */
    protected bool $asTimestamp;

    /**
     * Constructor.
     *
     * @param string|null $diff Optional difference to apply to NOW(), e.g. ' + INTERVAL 1 DAY'
     * @param bool $asTimestamp Whether to return the value as a timestamp (true) or as NOW() expression (false).
     */
    public function __construct(?string $diff = '', bool $asTimestamp = false)
    {
        parent::__construct($diff ?: '');
        $this->asTimestamp = $asTimestamp;
    }

    /**
     * Get whether to return as timestamp.
     *
     * @return bool True if the value should be returned as timestamp, false for NOW() expression.
     */
    public function getAsTimestamp(): bool
    {
        return $this->asTimestamp;
    }
}
