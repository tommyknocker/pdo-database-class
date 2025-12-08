<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp;

use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\mcp\tools\AnalyzeQueryTool;
use tommyknocker\pdodb\ai\mcp\tools\ExplainPlanTool;
use tommyknocker\pdodb\ai\mcp\tools\GetSchemaTool;
use tommyknocker\pdodb\ai\mcp\tools\McpToolInterface;
use tommyknocker\pdodb\ai\mcp\tools\SuggestIndexesTool;
use tommyknocker\pdodb\cli\TableManager;
use tommyknocker\pdodb\PdoDb;

/**
 * MCP (Model Context Protocol) server for database analysis.
 */
class McpServer
{
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

        $this->tools = [
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
     * @return array<string, mixed> MCP response
     */
    public function handleRequest(array $request): array
    {
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($params),
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolCall($params),
                'resources/list' => $this->handleResourcesList(),
                'resources/read' => $this->handleResourceRead($params),
                'prompts/list' => $this->handlePromptsList(),
                'prompts/get' => $this->handlePromptGet($params),
                default => throw new \RuntimeException("Unknown method: {$method}"),
            };

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32603,
                    'message' => $e->getMessage(),
                ],
            ];
        }
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
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => [],
                'resources' => [],
                'prompts' => [],
            ],
            'serverInfo' => [
                'name' => 'pdodb-mcp',
                'version' => '1.0.0',
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
                'inputSchema' => $tool->getInputSchema(),
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
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                $result = $tool->execute($arguments);
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT),
                        ],
                    ],
                ];
            }
        }

        throw new \RuntimeException("Tool not found: {$name}");
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
                ],
                [
                    'name' => 'optimize_schema',
                    'description' => 'Get optimization suggestions for database schema',
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

        return match ($name) {
            'analyze_query' => [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Analyze the following SQL query and provide optimization recommendations: {{sql}}',
                            ],
                        ],
                    ],
                ],
            ],
            'optimize_schema' => [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Analyze the database schema and provide optimization recommendations for table: {{table}}',
                            ],
                        ],
                    ],
                ],
            ],
            default => throw new \RuntimeException("Unknown prompt: {$name}"),
        };
    }

    /**
     * Run MCP server (read from stdin, write to stdout).
     */
    public function run(): void
    {
        while (($line = fgets(STDIN)) !== false) {
            $request = json_decode(trim($line), true);
            if ($request === null) {
                continue;
            }

            $response = $this->handleRequest($request);
            echo json_encode($response, JSON_UNESCAPED_SLASHES) . "\n";
            flush();
        }
    }
}
