<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use tommyknocker\pdodb\ai\providers\AnthropicProvider;
use tommyknocker\pdodb\ai\providers\DeepSeekProvider;
use tommyknocker\pdodb\ai\providers\GoogleProvider;
use tommyknocker\pdodb\ai\providers\MicrosoftProvider;
use tommyknocker\pdodb\ai\providers\OllamaProvider;
use tommyknocker\pdodb\ai\providers\OpenAiCompatibleProvider;
use tommyknocker\pdodb\ai\providers\OpenAiProvider;
use tommyknocker\pdodb\ai\providers\YandexProvider;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;

/**
 * Service for AI-powered database analysis.
 */
class AiAnalysisService
{
    protected AiConfig $config;
    protected PdoDb $db;
    protected AiAnalysisContext $contextBuilder;
    protected ?AiProviderInterface $currentProvider = null;

    /**
     * @param PdoDb $db Database instance
     * @param AiConfig|null $config AI config instance (optional)
     * @param array<string, mixed>|null $configArray Config array for AiConfig (optional)
     */
    public function __construct(PdoDb $db, ?AiConfig $config = null, ?array $configArray = null)
    {
        $this->db = $db;
        $this->config = $config ?? ($configArray !== null ? new AiConfig($configArray) : $db->getAiConfig());
        $this->contextBuilder = new AiAnalysisContext($db);
    }

    /**
     * Get AI provider by name.
     *
     * @param string|null $providerName Provider name or null for default
     *
     * @return AiProviderInterface Provider instance
     * @throws QueryException If provider is not available
     */
    public function getProvider(?string $providerName = null): AiProviderInterface
    {
        $providerName = mb_strtolower($providerName ?? $this->config->getDefaultProvider(), 'UTF-8');

        if ($this->currentProvider !== null && $this->currentProvider->getProviderName() === $providerName) {
            return $this->currentProvider;
        }

        $provider = $this->createProvider($providerName);

        if (!$provider->isAvailable()) {
            throw new QueryException(
                "AI provider '{$providerName}' is not available. Please check your configuration.",
                0
            );
        }

        $this->currentProvider = $provider;

        return $provider;
    }

    /**
     * Create provider instance.
     *
     * @param string $providerName Provider name
     *
     * @return AiProviderInterface Provider instance
     * @throws QueryException If provider is not supported
     */
    protected function createProvider(string $providerName): AiProviderInterface
    {
        $providerName = mb_strtolower($providerName, 'UTF-8');

        return match ($providerName) {
            'openai' => new OpenAiProvider($this->config),
            'openai-compatible', 'openrouter' => new OpenAiCompatibleProvider($this->config, $providerName),
            'anthropic' => new AnthropicProvider($this->config),
            'google' => new GoogleProvider($this->config),
            'microsoft' => new MicrosoftProvider($this->config),
            'ollama' => new OllamaProvider($this->config),
            'deepseek' => new DeepSeekProvider($this->config),
            'yandex' => new YandexProvider($this->config),
            default => throw new QueryException(
                "Unsupported AI provider: {$providerName}. Supported: openai, openai-compatible, openrouter, anthropic, google, microsoft, ollama, deepseek, yandex",
                0
            ),
        };
    }

    /**
     * Analyze SQL query with AI.
     *
     * @param string $sql SQL query
     * @param string|null $tableName Table name
     * @param string|null $provider Provider name
     * @param array<string, mixed> $options Additional options
     * @param ExplainAnalysis|null $explainAnalysis Optional explain analysis to include in context
     *
     * @return string AI analysis
     */
    public function analyzeQuery(string $sql, ?string $tableName = null, ?string $provider = null, array $options = [], ?ExplainAnalysis $explainAnalysis = null): string
    {
        $context = $this->previewQueryContext($sql, $tableName, $options, $explainAnalysis);
        return $this->analyzeQueryContext($context, $provider, $options);
    }

    /** Send a previously inspected context. The caller owns its disclosure policy.
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     */
    public function analyzeQueryContext(array $context, ?string $provider = null, array $options = []): string
    {
        $aiProvider = clone $this->getProvider($provider);

        if (isset($options['temperature'])) {
            $aiProvider->setTemperature((float)$options['temperature']);
        }
        if (isset($options['max_tokens'])) {
            $aiProvider->setMaxTokens((int)$options['max_tokens']);
        }
        if (isset($options['model'])) {
            $aiProvider->setModel((string)$options['model']);
        }
        if (isset($options['timeout'])) {
            $aiProvider->setTimeout((int)$options['timeout']);
        }

        return $aiProvider->analyzeQuery((string)($context['sql'] ?? ''), $context);
    }

    /** Build the exact query context without making an AI request.
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function previewQueryContext(string $sql, ?string $tableName = null, array $options = [], ?ExplainAnalysis $explainAnalysis = null): array
    {
        $params = $options['params'] ?? [];
        if (!is_array($params)) {
            throw new \InvalidArgumentException('AI query params must be an array');
        }
        if (isset($options['structured']) && !is_bool($options['structured'])) {
            throw new \InvalidArgumentException('AI structured option must be boolean');
        }
        return $this->contextBuilder->buildEnrichedQueryContext($sql, $tableName, $explainAnalysis, $options, $params);
    }

    /**
     * Analyze database schema with AI.
     *
     * @param string|null $tableName Table name or null for all tables
     * @param string|null $provider Provider name
     * @param array<string, mixed> $options Additional options
     *
     * @return string AI analysis
     */
    public function analyzeSchema(?string $tableName = null, ?string $provider = null, array $options = []): string
    {
        $aiProvider = clone $this->getProvider($provider);

        if (isset($options['temperature'])) {
            $aiProvider->setTemperature((float)$options['temperature']);
        }
        if (isset($options['max_tokens'])) {
            $aiProvider->setMaxTokens((int)$options['max_tokens']);
        }
        if (isset($options['model'])) {
            $aiProvider->setModel((string)$options['model']);
        }
        if (isset($options['timeout'])) {
            $aiProvider->setTimeout((int)$options['timeout']);
        }

        $context = $this->contextBuilder->buildSchemaContext($tableName);

        return $aiProvider->analyzeSchema($context['schema'] ?? [], $context);
    }

    /**
     * Get optimization suggestions based on existing analysis.
     *
     * @param array<string, mixed> $analysis Existing analysis
     * @param array<string, mixed> $context Additional context
     * @param string|null $provider Provider name
     * @param array<string, mixed> $options Additional options
     *
     * @return string AI suggestions
     */
    public function suggestOptimizations(array $analysis, array $context = [], ?string $provider = null, array $options = []): string
    {
        $aiProvider = clone $this->getProvider($provider);

        if (isset($options['temperature'])) {
            $aiProvider->setTemperature((float)$options['temperature']);
        }
        if (isset($options['max_tokens'])) {
            $aiProvider->setMaxTokens((int)$options['max_tokens']);
        }
        if (isset($options['model'])) {
            $aiProvider->setModel((string)$options['model']);
        }
        if (isset($options['timeout'])) {
            $aiProvider->setTimeout((int)$options['timeout']);
        }

        return $aiProvider->suggestOptimizations($analysis, $context);
    }
}
