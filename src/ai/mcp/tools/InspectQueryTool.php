<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp\tools;

use InvalidArgumentException;
use tommyknocker\pdodb\ai\QueryInspectionService;
use tommyknocker\pdodb\PdoDb;

/** Collects a real estimated plan without an additional model or EXPLAIN ANALYZE. */
class InspectQueryTool implements McpToolInterface
{
    public function __construct(protected PdoDb $db)
    {
    }

    public function getName(): string
    {
        return 'inspect_query';
    }

    public function getDescription(): string
    {
        return 'Inspect one SELECT/WITH query using estimated EXPLAIN without an LLM API key. Parameters are never echoed. Plans may contain SQL literals. Oracle uses PLAN_TABLE; MSSQL reports unavailable.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'required' => ['sql'], 'properties' => [
            'sql' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50000],
            'params' => ['type' => ['array', 'object'], 'description' => 'Positional or named parameter values'],
            'table' => ['type' => 'string'],
        ]];
    }

    public function execute(array $arguments): string|array
    {
        $sql = rtrim(trim($arguments['sql'] ?? ''), ';');
        // Intentionally conservative: this is an EXPLAIN tool, not an SQL execution gateway.
        if (preg_match('/^(SELECT|WITH)\b/i', $sql) !== 1 || str_contains($sql, ';')) {
            throw new InvalidArgumentException('Provide a single SELECT/WITH statement without internal semicolons');
        }
        $inspection = (new QueryInspectionService($this->db))->inspect($sql, $arguments['params'] ?? [], $arguments['table'] ?? null);
        return ['source' => $inspection['source'], 'analysis' => QueryInspectionService::format($inspection['analysis']),
            'warnings' => $inspection['warnings'], 'measurements' => false];
    }
}
