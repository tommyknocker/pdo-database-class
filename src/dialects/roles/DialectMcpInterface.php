<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\PdoDb;

/**
 * Statement timeouts used by the MCP server.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectMcpInterface
{
    public function supportsMcpStatementTimeout(): bool;

    /**
     * Read the session statement timeout; return value is driver-specific (ms for PostgreSQL, seconds for MariaDB).
     */
    public function readMcpStatementTimeout(PdoDb $db): string;

    /**
     * Apply a statement timeout for the current session (milliseconds configured at MCP layer).
     */
    public function setMcpStatementTimeout(PdoDb $db, int $milliseconds): void;

    /**
     * Restore a previously read session statement timeout value.
     */
    public function restoreMcpStatementTimeout(PdoDb $db, string $previousValue): void;

    /**
     * Whether {@see readMcpStatementTimeout()} values are stored in milliseconds (PostgreSQL) or seconds (MariaDB).
     */
    public function mcpStatementTimeoutStoredInMilliseconds(): bool;
}
