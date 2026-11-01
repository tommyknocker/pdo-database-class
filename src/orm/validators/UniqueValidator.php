<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use RuntimeException;
use tommyknocker\pdodb\orm\behaviors\SoftDeleteBehavior;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\query\QueryConstants;

/** Validates that no other row stores the same value. */
class UniqueValidator extends AbstractValidator
{
    public function validate(Model $model, string $attribute, mixed $value, array $params = []): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        $column = $params['targetAttribute'] ?? $attribute;
        if (!is_string($column) || $column === '') {
            throw new RuntimeException("Validator 'unique' received an invalid target attribute.");
        }

        // Query through the connection the model is bound to, and count soft-deleted rows
        // unless asked otherwise: the database unique index still sees them.
        $db = $model->getModelDb();
        $query = ($db !== null ? $model::on($db) : $model::find())->where($column, $value);
        if (($params['withoutTrashed'] ?? false) !== true) {
            $query->withoutGlobalScope(SoftDeleteBehavior::SCOPE);
        }
        if (!$model->getIsNewRecord()) {
            foreach ($model::primaryKey() as $primaryKey) {
                $current = $model->getAttributes()[$primaryKey] ?? null;
                if ($current !== null) {
                    $query->where($primaryKey, $current, QueryConstants::OP_NOT_EQUAL);
                }
            }
        }

        return !$query->exists();
    }

    protected function getDefaultMessage(string $attribute, array $params): string
    {
        return "Attribute '{$attribute}' has already been taken.";
    }
}
