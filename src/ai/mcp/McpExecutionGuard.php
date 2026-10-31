<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp;

use Closure;
use InvalidArgumentException;
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
        $dialect = $this->db->connection->getDialect();
        return [
            'configured_ms' => $this->milliseconds,
            'supported' => $dialect->supportsMcpStatementTimeout(),
            'scope' => 'per_statement',
            'restored_after_request' => true,
            'note' => 'Only PostgreSQL and MariaDB have a statement limit in this adapter. It is not a whole-request or LLM timeout.',
        ];
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
        $dialect = $this->db->connection->getDialect();
        if (!$dialect->supportsMcpStatementTimeout()) {
            throw new InvalidArgumentException('Configured MCP statement timeout is unsupported by this adapter; use 0 or configure limits directly in the database');
        }
        $pdo = $this->db->connection->getPdo();
        if ($pdo->inTransaction()) {
            throw new InvalidArgumentException('MCP statement timeouts require a connection outside an active transaction');
        }
        $previous = $dialect->readMcpStatementTimeout($this->db);
        $previousMs = $dialect->mcpStatementTimeoutStoredInMilliseconds()
            ? (float)$previous
            : (float)$previous * 1000;
        $effective = $previousMs > 0 ? min($previousMs, $this->milliseconds) : $this->milliseconds;
        $dialect->setMcpStatementTimeout($this->db, (int)$effective);

        try {
            return $operation();
        } finally {
            $dialect->restoreMcpStatementTimeout($this->db, $previous);
        }
    }
}
