<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * MOD / modulo operation (dialect-specific)
 */
class ModValue extends RawValue
{
    protected string|RawValue $dividend;
    protected string|RawValue $divisor;

    public function __construct(string|RawValue $dividend, string|RawValue $divisor)
    {
        $this->dividend = $dividend;
        $this->divisor = $divisor;
        parent::__construct('');
    }

    public function getDividend(): string|RawValue
    {
        return $this->dividend;
    }

    public function getDivisor(): string|RawValue
    {
        return $this->divisor;
    }
}
