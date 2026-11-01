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
use tommyknocker\pdodb\exceptions\QueryException;

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
            default => $this->fail("Unknown subcommand: {$subcommand}. Use 'ai --help' for usage."),
        };
    }

    /**
     * Analyze query using explainAiAdvice.
     */
    protected function query(): int
    {
        $sql = $this->getArgument(1);
        if ($sql === null || $sql === '') {
            return $this->fail('SQL query is required. Usage: pdodb ai query "SELECT * FROM users"');
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

            if ($format !== 'json') {
                $this->write("\r" . str_repeat(' ', 80) . "\r");
            }

            $providerName = $actualProvider->getProviderName();

            if ($format === 'json') {
                $this->writeln(json_encode([
                    'base_analysis' => QueryInspectionService::format($baseAnalysis),
                    'plan_source' => $inspection['source'],
                    'warnings' => $inspection['warnings'],
                    'ai_analysis' => $aiAnalysis,
                    'provider' => $providerName,
                    'model' => $model,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return 0;
            }

            foreach ($inspection['warnings'] as $warning) {
                $this->writeln("Warning: {$warning}");
            }
            $this->writeln('Base Analysis');
            $this->writeln(str_repeat('=', 80));
            if ($baseAnalysis !== null && $baseAnalysis->issues !== []) {
                foreach ($baseAnalysis->issues as $issue) {
                    $severity = strtoupper($issue->severity);
                    $this->writeln("[{$severity}] {$issue->description}");
                }
            }
            if ($baseAnalysis !== null && $baseAnalysis->recommendations !== []) {
                $this->writeln("\nRecommendations:");
                foreach ($baseAnalysis->recommendations as $rec) {
                    $severity = strtoupper($rec->severity);
                    $this->writeln("[{$severity}] {$rec->message}");
                    if (isset($rec->suggestion)) {
                        $this->writeln("  SQL: {$rec->suggestion}");
                    }
                }
            }

            $this->write("\n\nAI Analysis (Provider: {$providerName}" . ($model ? ", Model: {$model}" : '') . ")\n");
            $this->write(str_repeat('=', 80) . "\n\n");
            $formatter = new MarkdownFormatter();
            $this->writeln($formatter->format($aiAnalysis));

            return 0;
        } catch (\Throwable $e) {
            return $this->fail('AI query analysis failed: ' . $e->getMessage());
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

            if ($format !== 'json') {
                $this->write("\r" . str_repeat(' ', 80) . "\r");
            }

            if ($format === 'json') {
                $this->writeln(json_encode([
                    'table' => $tableName,
                    'provider' => $actualProvider->getProviderName(),
                    'analysis' => $analysis,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return 0;
            }

            $this->write('AI Schema Analysis (Provider: ' . $actualProvider->getProviderName() . ")\n");
            if ($tableName !== null) {
                $this->writeln("Table: {$tableName}");
            }
            $this->write(str_repeat('=', 80) . "\n\n");
            $formatter = new MarkdownFormatter();
            $this->writeln($formatter->format($analysis));

            return 0;
        } catch (\Throwable $e) {
            return $this->fail('AI schema analysis failed: ' . $e->getMessage());
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

            if ($format !== 'json') {
                $this->write("\r" . str_repeat(' ', 80) . "\r");
            }

            if ($format === 'json') {
                $this->writeln(json_encode([
                    'base_analysis' => $baseAnalysis,
                    'ai_suggestions' => $aiSuggestions,
                    'provider' => $actualProvider->getProviderName(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return 0;
            }

            $this->writeln('Base Analysis');
            $this->writeln(str_repeat('=', 80));
            if (isset($baseAnalysis['critical']) && !empty($baseAnalysis['critical'])) {
                $this->writeln('Critical Issues:');
                foreach ($baseAnalysis['critical'] as $issue) {
                    $this->writeln("  - {$issue['message']}");
                }
            }
            if (isset($baseAnalysis['warnings']) && !empty($baseAnalysis['warnings'])) {
                $this->writeln("\nWarnings:");
                foreach ($baseAnalysis['warnings'] as $warning) {
                    $this->writeln("  - {$warning['message']}");
                }
            }

            $this->write("\n\nAI Optimization Suggestions (Provider: " . $actualProvider->getProviderName() . ")\n");
            $this->write(str_repeat('=', 80) . "\n\n");
            $formatter = new MarkdownFormatter();
            $this->writeln($formatter->format($aiSuggestions));

            return 0;
        } catch (\Throwable $e) {
            return $this->fail('AI optimization analysis failed: ' . $e->getMessage());
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
            $exit = 0;
            if ($doctor) {
                $config = $db->getAiConfig();
                $provider = $config->getDefaultProvider();
                $model = $config->getProviderSetting($provider, 'model');
                $baseUrl = $config->getProviderSetting($provider, 'base_url');
                $hasKey = $config->hasApiKey($provider);
                $probe = $this->getOption('probe') !== null;
                $requestSummary = 'AI request: not made';
                $probeStatus = null;
                $probeError = null;
                $networkChecked = false;
                $exit = 0;
                $aiProvider = null;
                if ($probe) {
                    try {
                        $aiProvider = (new AiAnalysisService($db))->getProvider();
                    } catch (QueryException) {
                        $aiProvider = null;
                    }
                    if ($aiProvider === null || !$aiProvider->isAvailable()) {
                        $requestSummary = 'AI request: not sent';
                        $probeStatus = 'not_sent';
                        $probeError = 'The probe was not sent because the provider is not configured. No network request was made.';
                        $exit = 1;
                    } else {
                        try {
                            $aiProvider->analyzeQuery('SELECT 1');
                            $requestSummary = 'AI request: one short probe completed';
                            $probeStatus = 'ok';
                            $networkChecked = true;
                        } catch (\Throwable) {
                            $requestSummary = 'AI request: probe failed';
                            $probeStatus = 'failed';
                            $probeError = 'The provider request failed. Check the key, model, and endpoint. The response body is omitted.';
                            $networkChecked = true;
                            $exit = 1;
                        }
                    }
                }
                $summary = [
                    'Provider: ' . $provider,
                    'Model: ' . (is_string($model) && $model !== '' ? $model : 'provider default'),
                    'API key: ' . ($hasKey ? 'configured' : 'missing'),
                    'Endpoint: ' . (is_string($baseUrl) && $baseUrl !== '' ? 'configured' : 'provider default'),
                    'Database: connected',
                    $requestSummary,
                ];
                $result['ai'] = [
                    'provider' => $provider,
                    'api_key_configured' => $hasKey,
                    'provider_network_checked' => $networkChecked,
                    'metadata_tools_require_key' => false,
                    'provider_source' => getenv('PDODB_AI_PROVIDER') ? 'environment' : 'configuration/default',
                    'configured_model' => $model,
                    'settings_precedence' => 'environment > configuration > defaults',
                    'summary' => $summary,
                ];
                $result['database_connected'] = true;
                if ($probeStatus !== null) {
                    $result['ai']['probe_status'] = $probeStatus;
                }
                if ($aiProvider !== null && $aiProvider->isAvailable()) {
                    $result['ai']['probe_notice'] = 'This check sends one short provider request and may incur a charge. The API key and response body are not shown.';
                }
                if ($probeError !== null) {
                    $result['ai']['probe_error'] = $probeError;
                }
            }
            $this->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return $exit;
        } catch (\Throwable) {
            $this->writeErrorLine(json_encode(['error' => 'Unable to inspect database; check PDO driver, connection configuration and permissions'], JSON_THROW_ON_ERROR));
            return 1;
        }
    }

    protected function docs(): int
    {
        $query = (string)$this->getArgument(1, '');

        try {
            $result = (new AgentDocumentation())->search($query, (int)$this->getOption('limit', 5));
            $this->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return 0;
        } catch (\InvalidArgumentException $e) {
            $this->writeErrorLine(json_encode(['error' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
            return 1;
        }
    }

    protected function setup(): int
    {
        $this->writeln("PDOdb AI setup\n");
        $this->writeln('Query advice with your AI provider:');
        $this->write('  Choose PDODB_AI_PROVIDER (' . implode(', ', AiConfig::PROVIDERS) . ").\n");
        $this->writeln('  Set PDODB_AI_API_KEY and optionally PDODB_AI_MODEL. Custom endpoints use PDODB_AI_BASE_URL.');
        $this->writeln("  Run pdodb ai query \"SELECT * FROM users\" --table=users.\n");
        $this->writeln('Optional editor integration (no additional LLM API key):');
        $this->writeln('  Run pdodb mcp config to preview the local launch configuration.');
        $this->writeln('  Run pdodb mcp doctor to check the database connection.');
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
        $this->writeln('AI-Powered Database Analysis');
        $this->writeln("============================\n");
        $this->writeln("Usage: pdodb ai <subcommand> [arguments] [options]\n");
        $this->writeln('Subcommands:');
        $this->writeln('  context / doctor  Inspect environment without calling an LLM');
        $this->writeln('  docs <query>      Search bundled documentation (no database needed)');
        $this->writeln('  setup             Show agent/report setup instructions');
        $this->writeln('  query <sql>       Analyze query using explainAiAdvice (includes base analysis)');
        $this->writeln('  schema            Analyze database schema with AI');
        $this->writeln('  optimize          Get AI-powered optimization suggestions');
        $this->writeln("  help              Show this help message\n");
        $this->writeln('Options:');
        $this->writeln('  --provider=NAME   AI provider (openai, openai-compatible, openrouter, anthropic, google, microsoft, ollama, deepseek, yandex)');
        $this->writeln('  --model=NAME      Model name (provider-specific)');
        $this->writeln('  --temperature=N   Temperature (0.0-2.0, default: 0.7)');
        $this->writeln('  --max-tokens=N    Maximum tokens (default: 2000)');
        $this->writeln('  --params=JSON     Positional/named parameters for query EXPLAIN');
        $this->writeln('  --timeout=N       AI provider timeout in seconds');
        $this->writeln('  --table=NAME      Table name for context');
        $this->writeln('  --format=FORMAT   Output format (text, json)');
        $this->writeln("  --probe           With doctor, send one short provider request when the provider is configured. It may incur a charge. A missing configuration does not call the network and exits non-zero.\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb ai query "SELECT * FROM users" --provider=anthropic');
        $this->writeln('  pdodb ai schema --table=users --format=json');
        $this->writeln("  pdodb ai optimize --provider=openai --temperature=0.5\n");
        $this->writeln('Environment Variables:');
        $this->writeln('  PDODB_AI_PROVIDER        Default AI provider');
        $this->writeln('  PDODB_AI_API_KEY         API key for the selected provider');
        $this->writeln('  PDODB_AI_MODEL           Model ID (optional for providers with defaults)');
        $this->writeln('  PDODB_AI_BASE_URL        Custom endpoint or local Ollama address');

        return 0;
    }
}
