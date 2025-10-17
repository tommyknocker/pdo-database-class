<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * Raw value e.g. NOW(), CONCAT(), etc.
 */
class RawValue
{
    protected string $value;
    protected array $params = [];

    /**
     * @param string $value
     * @param array $params
     */
    public function __construct(string $value, array $params = [])
    {
        $this->value = $value;
        $this->params = $params;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getParams(): array
    {
        return $this->params;
    }
}
