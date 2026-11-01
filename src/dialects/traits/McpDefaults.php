<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\dialects\roles\DialectMcpInterface;
use tommyknocker\pdodb\exceptions\UnsupportedOperationException;
use tommyknocker\pdodb\PdoDb;

/**
 * Default implementation of the MCP statement timeouts part of the dialect ({@see DialectMcpInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait McpDefaults
{
    /**
     * {@inheritDoc}
     */
    public function supportsMcpStatementTimeout(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function readMcpStatementTimeout(PdoDb $db): string
    {
        throw new UnsupportedOperationException('MCP statement timeout is not supported for ' . $this->getDriverName());
    }

    /**
     * {@inheritDoc}
     */
    public function setMcpStatementTimeout(PdoDb $db, int $milliseconds): void
    {
        throw new UnsupportedOperationException('MCP statement timeout is not supported for ' . $this->getDriverName());
    }

    /**
     * {@inheritDoc}
     */
    public function restoreMcpStatementTimeout(PdoDb $db, string $previousValue): void
    {
        throw new UnsupportedOperationException('MCP statement timeout is not supported for ' . $this->getDriverName());
    }

    /**
     * {@inheritDoc}
     */
    public function mcpStatementTimeoutStoredInMilliseconds(): bool
    {
        return true;
    }
}
