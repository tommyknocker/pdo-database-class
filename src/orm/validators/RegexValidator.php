<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use tommyknocker\pdodb\orm\Model;

/** Validates a string against a regular expression. */
class RegexValidator extends AbstractValidator
{
    public function validate(Model $model, string $attribute, mixed $value, array $params = []): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return false;
        }

        $pattern = $params['pattern'] ?? null;
        if (!is_string($pattern) || $pattern === '') {
            return false;
        }

        return preg_match($pattern, (string) $value) === 1;
    }

    protected function getDefaultMessage(string $attribute, array $params): string
    {
        return "Attribute '{$attribute}' has an invalid format.";
    }
}
