<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp;

use tommyknocker\pdodb\ai\AgentContextService;
use tommyknocker\pdodb\ai\AgentDocumentation;
use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\mcp\tools\AnalyzeQueryTool;
use tommyknocker\pdodb\ai\mcp\tools\CallbackTool;
use tommyknocker\pdodb\ai\mcp\tools\ExplainPlanTool;
use tommyknocker\pdodb\ai\mcp\tools\GetSchemaTool;
use tommyknocker\pdodb\ai\mcp\tools\InspectQueryTool;
use tommyknocker\pdodb\ai\mcp\tools\McpToolInterface;
use tommyknocker\pdodb\ai\mcp\tools\SuggestIndexesTool;
use tommyknocker\pdodb\cli\TableManager;
use tommyknocker\pdodb\PdoDb;

/**
 * MCP (Model Context Protocol) server for database analysis.
 */
class McpServer
{
    private const array PROTOCOL_VERSIONS = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];
    protected PdoDb $db;
    protected AiAnalysisService $aiService;
    /** @var array<string, McpToolInterface> */
    protected array $tools = [];

    public function __construct(PdoDb $db, ?AiAnalysisService $aiService = null)
    {
        $this->db = $db;
        $this->aiService = $aiService ?? new AiAnalysisService($db);
        $this->registerTools();
    }

    /**
     * Register MCP tools.
     */
    protected function registerTools(): void
    {
        $getSchemaTool = new GetSchemaTool($this->db);
        $analyzeQueryTool = new AnalyzeQueryTool($this->db, $this->aiService);
        $suggestIndexesTool = new SuggestIndexesTool($this->db, $this->aiService);
        $explainPlanTool = new ExplainPlanTool($this->db, $this->aiService);

        $context = new AgentContextService($this->db);
        $contextTool = new CallbackTool(
            'get_context',
            'Get PDOdb/PHP/database versions, dialect capabilities and result contracts. No credentials or LLM key required.',
            ['type' => 'object', 'properties' => []],
            static fn (array $args): array => $context->context()
        );
        $docsTool = new CallbackTool(
            'search_docs',
            'Search documentation bundled with this installed PDOdb version. Returns bounded excerpts and source paths, without network access.',
            ['type' => 'object', 'required' => ['query'], 'properties' => [
                'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            ]],
            static fn (array $args): array => (new AgentDocumentation())->search($args['query'], $args['limit'] ?? 5)
        );
        $schemaTool = new CallbackTool(
            'search_schema',
            'Find table names without fetching full schemas. Use get_schema with a selected table for details.',
            ['type' => 'object', 'properties' => [
                'query' => ['type' => 'string', 'maxLength' => 300],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'offset' => ['type' => 'integer', 'minimum' => 0],
            ]],
            static fn (array $args): array => $context->searchSchema($args['query'] ?? '', $args['limit'] ?? 20, $args['offset'] ?? 0)
        );
        $inspectTool = new InspectQueryTool($this->db);
        $this->tools = [
            'get_context' => $contextTool,
            'search_docs' => $docsTool,
            'search_schema' => $schemaTool,
            'inspect_query' => $inspectTool,
            $getSchemaTool->getName() => $getSchemaTool,
            $analyzeQueryTool->getName() => $analyzeQueryTool,
            $suggestIndexesTool->getName() => $suggestIndexesTool,
            $explainPlanTool->getName() => $explainPlanTool,
        ];
    }

    /**
     * Handle MCP request.
     *
     * @param array<string, mixed> $request MCP request
     *
     * @return array<string, mixed>|null MCP response; null for notifications
     */
    public function handleRequest(array $request): ?array
    {
        $id = $request['id'] ?? null;
        $notification = !array_key_exists('id', $request);
        if (($request['jsonrpc'] ?? null) !== '2.0' || !is_string($request['method'] ?? null)
            || (!is_int($id) && !is_string($id) && $id !== null)) {
            return $this->error(null, -32600, 'Invalid JSON-RPC request');
        }
        $method = $request['method'];
        if ($notification) {
            // JSON-RPC notifications, including initialized/cancelled, never receive responses.
            return null;
        }
        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            return $this->error($id, -32602, 'params must be an object');
        }

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($params),
                'ping' => (object)[],
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolCall($params),
                'resources/list' => $this->handleResourcesList(),
                'resources/read' => $this->handleResourceRead($params),
                'prompts/list' => $this->handlePromptsList(),
                'prompts/get' => $this->handlePromptGet($params),
                default => null,
            };
            if ($result === null) {
                return $this->error($id, -32601, 'Method not found');
            }
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (\InvalidArgumentException $e) {
            return $this->error($id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error($id, -32603, 'Request failed; check server configuration and database permissions');
        }
    }

    /** @return array<string, mixed> */
    protected function error(int|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * Handle initialize request.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return array<string, mixed> Initialize result
     */
    protected function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => in_array($params['protocolVersion'] ?? '', self::PROTOCOL_VERSIONS, true)
                ? $params['protocolVersion'] : '2025-11-25',
            'capabilities' => [
                'tools' => (object)[],
                'resources' => (object)[],
                'prompts' => (object)[],
            ],
            'serverInfo' => [
                'name' => 'pdodb-mcp',
                'version' => '1.1.0',
            ],
        ];
    }

    /**
     * Handle tools/list request.
     *
     * @return array<string, mixed> Tools list
     */
    protected function handleToolsList(): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $this->schemaForWire($tool->getInputSchema()),
                'annotations' => [
                    'readOnlyHint' => in_array($tool->getName(), ['get_context', 'search_docs', 'search_schema', 'get_schema'], true),
                    'openWorldHint' => in_array($tool->getName(), ['analyze_query', 'explain_plan', 'suggest_indexes'], true),
                ],
            ];
        }

        return [
            'tools' => $tools,
        ];
    }

    /**
     * Handle tools/call request.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return array<string, mixed> Tool call result
     */
    protected function handleToolCall(array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || !isset($this->tools[$name]) || !is_array($arguments)) {
            throw new \InvalidArgumentException('Unknown tool or invalid arguments');
        }
        $tool = $this->tools[$name];

        try {
            $this->validateArguments($tool->getInputSchema(), $arguments);
            $result = $tool->execute($arguments);
            $text = is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (strlen($text) > 131072) {
                throw new \InvalidArgumentException('Result exceeds 128 KiB; select a specific table or narrow the query');
            }
            $response = ['content' => [['type' => 'text', 'text' => $text]]];
            if (is_array($result)) {
                $response['structuredContent'] = (object)$result;
                if (isset($result['error'])) {
                    $response['isError'] = true;
                }
            }
            return $response;
        } catch (\InvalidArgumentException $e) {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => $e->getMessage()]]];
        } catch (\Throwable $e) {
            return ['isError' => true, 'content' => [['type' => 'text',
                'text' => 'Tool execution failed; check database permissions, SQL parameters and provider configuration.']]];
        }
    }

    /** @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function schemaForWire(array $schema): array
    {
        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = (object)[];
        }
        return $schema;
    }

    /** Validate the JSON Schema subset used by built-in tools.
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $arguments
     */
    protected function validateArguments(array $schema, array $arguments): void
    {
        foreach ($schema['required'] ?? [] as $required) {
            if (!array_key_exists($required, $arguments)) {
                throw new \InvalidArgumentException('Missing required argument: ' . $required);
            }
        }
        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (!array_key_exists($name, $arguments)) {
                continue;
            }
            $value = $arguments[$name];
            $types = (array)($property['type'] ?? []);
            $valid = false;
            foreach ($types as $type) {
                $valid = $valid || match ($type) {
                    'string' => is_string($value),
                    'integer' => is_int($value),
                    'array', 'object' => is_array($value),
                    'boolean' => is_bool($value),
                    'number' => is_numeric($value),
                    default => false,
                };
            }
            if (!$valid || isset($property['enum']) && !in_array($value, $property['enum'], true)) {
                throw new \InvalidArgumentException('Invalid argument: ' . $name);
            }
            if (is_string($value) && (mb_strlen($value) < ($property['minLength'] ?? 0)
                || mb_strlen($value) > ($property['maxLength'] ?? 50000))) {
                throw new \InvalidArgumentException('Invalid argument length: ' . $name);
            }
            if (is_int($value) && ($value < ($property['minimum'] ?? PHP_INT_MIN)
                || $value > ($property['maximum'] ?? PHP_INT_MAX))) {
                throw new \InvalidArgumentException('Argument out of range: ' . $name);
            }
            if ($name === 'params') {
                foreach ($value as $parameter) {
                    if (!is_scalar($parameter) && $parameter !== null) {
                        throw new \InvalidArgumentException('SQL parameters must be scalar or null');
                    }
                }
            }
        }
    }

    /**
     * Handle resources/list request.
     *
     * @return array<string, mixed> Resources list
     */
    protected function handleResourcesList(): array
    {
        $tables = TableManager::listTables($this->db);
        $resources = [];

        foreach ($tables as $table) {
            $resources[] = [
                'uri' => "table://{$table}",
                'name' => "Table: {$table}",
                'description' => "Schema information for table {$table}",
                'mimeType' => 'application/json',
            ];
        }

        return [
            'resources' => $resources,
        ];
    }

    /**
     * Handle resources/read request.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return array<string, mixed> Resource content
     */
    protected function handleResourceRead(array $params): array
    {
        $uri = $params['uri'] ?? '';
        if (!str_starts_with($uri, 'table://')) {
            throw new \RuntimeException("Unknown resource URI: {$uri}");
        }

        $tableName = substr($uri, 8);
        $columns = $this->db->describe($tableName);
        $indexes = $this->db->schema()->getIndexes($tableName);
        $foreignKeys = $this->db->schema()->getForeignKeys($tableName);

        $schema = [
            'table' => $tableName,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
        ];

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'application/json',
                    'text' => json_encode($schema, JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }

    /**
     * Handle prompts/list request.
     *
     * @return array<string, mixed> Prompts list
     */
    protected function handlePromptsList(): array
    {
        return [
            'prompts' => [
                [
                    'name' => 'analyze_query',
                    'description' => 'Analyze a SQL query for performance issues',
                    'arguments' => [['name' => 'sql', 'required' => true]],
                ],
                [
                    'name' => 'optimize_schema',
                    'description' => 'Get optimization suggestions for database schema',
                    'arguments' => [['name' => 'table', 'required' => true]],
                ],
            ],
        ];
    }

    /**
     * Handle prompts/get request.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return array<string, mixed> Prompt content
     */
    protected function handlePromptGet(array $params): array
    {
        $name = $params['name'] ?? '';
        $argument = match ($name) {
            'analyze_query' => 'sql',
            'optimize_schema' => 'table',
            default => throw new \InvalidArgumentException('Unknown prompt'),
        };
        $value = $params['arguments'][$argument] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > 50000) {
            throw new \InvalidArgumentException('Missing or invalid prompt argument: ' . $argument);
        }
        $text = $name === 'analyze_query'
            ? 'Inspect this SQL query and its schema before recommending optimizations: '
            : 'Inspect the schema and existing indexes before recommending changes to table: ';
        return ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text . $value]]]];
    }

    /**
     * Run MCP server (read from stdin, write to stdout).
     */
    public function run(): void
    {
        while (($line = fgets(STDIN, 1048578)) !== false) {
            if (strlen($line) > 1048576) {
                while (!str_ends_with($line, "\n") && ($line = fgets(STDIN, 1048578)) !== false) {
                }
                $response = $this->error(null, -32600, 'Request exceeds 1 MiB');
            } else {
                try {
                    $decoded = json_decode($line, false, 64, JSON_THROW_ON_ERROR);
                    $request = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                    $response = is_object($decoded) && is_array($request)
                        ? $this->handleRequest($request) : $this->error(null, -32600, 'Invalid request');
                } catch (\JsonException) {
                    $response = $this->error(null, -32700, 'Parse error');
                }
            }
            if ($response !== null) {
                echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                flush();
            }
        }
    }
}
