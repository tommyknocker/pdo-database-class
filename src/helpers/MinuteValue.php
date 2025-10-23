<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * MINUTE extraction value (dialect-specific).
 */
class MinuteValue extends RawValue
{
    /** @var string|RawValue Source datetime expression */
    protected string|RawValue $source;

    public function __construct(string|RawValue $source)
    {
        $this->source = $source;
        parent::__construct('');
    }

    public function getSource(): string|RawValue
    {
        return $this->source;
    }
}
