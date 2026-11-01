<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm;

use RuntimeException;
use Throwable;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\events\ModelAfterDeleteEvent;
use tommyknocker\pdodb\events\ModelAfterInsertEvent;
use tommyknocker\pdodb\events\ModelAfterSaveEvent;
use tommyknocker\pdodb\events\ModelAfterUpdateEvent;
use tommyknocker\pdodb\events\ModelBeforeDeleteEvent;
use tommyknocker\pdodb\events\ModelBeforeInsertEvent;
use tommyknocker\pdodb\events\ModelBeforeSaveEvent;
use tommyknocker\pdodb\events\ModelBeforeUpdateEvent;
use tommyknocker\pdodb\exceptions\LazyRelationException;
use tommyknocker\pdodb\exceptions\MassAssignmentException;
use tommyknocker\pdodb\exceptions\ModelWriteAbortedException;
use tommyknocker\pdodb\exceptions\OptimisticLockException;
use tommyknocker\pdodb\exceptions\PrimaryKeyMutationException;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\orm\behaviors\SoftDeleteBehavior;
use tommyknocker\pdodb\orm\behaviors\TimestampBehavior;
use tommyknocker\pdodb\orm\relations\BelongsTo;
use tommyknocker\pdodb\orm\relations\HasMany;
use tommyknocker\pdodb\orm\relations\HasManyThrough;
use tommyknocker\pdodb\orm\relations\HasOne;
use tommyknocker\pdodb\orm\relations\RelationInterface;
use tommyknocker\pdodb\orm\relations\RelationTrace;
use tommyknocker\pdodb\orm\validators\ValidatorFactory;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * ActiveRecord trait provides ORM functionality for model classes.
 *
 * This trait implements the ActiveRecord pattern, allowing models to:
 * - Manage attributes through magic methods
 * - Track dirty (changed) attributes
 * - Save, update, and delete records
 * - Reload data from database
 */
trait ActiveRecord
{
    /** @var array<string, mixed> Model attributes */
    protected array $attributes = [];

    /** @var array<string, mixed> Original attributes (for dirty tracking) */
    protected array $oldAttributes = [];

    /** @var bool Whether this is a new record */
    protected bool $isNewRecord = true;

    /** @var array<string, array<int, string>> Validation errors (attribute => [messages]) */
    protected array $validationErrors = [];

    /** @var array<string, RelationInterface> Relationship instances cache */
    protected array $relations = [];

    /** @var array<string, mixed> Eager-loaded relationship data */
    protected array $relationData = [];

    protected ?PdoDb $instanceDb = null;
    protected ?ConnectionInterface $instanceConnection = null;
    protected bool $propagateConnection = false;

    /** When true, record writes skip the soft-delete global scope. */
    protected bool $writeWithoutSoftDeleteScope = false;

    /** Bind a new or freshly loaded model to the connection that owns it. */
    public function bindConnection(PdoDb $db, bool $propagateToRelations = true): static
    {
        if (!$this->isNewRecord && ($this->getModelDb() !== $db || ($this->instanceConnection !== null && $this->instanceConnection !== $db->connection))) {
            throw new RuntimeException('A loaded model cannot be rebound to another connection.');
        }
        $this->instanceDb = $db;
        $this->instanceConnection = $db->connection;
        $this->propagateConnection = $propagateToRelations;
        return $this;
    }

    public function getModelDb(): ?PdoDb
    {
        if ($this->instanceDb !== null) {
            if ($this->instanceDb->connection !== $this->instanceConnection) {
                throw new RuntimeException('The model connection changed after it was bound.');
            }
            return $this->instanceDb;
        }
        return static::getDb();
    }

    /**
     * @param string $modelClass
     *
     * @return ActiveQuery<Model>
     */
    public function relationQuery(string $modelClass): ActiveQuery
    {
        if (!is_a($modelClass, Model::class, true)) {
            throw new RuntimeException('Related model class must extend Model.');
        }
        if ($this->propagateConnection) {
            $db = $this->getModelDb();
            if ($db === null) {
                throw new RuntimeException('Database connection not set for relation.');
            }
            return $modelClass::on($db);
        }
        return $modelClass::find();
    }

    public function propagatesConnection(): bool
    {
        return $this->propagateConnection;
    }

    /**
     * Get attribute value.
     *
     * @param string $name Attribute name
     *
     * @return mixed Attribute value or null if not set
     */
    public function getAttribute(string $name): mixed
    {
        return $this->__get($name);
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->__set($name, $value);
    }

    public function __get(string $name): mixed
    {
        // Check if it's a relationship (try to load it)
        $relation = $this->getRelationInstance($name);
        if ($relation !== null) {
            return $this->loadRelation($name);
        }

        $key = $this->attributeKey($name, $this->attributes);
        $casts = static::casts();
        $castKey = $this->attributeKey($name, $casts);
        $value = $this->attributes[$key] ?? null;
        $cast = $casts[$castKey] ?? null;
        return $cast === null || $value instanceof RawValue ? $value : AttributeCaster::fromDatabase($cast, $value);
    }

    /**
     * Set attribute value.
     *
     * @param string $name Attribute name
     * @param mixed $value Attribute value
     */
    public function __set(string $name, mixed $value): void
    {
        $key = $this->attributeKey($name, $this->attributes);
        $casts = static::casts();
        $cast = $casts[$this->attributeKey($name, $casts)] ?? null;
        $this->attributes[$key] = $cast === null || $value instanceof RawValue ? $value : AttributeCaster::toDatabase($cast, $value);
    }

    /**
     * Check if attribute exists.
     *
     * @param string $name Attribute name
     *
     * @return bool True if attribute exists
     */
    public function __isset(string $name): bool
    {
        return isset($this->attributes[$this->attributeKey($name, $this->attributes)]);
    }

    /**
     * Unset attribute.
     *
     * @param string $name Attribute name
     */
    public function __unset(string $name): void
    {
        unset($this->attributes[$this->attributeKey($name, $this->attributes)]);
    }

    /** @param array<string, mixed> $attributes */
    protected function attributeKey(string $name, array $attributes): string
    {
        if (array_key_exists($name, $attributes)) {
            return $name;
        }
        $db = $this->getModelDb();
        if ($db === null) {
            return $name;
        }

        try {
            return $db->connection->getDialect()->resolveAttributeKeyForRead($name, $attributes);
        } catch (RuntimeException) {
            return $name;
        }
    }

    /**
     * Handle method calls (Yii2-like relationship query syntax).
     *
     * Allows calling relationships as methods to get ActiveQuery for modification:
     * $user->posts()->where('published', 1)->all()
     *
     * @param string $name Method name (should be a relationship name)
     * @param array<mixed> $arguments Method arguments (should be empty for relationships)
     *
     * @return mixed ActiveQuery instance if it's a relationship, otherwise throws exception
     * @throws RuntimeException If method doesn't exist and is not a relationship
     */
    public function __call(string $name, array $arguments): mixed
    {
        // Check if it's a relationship called as method
        $relation = $this->getRelationInstance($name);
        if ($relation !== null) {
            // Set model if not already set
            if ($relation->getModel() === null) {
                $relation->setModel($this);
            }
            return $relation->prepareQuery();
        }

        // Not a relationship - throw exception
        throw new RuntimeException(
            'Call to undefined method ' . static::class . '::' . $name . '(). ' .
            'If ' . $name . ' is a relationship, make sure it is defined in relations() method.'
        );
    }

    /**
     * Get all attributes.
     *
     * @return array<string, mixed> All attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Set attributes from array.
     *
     * @param array<string, mixed> $values Attributes to set
     * @param bool $safeOnly If true, only set safe attributes (if safeAttributes() method exists)
     */
    public function setAttributes(array $values, bool $safeOnly = false): void
    {
        if ($safeOnly) {
            if (!method_exists(static::class, 'safeAttributes')) {
                throw new MassAssignmentException(
                    'safeOnly is enabled but ' . static::class . ' does not declare safeAttributes().'
                );
            }
            $safe = static::safeAttributes();
            $values = array_intersect_key($values, array_flip($safe));
        }

        foreach ($values as $name => $value) {
            $this->__set($name, $value);
        }
    }

    /**
     * Get dirty (changed) attributes.
     *
     * @return array<string, mixed> Dirty attributes
     */
    public function getDirtyAttributes(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->oldAttributes) || $this->oldAttributes[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    /**
     * Check if model has changes.
     *
     * @return bool True if model has dirty attributes
     */
    public function getIsDirty(): bool
    {
        // For new records, if there are any attributes, consider it dirty
        if ($this->isNewRecord) {
            return !empty($this->attributes);
        }

        return !empty($this->getDirtyAttributes());
    }

    /**
     * Check if this is a new record.
     *
     * @return bool True if this is a new record
     */
    public function getIsNewRecord(): bool
    {
        return $this->isNewRecord;
    }

    /**
     * Set whether this is a new record.
     *
     * @param bool $isNewRecord True if this is a new record
     */
    public function setIsNewRecord(bool $isNewRecord): void
    {
        $this->isNewRecord = $isNewRecord;
    }

    /**
     * Get old attributes (original attribute values).
     *
     * @return array<string, mixed> Old attributes
     */
    public function getOldAttributes(): array
    {
        return $this->oldAttributes;
    }

    /**
     * Set old attributes (original attribute values).
     *
     * @param array<string, mixed> $attributes Old attributes
     */
    public function setOldAttributes(array $attributes): void
    {
        $this->oldAttributes = $attributes;
    }

    /**
     * Dispatch an event if dispatcher is available.
     *
     * @param object $event The event to dispatch
     */
    protected function dispatchEvent(object $event): void
    {
        $db = $this->getModelDb();
        if ($db === null) {
            return;
        }

        $queryBuilder = $db->find();
        $connection = $queryBuilder->getConnection();
        $dispatcher = $connection->getEventDispatcher();

        if ($dispatcher !== null) {
            $dispatcher->dispatch($event);
        }
    }

    /**
     * Save model (insert or update).
     *
     * @param bool $runValidation Whether to run validation before saving
     *
     * @return bool True on success, false on failure
     */
    public function save(bool $runValidation = true): bool
    {
        if ($runValidation && !$this->validate()) {
            return false;
        }

        $operation = $this->isNewRecord ? Model::OP_INSERT : Model::OP_UPDATE;

        return (bool) $this->runTransactional($operation, fn (): bool => $this->performSave());
    }

    protected function performSave(): bool
    {
        $beforeSaveEvent = new ModelBeforeSaveEvent($this, $this->isNewRecord);
        $this->dispatchEvent($beforeSaveEvent);
        if ($beforeSaveEvent->isPropagationStopped()) {
            return false;
        }

        $wasNewRecord = $this->isNewRecord;
        $success = $this->isNewRecord ? $this->insert() : $this->update();
        if ($success) {
            $this->dispatchEvent(new ModelAfterSaveEvent($this, $wasNewRecord));
        }

        return $success;
    }

    /**
     * Insert new record.
     *
     * @return bool True on success, false on failure
     */
    protected function insert(): bool
    {
        $this->applyBehaviorHook('beforeInsert');

        // Dispatch beforeInsert event
        $beforeInsertEvent = new ModelBeforeInsertEvent($this);
        $this->dispatchEvent($beforeInsertEvent);

        // Check if event propagation was stopped
        if ($beforeInsertEvent->isPropagationStopped()) {
            return false;
        }

        $db = $this->getModelDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
        }

        if ($this->instanceDb === null) {
            $this->bindConnection($db, false);
        }
        static::validateCastConnection($db);

        $tableName = static::tableName();
        $lockColumn = static::optimisticLock();
        if ($lockColumn !== null) {
            if ($lockColumn === '') {
                throw new OptimisticLockException('Optimistic lock column must not be empty.');
            }
            $version = $this->attributes[$lockColumn] ?? 1;
            if ((!is_int($version) && !is_string($version)) || filter_var($version, FILTER_VALIDATE_INT) === false || (int)$version < 0) {
                throw new OptimisticLockException('Initial optimistic lock version must be a non-negative integer.');
            }
            $this->attributes[$lockColumn] = (int)$version;
        }
        $attributes = $this->getAttributes();

        // Remove primary key if auto-increment
        $pk = static::primaryKey();
        $pkValue = $attributes[$pk[0]] ?? null;
        if (count($pk) === 1 && $pkValue === null) {
            if (!static::incrementing()) {
                throw new RuntimeException(static::class . ' is not incrementing and the primary key is empty.');
            }
            unset($attributes[$pk[0]]);
        }

        $result = $db->find()->table($tableName)->insert($attributes);

        if ($result > 0) {
            $insertId = null;

            // Set primary key if auto-increment
            if (count($pk) === 1 && (!isset($this->attributes[$pk[0]]) || ($this->attributes[$pk[0]] ?? null) === null)) {
                $insertId = $result;
                $this->attributes[$pk[0]] = $result;
            } else {
                $insertId = $this->attributes[$pk[0]] ?? null;
            }

            $this->oldAttributes = $this->attributes;
            $this->setIsNewRecord(false);
            $this->reloadExpressionAttributes();

            // Dispatch afterInsert event
            $afterInsertEvent = new ModelAfterInsertEvent($this, $insertId);
            $this->dispatchEvent($afterInsertEvent);

            return true;
        }

        return false;
    }

    /**
     * Update existing record.
     *
     * @return bool True on success, false on failure
     */
    protected function update(): bool
    {
        $db = $this->getModelDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
        }

        static::validateCastConnection($db);

        $this->applyBehaviorHook('beforeUpdate');

        $dirty = $this->getDirtyAttributes();
        if (empty($dirty)) {
            return true; // Nothing to update
        }

        // Dispatch beforeUpdate event
        $beforeUpdateEvent = new ModelBeforeUpdateEvent($this, $dirty);
        $this->dispatchEvent($beforeUpdateEvent);

        // Check if event propagation was stopped
        if ($beforeUpdateEvent->isPropagationStopped()) {
            return false;
        }

        $pk = static::primaryKey();
        $tableName = static::tableName();
        $query = $this->scopedRecordQuery($db, $tableName);

        $pkValues = $this->primaryKeyValuesForWhere();
        foreach ($pk as $key) {
            $query->where($key, $pkValues[$key]);
        }

        // Remove primary key from update data
        foreach ($pk as $key) {
            unset($dirty[$key]);
        }

        if (empty($dirty)) {
            return true; // Only primary key changed, nothing to update
        }

        $lockColumn = static::optimisticLock();
        $lockVersion = $this->originalLockVersion();
        $lockKey = null;
        if ($lockColumn !== null && $lockVersion !== null) {
            if ($lockVersion === PHP_INT_MAX) {
                throw new OptimisticLockException('Optimistic lock version cannot be incremented.');
            }
            $lockKey = $this->attributeKey($lockColumn, $this->attributes);
            $query->where($lockColumn, $lockVersion);
            $dirty[$lockKey] = $lockVersion + 1;
        }
        $result = $query->update($dirty);
        if ($lockColumn !== null && $result <= 0) {
            throw new OptimisticLockException('The record was changed, deleted, or is no longer accessible.');
        }

        if ($this->updateSucceeded($result, $tableName, $pkValues, $dirty)) {
            if ($lockKey !== null && $lockVersion !== null) {
                $this->attributes[$lockKey] = $lockVersion + 1;
            }
            $this->oldAttributes = $this->attributes;
            $this->reloadExpressionAttributes();

            // Dispatch afterUpdate event
            $afterUpdateEvent = new ModelAfterUpdateEvent($this, $dirty, max(0, $result));
            $this->dispatchEvent($afterUpdateEvent);

            return true;
        }

        return false;
    }

    /**
     * Delete record.
     *
     * @return bool True on success, false on failure
     */
    public function delete(): bool
    {
        if ($this->isNewRecord) {
            return false;
        }

        return (bool) $this->runTransactional(Model::OP_DELETE, fn (): bool => $this->performDelete(false));
    }

    public function forceDelete(): bool
    {
        if ($this->isNewRecord) {
            return false;
        }

        $this->writeWithoutSoftDeleteScope = true;

        try {
            return (bool) $this->runTransactional(Model::OP_DELETE, fn (): bool => $this->performDelete(true));
        } finally {
            $this->writeWithoutSoftDeleteScope = false;
        }
    }

    public function restore(): bool
    {
        $softDelete = $this->softDeleteBehavior();
        if ($softDelete === null) {
            throw new RuntimeException(static::class . ' does not use soft deletes.');
        }
        if ($this->isNewRecord || !$softDelete->isDeleted($this)) {
            return false;
        }

        $this->writeWithoutSoftDeleteScope = true;

        try {
            $this->setAttribute($softDelete->column(), null);

            return $this->save(false);
        } finally {
            $this->writeWithoutSoftDeleteScope = false;
        }
    }

    protected function performDelete(bool $force): bool
    {
        if (!$force) {
            $softDelete = $this->softDeleteBehavior();
            if ($softDelete !== null) {
                return $this->softDeleteRecord($softDelete);
            }
        }

        // Dispatch beforeDelete event
        $beforeDeleteEvent = new ModelBeforeDeleteEvent($this);
        $this->dispatchEvent($beforeDeleteEvent);

        // Check if event propagation was stopped
        if ($beforeDeleteEvent->isPropagationStopped()) {
            return false;
        }

        $db = $this->getModelDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
        }

        $pk = static::primaryKey();
        $tableName = static::tableName();
        $query = $this->scopedRecordQuery($db, $tableName);

        $pkValues = $this->primaryKeyValuesForWhere();
        foreach ($pk as $key) {
            $query->where($key, $pkValues[$key]);
        }

        $lockColumn = static::optimisticLock();
        $lockVersion = $this->originalLockVersion();
        if ($lockColumn !== null && $lockVersion !== null) {
            $query->where($lockColumn, $lockVersion);
        }
        $result = $query->delete();
        if ($lockColumn !== null && $result <= 0) {
            throw new OptimisticLockException('The record was changed, deleted, or is no longer accessible.');
        }

        if ($result > 0) {
            $this->setIsNewRecord(true);
            $this->oldAttributes = [];

            // Dispatch afterDelete event
            $afterDeleteEvent = new ModelAfterDeleteEvent($this, $result);
            $this->dispatchEvent($afterDeleteEvent);

            return true;
        }

        return false;
    }

    /**
     * Reload model from database.
     *
     * @return bool True on success, false on failure
     */
    public function refresh(): bool
    {
        if ($this->isNewRecord) {
            return false;
        }

        $pk = static::primaryKey();
        $condition = [];
        foreach ($pk as $key) {
            $attributeKey = $this->attributeKey($key, $this->attributes);
            if (!isset($this->attributes[$attributeKey])) {
                return false;
            }
            $condition[$key] = $this->attributes[$attributeKey];
        }

        $db = $this->getModelDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set.');
        }
        $query = static::on($db);
        $softDelete = $this->softDeleteBehavior();
        if ($softDelete !== null && $softDelete->isDeleted($this)) {
            $query->withoutGlobalScope(SoftDeleteBehavior::SCOPE);
        }
        $query->where($condition);
        $model = $query->one();
        if ($model !== null) {
            $this->attributes = $model->getAttributes();
            $this->oldAttributes = $model->getOldAttributes();
            return true;
        }

        return false;
    }

    /**
     * Validate model using rules.
     *
     * Override this method for custom validation logic.
     * This implementation uses rules() method to validate attributes.
     *
     * @return bool True if valid, false otherwise
     */
    public function validate(): bool
    {
        $this->validationErrors = [];

        $rules = static::rules();

        if (empty($rules)) {
            return true; // No rules = always valid
        }

        foreach ($rules as $rule) {
            if (!is_array($rule) || count($rule) < 2) {
                continue;
            }

            $attributes = (array) $rule[0];
            $validatorName = $rule[1];
            $params = array_slice($rule, 2);
            if (is_callable($validatorName)) {
                $params['callback'] = $validatorName;
                $validatorName = 'callback';
            }

            // Convert params array if needed (handle both associative and indexed)
            $paramsArray = [];
            foreach ($params as $key => $value) {
                if (is_int($key)) {
                    // Indexed param - could be a string key or just a value
                    if (is_string($value) && str_contains($value, '=')) {
                        // Try to parse as key=value
                        [$paramKey, $paramValue] = explode('=', $value, 2);
                        $paramsArray[$paramKey] = $paramValue;
                    } else {
                        // Just a value, use as-is or skip
                        continue;
                    }
                } else {
                    $paramsArray[$key] = $value;
                }
            }

            try {
                $validator = ValidatorFactory::create($validatorName);
            } catch (RuntimeException $e) {
                // Validator not found - skip this rule
                continue;
            }

            foreach ($attributes as $attribute) {
                $when = $paramsArray['when'] ?? null;
                if (is_callable($when) && $when($this, $attribute) !== true) {
                    continue;
                }

                // Check if attribute exists in model
                $value = $this->attributes[$attribute] ?? null;

                if (!$validator->validate($this, $attribute, $value, $paramsArray)) {
                    if (!isset($this->validationErrors[$attribute])) {
                        $this->validationErrors[$attribute] = [];
                    }
                    $this->validationErrors[$attribute][] = $validator->getMessage($this, $attribute, $paramsArray);
                }
            }
        }

        return empty($this->validationErrors);
    }

    /**
     * Get validation errors.
     *
     * @return array<string, array<int, string>> Validation errors (attribute => [messages])
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get validation errors for a specific attribute.
     *
     * @param string $attribute Attribute name
     *
     * @return array<int, string> Error messages for the attribute
     */
    public function getValidationErrorsForAttribute(string $attribute): array
    {
        return $this->validationErrors[$attribute] ?? [];
    }

    /**
     * Check if model has validation errors.
     *
     * @return bool True if has errors
     */
    public function hasValidationErrors(): bool
    {
        return !empty($this->validationErrors);
    }

    /**
     * Clear validation errors.
     */
    public function clearValidationErrors(): void
    {
        $this->validationErrors = [];
    }

    /**
     * Populate model from array.
     *
     * @param array<string, mixed> $data Data to populate
     *
     * @return static Self instance for chaining
     */
    public function populate(array $data): static
    {
        $this->attributes = array_merge($this->attributes, $data);
        return $this;
    }

    /**
     * Get model as array.
     *
     * @return array<string, mixed> Model attributes as array
     */
    public function toArray(): array
    {
        $data = $this->attributes;
        $visible = $this->visibleAttributes();
        if ($visible !== []) {
            $allowed = [];
            foreach ($visible as $name) {
                $key = $this->attributeKey($name, $data);
                if (array_key_exists($key, $data)) {
                    $allowed[$key] = $data[$key];
                }
            }
            $data = $allowed;
        }
        foreach ($this->hiddenAttributes() as $name) {
            unset($data[$this->attributeKey($name, $data)]);
        }
        foreach ($this->appendedAttributes() as $name => $value) {
            if ($visible !== [] && !in_array($name, $visible, true)) {
                continue;
            }
            $hidden = false;
            foreach ($this->hiddenAttributes() as $hiddenName) {
                if ($this->attributeKey($hiddenName, [$name => true]) === $name) {
                    $hidden = true;
                }
            }
            if (!$hidden) {
                $data[$name] = $value;
            }
        }

        return $data;
    }

    /**
     * Attribute names omitted from toArray().
     *
     * @return list<string>
     */
    public function hiddenAttributes(): array
    {
        return [];
    }

    /**
     * Attribute names included in toArray(). An empty list means every attribute
     * except those returned by hiddenAttributes().
     *
     * @return list<string>
     */
    public function visibleAttributes(): array
    {
        return [];
    }

    /**
     * Extra API fields merged into toArray().
     *
     * @return array<string, mixed>
     */
    protected function appendedAttributes(): array
    {
        return [];
    }

    /**
     * Queue work that must not run until the current transaction commits.
     *
     * Call this from an after-save event when the model operation is transactional.
     * Outside a transaction the callback runs immediately.
     *
     * @param callable(): mixed $callback
     */
    public function afterCommit(callable $callback): void
    {
        $db = $this->getModelDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
        }

        $db->afterCommit($callback);
    }

    /**
     * Validation errors in a shape that can be returned from an HTTP API.
     *
     * @return array{message: string, errors: array<string, array<int, string>>}
     */
    public function validationErrorPayload(): array
    {
        return [
            'message' => 'The given data was invalid.',
            'errors' => $this->validationErrors,
        ];
    }

    /**
     * Build an explicit API representation without loading missing relations.
     *
     * @param list<string> $fields
     * @param array<string, list<string>> $relations Relation names and their allowed fields
     *
     * @return array<string, mixed>
     */
    public function present(array $fields, array $relations = []): array
    {
        $result = [];
        foreach ($fields as $name) {
            if (!array_key_exists($this->attributeKey($name, $this->attributes), $this->attributes)) {
                throw new RuntimeException("Cannot present missing attribute '{$name}'.");
            }
            $value = $this->__get($name);
            $result[$name] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d\\TH:i:s.uP'),
                default => $value,
            };
        }
        foreach ($relations as $name => $relationFields) {
            if (!array_key_exists($name, $this->relationData)) {
                throw new RuntimeException("Relation '{$name}' must be eager-loaded before presentation.");
            }
            $related = $this->loadRelation($name);
            if ($related instanceof Model) {
                $result[$name] = $related->present($relationFields);
            } elseif (is_array($related)) {
                $result[$name] = array_map(static fn (Model $model): array => $model->present($relationFields), $related);
            } elseif ($related === null) {
                $result[$name] = null;
            } else {
                throw new RuntimeException("Invalid eager-loaded relation '{$name}'.");
            }
        }
        return $result;
    }

    /**
     * Define a has-one relationship.
     *
     * @param string $modelClass Related model class name
     * @param array<string, mixed> $config Relationship configuration
     *
     * @return HasOne Relationship instance
     */
    protected function hasOne(string $modelClass, array $config = []): HasOne
    {
        return new HasOne($modelClass, $config);
    }

    /**
     * Define a has-many relationship.
     *
     * @param string $modelClass Related model class name
     * @param array<string, mixed> $config Relationship configuration
     *
     * @return HasMany Relationship instance
     */
    protected function hasMany(string $modelClass, array $config = []): HasMany
    {
        return new HasMany($modelClass, $config);
    }

    /**
     * Define a belongs-to relationship.
     *
     * @param string $modelClass Related model class name
     * @param array<string, mixed> $config Relationship configuration
     *
     * @return BelongsTo Relationship instance
     */
    protected function belongsTo(string $modelClass, array $config = []): BelongsTo
    {
        return new BelongsTo($modelClass, $config);
    }

    /**
     * Define a has-many-through relationship.
     *
     * @param string $modelClass Related model class name
     * @param array<string, mixed> $config Relationship configuration
     *
     * @return HasManyThrough Relationship instance
     */
    protected function hasManyThrough(string $modelClass, array $config = []): HasManyThrough
    {
        return new HasManyThrough($modelClass, $config);
    }

    /**
     * Get relationship instance by name.
     *
     * @param string $name Relationship name
     *
     * @return RelationInterface|null Relationship instance or null if not found
     */
    public function getRelationInstance(string $name): ?RelationInterface
    {
        // Check if already cached
        if (isset($this->relations[$name])) {
            $relation = $this->relations[$name];
            if ($relation->getModel() === null) {
                $relation->setModel($this);
            }
            return $relation;
        }

        // Try to get from relations() method
        $relations = static::relations();
        if (!isset($relations[$name])) {
            return null;
        }

        $relationConfig = $relations[$name];
        if (!is_array($relationConfig) || count($relationConfig) < 1) {
            return null;
        }

        $relationType = $relationConfig[0];
        $relationModelClass = $relationConfig['modelClass'] ?? '';

        // Extract options (everything except first element and 'modelClass')
        $relationOptions = [];
        foreach ($relationConfig as $key => $value) {
            if ($key !== 0 && $key !== 'modelClass') {
                $relationOptions[$key] = $value;
            }
        }

        if (empty($relationModelClass)) {
            return null;
        }

        // Create relationship instance based on type
        $relation = match ($relationType) {
            'hasOne' => $this->hasOne($relationModelClass, $relationOptions),
            'hasMany' => $this->hasMany($relationModelClass, $relationOptions),
            'belongsTo' => $this->belongsTo($relationModelClass, $relationOptions),
            'hasManyThrough' => $this->hasManyThrough($relationModelClass, $relationOptions),
            default => null,
        };

        if ($relation !== null) {
            $relation->setModel($this);
            $this->relations[$name] = $relation;
        }

        return $relation;
    }

    /**
     * Load relationship data (lazy loading).
     *
     * @param string $name Relationship name
     *
     * @return mixed Related model(s) or null/empty array
     */
    protected function loadRelation(string $name): mixed
    {
        // Check if eager-loaded
        if (array_key_exists($name, $this->relationData)) {
            $relation = $this->getRelationInstance($name);
            if ($relation !== null) {
                return $relation->getEagerValue($this->relationData[$name]);
            }
        }

        // Lazy load
        if (static::lazyLoadingIsPrevented()) {
            throw new LazyRelationException(
                'Lazy loading of relation "' . $name . '" is disabled on ' . static::class . '. Eager load it first.'
            );
        }

        $relation = $this->getRelationInstance($name);
        if ($relation === null) {
            return null;
        }

        $traces = RelationTrace::begin($this, $name, 'lazy', $relation);

        try {
            return $relation->getValue();
        } finally {
            RelationTrace::end($traces);
        }
    }

    /**
     * Get relationship value (with lazy loading support).
     *
     * @param string $name Relationship name
     *
     * @return mixed Related model(s) or null/empty array
     */
    public function getRelation(string $name): mixed
    {
        return $this->loadRelation($name);
    }

    /**
     * Set eager-loaded relationship data.
     *
     * @param string $name Relationship name
     * @param mixed $data Eager-loaded data
     */
    public function setRelationData(string $name, mixed $data): void
    {
        $this->relationData[$name] = $data;
    }

    /**
     * Get all eager-loaded relationship data.
     *
     * @return array<string, mixed> Relationship data
     */
    public function getRelationData(): array
    {
        return $this->relationData;
    }

    /**
     * Clear all eager-loaded relationship data.
     */
    public function clearRelationData(): void
    {
        $this->relationData = [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function primaryKeyValuesForWhere(): array
    {
        $this->assertPrimaryKeyUnchanged();
        $values = [];
        foreach (static::primaryKey() as $key) {
            $oldKey = $this->attributeKey($key, $this->oldAttributes);
            if (!array_key_exists($oldKey, $this->oldAttributes)) {
                throw new RuntimeException("Primary key value '{$key}' is missing for this loaded record.");
            }
            $values[$key] = $this->oldAttributes[$oldKey];
        }

        return $values;
    }

    protected function assertPrimaryKeyUnchanged(): void
    {
        foreach (static::primaryKey() as $key) {
            $old = $this->oldAttributes[$this->attributeKey($key, $this->oldAttributes)] ?? null;
            $current = $this->attributes[$this->attributeKey($key, $this->attributes)] ?? null;
            if ($old !== $current) {
                throw new PrimaryKeyMutationException(
                    "Primary key column '{$key}' was changed in memory. Update or delete the original row explicitly."
                );
            }
        }
    }

    /** Return the loaded version and reject manually modified or invalid versions. */
    protected function originalLockVersion(): ?int
    {
        $column = static::optimisticLock();
        if ($column === null) {
            return null;
        }
        if ($column === '') {
            throw new OptimisticLockException('Optimistic lock column must not be empty.');
        }
        $oldKey = $this->attributeKey($column, $this->oldAttributes);
        $currentKey = $this->attributeKey($column, $this->attributes);
        if (!array_key_exists($oldKey, $this->oldAttributes) || !array_key_exists($currentKey, $this->attributes)) {
            throw new OptimisticLockException("Optimistic lock column '{$column}' must be selected.");
        }
        $oldValue = $this->oldAttributes[$oldKey];
        $currentValue = $this->attributes[$currentKey];
        $old = is_int($oldValue) || is_string($oldValue) ? filter_var($oldValue, FILTER_VALIDATE_INT) : false;
        $current = is_int($currentValue) || is_string($currentValue) ? filter_var($currentValue, FILTER_VALIDATE_INT) : false;
        if ($old === false || $old < 0 || $current === false || $current !== $old) {
            throw new OptimisticLockException("Optimistic lock column '{$column}' must contain the original non-negative integer version.");
        }
        return $old;
    }

    /** Apply the model's current global scopes to record operations. */
    protected function scopedRecordQuery(PdoDb $db, string $tableName): QueryBuilder
    {
        $query = $db->find()->table($tableName);
        $softDelete = $this->softDeleteBehavior();
        if ($this->writeWithoutSoftDeleteScope || ($softDelete !== null && $softDelete->isDeleted($this))) {
            $query->withoutGlobalScope(SoftDeleteBehavior::SCOPE);
        }
        foreach (static::resolvedGlobalScopes() as $scopeName => $scope) {
            if ($query->isGlobalScopeDisabled($scopeName)) {
                continue;
            }
            $query->isolatedScope($scope);
        }
        return $query;
    }

    /**
     * @param array<string, mixed> $pkValues
     * @param array<string, mixed> $dirty
     */
    protected function updateSucceeded(int $affectedRows, string $tableName, array $pkValues, array $dirty): bool
    {
        if ($affectedRows > 0) {
            return true;
        }

        $db = $this->getModelDb();
        if ($db === null) {
            return false;
        }

        $query = $this->scopedRecordQuery($db, $tableName);
        foreach ($pkValues as $column => $value) {
            $query->where($column, $value);
        }
        $row = $query->getOne();
        if (!is_array($row)) {
            return false;
        }

        foreach ($dirty as $column => $expected) {
            if ($expected instanceof RawValue) {
                continue;
            }
            if (!array_key_exists($column, $row)) {
                return false;
            }
            if ($row[$column] != $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param callable(): bool $callback
     */
    protected function runTransactional(string $operation, callable $callback): bool
    {
        if (!$this->operationIsTransactional($operation)) {
            return $callback();
        }

        $db = $this->getModelDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
        }

        $snapshot = $this->capturePersistenceState();
        $invoke = function () use ($db, $callback, $snapshot): bool {
            $db->beginAfterCommitScope();

            try {
                $result = $callback();
                if ($result === false) {
                    $db->rejectAfterCommitScope();
                    $this->restorePersistenceState($snapshot);

                    return false;
                }
                $db->acceptAfterCommitScope();
                $db->afterRollback(function () use ($snapshot): void {
                    $this->restorePersistenceState($snapshot);
                });

                return true;
            } catch (Throwable $exception) {
                $db->rejectAfterCommitScope();
                $this->restorePersistenceState($snapshot);

                throw $exception;
            }
        };

        $dialect = $db->connection->getDialect();
        if ($db->inTransaction() && !$dialect->supportsNestedTransactions()) {
            return $invoke();
        }

        try {
            $db->transaction(function () use ($invoke): void {
                if ($invoke() === false) {
                    throw new ModelWriteAbortedException('The model operation was aborted.');
                }
            });
        } catch (ModelWriteAbortedException) {
            return false;
        }

        return true;
    }

    protected function operationIsTransactional(string $operation): bool
    {
        $operations = static::transactional();

        return in_array(Model::OP_ALL, $operations, true) || in_array($operation, $operations, true);
    }

    /**
     * @return array{attributes: array<string, mixed>, oldAttributes: array<string, mixed>, isNewRecord: bool, validationErrors: array<string, array<int, string>>}
     */
    protected function capturePersistenceState(): array
    {
        return [
            'attributes' => $this->attributes,
            'oldAttributes' => $this->oldAttributes,
            'isNewRecord' => $this->isNewRecord,
            'validationErrors' => $this->validationErrors,
        ];
    }

    /**
     * @param array{attributes: array<string, mixed>, oldAttributes: array<string, mixed>, isNewRecord: bool, validationErrors: array<string, array<int, string>>} $state
     */
    protected function restorePersistenceState(array $state): void
    {
        $this->attributes = $state['attributes'];
        $this->oldAttributes = $state['oldAttributes'];
        $this->isNewRecord = $state['isNewRecord'];
        $this->validationErrors = $state['validationErrors'];
    }

    protected function applyBehaviorHook(string $hook): void
    {
        foreach (static::resolveBehaviors() as $behavior) {
            if ($hook === 'beforeInsert') {
                $behavior->beforeInsert($this);
                continue;
            }
            if ($hook === 'beforeUpdate') {
                $behavior->beforeUpdate($this);
            }
        }
    }

    protected function softDeleteBehavior(): ?SoftDeleteBehavior
    {
        return static::findSoftDeleteBehavior();
    }

    protected static function findSoftDeleteBehavior(): ?SoftDeleteBehavior
    {
        foreach (static::resolveBehaviors() as $behavior) {
            if ($behavior instanceof SoftDeleteBehavior) {
                return $behavior;
            }
        }

        return null;
    }

    protected function softDeleteRecord(SoftDeleteBehavior $softDelete): bool
    {
        $beforeDeleteEvent = new ModelBeforeDeleteEvent($this);
        $this->dispatchEvent($beforeDeleteEvent);
        if ($beforeDeleteEvent->isPropagationStopped()) {
            return false;
        }
        if ($softDelete->isDeleted($this)) {
            return true;
        }

        $this->setAttribute($softDelete->column(), $softDelete->deletionValue($this));
        if (!$this->update()) {
            return false;
        }

        $this->dispatchEvent(new ModelAfterDeleteEvent($this, 1));

        return true;
    }

    public function increment(string $column, int $by = 1): bool
    {
        return $this->changeColumn($column, $by, true);
    }

    public function decrement(string $column, int $by = 1): bool
    {
        return $this->changeColumn($column, $by, false);
    }

    protected function changeColumn(string $column, int $by, bool $increment): bool
    {
        if ($this->isNewRecord) {
            throw new RuntimeException('Cannot change a column on a new record.');
        }
        if ($by === 0) {
            return true;
        }

        return $this->runTransactional(Model::OP_UPDATE, function () use ($column, $by, $increment): bool {
            $db = $this->getModelDb();
            if ($db === null) {
                throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
            }

            $query = $this->scopedRecordQuery($db, static::tableName());
            $primaryKey = $this->primaryKeyValuesForWhere();
            foreach (static::primaryKey() as $key) {
                $query->where($key, $primaryKey[$key]);
            }

            $values = [$column => $increment ? Db::inc($by) : Db::dec($by)];
            $lockColumn = static::optimisticLock();
            $lockVersion = $this->originalLockVersion();
            if ($lockColumn !== null && $lockVersion !== null && $lockColumn !== $column) {
                $query->where($lockColumn, $lockVersion);
                $values[$lockColumn] = Db::inc(1);
            }
            $updated = $query->update($values);
            if ($lockColumn !== null && $updated <= 0) {
                throw new OptimisticLockException('The record was changed, deleted, or is no longer accessible.');
            }
            if ($updated <= 0) {
                return false;
            }

            $this->refreshColumn($column);
            if ($lockColumn !== null && $lockColumn !== $column) {
                $this->refreshColumn($lockColumn);
            }

            return true;
        });
    }

    /**
     * Replace attributes holding a database expression (such as the default timestamp) with the stored value.
     */
    protected function reloadExpressionAttributes(): void
    {
        $columns = [];
        foreach ($this->attributes as $key => $value) {
            if ($value instanceof RawValue) {
                $columns[] = (string) $key;
            }
        }
        if ($columns === []) {
            return;
        }

        $db = $this->getModelDb();
        $row = null;
        if ($db !== null) {
            $query = $db->find()->table(static::tableName());
            foreach ($this->primaryKeyValuesForWhere() as $key => $value) {
                $query->where($key, $value);
            }
            $row = $query->select($columns)->getOne();
        }
        $row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];
        foreach ($columns as $key) {
            $fresh = $row[strtolower($key)] ?? null;
            $this->attributes[$key] = $fresh;
            $this->oldAttributes[$key] = $fresh;
        }
    }

    protected function refreshColumn(string $column): void
    {
        $db = $this->getModelDb();
        if ($db === null) {
            return;
        }
        $query = $this->scopedRecordQuery($db, static::tableName());
        foreach ($this->primaryKeyValuesForWhere() as $key => $value) {
            $query->where($key, $value);
        }
        $fresh = $query->select([$column])->getValue();
        $key = $this->attributeKey($column, $this->attributes);
        $this->attributes[$key] = $fresh;
        $this->oldAttributes[$key] = $fresh;
    }

    /**
     * @param array<string, mixed> $search
     * @param array<string, mixed> $values
     */
    public static function firstOrCreate(array $search, array $values = []): static
    {
        static::upsertBySearch($search, $values, false);
        $model = static::findOne($search);
        if (!$model instanceof static) {
            throw new RuntimeException('firstOrCreate could not load the row after upsert.');
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $search
     * @param array<string, mixed> $values
     */
    public static function updateOrCreate(array $search, array $values = []): static
    {
        static::upsertBySearch($search, $values, true);
        $model = static::findOne($search);
        if (!$model instanceof static) {
            throw new RuntimeException('updateOrCreate could not load the row after upsert.');
        }

        return $model;
    }

    /**
     * Update every matching row without model events.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $condition
     */
    public static function updateAll(array $attributes, array $condition = []): int
    {
        $query = static::find();
        static::applyColumnCondition($query, $condition);

        return $query->update($attributes);
    }

    /**
     * Delete every matching row. Soft-delete models set the deletion timestamp instead.
     *
     * @param array<string, mixed> $condition
     */
    public static function deleteAll(array $condition = []): int
    {
        $query = static::find();
        static::applyColumnCondition($query, $condition);
        $softDelete = static::findSoftDeleteBehavior();
        if ($softDelete !== null) {
            $probe = new static();

            return $query->update([
                $softDelete->column() => $softDelete->deletionValue($probe),
            ]);
        }

        return $query->delete();
    }

    /**
     * Permanently delete every matching row, including soft-deleted rows.
     *
     * @param array<string, mixed> $condition
     */
    public static function forceDeleteAll(array $condition = []): int
    {
        $query = static::find()->withoutGlobalScope(SoftDeleteBehavior::SCOPE);
        static::applyColumnCondition($query, $condition);

        return $query->delete();
    }

    /**
     * @param array<string, mixed> $search
     * @param array<string, mixed> $values
     */
    protected static function upsertBySearch(array $search, array $values, bool $replaceValues): void
    {
        if ($search === []) {
            throw new RuntimeException('Search attributes are required.');
        }
        foreach (array_keys($search) as $column) {
            if (!is_string($column)) {
                throw new RuntimeException('Search keys must be column names.');
            }
        }

        $model = new static();
        $db = $model->getModelDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
        }
        foreach (array_merge($search, $values) as $column => $value) {
            if (!is_string($column)) {
                throw new RuntimeException('Value keys must be column names.');
            }
            $model->setAttribute($column, $value);
        }
        foreach (static::resolveBehaviors() as $behavior) {
            $behavior->beforeInsert($model);
            if ($replaceValues) {
                $behavior->beforeUpdate($model);
            }
        }

        $row = [];
        foreach ($model->getAttributes() as $column => $value) {
            $row[$column] = static::normalizeUpsertValue($value);
        }
        $updateColumns = [];
        if ($replaceValues) {
            foreach (array_keys($values) as $column) {
                if (!array_key_exists($column, $search)) {
                    $updateColumns[] = $column;
                }
            }
            foreach (static::resolveBehaviors() as $behavior) {
                if (!$behavior instanceof TimestampBehavior) {
                    continue;
                }
                $updatedAt = $behavior->updatedAtColumn();
                if ($updatedAt !== null && !array_key_exists($updatedAt, $search) && !in_array($updatedAt, $updateColumns, true)) {
                    $updateColumns[] = $updatedAt;
                }
            }
        }

        [$sql, $params] = $db->connection->getDialect()->buildIdempotentUpsertSql(
            static::tableName(),
            $row,
            array_keys($search),
            $updateColumns
        );
        $db->rawQuery($sql, $params);
    }

    protected static function normalizeUpsertValue(mixed $value): string|int|float|RawValue|null
    {
        if ($value instanceof RawValue) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }
        if ($value === null || is_string($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        throw new RuntimeException('Upsert values must be scalars.');
    }

    /**
     * @param ActiveQuery<static> $query
     * @param array<string, mixed> $condition
     */
    protected static function applyColumnCondition(ActiveQuery $query, array $condition): void
    {
        foreach ($condition as $column => $value) {
            if (!is_string($column)) {
                throw new RuntimeException('Conditions must be a map of column names to values.');
            }
            $query->where($column, $value);
        }
    }
}
