<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm;

use RuntimeException;
use tommyknocker\pdodb\helpers\Inflector;
use tommyknocker\pdodb\orm\behaviors\ModelBehavior;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Base Model class implementing ActiveRecord pattern.
 *
 * This class provides the foundation for ActiveRecord models:
 * - Static database connection management
 * - Table name and primary key configuration
 * - Static find methods (find, findOne, findAll)
 */
/**
 * @phpstan-consistent-constructor
 */
abstract class Model
{
    use ActiveRecord;

    public const string OP_INSERT = 'insert';

    public const string OP_UPDATE = 'update';

    public const string OP_DELETE = 'delete';

    public const string OP_ALL = '*';

    /** @var PdoDb|null Static PdoDb instance */
    protected static ?PdoDb $db = null;

    /** @var array<class-string<Model>, PdoDb|null> Explicit connections, including a null override. */
    protected static array $modelConnections = [];

    /** @var array<class-string, bool> Lazy-loading policy per model class */
    protected static array $preventLazyLoading = [];

    /**
     * Enable or disable lazy-loading detection for this model class.
     */
    public static function preventLazyLoading(bool $prevent = true): void
    {
        self::$preventLazyLoading[static::class] = $prevent;
    }

    public static function lazyLoadingIsPrevented(): bool
    {
        return self::$preventLazyLoading[static::class] ?? false;
    }

    /** @var array<class-string, bool> Whether mass assignment throws for attributes it would discard, per model class */
    protected static array $preventDiscardingAttributes = [];

    /**
     * Throw a MassAssignmentException when setAttributes() would silently discard attributes that are not safe
     * (off by default; handy in development to notice a misspelled or forgotten attribute).
     */
    public static function preventSilentlyDiscardingAttributes(bool $prevent = true): void
    {
        self::$preventDiscardingAttributes[static::class] = $prevent;
    }

    public static function discardingAttributesIsPrevented(): bool
    {
        return self::$preventDiscardingAttributes[static::class] ?? false;
    }

    /**
     * Get database connection.
     *
     * @return PdoDb|null Database connection instance
     */
    public static function getDb(): ?PdoDb
    {
        $class = static::class;
        while (is_a($class, self::class, true)) {
            if (array_key_exists($class, self::$modelConnections)) {
                return self::$modelConnections[$class];
            }
            $parent = get_parent_class($class);
            if ($parent === false) {
                break;
            }
            $class = $parent;
        }
        return static::$db;
    }

    /**
     * Set database connection.
     *
     * @param PdoDb|null $db Database connection instance
     */
    public static function setDb(?PdoDb $db): void
    {
        self::$modelConnections[static::class] = $db;
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
        return Inflector::tableize(Inflector::classBasename(static::class));
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

    /** Return the integer version column to enable optimistic locking, or null to disable it. */
    public static function optimisticLock(): ?string
    {
        return null;
    }

    /**
     * Operations wrapped in a transaction: insert, update, delete, or ['*'].
     *
     * The default is empty, so save() and delete() do not start a transaction.
     *
     * @return list<string>
     */
    public static function transactional(): array
    {
        return [];
    }

    /**
     * Whether a null primary key is omitted so the database can generate it.
     *
     * Return false when the key is assigned in PHP (UUID, ULID, or a natural key).
     */
    public static function incrementing(): bool
    {
        return true;
    }

    /**
     * Opt-in behaviors such as timestamps, soft deletes, and generated primary keys.
     *
     * Values may be a behavior class name, a configured class (`ClassName::class => []`),
     * or a behavior instance.
     *
     * @return array<int|class-string<ModelBehavior>, class-string<ModelBehavior>|ModelBehavior|array<string, mixed>>
     */
    public static function behaviors(): array
    {
        return [];
    }

    /**
     * @return list<ModelBehavior>
     */
    public static function resolveBehaviors(): array
    {
        $resolved = [];
        foreach (static::behaviors() as $key => $value) {
            if (is_int($key) && is_string($value) && is_a($value, ModelBehavior::class, true)) {
                $resolved[] = new $value();
                continue;
            }
            if (is_int($key) && $value instanceof ModelBehavior) {
                $resolved[] = $value;
                continue;
            }
            if (is_string($key) && is_array($value) && is_a($key, ModelBehavior::class, true)) {
                $resolved[] = new $key($value);
                continue;
            }

            throw new RuntimeException('Invalid behavior definition on ' . static::class . '.');
        }

        return $resolved;
    }

    /**
     * Global scopes from the model and from behaviors.
     *
     * A scope declared on the model replaces a behavior scope with the same name.
     *
     * @return array<string, callable(QueryBuilder, mixed...): QueryBuilder>
     */
    public static function resolvedGlobalScopes(): array
    {
        $scopes = static::globalScopes();
        foreach (static::resolveBehaviors() as $behavior) {
            foreach ($behavior->globalScopes() as $name => $scope) {
                if (!array_key_exists($name, $scopes)) {
                    $scopes[$name] = $scope;
                }
            }
        }

        return $scopes;
    }

    /** @return array<string, string> Attribute name to cast (json, DateTimeImmutable, or backed enum). */
    public static function casts(): array
    {
        return [];
    }

    /** Ensure Oracle does not silently discard fractional seconds in model casts. */
    public static function validateCastConnection(PdoDb $db): void
    {
        if (!in_array(\DateTimeImmutable::class, static::casts(), true)) {
            return;
        }
        $dialect = $db->connection->getDialect();
        if (!$dialect->requiresDateTimeImmutableCastValidation()) {
            return;
        }
        $dialect->validateDateTimeImmutableCastConnection($db);
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
     * - 'number': Attribute must be numeric (supports 'min', 'max')
     * - 'in': Value must be in 'range'
     * - 'date': Date string or DateTimeInterface (optional 'format')
     * - 'regex': Value must match 'pattern'
     * - 'unique': No other row has this value
     * - 'exist' / 'exists': A related row exists ('targetClass' or 'table')
     * - 'callback': Callable in 'callback', or use a closure as the validator
     * Optional 'when' callable skips the rule when it returns false.
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
     * Attributes that are validated by rules() but must never be set by mass assignment (`setAttributes()`), for
     * example `is_admin` or an owner id. Use `forceFill()` or `setAttribute()` to set them deliberately.
     *
     * @return array<int, string>
     */
    public static function unsafeAttributes(): array
    {
        return [];
    }

    /**
     * Get relationship definitions.
     *
     * Defines ActiveRecord relationships for lazy and eager loading.
     * Supports hasOne, hasMany, belongsTo, and hasManyThrough relationships.
     *
     * Format: [
     *   'relationName' => ['hasOne', 'modelClass' => RelatedModel::class, 'foreignKey' => '...', 'localKey' => '...'],
     *   'relationName' => ['hasMany', 'modelClass' => RelatedModel::class, 'foreignKey' => '...', 'localKey' => '...'],
     *   'relationName' => ['belongsTo', 'modelClass' => RelatedModel::class, 'foreignKey' => '...', 'ownerKey' => '...'],
     *   'relationName' => ['hasManyThrough', 'modelClass' => RelatedModel::class, 'viaTable' => '...', 'link' => [...], 'viaLink' => [...]],
     * ]
     *
     * Override this method to define relationships for the model.
     *
     * @return array<string, array<int|string, mixed>> Relationship definitions
     *
     * @example
     * // User has one Profile
     * public static function relations(): array
     * {
     *     return [
     *         'profile' => [
     *             'hasOne',
     *             'modelClass' => Profile::class,
     *             'foreignKey' => 'user_id',
     *             'localKey' => 'id'
     *         ]
     *     ];
     * }
     * @example
     * // User has many Posts
     * public static function relations(): array
     * {
     *     return [
     *         'posts' => [
     *             'hasMany',
     *             'modelClass' => Post::class,
     *             'foreignKey' => 'user_id',
     *             'localKey' => 'id'
     *         ]
     *     ];
     * }
     * @example
     * // Post belongs to User
     * public static function relations(): array
     * {
     *     return [
     *         'user' => [
     *             'belongsTo',
     *             'modelClass' => User::class,
     *             'foreignKey' => 'user_id',
     *             'ownerKey' => 'id'
     *         ]
     *     ];
     * }
     * @example
     * // Many-to-many via junction table
     * public static function relations(): array
     * {
     *     return [
     *         'projects' => [
     *             'hasManyThrough',
     *             'modelClass' => Project::class,
     *             'viaTable' => 'user_project',
     *             'link' => ['id' => 'user_id'],
     *             'viaLink' => ['project_id' => 'id']
     *         ]
     *     ];
     * }
     *
     * @note Relationships can be accessed as properties (lazy loading) or via with() (eager loading).
     *       Use eager loading to prevent N+1 query problems.
     *
     * @warning Foreign key and local key parameters are required for all relationship types.
     *
     * @see Model::with() For eager loading relationships
     * @see documentation/05-advanced-features/17-active-record-relationships.md
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
     * @return array<string, callable(QueryBuilder, mixed...): QueryBuilder> Global scope definitions
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
     * @return array<string, callable(QueryBuilder, mixed...): QueryBuilder> Local scope definitions
     */
    public static function scopes(): array
    {
        return [];
    }

    /**
     * Find records (returns ActiveQuery).
     *
     * @return ActiveQuery<static> Query builder instance
     */
    public static function find(): ActiveQuery
    {
        return new ActiveQuery(static::class);
    }

    /**
     * Create a query bound to a specific database without changing static settings.
     *
     * @return ActiveQuery<static>
     */
    public static function on(PdoDb $db): ActiveQuery
    {
        return new ActiveQuery(static::class, $db);
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
                    $query->where($key, $value);
                }
                /** @var static|null $result */
                $result = $query->one();
                return $result;
            }

            // Indexed array - treat as composite primary key values
            if (count($pk) === count($condition)) {
                $query = static::find();
                foreach ($pk as $i => $key) {
                    $query->where($key, $condition[$i] ?? null);
                }
                /** @var static|null $result */
                $result = $query->one();
                return $result;
            }
        }

        // Single value with single primary key
        if (count($pk) === 1) {
            /** @var ActiveQuery<static> $query */
            $query = static::find();
            $query->where($pk[0], $condition);
            /** @var static|null $result */
            $result = $query->one();
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
                $query->where($key, $value);
            }
        } else {
            $query->where($condition);
        }
        /** @var array<int, static> $result */
        $result = $query->all();
        return $result;
    }
}
