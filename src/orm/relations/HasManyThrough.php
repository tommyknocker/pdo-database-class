<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\relations;

use RuntimeException;
use tommyknocker\pdodb\orm\ActiveQuery;
use tommyknocker\pdodb\orm\Model;

/**
 * HasManyThrough relationship.
 *
 * Represents a many-to-many relationship through an intermediate table or relationship.
 * Supports both via() and viaTable() syntax similar to Yii2.
 */
class HasManyThrough implements RelationInterface
{
    /** @var string Related model class name */
    protected string $modelClass;

    /** @var string Intermediate table name (for viaTable) */
    protected string $viaTable;

    /** @var string Intermediate relationship name (for via) */
    protected string $viaRelation;

    /** @var array<string, string> Link from owner to intermediate table/relation */
    protected array $link;

    /** @var array<string, string> Link from intermediate table/relation to related model */
    protected array $viaLink;

    /** @var Model|null Owner model instance */
    protected ?Model $model = null;

    /**
     * HasManyThrough constructor.
     *
     * @param string $modelClass Related model class name
     * @param array<string, mixed> $config Relationship configuration
     *                                     - 'viaTable': Intermediate table name (mutually exclusive with 'via')
     *                                     - 'via': Intermediate relationship name (mutually exclusive with 'viaTable')
     *                                     - 'link': Array mapping owner keys to intermediate keys [owner_key => intermediate_key]
     *                                     - 'viaLink': Array mapping intermediate keys to related keys [intermediate_key => related_key]
     */
    public function __construct(string $modelClass, array $config = [])
    {
        $this->modelClass = $modelClass;

        // Either viaTable or via must be specified
        if (isset($config['viaTable'])) {
            $this->viaTable = (string)$config['viaTable'];
            $this->viaRelation = '';
        } elseif (isset($config['via'])) {
            $this->viaRelation = (string)$config['via'];
            $this->viaTable = '';
        } else {
            throw new RuntimeException('HasManyThrough relationship requires either "viaTable" or "via" configuration.');
        }

        // Link configuration (owner -> intermediate)
        $this->link = isset($config['link']) && is_array($config['link'])
            ? $config['link']
            : $this->defaultLink();

        // Via link configuration (intermediate -> related)
        $this->viaLink = isset($config['viaLink']) && is_array($config['viaLink'])
            ? $config['viaLink']
            : $this->defaultViaLink();
    }

    /**
     * Get default link configuration.
     *
     * @return array<string, string> Default link mapping
     */
    protected function defaultLink(): array
    {
        // Will be resolved when model is set
        return [];
    }

    /**
     * Get default via link configuration.
     *
     * @return array<string, string> Default via link mapping
     */
    protected function defaultViaLink(): array
    {
        // Will be resolved when model is set
        return [];
    }

    /**
     * Resolve default link configuration.
     */
    protected function resolveDefaults(): void
    {
        if ($this->model === null) {
            return;
        }

        // Resolve default link (owner -> intermediate)
        if (empty($this->link)) {
            $ownerTable = $this->model::tableName();
            $ownerPk = $this->model::primaryKey();
            if (!empty($ownerPk)) {
                $this->link = [$ownerPk[0] => $this->camelToSnake($ownerTable) . '_id'];
            }
        }

        // Resolve default via link (intermediate -> related)
        if (empty($this->viaLink)) {
            $relatedTable = $this->modelClass::tableName();
            $relatedPk = $this->modelClass::primaryKey();
            if (!empty($relatedPk)) {
                $this->viaLink = [$this->camelToSnake($relatedTable) . '_id' => $relatedPk[0]];
            }
        }
    }

    /**
     * Convert camelCase to snake_case.
     *
     * @param string $string String to convert
     *
     * @return string Converted string
     */
    protected function camelToSnake(string $string): string
    {
        $result = preg_replace('/([a-z])([A-Z])/', '$1_$2', $string);
        return strtolower($result ?? $string);
    }

    /**
     * {@inheritDoc}
     */
    public function setModel(Model $model): static
    {
        $this->model = $model;
        $this->resolveDefaults();
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getModel(): ?Model
    {
        return $this->model;
    }

    /**
     * {@inheritDoc}
     */
    public function getValue(): mixed
    {
        if ($this->model === null) {
            throw new RuntimeException('Model instance must be set before accessing relationship.');
        }

        $this->resolveDefaults();

        $relatedModelClass = $this->modelClass;
        $query = $relatedModelClass::find();

        // If using viaTable, build JOIN query
        if (!empty($this->viaTable)) {
            return $this->buildViaTableQuery($query);
        }

        // If using via, use intermediate relationship
        if (!empty($this->viaRelation)) {
            return $this->buildViaQuery($query);
        }

        return [];
    }

    /**
     * Build query using viaTable (direct junction table).
     *
     * @param ActiveQuery<Model> $query Base query
     *
     * @return array<int, Model> Related models
     */
    protected function buildViaTableQuery(ActiveQuery $query): array
    {
        $queryBuilder = $query->getQueryBuilder();
        $relatedTable = $this->modelClass::tableName();
        $relatedPk = $this->modelClass::primaryKey()[0] ?? 'id';

        // Get owner key value
        $ownerPk = array_key_first($this->link);
        if ($ownerPk === null) {
            throw new RuntimeException('Link configuration must contain at least one key-value pair.');
        }
        $ownerPkValue = $this->model->{$ownerPk} ?? null;

        if ($ownerPkValue === null) {
            return [];
        }

        // Build JOIN query: related_table JOIN junction_table ON ...
        // WHERE junction_table.owner_key = owner_value
        $junctionTable = $this->viaTable;
        $junctionOwnerKey = $this->link[$ownerPk] ?? '';
        $junctionRelatedKey = array_key_first($this->viaLink);
        if ($junctionRelatedKey === null) {
            throw new RuntimeException('viaLink must contain at least one key-value pair.');
        }
        $relatedKey = $this->viaLink[$junctionRelatedKey];

        // Use DISTINCT to avoid duplicates from JOIN
        $queryBuilder->distinct();

        // Build JOIN condition
        $joinCondition = $junctionTable . '.' . $junctionRelatedKey . ' = ' . $relatedTable . '.' . $relatedKey;
        $queryBuilder->innerJoin($junctionTable, $joinCondition);

        $queryBuilder->where($junctionTable . '.' . $junctionOwnerKey, $ownerPkValue);

        $data = $queryBuilder->get();
        $models = [];
        $seenIds = []; // Track IDs to avoid duplicates

        foreach ($data as $row) {
            if (is_array($row)) {
                // Get model ID
                $relatedPk = $this->modelClass::primaryKey()[0] ?? 'id';
                $modelId = $row[$relatedPk] ?? null;

                // Skip if already seen
                if ($modelId !== null && isset($seenIds[$modelId])) {
                    continue;
                }

                /** @var Model $model */
                $model = new $this->modelClass();
                $model->populate($row);

                // Set protected properties using public methods
                $model->setIsNewRecord(false);
                $model->setOldAttributes($row);

                $models[] = $model;
                if ($modelId !== null) {
                    $seenIds[$modelId] = true;
                }
            }
        }

        return $models;
    }

    /**
     * Build query using via (intermediate relationship).
     *
     * @param ActiveQuery<Model> $query Base query
     *
     * @return array<int, Model> Related models
     */
    protected function buildViaQuery(ActiveQuery $query): array
    {
        // Get intermediate relationship using public method
        if ($this->model === null) {
            throw new RuntimeException('Model instance must be set before accessing relationship.');
        }
        // Get intermediate relationship using public method
        $intermediateRelation = $this->model->getRelationInstance($this->viaRelation);
        if ($intermediateRelation === null) {
            throw new RuntimeException("Intermediate relationship '{$this->viaRelation}' not found.");
        }

        // Load intermediate models
        $intermediateModels = $intermediateRelation->getValue();
        if (empty($intermediateModels)) {
            return [];
        }

        // Normalize to array
        if (!is_array($intermediateModels)) {
            $intermediateModels = [$intermediateModels];
        }

        // Extract keys from intermediate models
        // viaLink maps: [foreign_key_in_related => primary_key_in_intermediate]
        // So we get intermediate primary key values, then find related by foreign key
        $relatedForeignKey = array_key_first($this->viaLink); // e.g., 'post_id'
        $intermediatePk = $this->viaLink[$relatedForeignKey]; // e.g., 'id'
        $relatedKeys = [];

        foreach ($intermediateModels as $intermediateModel) {
            // Get intermediate model's primary key value
            $intermediatePkValue = $intermediateModel->{$intermediatePk} ?? null;
            if ($intermediatePkValue !== null) {
                $relatedKeys[] = $intermediatePkValue;
            }
        }

        if (empty($relatedKeys)) {
            return [];
        }

        // Load related models using WHERE IN
        // Find related models where their foreign key matches intermediate primary keys
        $relatedModelClass = $this->modelClass;
        $query = $relatedModelClass::find();
        $queryBuilder = $query->getQueryBuilder();
        $queryBuilder->where($relatedForeignKey, $relatedKeys, 'IN');
        $data = $queryBuilder->get();

        $relatedModels = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                /** @var Model $model */
                $model = new $relatedModelClass();
                $model->populate($row);

                // Set protected properties using public methods
                $model->setIsNewRecord(false);
                $model->setOldAttributes($row);

                $relatedModels[] = $model;
            }
        }

        return $relatedModels;
    }

    /**
     * {@inheritDoc}
     */
    public function getEagerValue(mixed $data): mixed
    {
        if ($data === null || (is_array($data) && empty($data))) {
            return [];
        }

        if (!is_array($data)) {
            throw new RuntimeException('Eager-loaded data for HasManyThrough must be an array of Model instances or arrays.');
        }

        $models = [];

        foreach ($data as $item) {
            // If item is already a Model instance, use it
            if ($item instanceof Model) {
                $models[] = $item;
                continue;
            }

            // If item is array, populate model
            if (is_array($item)) {
                /** @var Model $model */
                $model = new $this->modelClass();
                $model->populate($item);

                // Set protected properties using public methods
                $model->setIsNewRecord(false);
                $model->setOldAttributes($item);

                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * {@inheritDoc}
     *
     * @return ActiveQuery<Model>
     */
    public function prepareQuery(): ActiveQuery
    {
        if ($this->model === null) {
            throw new RuntimeException('Model instance must be set before preparing query.');
        }

        $this->resolveDefaults();

        $relatedModelClass = $this->modelClass;
        $query = $relatedModelClass::find();
        $queryBuilder = $query->getQueryBuilder();

        // Get owner key value
        $ownerPk = array_key_first($this->link);
        $ownerPkValue = $this->model->{$ownerPk} ?? null;

        if ($ownerPkValue === null) {
            $queryBuilder->whereRaw('1 = 0'); // Always false condition
            return $query;
        }

        // If using viaTable, build JOIN query
        if (!empty($this->viaTable)) {
            $relatedTable = $this->modelClass::tableName();
            $junctionTable = $this->viaTable;
            $junctionOwnerKey = $this->link[$ownerPk] ?? '';
            $junctionRelatedKey = array_key_first($this->viaLink);
            if ($junctionRelatedKey === null) {
                throw new RuntimeException('viaLink must contain at least one key-value pair.');
            }
            $relatedKey = $this->viaLink[$junctionRelatedKey];

            // Use DISTINCT to avoid duplicates from JOIN
            $queryBuilder->distinct();

            // Build JOIN condition
            $joinCondition = $junctionTable . '.' . $junctionRelatedKey . ' = ' . $relatedTable . '.' . $relatedKey;
            $queryBuilder->innerJoin($junctionTable, $joinCondition);

            $queryBuilder->where($junctionTable . '.' . $junctionOwnerKey, $ownerPkValue);
        } elseif (!empty($this->viaRelation)) {
            // For via, we need to use a subquery
            // Get intermediate relationship using public method
            if ($this->model === null) {
                throw new RuntimeException('Model instance must be set before preparing query.');
            }
            $intermediateRelation = $this->model->getRelationInstance($this->viaRelation);
            if ($intermediateRelation !== null) {
                $intermediateQuery = $intermediateRelation->prepareQuery();
                $intermediateQueryBuilder = $intermediateQuery->getQueryBuilder();

                // viaLink maps: [foreign_key_in_related => primary_key_in_intermediate]
                $relatedForeignKey = array_key_first($this->viaLink); // e.g., 'post_id'
                if ($relatedForeignKey === null) {
                    throw new RuntimeException('viaLink must contain at least one key-value pair.');
                }
                $intermediatePk = $this->viaLink[$relatedForeignKey]; // e.g., 'id'

                // Use WHERE IN with subquery via callable
                // Build subquery from intermediate query
                $intermediateTable = $intermediateQueryBuilder->getTableName();
                if ($intermediateTable === null) {
                    $queryBuilder->whereRaw('1 = 0'); // Always false condition
                } else {
                    // Get SQL from intermediate query to preserve WHERE conditions
                    // Use it as a subquery in WHERE IN
                    $intermediateSqlData = $intermediateQueryBuilder->toSQL();
                    $intermediateSql = $intermediateSqlData['sql'] ?? '';
                    $intermediateParams = $intermediateSqlData['params'] ?? [];

                    // Build subquery: SELECT intermediatePk FROM (intermediate query)
                    // Use callable to build subquery that selects from the intermediate query
                    $queryBuilder->whereIn(
                        $relatedForeignKey,
                        function ($subQuery) use ($intermediatePk, $intermediateSql, $intermediateParams) {
                            // Build subquery using from with RawValue to preserve all conditions from intermediate query
                            $subQueryRawValue = new \tommyknocker\pdodb\helpers\values\RawValue("({$intermediateSql}) AS subquery", $intermediateParams);
                            $subQuery->from($subQueryRawValue)->select("subquery.{$intermediatePk}");
                        }
                    );
                }
            } else {
                $queryBuilder->whereRaw('1 = 0'); // Always false condition
            }
        }

        return $query;
    }

    /**
     * Get related model class name.
     *
     * @return string Related model class name
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /**
     * Get via table name.
     *
     * @return string Via table name or empty string if using via relation
     */
    public function getViaTable(): string
    {
        return $this->viaTable;
    }

    /**
     * Get via relation name.
     *
     * @return string Via relation name or empty string if using via table
     */
    public function getViaRelation(): string
    {
        return $this->viaRelation;
    }

    /**
     * Get link configuration.
     *
     * @return array<string, string> Link mapping
     */
    public function getLink(): array
    {
        return $this->link;
    }

    /**
     * Get via link configuration.
     *
     * @return array<string, string> Via link mapping
     */
    public function getViaLink(): array
    {
        return $this->viaLink;
    }
}
