<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * JSON array creation value
 */
class JsonArrayValue extends RawValue
{
    protected array $values;

    public function __construct(array $values)
    {
        $this->values = $values;
        
        // Simply encode array to JSON
        $this->value = json_encode($values, JSON_UNESCAPED_UNICODE);
        $this->params = [];
    }

    /**
     * Returns the array of values to be included in the JSON array.
     *
     * @return array The array of values.
     */
    public function getValues(): array
    {
        return $this->values;
    }
}

