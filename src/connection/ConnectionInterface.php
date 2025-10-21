<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use PDO;
use PDOStatement;
use tommyknocker\pdodb\dialects\DialectInterface;

/**
 * ConnectionInterface
 *
 * One logical connection (one PDO) abstraction.
 */
interface ConnectionInterface
{
    /**
     * Get the PDO instance
     * @return PDO
     */
    public function getPdo(): PDO;

    /**
     * Get the driver name (e.g., 'mysql', 'pgsql', 'sqlite')
     * @return string
     */
    public function getDriverName(): string;

    /**
     * Get the dialect instance
     * @return DialectInterface
     */
    public function getDialect(): DialectInterface;

    /**
     * Reset the internal state (error, query, etc.)
     * @return void
     */
    public function resetState(): void;
    
    /**
     * Prepare a SQL statement
     * @param string $sql
     * @param array<int|string, string|int|float|bool|null> $params
     * @return static
     */
    public function prepare(string $sql, array $params = []): static;

    /**
     * Execute the prepared statement
     * @param array<int|string, string|int|float|bool|null> $params
     * @return PDOStatement
     */
    public function execute(array $params = []): PDOStatement;

    /**
     * Execute a SQL query
     * @param string $sql
     * @return PDOStatement|false
     */
    public function query(string $sql): PDOStatement|false;

    /**
     * Quote a value for use in a SQL statement
     * @param mixed $value
     * @return string|false
     */
    public function quote(mixed $value): string|false;

    /**
     * Start a transaction
     * @return bool
     */
    public function transaction(): bool;

    /**
     * Commit the transaction
     * @return bool
     */
    public function commit(): bool;

    /**
     * Roll back the transaction
     * @return bool
     */
    public function rollBack(): bool;

    /**
     * Check if inside a transaction
     * @return bool
     */
    public function inTransaction(): bool;
    
    /**
     * Get the last insert ID
     * @param string|null $name
     * @return false|string
     */
    public function getLastInsertId(?string $name = null): false|string;
    
    /**
     * Get the last executed query
     * @return string|null
     */
    public function getLastQuery(): ?string;

    /**
     * Get the last error message
     * @return string|null
     */
    public function getLastError(): ?string;

    /**
     * Get the last error code
     * @return int
     */
    public function getLastErrno(): int;
    
    /**
     * Get the last execute state
     * @return bool|null
     */
    public function getExecuteState(): ?bool;
}
