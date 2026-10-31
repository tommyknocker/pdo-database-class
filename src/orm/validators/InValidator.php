<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use tommyknocker\pdodb\orm\Model;

/** Validates that a value is one of an allowed list. */
class InValidator extends AbstractValidator
{
    public function validate(Model $model, string $attribute, mixed $value, array $params = []): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        $range = $params['range'] ?? [];
        if (!is_array($range)) {
            return false;
        }

        return in_array($value, $range, false);
    }

    protected function getDefaultMessage(string $attribute, array $params): string
    {
        return "Attribute '{$attribute}' is not in the list of allowed values.";
    }
}
