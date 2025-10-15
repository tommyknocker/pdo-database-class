<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * Raw value (e.g. NOW(), NOW() + INTERVAL)
 */
class RawValue
{
    protected string $value;

    /**
     * @param string $value
     */
    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
