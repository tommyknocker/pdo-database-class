<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp;

use InvalidArgumentException;

/** Explicit MCP policy. Empty allowlists deny all; null preserves unrestricted visibility. */
class McpConfig
{
    public readonly ?string $connection;
    /** @var list<string>|null */
    public readonly ?array $allowedTables;
    /** @var list<string>|null */
    public readonly ?array $allowedSchemas;
    public readonly bool $queryAnalysisEnabled;
    public readonly bool $externalAiEnabled;
    public readonly int $statementTimeoutMs;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $known = ['connection', 'allowed_tables', 'allowed_schemas', 'query_analysis_enabled', 'external_ai_enabled', 'statement_timeout_ms'];
        if (array_diff(array_keys($options), $known) !== []) {
            throw new InvalidArgumentException('Unknown MCP configuration option');
        }
        $this->connection = $options['connection'] ?? null;
        if ($this->connection !== null && (trim($this->connection) === '' || strlen($this->connection) > 200)) {
            throw new InvalidArgumentException('Invalid MCP connection alias');
        }
        $this->allowedTables = $this->allowlist($options['allowed_tables'] ?? null, true);
        $this->allowedSchemas = $this->allowlist($options['allowed_schemas'] ?? null, false);
        foreach (['query_analysis_enabled', 'external_ai_enabled'] as $key) {
            if (array_key_exists($key, $options) && !is_bool($options[$key])) {
                throw new InvalidArgumentException('MCP ' . $key . ' must be a boolean');
            }
        }
        $this->queryAnalysisEnabled = !$this->restricted() && ($options['query_analysis_enabled'] ?? true);
        $this->externalAiEnabled = !$this->restricted() && ($options['external_ai_enabled'] ?? true);
        $timeout = $options['statement_timeout_ms'] ?? 0;
        if (!is_int($timeout) || $timeout < 0 || $timeout > 300000) {
            throw new InvalidArgumentException('MCP statement_timeout_ms must be an integer between 0 and 300000');
        }
        $this->statementTimeoutMs = $timeout;
    }

    public function restricted(): bool
    {
        return $this->allowedTables !== null || $this->allowedSchemas !== null;
    }

    public static function assertIdentifier(string $identifier, bool $qualified = false): void
    {
        $part = '[\p{L}_][\p{L}\p{N}_$]*';
        $pattern = $qualified ? '/^' . $part . '(?:\.' . $part . ')?$/uD' : '/^' . $part . '$/uD';
        if (strlen($identifier) > 256 || preg_match($pattern, $identifier) !== 1) {
            throw new InvalidArgumentException('MCP metadata identifiers must be simple names, optionally schema-qualified');
        }
    }

    /** @return list<string>|null */
    private function allowlist(mixed $values, bool $qualified): ?array
    {
        if ($values === null) {
            return null;
        }
        if (!is_array($values) || !array_is_list($values)) {
            throw new InvalidArgumentException('MCP allowlists must be lists of identifier strings');
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('MCP allowlists must contain identifier strings');
            }
            self::assertIdentifier($value, $qualified);
        }
        return array_values(array_unique($values));
    }

    public function allowsSchema(string $schema): bool
    {
        return $this->allowedSchemas === null || in_array($schema, $this->allowedSchemas, true);
    }

    public function allowsTable(string $schema, string $table, string $defaultSchema): bool
    {
        return $this->allowsSchema($schema) && ($this->allowedTables === null
            || in_array($schema . '.' . $table, $this->allowedTables, true)
            || $schema === $defaultSchema && in_array($table, $this->allowedTables, true));
    }

    public function allowsTool(string $name): bool
    {
        if ($this->restricted()) {
            return in_array($name, ['get_context', 'search_docs', 'search_schema', 'get_schema'], true);
        }
        if (in_array($name, ['inspect_query', 'analyze_query', 'explain_plan'], true) && !$this->queryAnalysisEnabled) {
            return false;
        }
        return !in_array($name, ['analyze_query', 'explain_plan', 'suggest_indexes'], true) || $this->externalAiEnabled;
    }

    public function allowsAnyTableInSchema(string $schema, string $defaultSchema): bool
    {
        if (!$this->allowsSchema($schema) || $this->allowedTables === null) {
            return $this->allowsSchema($schema);
        }
        foreach ($this->allowedTables as $table) {
            if (str_starts_with($table, $schema . '.') || !str_contains($table, '.') && $schema === $defaultSchema) {
                return true;
            }
        }
        return false;
    }

    public static function fromProject(): self
    {
        $path = getenv('PDODB_MCP_CONFIG_PATH') ?: getcwd() . '/config/mcp.php';
        if (getenv('PDODB_MCP_CONFIG_PATH') !== false && !is_file($path)) {
            throw new InvalidArgumentException('The selected MCP configuration file does not exist');
        }
        $options = is_file($path) ? require $path : [];
        if (!is_array($options)) {
            throw new InvalidArgumentException('MCP configuration must return an array');
        }
        foreach (['ALLOWED_TABLES', 'ALLOWED_SCHEMAS', 'CONNECTION', 'STATEMENT_TIMEOUT_MS', 'QUERY_ANALYSIS_ENABLED', 'EXTERNAL_AI_ENABLED'] as $suffix) {
            $value = getenv('PDODB_MCP_' . $suffix);
            if ($value === false) {
                continue;
            }
            $key = strtolower($suffix);
            if (str_starts_with($suffix, 'ALLOWED_')) {
                $decoded = json_decode($value, false, 16, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new InvalidArgumentException('MCP environment allowlists must be JSON arrays');
                }
                $options[$key] = $decoded;
            } elseif ($suffix === 'STATEMENT_TIMEOUT_MS') {
                if (!ctype_digit($value)) {
                    throw new InvalidArgumentException('MCP timeout must contain integer milliseconds');
                }
                $options[$key] = (int)$value;
            } elseif (str_ends_with($suffix, '_ENABLED')) {
                $options[$key] = match (strtolower($value)) {
                    'true', '1' => true,
                    'false', '0' => false,
                    default => throw new InvalidArgumentException('MCP enabled flags must be true/false or 1/0'),
                };
            } else {
                $options[$key] = $value;
            }
        }
        return new self($options);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return ['connection' => $this->connection, 'allowed_tables' => $this->allowedTables,
            'allowed_schemas' => $this->allowedSchemas, 'metadata_only' => $this->restricted(),
            'query_analysis_enabled' => $this->queryAnalysisEnabled, 'external_ai_enabled' => $this->externalAiEnabled,
            'statement_timeout_ms' => $this->statementTimeoutMs];
    }
}
