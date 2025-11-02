<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\relations;

use RuntimeException;
use tommyknocker\pdodb\orm\Model;

/**
 * EagerLoader handles eager loading of relationships.
 *
 * Prevents N+1 query problems by loading related data in batches.
 */
class EagerLoader
{
    /**
     * Load relationships for multiple models.
     *
     * @param array<int, Model> $models Array of model instances
     * @param array<int|string, string|array<string>> $relations Array of relationship names to load
     *
     * @throws RuntimeException If relationship is not found or invalid
     */
    public function load(array $models, array $relations): void
    {
        if (empty($models) || empty($relations)) {
            return;
        }

        // Normalize relations array (handle both ['relation1', 'relation2'] and ['relation1' => ['subrelation']])
        $normalizedRelations = $this->normalizeRelations($relations);

        foreach ($normalizedRelations as $relationName => $subRelations) {
            $this->loadRelation($models, $relationName, $subRelations);
        }
    }

    /**
     * Normalize relations array.
     *
     * @param array<int|string, string|array<string>> $relations Relations to normalize
     *
     * @return array<string, array<string>> Normalized relations (relationName => [subRelations])
     */
    protected function normalizeRelations(array $relations): array
    {
        $normalized = [];

        foreach ($relations as $key => $value) {
            if (is_int($key)) {
                // Indexed array: ['relation1', 'relation2']
                if (is_string($value)) {
                    $normalized[$value] = [];
                } elseif (is_array($value)) {
                    // This shouldn't happen in indexed array, but handle gracefully
                    continue;
                } else {
                    $normalized[(string)$value] = [];
                }
            } else {
                // Associative array: ['relation1' => ['subrelation']]
                $normalized[$key] = is_array($value) ? $value : [];
            }
        }

        return $normalized;
    }

    /**
     * Load a single relationship for multiple models.
     *
     * @param array<int, Model> $models Array of model instances
     * @param string $relationName Relationship name
     * @param array<string> $subRelations Nested relationships to load
     *
     * @throws RuntimeException If relationship is not found or invalid
     */
    protected function loadRelation(array $models, string $relationName, array $subRelations): void
    {
        if (empty($models)) {
            return;
        }

        // Get first model to determine relationship type
        $firstModel = $models[0];
        $relation = $this->getRelationInstance($firstModel, $relationName);
        if ($relation === null) {
            throw new RuntimeException("Relationship '{$relationName}' not found in model " . $firstModel::class);
        }

        // Load based on relationship type
        if ($relation instanceof BelongsTo) {
            $this->loadBelongsTo($models, $relation, $relationName, $subRelations);
        } elseif ($relation instanceof HasOne) {
            $this->loadHasOne($models, $relation, $relationName, $subRelations);
        } elseif ($relation instanceof HasMany) {
            $this->loadHasMany($models, $relation, $relationName, $subRelations);
        }
    }

    /**
     * Load belongs-to relationship.
     *
     * @param array<int, Model> $models Array of model instances
     * @param BelongsTo $relation Relationship instance
     * @param string $relationName Relationship name
     * @param array<string> $subRelations Nested relationships
     */
    protected function loadBelongsTo(array $models, BelongsTo $relation, string $relationName, array $subRelations): void
    {
        $foreignKey = $relation->getForeignKey();
        $ownerKey = $relation->getOwnerKey();
        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relation->getModelClass();

        // Collect all foreign key values
        $foreignValues = [];
        foreach ($models as $model) {
            $value = $model->{$foreignKey} ?? null;
            if ($value !== null) {
                $foreignValues[] = $value;
            }
        }

        if (empty($foreignValues)) {
            // No foreign keys, set null for all models
            foreach ($models as $model) {
                $model->setRelationData($relationName, null);
            }
            return;
        }

        // Remove duplicates
        $foreignValues = array_unique($foreignValues);

        // Load related models
        $query = $relatedModelClass::find();
        $queryBuilder = $query->getQueryBuilder();
        $relatedModels = $queryBuilder
            ->where($ownerKey, $foreignValues, 'IN')
            ->get();

        // Convert to models
        $models = [];
        foreach ($relatedModels as $row) {
            if (is_array($row)) {
                $models[] = $this->populateModel($relatedModelClass, $row);
            }
        }
        $relatedModels = $models;

        // If nested relations requested, load them
        if (!empty($subRelations)) {
            $this->load($relatedModels, $subRelations);
        }

        // Index by owner key for fast lookup
        $indexed = [];
        foreach ($relatedModels as $relatedModel) {
            $keyValue = $relatedModel->{$ownerKey} ?? null;
            if ($keyValue !== null) {
                $indexed[$keyValue] = $relatedModel;
            }
        }

        // Assign related models to owner models
        foreach ($models as $model) {
            $foreignValue = $model->{$foreignKey} ?? null;
            $relatedModel = $indexed[$foreignValue] ?? null;
            $model->setRelationData($relationName, $relatedModel);
        }
    }

    /**
     * Load has-one relationship.
     *
     * @param array<int, Model> $models Array of model instances
     * @param HasOne $relation Relationship instance
     * @param string $relationName Relationship name
     * @param array<string> $subRelations Nested relationships
     */
    protected function loadHasOne(array $models, HasOne $relation, string $relationName, array $subRelations): void
    {
        $foreignKey = $relation->getForeignKey();
        $localKey = $relation->getLocalKey();
        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relation->getModelClass();

        // Collect all local key values
        $localValues = [];
        foreach ($models as $model) {
            $value = $model->{$localKey} ?? null;
            if ($value !== null) {
                $localValues[] = $value;
            }
        }

        if (empty($localValues)) {
            // No local keys, set null for all models
            foreach ($models as $model) {
                $model->setRelationData($relationName, null);
            }
            return;
        }

        // Remove duplicates
        $localValues = array_unique($localValues);

        // Load related models
        $query = $relatedModelClass::find();
        $queryBuilder = $query->getQueryBuilder();
        $relatedData = $queryBuilder
            ->where($foreignKey, $localValues, 'IN')
            ->get();

        // Convert to models
        $relatedModels = [];
        foreach ($relatedData as $row) {
            if (is_array($row)) {
                $relatedModels[] = $this->populateModel($relatedModelClass, $row);
            }
        }

        // If nested relations requested, load them
        if (!empty($subRelations)) {
            $this->load($relatedModels, $subRelations);
        }

        // Index by foreign key for fast lookup
        $indexed = [];
        foreach ($relatedModels as $relatedModel) {
            $keyValue = $relatedModel->{$foreignKey} ?? null;
            if ($keyValue !== null) {
                // For hasOne, keep only the first match (or handle duplicates appropriately)
                if (!isset($indexed[$keyValue])) {
                    $indexed[$keyValue] = $relatedModel;
                }
            }
        }

        // Assign related models to owner models
        foreach ($models as $model) {
            $localValue = $model->{$localKey} ?? null;
            $relatedModel = $indexed[$localValue] ?? null;
            $model->setRelationData($relationName, $relatedModel);
        }
    }

    /**
     * Load has-many relationship.
     *
     * @param array<int, Model> $models Array of model instances
     * @param HasMany $relation Relationship instance
     * @param string $relationName Relationship name
     * @param array<string> $subRelations Nested relationships
     */
    protected function loadHasMany(array $models, HasMany $relation, string $relationName, array $subRelations): void
    {
        $foreignKey = $relation->getForeignKey();
        $localKey = $relation->getLocalKey();
        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relation->getModelClass();

        // Collect all local key values
        $localValues = [];
        foreach ($models as $model) {
            $value = $model->{$localKey} ?? null;
            if ($value !== null) {
                $localValues[] = $value;
            }
        }

        if (empty($localValues)) {
            // No local keys, set empty arrays for all models
            foreach ($models as $model) {
                $model->setRelationData($relationName, []);
            }
            return;
        }

        // Remove duplicates
        $localValues = array_unique($localValues);

        // Load related models
        $query = $relatedModelClass::find();
        $queryBuilder = $query->getQueryBuilder();
        $relatedData = $queryBuilder
            ->where($foreignKey, $localValues, 'IN')
            ->get();

        // Convert to models
        $relatedModels = [];
        foreach ($relatedData as $row) {
            if (is_array($row)) {
                $relatedModels[] = $this->populateModel($relatedModelClass, $row);
            }
        }

        // If nested relations requested, load them
        if (!empty($subRelations)) {
            $this->load($relatedModels, $subRelations);
        }

        // Group by foreign key for fast lookup
        $grouped = [];
        foreach ($relatedModels as $relatedModel) {
            $keyValue = $relatedModel->{$foreignKey} ?? null;
            if ($keyValue !== null) {
                if (!isset($grouped[$keyValue])) {
                    $grouped[$keyValue] = [];
                }
                $grouped[$keyValue][] = $relatedModel;
            }
        }

        // Assign related models to owner models
        foreach ($models as $model) {
            $localValue = $model->{$localKey} ?? null;
            $relatedModelsForThis = $grouped[$localValue] ?? [];
            $model->setRelationData($relationName, $relatedModelsForThis);
        }
    }

    /**
     * Get relationship instance.
     *
     * @param Model $model Model instance
     * @param string $relationName Relationship name
     *
     * @return RelationInterface|null Relationship instance or null if not found
     */
    protected function getRelationInstance(Model $model, string $relationName): ?RelationInterface
    {
        // Use reflection to access protected method
        $reflection = new \ReflectionClass($model);
        $method = $reflection->getMethod('getRelationInstance');
        $method->setAccessible(true);

        return $method->invoke($model, $relationName);
    }

    /**
     * Populate model from data array.
     *
     * @param string $modelClass Model class name
     * @param array<string, mixed> $data Data array
     *
     * @return Model Model instance
     */
    protected function populateModel(string $modelClass, array $data): Model
    {
        /** @var Model $model */
        $model = new $modelClass();
        $model->populate($data);

        // Use reflection to set protected properties
        $reflection = new \ReflectionClass($model);
        $isNewRecordProp = $reflection->getProperty('isNewRecord');
        $isNewRecordProp->setAccessible(true);
        $isNewRecordProp->setValue($model, false);

        $oldAttributesProp = $reflection->getProperty('oldAttributes');
        $oldAttributesProp->setAccessible(true);
        $oldAttributesProp->setValue($model, $data);

        return $model;
    }
}
