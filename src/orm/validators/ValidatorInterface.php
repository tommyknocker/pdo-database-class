<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use tommyknocker\pdodb\orm\Model;

/**
 * Validator interface for ActiveRecord models.
 *
 * Each validator validates a single attribute value according to specific rules.
 */
interface ValidatorInterface
{
    /**
     * Validate a value.
     *
     * @param Model $model The model being validated
     * @param string $attribute The attribute name
     * @param mixed $value The value to validate
     * @param array<string, mixed> $params Additional parameters for validation
     *
     * @return bool True if valid, false otherwise
     */
    public function validate(Model $model, string $attribute, mixed $value, array $params = []): bool;

    /**
     * Get validation error message.
     *
     * @param Model $model The model being validated
     * @param string $attribute The attribute name
     * @param array<string, mixed> $params Additional parameters
     *
     * @return string Error message
     */
    public function getMessage(Model $model, string $attribute, array $params = []): string;
}
