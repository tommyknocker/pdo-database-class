<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\ai\AgentContextService;
use tommyknocker\pdodb\ai\AgentDocumentation;
use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\QueryInspectionService;
use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\MarkdownFormatter;
use tommyknocker\pdodb\cli\SchemaAnalyzer;

/**
 * AI-powered database analysis command.
 */
class AiCommand extends Command
{
    public function __construct()
    {
        parent::__construct('ai', 'AI-powered database analysis and optimization');
    }

    public function execute(): int
    {
        $subcommand = $this->getArgument(0);

        return match ($subcommand) {
            'context', 'doctor' => $this->context($subcommand === 'doctor'),
            'docs' => $this->docs(),
            'setup' => $this->setup(),
            'query' => $this->query(),
            'schema' => $this->schema(),
            'optimize' => $this->optimize(),
            'help', '--help', '-h', null => $this->showHelp(),
            default => $this->showError("Unknown subcommand: {$subcommand}. Use 'ai --help' for usage."),
        };
    }

    /**
     * Analyze query using explainAiAdvice.
     */
    protected function query(): int
    {
        $sql = $this->getArgument(1);
        if ($sql === null || $sql === '') {
            return $this->showError('SQL query is required. Usage: pdodb ai query "SELECT * FROM users"');
        }

        $options = $this->generationOptions();

        $db = $this->getDb();
        $provider = $this->getOption('provider');
        $format = (string)$this->getOption('format', 'text');
        $tableName = $this->getOption('table');

        try {
            $this->progress('Analyzing query execution plan...');
            $params = json_decode((string)$this->getOption('params', '[]'), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($params)) {
                throw new \InvalidArgumentException('--params must contain a JSON array or object');
            }
            $inspection = (new QueryInspectionService($db))->inspect($sql, $params, $tableName);
            $baseAnalysis = $inspection['analysis'];

            // Then get AI analysis
            $this->progress('Initializing AI service...');
            $aiService = new AiAnalysisService($db);
            $actualProvider = $aiService->getProvider($provider);
            $model = $options['model'] ?? $actualProvider->getModel();

            $this->progress("Provider: {$actualProvider->getProviderName()}");
            $this->progress("Model: {$model}");
            $this->progress('Sending request to AI API');

            // Pass baseAnalysis (which includes EXPLAIN plan) to AI for better analysis
            $options['params'] = $params;
            $aiAnalysis = $aiService->analyzeQuery($sql, $tableName, $provider, $options, $baseAnalysis);

            if ($format !== 'json' && getenv('PHPUNIT') === false) {
                echo "\r" . str_repeat(' ', 80) . "\r"; // Clear loading line
            }

            $providerName = $actualProvider->getProviderName();

            if ($format === 'json') {
                echo json_encode([
                    'base_analysis' => QueryInspectionService::format($baseAnalysis),
                    'plan_source' => $inspection['source'],
                    'warnings' => $inspection['warnings'],
                    'ai_analysis' => $aiAnalysis,
                    'provider' => $providerName,
                    'model' => $model,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                return 0;
            }

            foreach ($inspection['warnings'] as $warning) {
                echo "Warning: {$warning}\n";
            }
            echo "Base Analysis\n";
            echo str_repeat('=', 80) . "\n";
            if ($baseAnalysis !== null && $baseAnalysis->issues !== []) {
                foreach ($baseAnalysis->issues as $issue) {
                    $severity = strtoupper($issue->severity);
                    echo "[{$severity}] {$issue->description}\n";
                }
            }
            if ($baseAnalysis !== null && $baseAnalysis->recommendations !== []) {
                echo "\nRecommendations:\n";
                foreach ($baseAnalysis->recommendations as $rec) {
                    $severity = strtoupper($rec->severity);
                    echo "[{$severity}] {$rec->message}\n";
                    if (isset($rec->suggestion)) {
                        echo "  SQL: {$rec->suggestion}\n";
                    }
                }
            }

            echo "\n\nAI Analysis (Provider: {$providerName}" . ($model ? ", Model: {$model}" : '') . ")\n";
            echo str_repeat('=', 80) . "\n\n";
            $formatter = new MarkdownFormatter();
            echo $formatter->format($aiAnalysis) . "\n";

            return 0;
        } catch (\Throwable $e) {
            return $this->showError('AI query analysis failed: ' . $e->getMessage());
        }
    }

    /**
     * Analyze database schema with AI.
     */
    protected function schema(): int
    {
        $options = $this->generationOptions();

        $db = $this->getDb();
        $provider = $this->getOption('provider');
        $format = (string)$this->getOption('format', 'text');
        $tableName = $this->getOption('table');

        try {
            $this->progress('Collecting schema information...');
            if ($tableName !== null) {
                $this->progress("Table: {$tableName}");
            }

            $this->progress('Initializing AI service...');
            $aiService = new AiAnalysisService($db);
            $actualProvider = $aiService->getProvider($provider);
            $model = $options['model'] ?? $actualProvider->getModel();

            $this->progress("Provider: {$actualProvider->getProviderName()}");
            $this->progress("Model: {$model}");
            $this->progress('Sending request to AI API');

            $analysis = $aiService->analyzeSchema($tableName, $provider, $options);

            if ($format !== 'json' && getenv('PHPUNIT') === false) {
                echo "\r" . str_repeat(' ', 80) . "\r"; // Clear loading line
            }

            if ($format === 'json') {
                echo json_encode([
                    'table' => $tableName,
                    'provider' => $actualProvider->getProviderName(),
                    'analysis' => $analysis,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                return 0;
            }

            echo 'AI Schema Analysis (Provider: ' . $actualProvider->getProviderName() . ")\n";
            if ($tableName !== null) {
                echo "Table: {$tableName}\n";
            }
            echo str_repeat('=', 80) . "\n\n";
            $formatter = new MarkdownFormatter();
            echo $formatter->format($analysis) . "\n";

            return 0;
        } catch (\Throwable $e) {
            return $this->showError('AI schema analysis failed: ' . $e->getMessage());
        }
    }

    /**
     * Get AI-powered optimization suggestions.
     */
    protected function optimize(): int
    {
        $options = $this->generationOptions();

        $db = $this->getDb();
        $provider = $this->getOption('provider');
        $format = (string)$this->getOption('format', 'text');
        $tableName = $this->getOption('table');

        try {
            $this->progress('Analyzing database schema...');
            if ($tableName !== null) {
                $this->progress("Table: {$tableName}");
            }

            // Get base analysis first
            $schemaAnalyzer = new SchemaAnalyzer($db);
            $baseAnalysis = $tableName !== null
                ? $schemaAnalyzer->analyzeTable($tableName)
                : $schemaAnalyzer->analyze();

            // Get AI suggestions
            $this->progress('Initializing AI service...');
            $aiService = new AiAnalysisService($db);
            $actualProvider = $aiService->getProvider($provider);
            $model = $options['model'] ?? $actualProvider->getModel();

            $this->progress("Provider: {$actualProvider->getProviderName()}");
            $this->progress("Model: {$model}");
            $this->progress('Sending request to AI API');

            $context = [
                'base_analysis' => $baseAnalysis,
            ];
            $aiSuggestions = $aiService->suggestOptimizations($baseAnalysis, $context, $provider, $options);

            if ($format !== 'json' && getenv('PHPUNIT') === false) {
                echo "\r" . str_repeat(' ', 80) . "\r"; // Clear loading line
            }

            if ($format === 'json') {
                echo json_encode([
                    'base_analysis' => $baseAnalysis,
                    'ai_suggestions' => $aiSuggestions,
                    'provider' => $actualProvider->getProviderName(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                return 0;
            }

            echo "Base Analysis\n";
            echo str_repeat('=', 80) . "\n";
            if (isset($baseAnalysis['critical']) && !empty($baseAnalysis['critical'])) {
                echo "Critical Issues:\n";
                foreach ($baseAnalysis['critical'] as $issue) {
                    echo "  - {$issue['message']}\n";
                }
            }
            if (isset($baseAnalysis['warnings']) && !empty($baseAnalysis['warnings'])) {
                echo "\nWarnings:\n";
                foreach ($baseAnalysis['warnings'] as $warning) {
                    echo "  - {$warning['message']}\n";
                }
            }

            echo "\n\nAI Optimization Suggestions (Provider: " . $actualProvider->getProviderName() . ")\n";
            echo str_repeat('=', 80) . "\n\n";
            $formatter = new MarkdownFormatter();
            echo $formatter->format($aiSuggestions) . "\n";

            return 0;
        } catch (\Throwable $e) {
            return $this->showError('AI optimization analysis failed: ' . $e->getMessage());
        }
    }

    protected function progress(string $message): void
    {
        if ($this->getOption('format', 'text') !== 'json') {
            static::info($message);
        }
    }

    protected function context(bool $doctor): int
    {
        try {
            $db = $this->getDb();
            $result = (new AgentContextService($db))->context();
            if ($doctor) {
                $config = $db->getAiConfig();
                $provider = $config->getDefaultProvider();
                $model = $config->getProviderSetting($provider, 'model');
                $baseUrl = $config->getProviderSetting($provider, 'base_url');
                $hasKey = $config->hasApiKey($provider);
                $probe = $this->getOption('probe') !== null;
                $summary = [
                    'Provider: ' . $provider,
                    'Model: ' . (is_string($model) && $model !== '' ? $model : 'provider default'),
                    'API key: ' . ($hasKey ? 'configured' : 'missing'),
                    'Endpoint: ' . (is_string($baseUrl) && $baseUrl !== '' ? 'configured' : 'provider default'),
                    'Database: connected',
                    'AI request: ' . ($probe ? 'one short probe was requested' : 'not made'),
                ];
                $result['ai'] = [
                    'provider' => $provider,
                    'api_key_configured' => $hasKey,
                    'provider_network_checked' => false,
                    'metadata_tools_require_key' => false,
                    'provider_source' => getenv('PDODB_AI_PROVIDER') ? 'environment' : 'configuration/default',
                    'configured_model' => $model,
                    'settings_precedence' => 'environment > configuration > defaults',
                    'summary' => $summary,
                ];
                $result['database_connected'] = true;
                if ($probe) {
                    $result['ai']['probe_notice'] = 'This check sends one short provider request and may incur a charge. The API key and response body are not shown.';

                    try {
                        $aiProvider = (new AiAnalysisService($db))->getProvider();
                        $aiProvider->analyzeQuery('SELECT 1');
                        $result['ai']['provider_network_checked'] = true;
                        $result['ai']['probe_status'] = 'ok';
                    } catch (\Throwable) {
                        $result['ai']['provider_network_checked'] = true;
                        $result['ai']['probe_status'] = 'failed';
                        $result['ai']['probe_error'] = 'The provider request failed. Check the key, model, and endpoint. The response body is omitted.';
                    }
                }
            }
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            return 0;
        } catch (\Throwable) {
            echo json_encode(['error' => 'Unable to inspect database; check PDO driver, connection configuration and permissions']) . "\n";
            return 1;
        }
    }

    protected function docs(): int
    {
        $query = (string)$this->getArgument(1, '');

        try {
            $result = (new AgentDocumentation())->search($query, (int)$this->getOption('limit', 5));
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            return 0;
        } catch (\InvalidArgumentException $e) {
            echo json_encode(['error' => $e->getMessage()]) . "\n";
            return 1;
        }
    }

    protected function setup(): int
    {
        echo "PDOdb AI setup\n\n";
        echo "Query advice with your AI provider:\n";
        echo '  Choose PDODB_AI_PROVIDER (' . implode(', ', AiConfig::PROVIDERS) . ").\n";
        echo "  Set PDODB_AI_API_KEY and optionally PDODB_AI_MODEL. Custom endpoints use PDODB_AI_BASE_URL.\n";
        echo "  Run pdodb ai query \"SELECT * FROM users\" --table=users.\n\n";
        echo "Optional editor integration (no additional LLM API key):\n";
        echo "  Run pdodb mcp config to preview the local launch configuration.\n";
        echo "  Run pdodb mcp doctor to check the database connection.\n";
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    protected function generationOptions(): array
    {
        $temperature = $this->numericOption('temperature', 0.0, 2.0);
        if ($temperature === false) {
            $this->showError('Option --temperature must be a number from 0 to 2.');
        }
        $maxTokens = $this->positiveIntOption('max-tokens');
        if ($maxTokens === false) {
            $this->showError('Option --max-tokens must be a positive integer.');
        }
        $timeout = $this->positiveIntOption('timeout');
        if ($timeout === false) {
            $this->showError('Option --timeout must be a positive integer.');
        }
        $options = [];
        if ($temperature !== null) {
            $options['temperature'] = $temperature;
        }
        if ($maxTokens !== null) {
            $options['max_tokens'] = $maxTokens;
        }
        if ($timeout !== null) {
            $options['timeout'] = $timeout;
        }
        if ($this->getOption('model') !== null) {
            $options['model'] = (string)$this->getOption('model');
        }
        return $options;
    }

    protected function numericOption(string $name, float $min, float $max): float|false|null
    {
        $value = $this->getOption($name);
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            return false;
        }
        $number = (float)$value;
        return $number >= $min && $number <= $max ? $number : false;
    }

    protected function positiveIntOption(string $name): int|false|null
    {
        $value = $this->getOption($name);
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value) || (int)$value != $value || (int)$value < 1) {
            return false;
        }
        return (int)$value;
    }

    public function getName(): string
    {
        return 'ai';
    }

    public function getDescription(): string
    {
        return 'AI-powered database analysis and optimization';
    }

    protected function showHelp(): int
    {
        echo "AI-Powered Database Analysis\n";
        echo "============================\n\n";
        echo "Usage: pdodb ai <subcommand> [arguments] [options]\n\n";
        echo "Subcommands:\n";
        echo "  context / doctor  Inspect environment without calling an LLM\n";
        echo "  docs <query>      Search bundled documentation (no database needed)\n";
        echo "  setup             Show agent/report setup instructions\n";
        echo "  query <sql>       Analyze query using explainAiAdvice (includes base analysis)\n";
        echo "  schema            Analyze database schema with AI\n";
        echo "  optimize          Get AI-powered optimization suggestions\n";
        echo "  help              Show this help message\n\n";
        echo "Options:\n";
        echo "  --provider=NAME   AI provider (openai, openai-compatible, openrouter, anthropic, google, microsoft, ollama, deepseek, yandex)\n";
        echo "  --model=NAME      Model name (provider-specific)\n";
        echo "  --temperature=N   Temperature (0.0-2.0, default: 0.7)\n";
        echo "  --max-tokens=N    Maximum tokens (default: 2000)\n";
        echo "  --params=JSON     Positional/named parameters for query EXPLAIN\n";
        echo "  --timeout=N       AI provider timeout in seconds\n";
        echo "  --table=NAME      Table name for context\n";
        echo "  --format=FORMAT   Output format (text, json)\n";
        echo "  --probe           With doctor, send one short provider request. It may incur a charge.\n\n";
        echo "Examples:\n";
        echo "  pdodb ai query \"SELECT * FROM users\" --provider=anthropic\n";
        echo "  pdodb ai schema --table=users --format=json\n";
        echo "  pdodb ai optimize --provider=openai --temperature=0.5\n\n";
        echo "Environment Variables:\n";
        echo "  PDODB_AI_PROVIDER        Default AI provider\n";
        echo "  PDODB_AI_API_KEY         API key for the selected provider\n";
        echo "  PDODB_AI_MODEL           Model ID (optional for providers with defaults)\n";
        echo "  PDODB_AI_BASE_URL        Custom endpoint or local Ollama address\n";

        return 0;
    }
}
