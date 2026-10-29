<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\ai\mcp\McpConfig;
use tommyknocker\pdodb\ai\mcp\McpServer;
use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\PdoDb;

/** MCP launch configuration preview and stdio transport. */
class McpCommand extends Command
{
    public function __construct()
    {
        parent::__construct('mcp', 'Run the MCP server or preview its launch configuration');
    }

    public function execute(): int
    {
        $action = $this->getArgument(0);
        if ($action === 'config') {
            $script = dirname(__DIR__, 3) . '/bin/pdodb-mcp';
            echo json_encode(
                ['mcpServers' => ['pdodb' => ['command' => PHP_BINARY, 'args' => [$script, '--project=' . getcwd()]]]],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
            return 0;
        }
        if ($action === 'serve' || $action === 'doctor') {
            $bufferLevel = ob_get_level();
            ob_start();

            try {
                $project = $this->getOption('project');
                if ($project !== null && (!is_string($project) || !is_dir($project) || !chdir($project))) {
                    throw new \InvalidArgumentException('MCP project directory is unavailable');
                }
                static::loadEnvFile();
                $config = McpConfig::fromProject();
                $db = $this->diagnosticDatabase($config);
                $server = new McpServer($db, config: $config);
            } catch (\Throwable) {
                fwrite(STDERR, "Unable to start PDOdb MCP; check project directory, database configuration and permissions.\n");
                return 1;
            } finally {
                while (ob_get_level() > $bufferLevel) {
                    ob_end_clean();
                }
            }
            if ($action === 'doctor') {
                $response = $server->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_context']]);
                $context = (array)($response['result']['structuredContent'] ?? []);
                $ready = !isset($response['error']) && !($response['result']['isError'] ?? false)
                    && ($config->statementTimeoutMs === 0 || ($context['statement_timeout']['supported'] ?? false));
                if ($ready) {
                    $metadata = $server->handleRequest(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list']);
                    $ready = !isset($metadata['error']);
                }
                $context['metadata_accessible'] = $ready;
                echo json_encode(['ready' => $ready, 'context' => $context], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
                return $ready ? 0 : 1;
            }
            $server->run();
            return 0;
        }
        echo "Usage: pdodb mcp config | doctor | serve\n";
        echo "config prints a launch preview without writing files or embedding credentials.\n";
        echo "Run the server in the project directory containing your .env or config/db.php.\n";
        return 0;
    }

    private function diagnosticDatabase(McpConfig $config): PdoDb
    {
        if ($this->db !== null || $config->connection === null) {
            return $this->getDb();
        }
        // An explicit diagnostic alias must never silently fall back to ambient PDODB_DRIVER credentials.
        $path = getenv('PDODB_CONFIG_PATH') ?: getcwd() . '/config/db.php';
        if (!is_file($path)) {
            throw new \InvalidArgumentException('A named MCP connection requires a database configuration file');
        }
        $all = require $path;
        if (!is_array($all)) {
            throw new \InvalidArgumentException('Database configuration must return an array');
        }
        $selected = $all['connections'][$config->connection] ?? $all[$config->connection] ?? null;
        if (!is_array($selected) || !is_string($selected['driver'] ?? null)) {
            throw new \InvalidArgumentException('The configured MCP connection alias does not exist');
        }
        $db = new PdoDb(null, ['ai' => $selected['ai'] ?? $all['ai'] ?? []]);
        $db->addConnection($config->connection, $selected);
        $db->connection($config->connection);
        return $db;
    }

    /** Keep configuration errors out of the JSON-RPC stdout stream. */
    public static function error(string $message, int $code = 1): never
    {
        throw new \RuntimeException('Invalid MCP database configuration', $code);
    }
}
