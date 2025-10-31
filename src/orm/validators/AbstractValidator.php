<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use tommyknocker\pdodb\orm\Model;

/**
 * Abstract base validator providing common functionality.
 */
abstract class AbstractValidator implements ValidatorInterface
{
    /**
     * Get validation error message.
     *
     * @param Model $model The model being validated
     * @param string $attribute The attribute name
     * @param array<string, mixed> $params Additional parameters
     *
     * @return string Error message
     */
    public function getMessage(Model $model, string $attribute, array $params = []): string
    {
        $message = $params['message'] ?? null;
        if ($message !== null && is_string($message)) {
            return $message;
        }

        return $this->getDefaultMessage($attribute, $params);
    }

    /**
     * Get default error message.
     *
     * @param string $attribute The attribute name
     * @param array<string, mixed> $params Additional parameters
     *
     * @return string Default error message
     */
    abstract protected function getDefaultMessage(string $attribute, array $params): string;
}
