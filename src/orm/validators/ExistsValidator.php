<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use RuntimeException;
use tommyknocker\pdodb\orm\Model;

/** Validates that a related row exists. */
class ExistsValidator extends AbstractValidator
{
    public function validate(Model $model, string $attribute, mixed $value, array $params = []): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        $targetAttribute = $params['targetAttribute'] ?? $attribute;
        if (!is_string($targetAttribute) || $targetAttribute === '') {
            throw new RuntimeException("Validator 'exist' received an invalid target attribute.");
        }

        $targetClass = $params['targetClass'] ?? null;
        if (is_string($targetClass) && is_a($targetClass, Model::class, true)) {
            return $targetClass::find()->where($targetAttribute, $value)->exists();
        }

        $table = $params['table'] ?? null;
        if (!is_string($table) || $table === '') {
            throw new RuntimeException("Validator 'exist' requires 'targetClass' or 'table'.");
        }

        $db = $model->getModelDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
        }

        return $db->find()->from($table)->where($targetAttribute, $value)->exists();
    }

    protected function getDefaultMessage(string $attribute, array $params): string
    {
        return "Attribute '{$attribute}' does not match an existing record.";
    }
}
