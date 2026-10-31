<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use RuntimeException;
use tommyknocker\pdodb\orm\Model;

/** Validates with a user-supplied callable. */
class CallbackValidator extends AbstractValidator
{
    public function validate(Model $model, string $attribute, mixed $value, array $params = []): bool
    {
        $callback = $params['callback'] ?? null;
        if (!is_callable($callback)) {
            throw new RuntimeException("Validator 'callback' requires a callable 'callback' parameter.");
        }

        return $callback($model, $attribute, $value) === true;
    }

    protected function getDefaultMessage(string $attribute, array $params): string
    {
        return "Attribute '{$attribute}' is invalid.";
    }
}
