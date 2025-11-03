<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm;

use tommyknocker\pdodb\PdoDb;

/**
 * Base Model class implementing ActiveRecord pattern.
 *
 * This class provides the foundation for ActiveRecord models:
 * - Static database connection management
 * - Table name and primary key configuration
 * - Static find methods (find, findOne, findAll)
 */
abstract class Model
{
    use ActiveRecord;

    /** @var PdoDb|null Static PdoDb instance */
    protected static ?PdoDb $db = null;

    /**
     * Get database connection.
     *
     * @return PdoDb|null Database connection instance
     */
    public static function getDb(): ?PdoDb
    {
        return static::$db;
    }

    /**
     * Set database connection.
     *
     * @param PdoDb|null $db Database connection instance
     */
    public static function setDb(?PdoDb $db): void
    {
        static::$db = $db;
    }

    /**
     * Get table name (auto-detect from class name).
     *
     * Override this method to specify a custom table name.
     *
     * @return string Table name
     */
    public static function tableName(): string
    {
        $className = static::class;
        $className = substr($className, strrpos($className, '\\') + 1);
        // Convert CamelCase to snake_case and pluralize
        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className) ?: $className);
        return $snakeCase . 's';
    }

    /**
     * Get primary key column(s).
     *
     * Override this method to specify composite or custom primary keys.
     *
     * @return array<string> Primary key column names
     */
    public static function primaryKey(): array
    {
        return ['id'];
    }

    /**
     * Get validation rules.
     *
     * Format: [
     *   [['attribute1', 'attribute2'], 'validator', 'param1' => 'value1', ...],
     *   ['attribute', 'validator', 'param1' => 'value1', ...],
     * ]
     *
     * Built-in validators:
     * - 'required': Attribute must not be empty
     * - 'email': Attribute must be a valid email address
     * - 'integer': Attribute must be an integer (supports 'min', 'max' params)
     * - 'string': Attribute must be a string (supports 'min', 'max', 'length' params)
     *
     * Override this method to define validation rules for the model.
     *
     * @return array<int, array<int|string, mixed>> Validation rules
     */
    public static function rules(): array
    {
        return [];
    }

    /**
     * Get relationship definitions.
     *
     * Format: [
     *   'relationName' => ['hasOne', 'modelClass' => RelatedModel::class, 'foreignKey' => '...', 'localKey' => '...'],
     *   'relationName' => ['hasMany', 'modelClass' => RelatedModel::class, 'foreignKey' => '...', 'localKey' => '...'],
     *   'relationName' => ['belongsTo', 'modelClass' => RelatedModel::class, 'foreignKey' => '...', 'ownerKey' => '...'],
     * ]
     *
     * Override this method to define relationships for the model.
     *
     * @return array<string, array<int|string, mixed>> Relationship definitions
     */
    public static function relations(): array
    {
        return [];
    }

    /**
     * Get global scopes that are automatically applied to all queries.
     *
     * Global scopes are applied automatically when using Model::find().
     * They cannot be disabled except by using withoutGlobalScope().
     *
     * Format: [
     *   'scopeName' => function($query) { ... return $query; },
     * ]
     *
     * Override this method to define global scopes for the model.
     *
     * @return array<string, callable(\tommyknocker\pdodb\query\QueryBuilder, mixed...): \tommyknocker\pdodb\query\QueryBuilder> Global scope definitions
     */
    public static function globalScopes(): array
    {
        return [];
    }

    /**
     * Get local scopes that can be applied on-demand.
     *
     * Local scopes are applied only when explicitly called via scope() method.
     *
     * Format: [
     *   'scopeName' => function($query, ...$args) { ... return $query; },
     * ]
     *
     * Override this method to define local scopes for the model.
     *
     * @return array<string, callable(\tommyknocker\pdodb\query\QueryBuilder, mixed...): \tommyknocker\pdodb\query\QueryBuilder> Local scope definitions
     */
    public static function scopes(): array
    {
        return [];
    }

    /**
     * Find records (returns ActiveQuery).
     *
     * @return ActiveQuery Query builder instance
     */
    public static function find(): ActiveQuery
    {
        return new ActiveQuery(static::class);
    }

    /**
     * Find one record by primary key or condition.
     *
     * @param mixed $condition Primary key value, array of primary key values, or condition array
     *
     * @return static|null Model instance or null if not found
     */
    public static function findOne(mixed $condition): ?static
    {
        $pk = static::primaryKey();

        // If condition is an array, check if it's an associative array (condition) or indexed array (composite key)
        if (is_array($condition)) {
            // Empty array - return null without querying
            if (empty($condition)) {
                return null;
            }

            // Check if it's an associative array (has string keys) - treat as condition
            if (array_keys($condition) !== range(0, count($condition) - 1)) {
                // Associative array - treat as condition
                $query = static::find();
                foreach ($condition as $key => $value) {
                    $query->where($key, $value); // @phpstan-ignore-line
                }
                /** @var static|null $result */
                $result = $query->one();
                return $result;
            }

            // Indexed array - treat as composite primary key values
            if (count($pk) === count($condition)) {
                $query = static::find();
                foreach ($pk as $i => $key) {
                    $query->where($key, $condition[$i] ?? null); // @phpstan-ignore-line
                }
                /** @var static|null $result */
                $result = $query->one();
                return $result;
            }
        }

        // Single value with single primary key
        if (count($pk) === 1) {
            /** @var static|null $result */
            $result = static::find()->where($pk[0], $condition)->one(); // @phpstan-ignore-line
            return $result;
        }

        return null;
    }

    /**
     * Find all records matching condition.
     * Returns array of model instances.
     *
     * @param array<string, mixed>|string $condition Condition array or raw WHERE clause
     *
     * @return array<int, static> Array of model instances
     */
    public static function findAll(array|string $condition): array
    {
        $query = static::find();
        if (is_array($condition)) {
            foreach ($condition as $key => $value) {
                $query->where($key, $value); // @phpstan-ignore-line
            }
        } else {
            $query->where($condition); // @phpstan-ignore-line
        }
        /** @var array<int, static> $result */
        $result = $query->all();
        return $result;
    }
}
