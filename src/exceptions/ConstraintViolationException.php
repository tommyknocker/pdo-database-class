<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

use PDOException;

/**
 * Constraint violation exceptions.
 * 
 * Thrown when database constraints are violated,
 * such as unique key violations, foreign key violations, etc.
 */
class ConstraintViolationException extends DatabaseException
{
    protected ?string $constraintName = null;
    protected ?string $tableName = null;
    protected ?string $columnName = null;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = '',
        int|string $code = 0,
        ?PDOException $previous = null,
        string $driver = 'unknown',
        ?string $query = null,
        array $context = [],
        ?string $constraintName = null,
        ?string $tableName = null,
        ?string $columnName = null
    ) {
        parent::__construct($message, $code, $previous, $driver, $query, $context);
        
        $this->constraintName = $constraintName;
        $this->tableName = $tableName;
        $this->columnName = $columnName;
    }

    public function getCategory(): string
    {
        return 'constraint';
    }

    public function isRetryable(): bool
    {
        // Constraint violations are not retryable
        return false;
    }

    public function getConstraintName(): ?string
    {
        return $this->constraintName;
    }

    public function getTableName(): ?string
    {
        return $this->tableName;
    }

    public function getColumnName(): ?string
    {
        return $this->columnName;
    }

    public function getDescription(): string
    {
        $description = parent::getDescription();
        
        if ($this->constraintName) {
            $description .= " (Constraint: {$this->constraintName})";
        }
        
        if ($this->tableName) {
            $description .= " (Table: {$this->tableName})";
        }
        
        if ($this->columnName) {
            $description .= " (Column: {$this->columnName})";
        }
        
        return $description;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['constraint_name'] = $this->constraintName;
        $data['table_name'] = $this->tableName;
        $data['column_name'] = $this->columnName;
        
        return $data;
    }
}
