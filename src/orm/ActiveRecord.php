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

    /**
     * Get attribute value.
     *
     * @param string $name Attribute name
     *
     * @return mixed Attribute value or null if not set
     */
    public function __get(string $name): mixed
    {
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
    protected function setIsNewRecord(bool $isNewRecord): void
    {
        $this->isNewRecord = $isNewRecord;
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
            // Access oldAttributes via reflection since it's protected
            $reflection = new \ReflectionClass($model);
            $oldAttributesProp = $reflection->getProperty('oldAttributes');
            $oldAttributesProp->setAccessible(true);
            $this->oldAttributes = $oldAttributesProp->getValue($model);
            return true;
        }

        return false;
    }

    /**
     * Validate model (override in subclasses).
     *
     * @return bool True if valid, false otherwise
     */
    public function validate(): bool
    {
        return true; // Default: no validation
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
}
