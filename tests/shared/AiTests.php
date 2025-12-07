<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\providers\OllamaProvider;

/**
 * Tests for AI functionality.
 * These tests use Ollama provider (no API keys required).
 * Tests are skipped in CI/GitHub Actions.
 */
class AiTests extends TestCase
{
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
        $url = getenv('PDODB_AI_OLLAMA_URL') ?: 'http://localhost:11434';
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url . '/api/tags', false, $context);
        return $response !== false;
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
            $this->markTestSkipped('Ollama server is not available');
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
        putenv('PDODB_AI_OLLAMA_URL=http://localhost:11434');

        $config = new AiConfig();
        $this->assertEquals('ollama', $config->getDefaultProvider());
        $this->assertEquals('http://localhost:11434', $config->getOllamaUrl());

        // Cleanup
        putenv('PDODB_AI_PROVIDER');
        putenv('PDODB_AI_OLLAMA_URL');
    }

    public function testAiConfigLoadsFromConfigArray(): void
    {
        $configArray = [
            'ai' => [
                'provider' => 'ollama',
                'ollama_url' => 'http://localhost:11434',
                'providers' => [
                    'ollama' => [
                        'model' => 'llama2',
                        'temperature' => 0.5,
                    ],
                ],
            ],
        ];

        $config = new AiConfig($configArray);
        $this->assertEquals('ollama', $config->getDefaultProvider());
        $this->assertEquals('http://localhost:11434', $config->getOllamaUrl());
        $this->assertEquals('llama2', $config->getProviderSetting('ollama', 'model'));
        $this->assertEquals(0.5, $config->getProviderSetting('ollama', 'temperature'));
    }

    public function testOllamaProviderIsAvailable(): void
    {
        $config = new AiConfig([
            'ai' => [
                'ollama_url' => 'http://localhost:11434',
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
                'ollama_url' => 'http://localhost:11434',
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
                'ollama_url' => 'http://localhost:11434',
                'providers' => [
                    'ollama' => [
                        'model' => 'llama3.2', // Try llama3.2, fallback to any available model
                        'max_tokens' => 500, // Limit tokens for faster response
                    ],
                ],
            ],
        ]);

        $result = self::$db->find()
            ->from('test_users')
            ->where('id', 1)
            ->explainAiAdvice(null, 'ollama', ['max_tokens' => 500]);

        $this->assertInstanceOf(\tommyknocker\pdodb\query\analysis\AiExplainAnalysis::class, $result);
        $this->assertInstanceOf(\tommyknocker\pdodb\query\analysis\ExplainAnalysis::class, $result->baseAnalysis);
        $this->assertEquals('ollama', $result->provider);
        $this->assertNotEmpty($result->aiAnalysis);
    }

    public function testAiAnalysisServiceAnalyzeQuery(): void
    {
        $config = new AiConfig([
            'ai' => [
                'provider' => 'ollama',
                'ollama_url' => 'http://localhost:11434',
                'providers' => [
                    'ollama' => [
                        'model' => 'deepseek-coder:6.7b',
                        'max_tokens' => 500,
                    ],
                ],
            ],
        ]);

        $service = new AiAnalysisService(self::$db, $config);
        $sql = 'SELECT * FROM test_users WHERE id = 1';

        $analysis = $service->analyzeQuery($sql, 'test_users', 'ollama', ['max_tokens' => 500]);

        $this->assertIsString($analysis);
        $this->assertNotEmpty($analysis);
    }

    public function testAiAnalysisServiceAnalyzeSchema(): void
    {
        $config = new AiConfig([
            'ai' => [
                'provider' => 'ollama',
                'ollama_url' => 'http://localhost:11434',
                'providers' => [
                    'ollama' => [
                        'model' => 'deepseek-coder:6.7b',
                        'max_tokens' => 500,
                    ],
                ],
            ],
        ]);

        $service = new AiAnalysisService(self::$db, $config);

        $analysis = $service->analyzeSchema('test_users', 'ollama', ['max_tokens' => 500]);

        $this->assertIsString($analysis);
        $this->assertNotEmpty($analysis);
    }

    public function testAiConfigEnvironmentTakesPrecedenceOverConfig(): void
    {
        // Set environment variable
        putenv('PDODB_AI_PROVIDER=ollama');
        putenv('PDODB_AI_OLLAMA_URL=http://custom:11434');

        $configArray = [
            'ai' => [
                'provider' => 'openai',
                'ollama_url' => 'http://localhost:11434',
            ],
        ];

        $config = new AiConfig($configArray);

        // Environment should take precedence
        $this->assertEquals('ollama', $config->getDefaultProvider());
        $this->assertEquals('http://custom:11434', $config->getOllamaUrl());

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

