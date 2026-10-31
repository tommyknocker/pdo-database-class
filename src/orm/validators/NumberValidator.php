<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use tommyknocker\pdodb\orm\Model;

/** Validates a numeric value and optional inclusive bounds. */
class NumberValidator extends AbstractValidator
{
    public function validate(Model $model, string $attribute, mixed $value, array $params = []): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return false;
        }

        $number = (float) $value;
        if (isset($params['min']) && is_numeric($params['min']) && $number < (float) $params['min']) {
            return false;
        }
        if (isset($params['max']) && is_numeric($params['max']) && $number > (float) $params['max']) {
            return false;
        }

        return true;
    }

    protected function getDefaultMessage(string $attribute, array $params): string
    {
        if (isset($params['min'], $params['max'])) {
            return "Attribute '{$attribute}' must be a number between {$params['min']} and {$params['max']}.";
        }
        if (isset($params['min'])) {
            return "Attribute '{$attribute}' must be a number >= {$params['min']}.";
        }
        if (isset($params['max'])) {
            return "Attribute '{$attribute}' must be a number <= {$params['max']}.";
        }

        return "Attribute '{$attribute}' must be a number.";
    }
}
