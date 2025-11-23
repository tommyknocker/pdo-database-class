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
        } elseif ($relation instanceof HasManyThrough) {
            $this->loadHasManyThrough($models, $relation, $relationName, $subRelations);
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
        // Use public method to access relation
        return $model->getRelationInstance($relationName);
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

        // Set protected properties using public methods
        $model->setIsNewRecord(false);
        $model->setOldAttributes($data);

        return $model;
    }

    /**
     * Load has-many-through relationship.
     *
     * @param array<int, Model> $models Array of model instances
     * @param HasManyThrough $relation Relationship instance
     * @param string $relationName Relationship name
     * @param array<string> $subRelations Nested relationships
     */
    protected function loadHasManyThrough(array $models, HasManyThrough $relation, string $relationName, array $subRelations): void
    {
        if (empty($models)) {
            return;
        }

        $viaTable = $relation->getViaTable();
        $viaRelation = $relation->getViaRelation();
        $link = $relation->getLink();
        $viaLink = $relation->getViaLink();
        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relation->getModelClass();

        // Collect owner key values
        $ownerKey = array_key_first($link);
        if ($ownerKey === null) {
            throw new RuntimeException('Link configuration must contain at least one key-value pair.');
        }
        $ownerValues = [];
        foreach ($models as $model) {
            $value = $model->{$ownerKey} ?? null;
            if ($value !== null) {
                $ownerValues[] = $value;
            }
        }

        if (empty($ownerValues)) {
            // No owner keys, set empty arrays for all models
            foreach ($models as $model) {
                $model->setRelationData($relationName, []);
            }
            return;
        }

        // Remove duplicates
        $ownerValues = array_unique($ownerValues);

        $relatedModels = [];

        // If using viaTable, load directly through junction table
        if (!empty($viaTable)) {
            $relatedModels = $this->loadViaTable($models, $relation, $ownerValues, $ownerKey);
        } elseif (!empty($viaRelation)) {
            // If using via, first load intermediate models, then related
            $relatedModels = $this->loadViaRelation($models, $relation, $ownerValues, $ownerKey);
        }

        // If nested relations requested, load them
        if (!empty($subRelations) && !empty($relatedModels)) {
            $this->load($relatedModels, $subRelations);
        }

        // Group by owner for fast lookup
        $grouped = $this->groupHasManyThroughByOwner($models, $relation, $relatedModels);

        // Assign related models to owner models
        foreach ($models as $model) {
            $ownerValue = $model->{$ownerKey} ?? null;
            $relatedModelsForThis = $grouped[$ownerValue] ?? [];
            $model->setRelationData($relationName, $relatedModelsForThis);
        }
    }

    /**
     * Load viaTable relationship.
     *
     * @param array<int, Model> $models Owner models
     * @param HasManyThrough $relation Relationship instance
     * @param array<mixed> $ownerValues Owner key values
     * @param string $ownerKey Owner key name
     *
     * @return array<int, Model> Related models
     */
    protected function loadViaTable(array $models, HasManyThrough $relation, array $ownerValues, string $ownerKey): array
    {
        $viaTable = $relation->getViaTable();
        $link = $relation->getLink();
        $viaLink = $relation->getViaLink();
        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relation->getModelClass();

        $junctionOwnerKey = $link[$ownerKey] ?? '';
        $junctionRelatedKey = array_key_first($viaLink);
        if ($junctionRelatedKey === null) {
            throw new RuntimeException('viaLink must contain at least one key-value pair.');
        }
        $relatedKey = $viaLink[$junctionRelatedKey];
        $relatedTable = $relatedModelClass::tableName();

        // Build query: SELECT related.* FROM related
        // INNER JOIN junction ON junction.related_key = related.id
        // WHERE junction.owner_key IN (...)
        $query = $relatedModelClass::find();
        $queryBuilder = $query->getQueryBuilder();

        // Use DISTINCT to avoid duplicates from JOIN
        $queryBuilder->distinct();

        // Build JOIN condition
        $joinCondition = $viaTable . '.' . $junctionRelatedKey . ' = ' . $relatedTable . '.' . $relatedKey;
        $queryBuilder->innerJoin($viaTable, $joinCondition);

        // Use where() with IN operator and array - ConditionBuilder supports this
        $queryBuilder->where($viaTable . '.' . $junctionOwnerKey, $ownerValues, 'IN');

        $data = $queryBuilder->get();
        $relatedModels = [];
        $seenIds = []; // Track IDs to avoid duplicates (though DISTINCT should handle this)

        foreach ($data as $row) {
            if (is_array($row)) {
                // Get model ID
                $relatedPk = $relatedModelClass::primaryKey()[0] ?? 'id';
                $modelId = $row[$relatedPk] ?? null;

                // Skip if already seen (extra safety)
                if ($modelId !== null && isset($seenIds[$modelId])) {
                    continue;
                }

                $relatedModels[] = $this->populateModel($relatedModelClass, $row);
                if ($modelId !== null) {
                    $seenIds[$modelId] = true;
                }
            }
        }

        return $relatedModels;
    }

    /**
     * Load via relationship.
     *
     * @param array<int, Model> $models Owner models
     * @param HasManyThrough $relation Relationship instance
     * @param array<mixed> $ownerValues Owner key values
     * @param string $ownerKey Owner key name
     *
     * @return array<int, Model> Related models
     */
    protected function loadViaRelation(array $models, HasManyThrough $relation, array $ownerValues, string $ownerKey): array
    {
        $viaRelation = $relation->getViaRelation();
        $viaLink = $relation->getViaLink();
        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relation->getModelClass();

        // First, load all intermediate models for all owner models
        $allIntermediateModels = [];
        foreach ($models as $model) {
            $intermediateRelation = $this->getRelationInstance($model, $viaRelation);
            if ($intermediateRelation !== null) {
                $intermediateModels = $intermediateRelation->getValue();
                if (!is_array($intermediateModels)) {
                    $intermediateModels = $intermediateModels !== null ? [$intermediateModels] : [];
                }
                $allIntermediateModels = array_merge($allIntermediateModels, $intermediateModels);
            }
        }

        if (empty($allIntermediateModels)) {
            return [];
        }

        // Extract related keys from intermediate models
        // viaLink maps: [foreign_key_in_related => primary_key_in_intermediate]
        $relatedForeignKey = array_key_first($viaLink); // e.g., 'post_id'
        if ($relatedForeignKey === null) {
            throw new RuntimeException('viaLink must contain at least one key-value pair.');
        }
        $intermediatePk = $viaLink[$relatedForeignKey]; // e.g., 'id'
        $relatedKeys = [];

        foreach ($allIntermediateModels as $intermediateModel) {
            // Get intermediate model's primary key value
            $intermediatePkValue = $intermediateModel->{$intermediatePk} ?? null;
            if ($intermediatePkValue !== null) {
                $relatedKeys[] = $intermediatePkValue;
            }
        }

        if (empty($relatedKeys)) {
            return [];
        }

        // Remove duplicates
        $relatedKeys = array_unique($relatedKeys);

        // Load related models using WHERE IN with array
        // Find related models where their foreign key matches intermediate primary keys
        $query = $relatedModelClass::find();
        $queryBuilder = $query->getQueryBuilder();
        $relatedData = $queryBuilder
            ->where($relatedForeignKey, $relatedKeys, 'IN')
            ->get();

        $relatedModels = [];
        foreach ($relatedData as $row) {
            if (is_array($row)) {
                $relatedModels[] = $this->populateModel($relatedModelClass, $row);
            }
        }

        return $relatedModels;
    }

    /**
     * Group has-many-through related models by owner.
     *
     * @param array<int, Model> $models Owner models
     * @param HasManyThrough $relation Relationship instance
     * @param array<int, Model> $relatedModels All related models
     *
     * @return array<mixed, array<int, Model>> Grouped models indexed by owner key value
     */
    protected function groupHasManyThroughByOwner(array $models, HasManyThrough $relation, array $relatedModels): array
    {
        $viaTable = $relation->getViaTable();
        $viaRelation = $relation->getViaRelation();
        $link = $relation->getLink();
        $viaLink = $relation->getViaLink();
        $ownerKey = array_key_first($link);
        if ($ownerKey === null) {
            throw new RuntimeException('Link configuration must contain at least one key-value pair.');
        }
        $junctionOwnerKey = $link[$ownerKey] ?? '';
        $junctionRelatedKey = array_key_first($viaLink);
        if ($junctionRelatedKey === null) {
            throw new RuntimeException('viaLink must contain at least one key-value pair.');
        }
        $relatedKey = $viaLink[$junctionRelatedKey];

        $grouped = [];

        if (!empty($viaTable)) {
            // For viaTable, we need to query junction table to map owners to related
            // Build a map: owner_value => [related_ids]
            $ownerValueToRelatedIds = [];

            // Collect all owner values and related IDs
            foreach ($models as $model) {
                $ownerValue = $model->{$ownerKey} ?? null;
                if ($ownerValue === null) {
                    continue;
                }

                if (!isset($ownerValueToRelatedIds[$ownerValue])) {
                    $ownerValueToRelatedIds[$ownerValue] = [];
                }

                // Query junction table for this owner
                $db = $model::getDb();
                if ($db === null) {
                    throw new RuntimeException('Database connection not set for model ' . $model::class);
                }
                $junctionData = $db->find()
                    ->from($viaTable)
                    ->where($junctionOwnerKey, $ownerValue)
                    ->get();

                foreach ($junctionData as $junctionRow) {
                    $relatedId = $junctionRow[$junctionRelatedKey] ?? null;
                    if ($relatedId !== null && !in_array($relatedId, $ownerValueToRelatedIds[$ownerValue], true)) {
                        $ownerValueToRelatedIds[$ownerValue][] = $relatedId;
                    }
                }
            }

            // Index related models by their key
            $indexedRelated = [];
            foreach ($relatedModels as $relatedModel) {
                $keyValue = $relatedModel->{$relatedKey} ?? null;
                if ($keyValue !== null) {
                    $indexedRelated[$keyValue] = $relatedModel;
                }
            }

            // Group by owner
            foreach ($ownerValueToRelatedIds as $ownerValue => $relatedIds) {
                if (!isset($grouped[$ownerValue])) {
                    $grouped[$ownerValue] = [];
                }
                foreach ($relatedIds as $relatedId) {
                    if (isset($indexedRelated[$relatedId])) {
                        $grouped[$ownerValue][] = $indexedRelated[$relatedId];
                    }
                }
            }
        } elseif (!empty($viaRelation)) {
            // For via, group through intermediate models
            // viaLink maps: [foreign_key_in_related => primary_key_in_intermediate]
            $relatedForeignKey = array_key_first($viaLink); // e.g., 'post_id'
            $intermediatePk = $viaLink[$relatedForeignKey]; // e.g., 'id'

            foreach ($models as $model) {
                $ownerValue = $model->{$ownerKey} ?? null;
                if ($ownerValue === null) {
                    continue;
                }

                // Get intermediate models for this owner
                $intermediateRelation = $this->getRelationInstance($model, $viaRelation);
                if ($intermediateRelation === null) {
                    continue;
                }

                $intermediateModels = $intermediateRelation->getValue();
                if (!is_array($intermediateModels)) {
                    $intermediateModels = $intermediateModels !== null ? [$intermediateModels] : [];
                }

                // Get related models through intermediate
                $relatedForOwner = [];
                foreach ($intermediateModels as $intermediateModel) {
                    // Get intermediate model's primary key value
                    $intermediatePkValue = $intermediateModel->{$intermediatePk} ?? null;
                    if ($intermediatePkValue === null) {
                        continue;
                    }

                    foreach ($relatedModels as $relatedModel) {
                        // Get related model's foreign key value (should match intermediate PK)
                        $relatedFkValue = $relatedModel->{$relatedForeignKey} ?? null;
                        if ($relatedFkValue === $intermediatePkValue) {
                            $relatedForOwner[] = $relatedModel;
                        }
                    }
                }

                $grouped[$ownerValue] = $relatedForOwner;
            }
        }

        return $grouped;
    }
}
