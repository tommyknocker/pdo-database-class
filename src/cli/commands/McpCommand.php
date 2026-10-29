<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\ai\mcp\McpServer;
use tommyknocker\pdodb\cli\Command;

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
        if ($action === 'serve') {
            $bufferLevel = ob_get_level();
            ob_start();

            try {
                $project = $this->getOption('project');
                if ($project !== null && (!is_string($project) || !is_dir($project) || !chdir($project))) {
                    throw new \InvalidArgumentException('MCP project directory is unavailable');
                }
                $db = $this->getDb();
                $server = new McpServer($db);
            } catch (\Throwable) {
                fwrite(STDERR, "Unable to start PDOdb MCP; check project directory, database configuration and permissions.\n");
                return 1;
            } finally {
                while (ob_get_level() > $bufferLevel) {
                    ob_end_clean();
                }
            }
            $server->run();
            return 0;
        }
        echo "Usage: pdodb mcp config | serve\n";
        echo "config prints a launch preview without writing files or embedding credentials.\n";
        echo "Run the server in the project directory containing your .env or config/db.php.\n";
        return 0;
    }

    /** Keep configuration errors out of the JSON-RPC stdout stream. */
    public static function error(string $message, int $code = 1): never
    {
        throw new \RuntimeException('Invalid MCP database configuration', $code);
    }
}
