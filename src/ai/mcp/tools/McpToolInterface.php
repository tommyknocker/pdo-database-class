<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp\tools;

/**
 * Interface for MCP tools.
 */
interface McpToolInterface
{
    /**
     * Get tool name.
     */
    public function getName(): string;

    /**
     * Get tool description.
     */
    public function getDescription(): string;

    /**
     * Get input schema (JSON Schema).
     *
     * @return array<string, mixed> JSON Schema
     */
    public function getInputSchema(): array;

    /**
     * Execute tool with arguments.
     *
     * @param array<string, mixed> $arguments Tool arguments
     *
     * @return string|array<string, mixed> Tool result
     */
    public function execute(array $arguments): string|array;
}
