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
     * Constructor
     *
     * @param string $value The raw SQL value.
     * @param array $params Optional parameters for prepared statements.
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
     * @return array The parameters.
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
