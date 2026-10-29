<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\ai\AgentDocumentation;
use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\mcp\McpServer;
use tommyknocker\pdodb\ai\providers\OpenAiCompatibleProvider;
use tommyknocker\pdodb\ai\providers\OpenAiProvider;
use tommyknocker\pdodb\ai\QueryInspectionService;
use tommyknocker\pdodb\cli\commands\AiCommand;
use tommyknocker\pdodb\cli\commands\McpCommand;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

final class AiAgentTests extends TestCase
{
    private PdoDb $db;
    private McpServer $server;
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (getenv() as $key => $value) {
            if (str_starts_with($key, 'PDODB_AI_')) {
                $this->environment[$key] = $value;
                putenv($key);
            }
        }
        $this->db = new PdoDb('sqlite', ['path' => ':memory:']);
        $this->db->rawQuery('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $this->server = new McpServer($this->db);
    }

    protected function tearDown(): void
    {
        foreach (getenv() as $key => $value) {
            if (str_starts_with($key, 'PDODB_AI_')) {
                putenv($key);
            }
        }
        foreach ($this->environment as $key => $value) {
            putenv($key . '=' . $value);
        }
    }

    private function request(string $method, array $params = []): array
    {
        return $this->server->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    private function call(string $name, array $arguments = []): array
    {
        return $this->request('tools/call', ['name' => $name, 'arguments' => $arguments])['result'];
    }

    public function testConfigPreservesOllamaAndEnvironmentOverridesOnlyWhenPresent(): void
    {
        $config = new AiConfig(['ai' => ['ollama_url' => 'http://remote.test:11434']]);
        self::assertSame('http://remote.test:11434', $config->getOllamaUrl());
        putenv('PDODB_AI_OLLAMA_URL=http://override.test:11434');
        self::assertSame('http://override.test:11434', (new AiConfig(['ai' => ['ollama_url' => 'http://remote.test']]))->getOllamaUrl());
        putenv('PDODB_AI_OPENAI_COMPATIBLE_KEY=example-key');
        putenv('PDODB_AI_OPENAI_COMPATIBLE_BASE_URL=https://gateway.example.test/v1');
        putenv('PDODB_AI_OPENAI_COMPATIBLE_MODEL=vendor/model');
        $provider = (new AiAnalysisService($this->db))->getProvider('openai-compatible');
        self::assertInstanceOf(OpenAiCompatibleProvider::class, $provider);
        self::assertSame('vendor/model', $provider->getModel());
    }

    public function testMcpNegotiationUsesObjectsAndDoesNotRespondToNotifications(): void
    {
        $result = $this->request('initialize', ['protocolVersion' => '2025-06-18'])['result'];
        self::assertSame('2025-06-18', $result['protocolVersion']);
        self::assertIsObject($result['capabilities']['tools']);
        self::assertNull($this->server->handleRequest(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        self::assertNull($this->server->handleRequest(['jsonrpc' => '2.0', 'method' => 'unknown_notification']));
        self::assertSame(-32601, $this->request('unknown_method')['error']['code']);
        self::assertIsObject($this->request('ping')['result']);
        $listed = $this->request('tools/list')['result']['tools'];
        self::assertCount(8, $listed);
        $toolsByName = array_column($listed, null, 'name');
        self::assertContains('openrouter', $toolsByName['analyze_query']['inputSchema']['properties']['provider']['enum']);
    }

    public function testToolErrorsAreActionableAndSchemaValidationPreventsTypeErrors(): void
    {
        foreach ([[], ['sql' => []], ['sql' => 'SELECT ?', 'params' => [['secret' => 'value']]]] as $args) {
            self::assertTrue($this->call('inspect_query', $args)['isError']);
        }
        self::assertTrue($this->call('search_schema', ['offset' => -1])['isError']);
        self::assertTrue($this->call('inspect_query', ['sql' => 'DELETE FROM users'])['isError']);
        self::assertTrue($this->call('inspect_query', ['sql' => 'SELECT 1; DELETE FROM users'])['isError']);
        self::assertTrue($this->call('inspect_query', ['sql' => 'SELECT * FROM private_secret_table'])['isError']);
        self::assertStringNotContainsString('private_secret_table', json_encode($this->call('inspect_query', ['sql' => 'SELECT * FROM private_secret_table'])));
    }

    public function testPromptArgumentsAreResolvedIntoAValidContentBlock(): void
    {
        $prompt = $this->request('prompts/get', ['name' => 'analyze_query', 'arguments' => ['sql' => 'SELECT 42']]);
        $content = $prompt['result']['messages'][0]['content'];
        self::assertSame('text', $content['type']);
        self::assertStringContainsString('SELECT 42', $content['text']);
        self::assertStringNotContainsString('{{sql}}', $content['text']);
        self::assertSame(-32602, $this->request('prompts/get', ['name' => 'analyze_query'])['error']['code']);
    }

    public function testMetadataAndPlansWorkWithoutAnAiKey(): void
    {
        $context = (array)$this->call('get_context')['structuredContent'];
        self::assertFalse($context['llm_required']);
        self::assertSame('sqlite', $context['dialect']);
        self::assertStringContainsString('false', $context['contracts']['getOne']);
        $schema = (array)$this->call('search_schema', ['query' => 'use', 'limit' => 1])['structuredContent'];
        self::assertSame(['users'], $schema['tables']);
        $plan = (array)$this->call('inspect_query', ['sql' => 'SELECT * FROM users WHERE name = ?', 'params' => ['private-value']])['structuredContent'];
        self::assertSame('database', $plan['source']);
        self::assertFalse($plan['measurements']);
        self::assertNotEmpty($plan['analysis']['raw_explain']);
        self::assertStringNotContainsString('private-value', json_encode($plan));
        self::assertNotEmpty($this->call('inspect_query', ['sql' => 'SELECT * FROM users WHERE id = :id', 'params' => ['id' => 1]])['structuredContent']);
        $this->db->rawQuery('CREATE TABLE users_archive (id INTEGER PRIMARY KEY)');
        $page = (array)$this->call('search_schema', ['query' => 'users', 'limit' => 1])['structuredContent'];
        self::assertSame(1, $page['next_offset']);
        self::assertSame(['users_archive'], ((array)$this->call('search_schema', ['query' => 'users', 'limit' => 1, 'offset' => 1])['structuredContent'])['tables']);
    }

    public function testDocumentationAndCliSetupDoNotNeedDatabaseOrProvider(): void
    {
        $docs = (new AgentDocumentation())->search('asObject', 2);
        self::assertSame('installed_package', $docs['source']);
        self::assertLessThanOrEqual(2, count($docs['results']));
        self::assertStringContainsString('asObject', json_encode($docs));
        $command = new AiCommand();
        $command->setArguments(['docs', 'getOne']);
        ob_start();
        self::assertSame(0, $command->execute());
        $output = ob_get_clean();
        self::assertNotEmpty(json_decode($output, true, 512, JSON_THROW_ON_ERROR)['results']);
        $mcp = new McpCommand();
        $mcp->setArguments(['config']);
        ob_start();
        self::assertSame(0, $mcp->execute());
        $config = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFileExists($config['mcpServers']['pdodb']['args'][0]);
        $command->setDb($this->db)->setArguments(['doctor']);
        ob_start();
        self::assertSame(0, $command->execute());
        $doctor = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($doctor['ai']['provider_network_checked']);
        self::assertFalse($doctor['ai']['api_key_configured']);
    }

    public function testUnsupportedPlansAreExplicitAndDoNotReturnSyntheticEvidence(): void
    {
        foreach (['sqlsrv', 'oci'] as $driver) {
            $db = new PdoDb();
            $db->addConnection('test', ['driver' => $driver, 'pdo' => $this->db->connection->getPdo(), 'enable_regexp' => false]);
            $db->connection('test');
            $result = (new QueryInspectionService($db))->inspect('SELECT ?', [1]);
            self::assertSame('unavailable', $result['source']);
            self::assertNull($result['analysis']);
            self::assertNotEmpty($result['warnings']);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCompatibleHttpRequestAndDatabaseConfigPropagation(): void
    {
        require __DIR__ . '/../fixtures/ai-http-functions.php';
        $db = new PdoDb('sqlite', ['path' => ':memory:', 'ai' => [
            'provider' => 'openrouter', 'openrouter_key' => 'example-router-key',
            'providers' => ['openrouter' => ['base_url' => 'https://gateway.example.test/api/v1/',
                'model' => 'vendor/model', 'headers' => ['HTTP-Referer' => 'https://project.test'],
                'request_options' => ['temperature' => null, 'max_tokens' => null, 'max_completion_tokens' => 128]]],
        ]]);
        $db->rawQuery('CREATE TABLE people (id INTEGER PRIMARY KEY, unique_column TEXT)');
        $result = $db->find()->from('people')->explainAiAdvice();
        self::assertSame('openrouter', $result->provider);
        self::assertSame('vendor/model', $result->model);
        self::assertSame('Test analysis', $result->aiAnalysis);
        $request = $GLOBALS['ai_test_requests'][0];
        self::assertSame('https://gateway.example.test/api/v1/chat/completions', $request['url']);
        self::assertStringContainsString('Authorization: Bearer example-router-key', $request['options']['http']['header']);
        self::assertStringContainsString('HTTP-Referer: https://project.test', $request['options']['http']['header']);
        self::assertSame(0, $request['options']['http']['follow_location']);
        $body = json_decode($request['options']['http']['content'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('vendor/model', $body['model']);
        self::assertArrayNotHasKey('temperature', $body);
        self::assertArrayNotHasKey('max_tokens', $body);
        self::assertSame(128, $body['max_completion_tokens']);
        self::assertStringContainsString('unique_column', $body['messages'][1]['content']);
        self::assertStringNotContainsString('COUNT(*)', $body['messages'][1]['content']);
        $service = new AiAnalysisService($db);
        $service->analyzeQuery('SELECT 1', options: ['model' => 'override', 'temperature' => 0.2]);
        $service->analyzeQuery('SELECT 1');
        $last = json_decode(end($GLOBALS['ai_test_requests'])['options']['http']['content'], true);
        self::assertSame('vendor/model', $last['model']);
        self::assertSame('vendor/model', $service->getProvider()->getModel());
        $server = new McpServer($db);
        $metadata = $server->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_context']]);
        self::assertStringNotContainsString('example-router-key', json_encode($metadata));
        self::assertStringNotContainsString('gateway.example.test', json_encode($metadata));
        $response = $server->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'analyze_query', 'arguments' => ['sql' => 'SELECT * FROM people WHERE id = ?', 'params' => [1]]]]);
        self::assertSame('openrouter', ((array)$response['result']['structuredContent'])['provider']);
        self::assertSame('database', ((array)$response['result']['structuredContent'])['plan_source']);
        $command = new AiCommand();
        $command->setDb($db)->setArguments(['query', 'SELECT * FROM people WHERE id = ?'])
            ->setOptions(['params' => '[1]', 'format' => 'json', 'model' => 'vendor/override']);
        ob_start();
        self::assertSame(0, $command->execute());
        $report = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('openrouter', $report['provider']);
        self::assertSame('vendor/override', $report['model']);
        self::assertSame('database', $report['plan_source']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDefaultEndpointsAndReservedRequestFields(): void
    {
        require __DIR__ . '/../fixtures/ai-http-functions.php';
        foreach (['openai' => 'https://api.openai.com/v1/chat/completions',
            'openrouter' => 'https://openrouter.ai/api/v1/chat/completions'] as $name => $url) {
            $config = new AiConfig(['ai' => [$name . '_key' => 'fake-key']]);
            $provider = (new AiAnalysisService($this->db, $config))->getProvider($name);
            $provider->analyzeQuery('SELECT 1');
            self::assertSame($url, end($GLOBALS['ai_test_requests'])['url']);
        }
        $legacyProvider = new class (new AiConfig(['ai' => ['openai_key' => 'fake-key']])) extends OpenAiProvider {
            protected string $apiUrl = 'https://legacy.test/api/chat/completions';
        };
        $legacyProvider->analyzeQuery('SELECT 1');
        self::assertSame('https://legacy.test/api/chat/completions', end($GLOBALS['ai_test_requests'])['url']);
        foreach ([['headers' => ['Authorization' => 'override']], ['headers' => ['X-Name' => "value\r\nX-Injected: yes"]],
            ['request_options' => ['messages' => null]], ['request_options' => ['stream' => true]]] as $options) {
            $provider = new OpenAiCompatibleProvider(new AiConfig(['ai' => ['openai_compatible_key' => 'fake-key',
                'providers' => ['openai-compatible' => ['base_url' => 'https://gateway.test/v1'] + $options]]]));
            $before = count($GLOBALS['ai_test_requests']);

            try {
                $provider->analyzeQuery('SELECT 1');
                self::fail('Reserved or invalid request fields must not reach the endpoint');
            } catch (QueryException) {
                self::assertCount($before, $GLOBALS['ai_test_requests']);
            }
        }
    }

    public function testUnsafeEndpointIsRejectedWithoutIncludingCredentials(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('without credentials');
        new OpenAiCompatibleProvider(new AiConfig(['ai' => ['providers' => ['openai-compatible' => [
            'base_url' => 'https://user:secret@example.test/v1',
        ]]]]));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReasoningOnlyCompletionReportsTokenExhaustion(): void
    {
        require __DIR__ . '/../fixtures/ai-http-functions.php';
        $provider = new OpenAiCompatibleProvider(new AiConfig(['ai' => ['openai_compatible_key' => 'fake-key',
            'providers' => ['openai-compatible' => ['base_url' => 'https://gateway.test/v1', 'model' => 'test/reasoning-budget']]]]));
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('exhausted its output token limit before producing visible text');
        $provider->analyzeQuery('SELECT 1');
    }

    public function testStdioSessionHasNoResponseToNotificationsAndReportsInvalidJson(): void
    {
        $environment = array_merge(getenv(), ['PDODB_DRIVER' => 'sqlite', 'PDODB_PATH' => ':memory:']);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/pdodb-mcp'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );
        self::assertIsResource($process);
        fwrite($pipes[0], "{invalid\n[]\n");
        foreach ([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_context']],
        ] as $request) {
            fwrite($pipes[0], json_encode($request) . "\n");
        }
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 10);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        $responses = array_map(static fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($output)));
        self::assertCount(4, $responses);
        self::assertSame(-32700, $responses[0]['error']['code']);
        self::assertSame(-32600, $responses[1]['error']['code']);
        self::assertSame('2024-11-05', $responses[2]['result']['protocolVersion']);
        self::assertSame('sqlite', $responses[3]['result']['structuredContent']['dialect']);
    }

    public function testRealCompatibleHttpTransportAgainstLoopbackServer(): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../fixtures/ai-http-server.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        try {
            stream_set_timeout($pipes[1], 5);
            $address = trim((string)fgets($pipes[1]));
            self::assertStringStartsWith('127.0.0.1:', $address);
            $provider = new OpenAiCompatibleProvider(new AiConfig(['ai' => [
                'openai_compatible_key' => 'fake-local-key',
                'providers' => ['openai-compatible' => ['base_url' => 'http://' . $address . '/custom/v1',
                    'model' => 'vendor/test-model', 'timeout' => 5, 'headers' => ['X-Project' => 'pdodb-test']]],
            ]]));
            $result = json_decode($provider->analyzeQuery('SELECT 1'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('POST /custom/v1/chat/completions HTTP/1.1', $result['request']);
            self::assertSame('Bearer fake-local-key', $result['headers']['authorization']);
            self::assertSame('pdodb-test', $result['headers']['x-project']);
            self::assertSame('vendor/test-model', $result['body']['model']);
            self::assertSame('system', $result['body']['messages'][0]['role']);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testInstalledMcpLauncherFindsAutoloadAndUsesExplicitProject(): void
    {
        $project = sys_get_temp_dir() . '/pdodb-mcp-' . bin2hex(random_bytes(6));
        $bin = $project . '/vendor/tommyknocker/pdo-database-class/bin';
        mkdir($bin, 0700, true);
        mkdir($project . '/config', 0700);
        $root = dirname(__DIR__, 2);
        copy($root . '/bin/pdodb-mcp', $bin . '/pdodb-mcp');
        file_put_contents($project . '/vendor/autoload.php', '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';');
        file_put_contents($project . '/config/db.php', "<?php echo 'config debug output'; return ['driver' => 'sqlite', 'path' => ':memory:'];");
        $environment = array_filter(getenv(), static fn ($key) => !str_starts_with($key, 'PDODB_'), ARRAY_FILTER_USE_KEY);

        try {
            $process = proc_open(
                [PHP_BINARY, $bin . '/pdodb-mcp', '--project=' . $project],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                sys_get_temp_dir(),
                $environment
            );
            self::assertIsResource($process);
            fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_context']]) . "\n");
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 10);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            self::assertSame('sqlite', json_decode($output, true, 512, JSON_THROW_ON_ERROR)['result']['structuredContent']['dialect']);
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($project, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($project);
        }
    }
}
