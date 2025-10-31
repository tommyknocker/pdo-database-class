<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use tommyknocker\pdodb\orm\Model;

/**
 * Validates that attribute is an integer.
 */
class IntegerValidator extends AbstractValidator
{
    /**
     * Validate a value.
     *
     * @param Model $model The model being validated
     * @param string $attribute The attribute name
     * @param mixed $value The value to validate
     * @param array<string, mixed> $params Additional parameters (min, max)
     *
     * @return bool True if valid, false otherwise
     */
    public function validate(Model $model, string $attribute, mixed $value, array $params = []): bool
    {
        // Empty values are handled by RequiredValidator
        if ($value === null || $value === '') {
            return true;
        }

        if (!is_numeric($value)) {
            return false;
        }

        $intValue = (int) $value;

        // Check if value matches original (handles floats)
        if ((string) $intValue !== (string) $value) {
            return false;
        }

        if (isset($params['min']) && is_numeric($params['min'])) {
            $min = (int) $params['min'];
            if ($intValue < $min) {
                return false;
            }
        }

        if (isset($params['max']) && is_numeric($params['max'])) {
            $max = (int) $params['max'];
            if ($intValue > $max) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get default error message.
     *
     * @param string $attribute The attribute name
     * @param array<string, mixed> $params Additional parameters
     *
     * @return string Default error message
     */
    protected function getDefaultMessage(string $attribute, array $params): string
    {
        if (isset($params['min']) && isset($params['max'])) {
            $min = $params['min'];
            $max = $params['max'];
            return "Attribute '{$attribute}' must be an integer between {$min} and {$max}.";
        }
        if (isset($params['min'])) {
            $min = $params['min'];
            return "Attribute '{$attribute}' must be an integer >= {$min}.";
        }
        if (isset($params['max'])) {
            $max = $params['max'];
            return "Attribute '{$attribute}' must be an integer <= {$max}.";
        }

        return "Attribute '{$attribute}' must be an integer.";
    }
}
