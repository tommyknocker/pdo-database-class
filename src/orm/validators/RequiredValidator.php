<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use tommyknocker\pdodb\orm\Model;

/**
 * Validates that attribute is not empty.
 */
class RequiredValidator extends AbstractValidator
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
        if ($value === null) {
            return false;
        }

        if ($value === '') {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
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
        return "Attribute '{$attribute}' is required.";
    }
}
