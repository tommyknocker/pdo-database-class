<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\MarkdownFormatter;
use tommyknocker\pdodb\cli\SchemaAnalyzer;
use tommyknocker\pdodb\query\analysis\AiExplainAnalysis;
use tommyknocker\pdodb\query\analysis\ExplainAnalyzer;

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
            'analyze' => $this->analyze(),
            'query' => $this->query(),
            'schema' => $this->schema(),
            'optimize' => $this->optimize(),
            'help', '--help', '-h', null => $this->showHelp(),
            default => $this->showError("Unknown subcommand: {$subcommand}. Use 'ai --help' for usage."),
        };
    }

    /**
     * Analyze SQL query with AI.
     */
    protected function analyze(): int
    {
        $sql = $this->getArgument(1);
        if ($sql === null || $sql === '') {
            return $this->showError('SQL query is required. Usage: pdodb ai analyze "SELECT * FROM users"');
        }

        $db = $this->getDb();
        $provider = $this->getOption('provider');
        $format = (string)$this->getOption('format', 'text');

        $options = [];
        if (($this->getOption('temperature')) !== null) {
            $options['temperature'] = (float)$this->getOption('temperature');
        }
        if (($this->getOption('max-tokens')) !== null) {
            $options['max_tokens'] = (int)$this->getOption('max-tokens');
        }
        if (($this->getOption('model')) !== null) {
            $options['model'] = (string)$this->getOption('model');
        }

        $tableName = $this->getOption('table');

        try {
            // Get EXPLAIN plan for better AI analysis
            static::info('Analyzing query execution plan...');
            $connection = $db->connection;
            $dialect = $connection->getDialect();
            $pdo = $connection->getPdo();

            $explainAnalysis = null;
            try {
                $explainResults = $dialect->executeExplain($pdo, $sql, []);
                $queryBuilder = $db->find();
                $reflection = new \ReflectionClass($queryBuilder);
                $executionEngineProperty = $reflection->getProperty('executionEngine');
                $executionEngineProperty->setAccessible(true);
                $executionEngine = $executionEngineProperty->getValue($queryBuilder);
                $analyzer = new ExplainAnalyzer($dialect, $executionEngine);
                $explainAnalysis = $analyzer->analyze($explainResults, $tableName);
            } catch (\Throwable $e) {
                // If EXPLAIN fails, continue without it (e.g., invalid SQL or unsupported)
                static::info('Note: Could not get execution plan: ' . $e->getMessage());
            }

            static::info('Initializing AI service...');
            $aiService = new AiAnalysisService($db);
            $actualProvider = $aiService->getProvider($provider);
            $model = $actualProvider->getModel();

            static::info("Provider: {$actualProvider->getProviderName()}");
            static::info("Model: {$model}");
            static::loading('Sending request to AI API');

            $analysis = $aiService->analyzeQuery($sql, $tableName, $provider, $options, $explainAnalysis);

            if (getenv('PHPUNIT') === false) {
                echo "\r" . str_repeat(' ', 80) . "\r"; // Clear loading line
            }

            if ($format === 'json') {
                echo json_encode([
                    'sql' => $sql,
                    'provider' => $actualProvider->getProviderName(),
                    'analysis' => $analysis,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                return 0;
            }

            echo 'AI Analysis (Provider: ' . $actualProvider->getProviderName() . ")\n";
            echo str_repeat('=', 80) . "\n\n";
            $formatter = new MarkdownFormatter();
            echo $formatter->format($analysis) . "\n";

            return 0;
        } catch (\Throwable $e) {
            return $this->showError('AI analysis failed: ' . $e->getMessage());
        }
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

        $db = $this->getDb();
        $provider = $this->getOption('provider');
        $format = (string)$this->getOption('format', 'text');
        $tableName = $this->getOption('table');

        $options = [];
        if (($this->getOption('temperature')) !== null) {
            $options['temperature'] = (float)$this->getOption('temperature');
        }
        if (($this->getOption('max-tokens')) !== null) {
            $options['max_tokens'] = (int)$this->getOption('max-tokens');
        }
        if (($this->getOption('model')) !== null) {
            $options['model'] = (string)$this->getOption('model');
        }

        try {
            static::info('Analyzing query execution plan...');
            // For raw SQL, we need to use ExplainAnalyzer and AiAnalysisService directly
            $connection = $db->connection;
            $dialect = $connection->getDialect();
            $pdo = $connection->getPdo();

            // Get base analysis - use QueryBuilder's internal execution engine
            $explainResults = $dialect->executeExplain($pdo, $sql, []);
            $queryBuilder = $db->find();
            // Access execution engine via reflection or create new one
            $reflection = new \ReflectionClass($queryBuilder);
            $executionEngineProperty = $reflection->getProperty('executionEngine');
            $executionEngineProperty->setAccessible(true);
            $executionEngine = $executionEngineProperty->getValue($queryBuilder);
            $analyzer = new ExplainAnalyzer($dialect, $executionEngine);
            $baseAnalysis = $analyzer->analyze($explainResults, $tableName);

            // Then get AI analysis
            static::info('Initializing AI service...');
            $aiService = new AiAnalysisService($db);
            $actualProvider = $aiService->getProvider($provider);
            $model = $actualProvider->getModel();

            static::info("Provider: {$actualProvider->getProviderName()}");
            static::info("Model: {$model}");
            static::loading('Sending request to AI API');

            // Pass baseAnalysis (which includes EXPLAIN plan) to AI for better analysis
            $aiAnalysis = $aiService->analyzeQuery($sql, $tableName, $provider, $options, $baseAnalysis);

            if (getenv('PHPUNIT') === false) {
                echo "\r" . str_repeat(' ', 80) . "\r"; // Clear loading line
            }

            $result = new AiExplainAnalysis(
                $baseAnalysis,
                $aiAnalysis,
                $actualProvider->getProviderName(),
                $model
            );

            if ($format === 'json') {
                echo json_encode([
                    'base_analysis' => [
                        'raw_explain' => $result->baseAnalysis->rawExplain,
                        'issues' => array_map(function ($issue) {
                            return [
                                'type' => $issue->type,
                                'severity' => $issue->severity,
                                'description' => $issue->description,
                                'table' => $issue->table ?? null,
                            ];
                        }, $result->baseAnalysis->issues),
                        'recommendations' => array_map(function ($rec) {
                            return [
                                'type' => $rec->type,
                                'severity' => $rec->severity,
                                'message' => $rec->message,
                                'suggestion' => $rec->suggestion ?? null,
                            ];
                        }, $result->baseAnalysis->recommendations),
                    ],
                    'ai_analysis' => $result->aiAnalysis,
                    'provider' => $result->provider,
                    'model' => $result->model,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                return 0;
            }

            echo "Base Analysis\n";
            echo str_repeat('=', 80) . "\n";
            if (!empty($result->baseAnalysis->issues)) {
                foreach ($result->baseAnalysis->issues as $issue) {
                    $severity = strtoupper($issue->severity);
                    echo "[{$severity}] {$issue->description}\n";
                }
            }
            if (!empty($result->baseAnalysis->recommendations)) {
                echo "\nRecommendations:\n";
                foreach ($result->baseAnalysis->recommendations as $rec) {
                    $severity = strtoupper($rec->severity);
                    echo "[{$severity}] {$rec->message}\n";
                    if (isset($rec->suggestion)) {
                        echo "  SQL: {$rec->suggestion}\n";
                    }
                }
            }

            echo "\n\nAI Analysis (Provider: {$result->provider}" . ($result->model ? ", Model: {$result->model}" : '') . ")\n";
            echo str_repeat('=', 80) . "\n\n";
            $formatter = new MarkdownFormatter();
            echo $formatter->format($result->aiAnalysis) . "\n";

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
        $db = $this->getDb();
        $provider = $this->getOption('provider');
        $format = (string)$this->getOption('format', 'text');
        $tableName = $this->getOption('table');

        $options = [];
        if (($this->getOption('temperature')) !== null) {
            $options['temperature'] = (float)$this->getOption('temperature');
        }
        if (($this->getOption('max-tokens')) !== null) {
            $options['max_tokens'] = (int)$this->getOption('max-tokens');
        }
        if (($this->getOption('model')) !== null) {
            $options['model'] = (string)$this->getOption('model');
        }

        try {
            static::info('Collecting schema information...');
            if ($tableName !== null) {
                static::info("Table: {$tableName}");
            }

            static::info('Initializing AI service...');
            $aiService = new AiAnalysisService($db);
            $actualProvider = $aiService->getProvider($provider);
            $model = $actualProvider->getModel();

            static::info("Provider: {$actualProvider->getProviderName()}");
            static::info("Model: {$model}");
            static::loading('Sending request to AI API');

            $analysis = $aiService->analyzeSchema($tableName, $provider, $options);

            if (getenv('PHPUNIT') === false) {
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
        $db = $this->getDb();
        $provider = $this->getOption('provider');
        $format = (string)$this->getOption('format', 'text');
        $tableName = $this->getOption('table');

        $options = [];
        if (($this->getOption('temperature')) !== null) {
            $options['temperature'] = (float)$this->getOption('temperature');
        }
        if (($this->getOption('max-tokens')) !== null) {
            $options['max_tokens'] = (int)$this->getOption('max-tokens');
        }
        if (($this->getOption('model')) !== null) {
            $options['model'] = (string)$this->getOption('model');
        }

        try {
            static::info('Analyzing database schema...');
            if ($tableName !== null) {
                static::info("Table: {$tableName}");
            }

            // Get base analysis first
            $schemaAnalyzer = new SchemaAnalyzer($db);
            $baseAnalysis = $tableName !== null
                ? $schemaAnalyzer->analyzeTable($tableName)
                : $schemaAnalyzer->analyze();

            // Get AI suggestions
            static::info('Initializing AI service...');
            $aiService = new AiAnalysisService($db);
            $actualProvider = $aiService->getProvider($provider);
            $model = $actualProvider->getModel();

            static::info("Provider: {$actualProvider->getProviderName()}");
            static::info("Model: {$model}");
            static::loading('Sending request to AI API');

            $context = [
                'base_analysis' => $baseAnalysis,
            ];
            $aiSuggestions = $aiService->suggestOptimizations($baseAnalysis, $context, $provider, $options);

            if (getenv('PHPUNIT') === false) {
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
        echo "  analyze <sql>     Analyze SQL query with AI\n";
        echo "  query <sql>       Analyze query using explainAiAdvice (includes base analysis)\n";
        echo "  schema            Analyze database schema with AI\n";
        echo "  optimize          Get AI-powered optimization suggestions\n";
        echo "  help              Show this help message\n\n";
        echo "Options:\n";
        echo "  --provider=NAME   AI provider (openai, anthropic, google, microsoft, ollama)\n";
        echo "  --model=NAME      Model name (provider-specific)\n";
        echo "  --temperature=N   Temperature (0.0-2.0, default: 0.7)\n";
        echo "  --max-tokens=N    Maximum tokens (default: 2000)\n";
        echo "  --table=NAME      Table name for context\n";
        echo "  --format=FORMAT   Output format (text, json)\n\n";
        echo "Examples:\n";
        echo "  pdodb ai analyze \"SELECT * FROM users WHERE id = 1\"\n";
        echo "  pdodb ai query \"SELECT * FROM users\" --provider=anthropic\n";
        echo "  pdodb ai schema --table=users --format=json\n";
        echo "  pdodb ai optimize --provider=openai --temperature=0.5\n\n";
        echo "Environment Variables:\n";
        echo "  PDODB_AI_PROVIDER        Default AI provider\n";
        echo "  PDODB_AI_OPENAI_KEY      OpenAI API key\n";
        echo "  PDODB_AI_ANTHROPIC_KEY   Anthropic API key\n";
        echo "  PDODB_AI_GOOGLE_KEY      Google API key\n";
        echo "  PDODB_AI_MICROSOFT_KEY   Microsoft API key\n";
        echo "  PDODB_AI_OLLAMA_URL      Ollama server URL (default: http://localhost:11434)\n";

        return 0;
    }
}
