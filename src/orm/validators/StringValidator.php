<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use tommyknocker\pdodb\orm\Model;

/**
 * Validates that attribute is a string with optional length constraints.
 */
class StringValidator extends AbstractValidator
{
    /**
     * Validate a value.
     *
     * @param Model $model The model being validated
     * @param string $attribute The attribute name
     * @param mixed $value The value to validate
     * @param array<string, mixed> $params Additional parameters (min, max, length)
     *
     * @return bool True if valid, false otherwise
     */
    public function validate(Model $model, string $attribute, mixed $value, array $params = []): bool
    {
        // Empty values are handled by RequiredValidator
        if ($value === null || $value === '') {
            return true;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        $stringValue = (string) $value;
        $length = strlen($stringValue);

        // Check exact length
        if (isset($params['length']) && is_numeric($params['length'])) {
            $exactLength = (int) $params['length'];
            if ($length !== $exactLength) {
                return false;
            }
        }

        // Check min length
        if (isset($params['min']) && is_numeric($params['min'])) {
            $minLength = (int) $params['min'];
            if ($length < $minLength) {
                return false;
            }
        }

        // Check max length
        if (isset($params['max']) && is_numeric($params['max'])) {
            $maxLength = (int) $params['max'];
            if ($length > $maxLength) {
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
        if (isset($params['length'])) {
            $length = $params['length'];
            return "Attribute '{$attribute}' must be exactly {$length} characters long.";
        }
        if (isset($params['min']) && isset($params['max'])) {
            $min = $params['min'];
            $max = $params['max'];
            return "Attribute '{$attribute}' must be between {$min} and {$max} characters long.";
        }
        if (isset($params['min'])) {
            $min = $params['min'];
            return "Attribute '{$attribute}' must be at least {$min} characters long.";
        }
        if (isset($params['max'])) {
            $max = $params['max'];
            return "Attribute '{$attribute}' must be at most {$max} characters long.";
        }

        return "Attribute '{$attribute}' must be a string.";
    }
}
