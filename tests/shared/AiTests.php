<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\providers\OllamaProvider;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\AiExplainAnalysis;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;

/**
 * Tests for AI functionality.
 * These tests use Ollama provider (no API keys required).
 * Tests are skipped in CI/GitHub Actions.
 */
class AiTests extends TestCase
{
    private const int OLLAMA_CHECK_TIMEOUT = 1;
    private const int OLLAMA_API_TIMEOUT = 30;
    private const int OLLAMA_MAX_TOKENS = 500;
    private const string OLLAMA_DEFAULT_URL = 'http://localhost:11434';
    private const int OLLAMA_DEFAULT_PORT = 11434;
    private const string OLLAMA_DEFAULT_MODEL = 'deepseek-coder:6.7b';

    protected static ?PdoDb $db = null;
    protected static bool $ollamaAvailable = false;

    public static function setUpBeforeClass(): void
    {
        // Skip tests in CI/GitHub Actions
        if (getenv('CI') !== false || getenv('GITHUB_ACTIONS') !== false) {
            return;
        }

        // Check if Ollama is available
        self::$ollamaAvailable = self::checkOllamaAvailability();

        // Initialize database (use SQLite for simplicity)
        self::$db = new PdoDb('sqlite', ['path' => ':memory:']);
    }

    /**
     * Check if Ollama server is available.
     */
    protected static function checkOllamaAvailability(): bool
    {
        $url = getenv('PDODB_AI_OLLAMA_URL') ?: self::OLLAMA_DEFAULT_URL;

        // A listening port is insufficient: Ollama may not have the configured model.
        // Read only the local model inventory, with a bounded timeout and response size.
        $context = stream_context_create(['http' => ['timeout' => self::OLLAMA_CHECK_TIMEOUT, 'follow_location' => 0]]);
        $json = @file_get_contents(rtrim($url, '/') . '/api/tags', false, $context, 0, 1048576);
        $inventory = $json === false ? null : json_decode($json, true);
        if (!is_array($inventory) || !is_array($inventory['models'] ?? null)) {
            return false;
        }
        $model = getenv('PDODB_AI_OLLAMA_MODEL') ?: 'deepseek-coder:6.7b';
        $expected = str_contains($model, ':') ? $model : $model . ':latest';
        foreach ($inventory['models'] as $entry) {
            if (is_array($entry) && ($entry['name'] ?? $entry['model'] ?? null) === $expected) {
                return true;
            }
        }
        return false;
    }

    protected function setUp(): void
    {
        // Skip if in CI
        if (getenv('CI') !== false || getenv('GITHUB_ACTIONS') !== false) {
            $this->markTestSkipped('AI tests skipped in CI environment');
            return;
        }

        // Skip if Ollama is not available
        if (!self::$ollamaAvailable) {
            $this->markTestSkipped('Ollama is unavailable or the configured model is not installed');
            return;
        }

        if (self::$db === null) {
            $this->markTestSkipped('Database not initialized');
        }
    }

    public function testAiConfigLoadsFromEnvironment(): void
    {
        // Set environment variable
        putenv('PDODB_AI_PROVIDER=ollama');
        putenv('PDODB_AI_OLLAMA_URL=' . self::OLLAMA_DEFAULT_URL);

        $config = new AiConfig();
        $this->assertEquals('ollama', $config->getDefaultProvider());
        $this->assertEquals(self::OLLAMA_DEFAULT_URL, $config->getOllamaUrl());

        // Cleanup
        putenv('PDODB_AI_PROVIDER');
        putenv('PDODB_AI_OLLAMA_URL');
    }

    public function testAiConfigLoadsFromConfigArray(): void
    {
        $testModel = 'llama2';
        $configArray = [
            'ai' => [
                'provider' => 'ollama',
                'ollama_url' => self::OLLAMA_DEFAULT_URL,
                'providers' => [
                    'ollama' => [
                        'model' => $testModel,
                        'temperature' => 0.5,
                    ],
                ],
            ],
        ];

        $config = new AiConfig($configArray);
        $this->assertEquals('ollama', $config->getDefaultProvider());
        $this->assertEquals(self::OLLAMA_DEFAULT_URL, $config->getOllamaUrl());
        $this->assertEquals($testModel, $config->getProviderSetting('ollama', 'model'));
        $this->assertEquals(0.5, $config->getProviderSetting('ollama', 'temperature'));
    }

    public function testOllamaProviderIsAvailable(): void
    {
        $config = new AiConfig([
            'ai' => [
                'ollama_url' => self::OLLAMA_DEFAULT_URL,
            ],
        ]);

        $provider = new OllamaProvider($config);
        $this->assertTrue($provider->isAvailable());
        $this->assertEquals('ollama', $provider->getProviderName());
    }

    public function testAiAnalysisServiceCreatesProvider(): void
    {
        $config = new AiConfig([
            'ai' => [
                'provider' => 'ollama',
                'ollama_url' => self::OLLAMA_DEFAULT_URL,
            ],
        ]);

        $service = new AiAnalysisService(self::$db, $config);
        $provider = $service->getProvider('ollama');

        $this->assertInstanceOf(OllamaProvider::class, $provider);
        $this->assertEquals('ollama', $provider->getProviderName());
    }

    public function testExplainAiAdviceReturnsAiExplainAnalysis(): void
    {
        // Create test table
        self::$db->rawQuery('CREATE TABLE IF NOT EXISTS test_users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
        self::$db->rawQuery('INSERT INTO test_users (name, email) VALUES (?, ?)', ['John', 'john@example.com']);

        $config = new AiConfig([
            'ai' => [
                'provider' => 'ollama',
                'ollama_url' => self::OLLAMA_DEFAULT_URL,
                'providers' => [
                    'ollama' => [
                        'model' => self::OLLAMA_DEFAULT_MODEL,
                        'max_tokens' => self::OLLAMA_MAX_TOKENS,
                    ],
                ],
            ],
        ]);

        $result = self::$db->find()
            ->from('test_users')
            ->where('id', 1)
            ->explainAiAdvice(null, 'ollama', [
                'max_tokens' => self::OLLAMA_MAX_TOKENS,
                'timeout' => self::OLLAMA_API_TIMEOUT,
            ]);

        $this->assertInstanceOf(AiExplainAnalysis::class, $result);
        $this->assertInstanceOf(ExplainAnalysis::class, $result->baseAnalysis);
        $this->assertEquals('ollama', $result->provider);
        $this->assertNotEmpty($result->aiAnalysis);
    }

    public function testAiAnalysisServiceAnalyzeQuery(): void
    {
        $config = new AiConfig([
            'ai' => [
                'provider' => 'ollama',
                'ollama_url' => self::OLLAMA_DEFAULT_URL,
                'providers' => [
                    'ollama' => [
                        'model' => self::OLLAMA_DEFAULT_MODEL,
                        'max_tokens' => self::OLLAMA_MAX_TOKENS,
                    ],
                ],
            ],
        ]);

        $service = new AiAnalysisService(self::$db, $config);
        $sql = 'SELECT * FROM test_users WHERE id = 1';

        $analysis = $service->analyzeQuery($sql, 'test_users', 'ollama', [
            'max_tokens' => self::OLLAMA_MAX_TOKENS,
            'timeout' => self::OLLAMA_API_TIMEOUT,
        ]);

        $this->assertIsString($analysis);
        $this->assertNotEmpty($analysis);
    }

    public function testAiAnalysisServiceAnalyzeSchema(): void
    {
        $config = new AiConfig([
            'ai' => [
                'provider' => 'ollama',
                'ollama_url' => self::OLLAMA_DEFAULT_URL,
                'providers' => [
                    'ollama' => [
                        'model' => self::OLLAMA_DEFAULT_MODEL,
                        'max_tokens' => self::OLLAMA_MAX_TOKENS,
                    ],
                ],
            ],
        ]);

        $service = new AiAnalysisService(self::$db, $config);

        $analysis = $service->analyzeSchema('test_users', 'ollama', [
            'max_tokens' => self::OLLAMA_MAX_TOKENS,
            'timeout' => self::OLLAMA_API_TIMEOUT,
        ]);

        $this->assertIsString($analysis);
        $this->assertNotEmpty($analysis);
    }

    public function testAiConfigEnvironmentTakesPrecedenceOverConfig(): void
    {
        // Set environment variable
        putenv('PDODB_AI_PROVIDER=ollama');
        putenv('PDODB_AI_OLLAMA_URL=http://custom:' . self::OLLAMA_DEFAULT_PORT);

        $configArray = [
            'ai' => [
                'provider' => 'openai',
                'ollama_url' => self::OLLAMA_DEFAULT_URL,
            ],
        ];

        $config = new AiConfig($configArray);

        // Environment should take precedence
        $this->assertEquals('ollama', $config->getDefaultProvider());
        $this->assertEquals('http://custom:' . self::OLLAMA_DEFAULT_PORT, $config->getOllamaUrl());

        // Cleanup
        putenv('PDODB_AI_PROVIDER');
        putenv('PDODB_AI_OLLAMA_URL');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                self::$db->rawQuery('DROP TABLE IF EXISTS test_users');
            } catch (\Throwable) {
                // Ignore errors
            }
        }
    }
}
