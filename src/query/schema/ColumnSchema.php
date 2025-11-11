<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\schema;

/**
 * Column schema builder for fluent DDL API.
 *
 * This class provides a fluent interface for defining column schemas,
 * similar to Yii2's ColumnSchemaBuilder.
 */
class ColumnSchema
{
    /** @var string Column type (e.g., 'VARCHAR', 'INT', 'TEXT') */
    protected string $type = '';

    /** @var int|null Length or precision */
    protected ?int $length = null;

    /** @var int|null Scale for decimal types */
    protected ?int $scale = null;

    /** @var bool Whether column is NOT NULL */
    protected bool $notNull = false;

    /** @var mixed Default value */
    protected mixed $defaultValue = null;

    /** @var bool Whether default is an expression (not a literal value) */
    protected bool $defaultIsExpression = false;

    /** @var string|null Column comment */
    protected ?string $comment = null;

    /** @var bool Whether column is UNSIGNED (MySQL/MariaDB) */
    protected bool $unsigned = false;

    /** @var bool Whether column is AUTO_INCREMENT */
    protected bool $autoIncrement = false;

    /** @var bool Whether column is UNIQUE */
    protected bool $unique = false;

    /** @var string|null Column to place this column after (MySQL/MariaDB) */
    protected ?string $after = null;

    /** @var bool Whether to place column first (MySQL/MariaDB) */
    protected bool $first = false;

    /**
     * Create new column schema.
     *
     * @param string $type Column type
     * @param int|null $length Length or precision
     * @param int|null $scale Scale for decimal types
     */
    public function __construct(string $type, ?int $length = null, ?int $scale = null)
    {
        $this->type = $type;
        $this->length = $length;
        $this->scale = $scale;
    }

    /**
     * Set column as NOT NULL.
     *
     * @return static
     */
    public function notNull(): static
    {
        $this->notNull = true;
        return $this;
    }

    /**
     * Set column as NULL (default).
     *
     * @return static
     */
    public function null(): static
    {
        $this->notNull = false;
        return $this;
    }

    /**
     * Set default value.
     *
     * @param mixed $value Default value
     *
     * @return static
     *
     * @example
     * // Literal default value
     * $db->schema()->createTable('users', [
     *     'status' => $db->schema()->string(20)->defaultValue('active')
     * ]);
     * @example
     * // Expression default (use defaultExpression instead)
     * $db->schema()->createTable('posts', [
     *     'created_at' => $db->schema()->timestamp()->defaultExpression('CURRENT_TIMESTAMP')
     * ]);
     *
     * @warning For SQL expressions (like CURRENT_TIMESTAMP), use defaultExpression() instead.
     *          This method treats the value as a literal string/number.
     *
     * @see ColumnSchema::defaultExpression() For SQL expression defaults
     * @see documentation/03-query-builder/12-ddl-operations.md#column-schema-methods
     */
    public function defaultValue(mixed $value): static
    {
        $this->defaultValue = $value;
        $this->defaultIsExpression = false;
        return $this;
    }

    /**
     * Set default expression (e.g., 'CURRENT_TIMESTAMP').
     *
     * @param string $expression SQL expression
     *
     * @return static
     */
    public function defaultExpression(string $expression): static
    {
        $this->defaultValue = $expression;
        $this->defaultIsExpression = true;
        return $this;
    }

    /**
     * Set column comment.
     *
     * @param string $comment Comment text
     *
     * @return static
     */
    public function comment(string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Set column as UNSIGNED (MySQL/MariaDB).
     *
     * @return static
     */
    public function unsigned(): static
    {
        $this->unsigned = true;
        return $this;
    }

    /**
     * Set column as AUTO_INCREMENT.
     *
     * @return static
     */
    public function autoIncrement(): static
    {
        $this->autoIncrement = true;
        return $this;
    }

    /**
     * Set column as UNIQUE.
     *
     * @return static
     */
    public function unique(): static
    {
        $this->unique = true;
        return $this;
    }

    /**
     * Place column after another column (MySQL/MariaDB).
     *
     * @param string $column Column name
     *
     * @return static
     */
    public function after(string $column): static
    {
        $this->after = $column;
        $this->first = false;
        return $this;
    }

    /**
     * Place column first (MySQL/MariaDB).
     *
     * @return static
     */
    public function first(): static
    {
        $this->first = true;
        $this->after = null;
        return $this;
    }

    /**
     * Get column type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get length.
     *
     * @return int|null
     */
    public function getLength(): ?int
    {
        return $this->length;
    }

    /**
     * Get scale.
     *
     * @return int|null
     */
    public function getScale(): ?int
    {
        return $this->scale;
    }

    /**
     * Check if column is NOT NULL.
     *
     * @return bool
     */
    public function isNotNull(): bool
    {
        return $this->notNull;
    }

    /**
     * Get default value.
     *
     * @return mixed
     */
    public function getDefaultValue(): mixed
    {
        return $this->defaultValue;
    }

    /**
     * Check if default is an expression.
     *
     * @return bool
     */
    public function isDefaultExpression(): bool
    {
        return $this->defaultIsExpression;
    }

    /**
     * Get comment.
     *
     * @return string|null
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Check if column is UNSIGNED.
     *
     * @return bool
     */
    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }

    /**
     * Check if column is AUTO_INCREMENT.
     *
     * @return bool
     */
    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    /**
     * Check if column is UNIQUE.
     *
     * @return bool
     */
    public function isUnique(): bool
    {
        return $this->unique;
    }

    /**
     * Get 'after' column name.
     *
     * @return string|null
     */
    public function getAfter(): ?string
    {
        return $this->after;
    }

    /**
     * Check if column should be placed first.
     *
     * @return bool
     */
    public function isFirst(): bool
    {
        return $this->first;
    }

    /**
     * Set column type.
     *
     * @param string $type Column type
     *
     * @return static
     */
    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Set length.
     *
     * @param int|null $length Length or precision
     *
     * @return static
     */
    public function setLength(?int $length): static
    {
        $this->length = $length;
        return $this;
    }
}
