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
    public function getPdo(): PDO;

    public function getDriverName(): string;

    public function getDialect(): DialectInterface;

    public function resetState(): void;
    
    public function prepare(string $sql, array $params = []): static;

    public function execute(array $params = []): PDOStatement;

    public function query(string $sql): PDOStatement|false;

    public function quote(mixed $value): string|false;

    public function transaction(): bool;

    public function commit(): bool;

    public function rollBack(): bool;

    public function inTransaction(): bool;
    public function getLastInsertId(?string $name = null): false|string;
    public function getLastQuery(): ?string;

    public function getLastError(): ?string;

    public function getLastErrno(): int;
}
