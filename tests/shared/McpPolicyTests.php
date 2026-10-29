<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\ai\mcp\McpConfig;
use tommyknocker\pdodb\ai\mcp\McpExecutionGuard;
use tommyknocker\pdodb\ai\mcp\McpServer;
use tommyknocker\pdodb\PdoDb;

final class McpPolicyTests extends TestCase
{
    private PdoDb $db;

    protected function setUp(): void
    {
        $this->db = new PdoDb('sqlite', ['path' => ':memory:']);
        $this->db->rawQuery('CREATE TABLE hidden_accounts (id INTEGER PRIMARY KEY, private_column TEXT)');
        $this->db->rawQuery('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, ref_id INTEGER REFERENCES hidden_accounts(id))');
        $this->db->rawQuery('CREATE INDEX user_name_idx ON users(name)');
        $this->db->rawQuery("ATTACH DATABASE ':memory:' AS archive");
        $this->db->rawQuery('CREATE TABLE archive.users (id INTEGER PRIMARY KEY, archive_only TEXT)');
    }

    private function request(McpServer $server, string $method, array $params = []): array
    {
        return $server->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    private function call(McpServer $server, string $name, array $arguments = []): array
    {
        return $this->request($server, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
    }

    private function data(array $response): array
    {
        self::assertArrayNotHasKey('error', $response);
        self::assertFalse($response['result']['isError'] ?? false, json_encode($response));
        return (array)$response['result']['structuredContent'];
    }

    public function testVisibilityFiltersEveryMetadataPathAndForeignKeyTargets(): void
    {
        $server = new McpServer($this->db, config: new McpConfig(['allowed_tables' => ['users']]));
        $tools = $this->request($server, 'tools/list')['result']['tools'];
        self::assertEqualsCanonicalizing(['get_context', 'search_docs', 'search_schema', 'get_schema'], array_column($tools, 'name'));
        foreach (['inspect_query', 'analyze_query', 'explain_plan', 'suggest_indexes'] as $tool) {
            self::assertSame(-32602, $this->call($server, $tool, ['sql' => 'SELECT * FROM hidden_accounts', 'table' => 'users'])['error']['code']);
        }
        $all = $this->data($this->call($server, 'get_schema'));
        self::assertSame(['users'], array_keys($all));
        self::assertSame([], $all['users']['foreign_keys']);
        self::assertTrue($all['users']['foreign_keys_filtered']);
        self::assertStringNotContainsString('hidden_accounts', json_encode($all));
        $search = $this->data($this->call($server, 'search_schema', ['kind' => 'columns', 'query' => 'private']));
        self::assertSame([], $search['columns']);
        $resources = $this->request($server, 'resources/list')['result']['resources'];
        self::assertSame(['table://users'], array_column($resources, 'uri'));
        self::assertSame(-32602, $this->request($server, 'resources/read', ['uri' => 'table://hidden_accounts'])['error']['code']);
        $allowed = $this->request($server, 'resources/read', ['uri' => 'table://users']);
        self::assertArrayNotHasKey('error', $allowed);
        self::assertStringNotContainsString('hidden_accounts', json_encode($allowed));
        self::assertTrue($this->call($server, 'get_schema', ['table' => 'archive.users'])['result']['isError']);
    }

    public function testEmptyAllowlistsDenyAllAndCannotBeOverriddenByToolFlags(): void
    {
        $config = new McpConfig(['allowed_tables' => [], 'query_analysis_enabled' => true, 'external_ai_enabled' => true]);
        self::assertFalse($config->queryAnalysisEnabled);
        self::assertFalse($config->externalAiEnabled);
        $server = new McpServer($this->db, config: $config);
        self::assertSame([], $this->data($this->call($server, 'get_schema')));
        self::assertSame([], $this->data($this->call($server, 'search_schema', ['kind' => 'schemas']))['schemas']);
        self::assertSame([], $this->request($server, 'resources/list')['result']['resources']);
        $server = new McpServer($this->db, config: new McpConfig(['allowed_schemas' => []]));
        self::assertSame([], $this->request($server, 'resources/list')['result']['resources']);
        self::assertTrue($this->call($server, 'get_schema', ['table' => 'users'])['result']['isError']);
    }

    public function testColumnAndSchemaSearchUsesExactNamespaces(): void
    {
        $server = new McpServer($this->db);
        $main = $this->data($this->call($server, 'search_schema', ['kind' => 'columns', 'schema' => 'main', 'query' => 'archive_only']));
        self::assertSame([], $main['columns']);
        $archive = $this->data($this->call($server, 'search_schema', ['kind' => 'columns', 'schema' => 'archive', 'query' => 'archive_only']));
        self::assertSame('users', $archive['columns'][0]['table']);
        self::assertSame('archive', $archive['columns'][0]['schema']);
        $description = $this->data($this->call($server, 'get_schema', ['schema' => 'archive', 'table' => 'users']));
        self::assertContains('archive_only', array_column($description['columns'], 'name'));
        self::assertNotContains('name', array_column($description['columns'], 'name'));
        $server = new McpServer($this->db, config: new McpConfig(['allowed_schemas' => ['archive']]));
        $context = $this->data($this->call($server, 'get_context'));
        self::assertSame('archive', $context['metadata_default_schema']);
        self::assertSame(['archive'], $this->data($this->call($server, 'search_schema', ['kind' => 'schemas']))['schemas']);
        self::assertTrue($this->call($server, 'get_schema', ['schema' => 'main', 'table' => 'users'])['result']['isError']);
        $default = $this->data($this->call($server, 'get_schema', ['table' => 'users']));
        self::assertSame('archive', $default['schema']);
    }

    public function testIdentifiersCannotEscapeMetadataQueries(): void
    {
        $server = new McpServer($this->db);
        foreach (["users' UNION SELECT * FROM hidden_accounts --", 'main.users; DROP TABLE users', 'main.archive.users'] as $table) {
            self::assertTrue($this->call($server, 'get_schema', ['table' => $table])['result']['isError']);
            self::assertSame(-32602, $this->request($server, 'resources/read', ['uri' => 'table://' . $table])['error']['code']);
        }
        self::assertSame(0, $this->db->rawQueryValue('SELECT COUNT(*) FROM users'));
    }

    public function testDedicatedConnectionDoesNotChangeCallersActiveConnection(): void
    {
        $original = $this->db->connection;
        $diagnostics = new PdoDb('sqlite', ['path' => ':memory:']);
        $diagnostics->rawQuery('CREATE TABLE reports (id INTEGER PRIMARY KEY)');
        $this->db->addConnection('diagnostics', ['driver' => 'sqlite', 'pdo' => $diagnostics->connection->getPdo()]);
        $server = new McpServer($this->db, config: new McpConfig(['connection' => 'diagnostics']));
        self::assertSame($original, $this->db->connection);
        self::assertSame(['reports'], $this->data($this->call($server, 'search_schema'))['tables']);
        self::assertSame(['diagnostics'], $this->data($this->call($server, 'get_context'))['connections']);
    }

    public function testUnsupportedTimeoutNeverPretendsToCancelAStatement(): void
    {
        $called = false;

        try {
            (new McpExecutionGuard($this->db, 10))->run(function () use (&$called): void {
                $called = true;
            });
            self::fail('SQLite does not have a PDO statement timeout in this adapter');
        } catch (InvalidArgumentException) {
            self::assertFalse($called);
        }
        $server = new McpServer($this->db, config: new McpConfig(['statement_timeout_ms' => 10]));
        self::assertFalse($this->data($this->call($server, 'get_context'))['statement_timeout']['supported']);
        $error = $this->call($server, 'search_schema')['result'];
        self::assertTrue($error['isError']);
        self::assertStringContainsString('unsupported', $error['content'][0]['text']);
    }

    public function testConfigurationTyposAndNonBooleanFlagsFailClosed(): void
    {
        foreach ([['allowed_table' => ['users']], ['external_ai_enabled' => 'false'], ['external_ai_enabled' => null],
            ['allowed_tables' => 'users'], ['allowed_schemas' => ['main; SELECT 1']], ['statement_timeout_ms' => -1]] as $options) {
            try {
                new McpConfig($options);
                self::fail('Invalid configuration must not silently permit access');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEnvironmentOverridesAreStrictAndEmptyListDoesNotBecomeUnrestricted(): void
    {
        $saved = [];
        foreach (getenv() as $key => $value) {
            if (str_starts_with($key, 'PDODB_MCP_')) {
                $saved[$key] = $value;
                putenv($key);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'mcp-config-');
        file_put_contents($path, "<?php return ['allowed_tables' => ['users'], 'external_ai_enabled' => true];");

        try {
            putenv('PDODB_MCP_CONFIG_PATH=' . $path);
            putenv('PDODB_MCP_ALLOWED_TABLES=[]');
            putenv('PDODB_MCP_STATEMENT_TIMEOUT_MS=25');
            putenv('PDODB_MCP_EXTERNAL_AI_ENABLED=false');
            $config = McpConfig::fromProject();
            self::assertSame([], $config->allowedTables);
            self::assertSame(25, $config->statementTimeoutMs);
            self::assertFalse($config->externalAiEnabled);
            putenv('PDODB_MCP_ALLOWED_TABLES={}');

            try {
                McpConfig::fromProject();
                self::fail('An environment allowlist must be a JSON array');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        } finally {
            foreach (getenv() as $key => $value) {
                if (str_starts_with($key, 'PDODB_MCP_')) {
                    putenv($key);
                }
            }
            foreach ($saved as $key => $value) {
                putenv($key . '=' . $value);
            }
            unlink($path);
        }
    }

    public function testLauncherUsesDiagnosticAliasAndNeverFallsBackOnUnknownAlias(): void
    {
        $project = sys_get_temp_dir() . '/pdodb-mcp-policy-' . bin2hex(random_bytes(5));
        mkdir($project . '/config', 0700, true);
        $primaryPath = $project . '/primary.sqlite';
        $diagnosticPath = $project . '/diagnostic.sqlite';
        $primary = new PdoDb('sqlite', ['path' => $primaryPath]);
        $primary->rawQuery('CREATE TABLE primary_users (id INTEGER PRIMARY KEY)');
        $diagnostics = new PdoDb('sqlite', ['path' => $diagnosticPath]);
        $diagnostics->rawQuery('CREATE TABLE reports (id INTEGER PRIMARY KEY)');
        unset($primary, $diagnostics);
        file_put_contents($project . '/config/db.php', '<?php return ' . var_export(['default' => 'main', 'connections' => [
            'main' => ['driver' => 'sqlite', 'path' => $primaryPath],
            'diagnostics' => ['driver' => 'sqlite', 'path' => $diagnosticPath],
        ]], true) . ';');
        $environment = array_filter(getenv(), static fn ($key) => !str_starts_with($key, 'PDODB_'), ARRAY_FILTER_USE_KEY);
        $environment += ['PDODB_DRIVER' => 'sqlite', 'PDODB_PATH' => $primaryPath];

        try {
            foreach (['diagnostics', 'missing_alias'] as $alias) {
                file_put_contents($project . '/config/mcp.php', '<?php return ' . var_export(['connection' => $alias, 'allowed_tables' => ['reports']], true) . ';');
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__, 2) . '/bin/pdodb-mcp', '--project=' . $project],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes,
                    null,
                    $environment
                );
                self::assertIsResource($process);
                fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_schema']]) . "\n");
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 10);
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $status = proc_close($process);
                if ($alias === 'diagnostics') {
                    self::assertSame(0, $status, $errors);
                    self::assertSame(['reports'], json_decode($output, true, 512, JSON_THROW_ON_ERROR)['result']['structuredContent']['tables']);
                } else {
                    self::assertSame(1, $status);
                    self::assertSame('', $output);
                    self::assertStringContainsString('Unable to start PDOdb MCP', $errors);
                }
            }
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($project, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($project);
        }
    }
}
