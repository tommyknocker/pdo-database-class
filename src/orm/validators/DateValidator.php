<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;
use tommyknocker\pdodb\orm\Model;

/** Validates a date string or a DateTimeInterface value. */
class DateValidator extends AbstractValidator
{
    public function validate(Model $model, string $attribute, mixed $value, array $params = []): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if ($value instanceof DateTimeInterface) {
            return true;
        }
        if (!is_string($value)) {
            return false;
        }

        $format = $params['format'] ?? null;
        if (is_string($format) && $format !== '') {
            $parsed = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            return $parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
        }

        try {
            new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();

            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        } catch (Throwable) {
            return false;
        }
    }

    protected function getDefaultMessage(string $attribute, array $params): string
    {
        return "Attribute '{$attribute}' must be a valid date.";
    }
}
