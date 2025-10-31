<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use tommyknocker\pdodb\orm\Model;

/**
 * Validates that attribute is a valid email address.
 */
class EmailValidator extends AbstractValidator
{
    /**
     * Validate a value.
     *
     * @param Model $model The model being validated
     * @param string $attribute The attribute name
     * @param mixed $value The value to validate
     * @param array<string, mixed> $params Additional parameters
     *
     * @return bool True if valid, false otherwise
     */
    public function validate(Model $model, string $attribute, mixed $value, array $params = []): bool
    {
        // Empty values are handled by RequiredValidator
        if ($value === null || $value === '') {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
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
        return "Attribute '{$attribute}' must be a valid email address.";
    }
}
