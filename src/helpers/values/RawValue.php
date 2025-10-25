<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * Raw value e.g. NOW(), CONCAT(), etc.
 */
class RawValue
{
    /** @var string Raw SQL expression */
    protected string $value;

    /** @var array<int|string, string|int|float|bool|null> Parameters for prepared statements */
    protected array $params = [];

    /**
     * Constructor.
     *
     * @param string $value The raw SQL value.
     * @param array<int|string, string|int|float|bool|null> $params Optional parameters for prepared statements.
     */
    public function __construct(string $value, array $params = [])
    {
        $this->value = $value;
        $this->params = $params;
    }

    /**
     * Get the raw SQL value.
     *
     * @return string The raw SQL value.
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Get the parameters for prepared statements.
     *
     * @return array<int|string, string|int|float|bool|null> The parameters.
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
