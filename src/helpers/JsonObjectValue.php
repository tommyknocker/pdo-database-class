<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * JSON object creation value
 */
class JsonObjectValue extends RawValue
{
    protected array $pairs;

    /**
     * Constructor
     *
     * @param array $pairs The key-value pairs for the JSON object.
     */
    public function __construct(array $pairs)
    {
        $this->pairs = $pairs;
        
        // Simply encode associative array to JSON
        $this->value = json_encode($pairs, JSON_UNESCAPED_UNICODE);
        $this->params = [];
    }

    /**
     * Get the key-value pairs for the JSON object.
     *
     * @return array The key-value pairs.
     */
    public function getPairs(): array
    {
        return $this->pairs;
    }
}

