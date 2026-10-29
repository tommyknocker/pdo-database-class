<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp;

use Closure;
use RuntimeException;
use tommyknocker\pdodb\PdoDb;

/** Scoped server-side statement limits, restoring the caller's session after failures as well. */
class McpExecutionGuard
{
    public function __construct(private PdoDb $db, private int $milliseconds)
    {
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $driver = $this->db->connection->getDialect()->getDriverName();
        return ['configured_ms' => $this->milliseconds, 'supported' => in_array($driver, ['pgsql', 'mariadb'], true),
            'scope' => 'per_statement', 'restored_after_request' => true,
            'note' => 'Only PostgreSQL and MariaDB have a statement limit in this adapter. It is not a whole-request or LLM timeout.'];
    }

    /** @template T
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function run(Closure $operation): mixed
    {
        if ($this->milliseconds === 0) {
            return $operation();
        }
        $driver = $this->db->connection->getDialect()->getDriverName();
        if (!in_array($driver, ['pgsql', 'mariadb'], true)) {
            throw new \InvalidArgumentException('Configured MCP statement timeout is unsupported by this adapter; use 0 or configure limits directly in the database');
        }
        $pdo = $this->db->connection->getPdo();
        if ($pdo->inTransaction()) {
            throw new \InvalidArgumentException('MCP statement timeouts require a connection outside an active transaction');
        }
        $read = $pdo->query($driver === 'pgsql'
            ? "SELECT setting FROM pg_settings WHERE name = 'statement_timeout'" : 'SELECT @@SESSION.max_statement_time');
        if ($read === false) {
            throw new RuntimeException('Unable to read the current statement timeout');
        }
        $previous = $read->fetchColumn();
        $read->closeCursor();
        if (!is_scalar($previous) || is_bool($previous)) {
            throw new RuntimeException('Unable to read the current statement timeout');
        }
        $set = static function (string $value) use ($pdo, $driver): void {
            if ($driver === 'mariadb') {
                // MariaDB rejects a string-bound prepared parameter for this numeric session variable.
                if (!is_numeric($value)) {
                    throw new RuntimeException('Invalid saved statement timeout');
                }
                $pdo->exec('SET SESSION max_statement_time = ' . sprintf('%.6F', (float)$value));
                return;
            }
            $statement = $pdo->prepare("SELECT set_config('statement_timeout', ?, false)");
            $statement->execute([$value]);
            $statement->closeCursor();
        };
        $previousMs = (float)$previous * ($driver === 'pgsql' ? 1 : 1000);
        $effective = $previousMs > 0 ? min($previousMs, $this->milliseconds) : $this->milliseconds;
        $set($driver === 'pgsql' ? (string)(int)$effective : sprintf('%.6F', $effective / 1000));

        try {
            return $operation();
        } finally {
            $set((string)$previous);
        }
    }
}
