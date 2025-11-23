<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm;

use RuntimeException;
use tommyknocker\pdodb\events\ModelAfterDeleteEvent;
use tommyknocker\pdodb\events\ModelAfterInsertEvent;
use tommyknocker\pdodb\events\ModelAfterSaveEvent;
use tommyknocker\pdodb\events\ModelAfterUpdateEvent;
use tommyknocker\pdodb\events\ModelBeforeDeleteEvent;
use tommyknocker\pdodb\events\ModelBeforeInsertEvent;
use tommyknocker\pdodb\events\ModelBeforeSaveEvent;
use tommyknocker\pdodb\events\ModelBeforeUpdateEvent;
use tommyknocker\pdodb\orm\relations\BelongsTo;
use tommyknocker\pdodb\orm\relations\HasMany;
use tommyknocker\pdodb\orm\relations\HasManyThrough;
use tommyknocker\pdodb\orm\relations\HasOne;
use tommyknocker\pdodb\orm\relations\RelationInterface;
use tommyknocker\pdodb\orm\validators\ValidatorFactory;

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

    /**
     * Get attribute value.
     *
     * @param string $name Attribute name
     *
     * @return mixed Attribute value or null if not set
     */
    public function __get(string $name): mixed
    {
        // Check if it's a relationship (try to load it)
        $relation = $this->getRelationInstance($name);
        if ($relation !== null) {
            return $this->loadRelation($name);
        }

        return $this->attributes[$name] ?? null;
    }

    /**
     * Set attribute value.
     *
     * @param string $name Attribute name
     * @param mixed $value Attribute value
     */
    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
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
        return isset($this->attributes[$name]);
    }

    /**
     * Unset attribute.
     *
     * @param string $name Attribute name
     */
    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
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
        if ($safeOnly && method_exists(static::class, 'safeAttributes')) {
            $safe = static::safeAttributes();
            $values = array_intersect_key($values, array_flip($safe));
        }

        foreach ($values as $name => $value) {
            $this->attributes[$name] = $value;
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
        $db = static::getDb();
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

        // Dispatch beforeSave event
        $beforeSaveEvent = new ModelBeforeSaveEvent($this, $this->isNewRecord);
        $this->dispatchEvent($beforeSaveEvent);

        // Check if event propagation was stopped
        if ($beforeSaveEvent->isPropagationStopped()) {
            return false;
        }

        $wasNewRecord = $this->isNewRecord;
        $success = false;

        if ($this->isNewRecord) {
            $success = $this->insert();
        } else {
            $success = $this->update();
        }

        if ($success) {
            // Dispatch afterSave event
            $afterSaveEvent = new ModelAfterSaveEvent($this, $wasNewRecord);
            $this->dispatchEvent($afterSaveEvent);
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
        // Dispatch beforeInsert event
        $beforeInsertEvent = new ModelBeforeInsertEvent($this);
        $this->dispatchEvent($beforeInsertEvent);

        // Check if event propagation was stopped
        if ($beforeInsertEvent->isPropagationStopped()) {
            return false;
        }

        $db = static::getDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
        }

        $tableName = static::tableName();
        $attributes = $this->getAttributes();

        // Remove primary key if auto-increment
        $pk = static::primaryKey();
        $pkValue = $attributes[$pk[0]] ?? null;
        if (count($pk) === 1 && $pkValue === null) {
            // Auto-increment assumed, remove from insert
            unset($attributes[$pk[0]]);
        }

        $result = $db->find()->table($tableName)->insert($attributes);

        if ($result > 0) {
            $insertId = null;

            // Set primary key if auto-increment
            if (count($pk) === 1 && (!isset($this->attributes[$pk[0]]) || ($this->attributes[$pk[0]] ?? null) === null)) {
                // Get connection from QueryBuilder to access getLastInsertId
                $queryBuilder = $db->find();
                $connection = $queryBuilder->getConnection();
                $lastId = $connection->getLastInsertId();
                if ($lastId !== false && $lastId !== '0') {
                    $insertId = is_numeric($lastId) ? (int)$lastId : $lastId;
                    $this->attributes[$pk[0]] = $insertId;
                }
            } else {
                $insertId = $this->attributes[$pk[0]] ?? null;
            }

            $this->oldAttributes = $this->attributes;
            $this->setIsNewRecord(false);

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
        $db = static::getDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
        }

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
        $query = $db->find()->table($tableName);

        // Build WHERE condition from primary key
        foreach ($pk as $key) {
            if (!isset($this->attributes[$key])) {
                throw new RuntimeException("Primary key value '{$key}' is missing for update operation.");
            }
            $query->where($key, $this->attributes[$key]);
        }

        // Remove primary key from update data
        foreach ($pk as $key) {
            unset($dirty[$key]);
        }

        if (empty($dirty)) {
            return true; // Only primary key changed, nothing to update
        }

        $result = $query->update($dirty);

        if ($result > 0) {
            $this->oldAttributes = $this->attributes;

            // Dispatch afterUpdate event
            $afterUpdateEvent = new ModelAfterUpdateEvent($this, $dirty, $result);
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

        // Dispatch beforeDelete event
        $beforeDeleteEvent = new ModelBeforeDeleteEvent($this);
        $this->dispatchEvent($beforeDeleteEvent);

        // Check if event propagation was stopped
        if ($beforeDeleteEvent->isPropagationStopped()) {
            return false;
        }

        $db = static::getDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
        }

        $pk = static::primaryKey();
        $tableName = static::tableName();
        $query = $db->find()->table($tableName);

        // Build WHERE condition from primary key
        foreach ($pk as $key) {
            if (!isset($this->attributes[$key])) {
                throw new RuntimeException("Primary key value '{$key}' is missing for delete operation.");
            }
            $query->where($key, $this->attributes[$key]);
        }

        $result = $query->delete();

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
            if (!isset($this->attributes[$key])) {
                return false;
            }
            $condition[$key] = $this->attributes[$key];
        }

        $model = static::findOne($condition);
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
        return $this->attributes;
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
        if (isset($this->relationData[$name])) {
            $relation = $this->getRelationInstance($name);
            if ($relation !== null) {
                return $relation->getEagerValue($this->relationData[$name]);
            }
        }

        // Lazy load
        $relation = $this->getRelationInstance($name);
        if ($relation === null) {
            return null;
        }

        return $relation->getValue();
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
}
