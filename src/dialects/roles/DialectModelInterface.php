<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;

/**
 * Support for ActiveRecord: attribute keys, value binding and cast validation.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectModelInterface
{
    /**
     * Resolve attribute key for ORM reads (e.g. case-insensitive Oracle column names).
     *
     * @param array<string, mixed> $attributes
     */
    public function resolveAttributeKeyForRead(string $name, array $attributes): string;

    /**
     * Whether DateTimeImmutable cast validation must run for this connection.
     */
    public function requiresDateTimeImmutableCastValidation(): bool;

    /**
     * Validate connection settings required for DateTimeImmutable casts.
     */
    public function validateDateTimeImmutableCastConnection(PdoDb $db): void;

    /**
     * Get columns for INSERT ... SELECT operation, excluding auto-increment/identity columns.
     *
     * @param string $tableName Table name
     * @param array<int, string>|null $columns Explicit column list (null = auto-detect)
     * @param ExecutionEngineInterface $executionEngine Execution engine for query execution
     *
     * @return array<int, string> Array of column names to use in INSERT
     */
    public function getInsertSelectColumns(string $tableName, ?array $columns, ExecutionEngineInterface $executionEngine): array;

    /**
     * Transform a value before it's bound as a parameter in INSERT/UPDATE operations.
     * Dialects can override this to handle special conversions (e.g., UUID format for Oracle RAW).
     *
     * @param mixed $value The value to transform
     * @param string $columnName The column name this value is for
     * @param string|null $table The table being written, when known
     *
     * @return mixed The transformed value, or original value if no transformation needed
     */
    public function transformValueForBinding(mixed $value, string $columnName, ?string $table = null): mixed;
}
