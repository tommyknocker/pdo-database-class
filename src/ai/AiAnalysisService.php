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

    private ?AiUsage $lastUsage = null;

    private ?AiUsage $totalUsage = null;

    /**
     * @param PdoDb $db Database instance
     * @param AiConfig|null $config AI config instance (optional)
     * @param array<string, mixed>|null $configArray Config array for AiConfig (optional)
     * @param AiTransport|null $transport How requests reach the provider; null sends them with PHP streams
     */
    public function __construct(PdoDb $db, ?AiConfig $config = null, ?array $configArray = null, protected ?AiTransport $transport = null)
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
            'openai' => new OpenAiProvider($this->config, $this->transport),
            'openai-compatible', 'openrouter' => new OpenAiCompatibleProvider($this->config, $providerName, $this->transport),
            'anthropic' => new AnthropicProvider($this->config, $this->transport),
            'google' => new GoogleProvider($this->config, $this->transport),
            'microsoft' => new MicrosoftProvider($this->config, $this->transport),
            'ollama' => new OllamaProvider($this->config, $this->transport),
            'deepseek' => new DeepSeekProvider($this->config, $this->transport),
            'yandex' => new YandexProvider($this->config, $this->transport),
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

    /** @param array<string, mixed> $options */
    private function providerWithOptions(?string $provider, array $options): AiProviderInterface
    {
        $aiProvider = clone $this->getProvider($provider);
        if (isset($options['temperature'])) {
            if (!is_numeric($options['temperature'])) {
                throw new \InvalidArgumentException('AI temperature must be a number between 0 and 2');
            }
            $aiProvider->setTemperature((float)$options['temperature']);
        }
        foreach (['max_tokens', 'timeout'] as $key) {
            if (isset($options[$key])) {
                $number = filter_var($options[$key], FILTER_VALIDATE_INT);
                if ($number === false || $number < 1) {
                    throw new \InvalidArgumentException("AI {$key} must be a positive integer");
                }
                if ($key === 'max_tokens') {
                    $aiProvider->setMaxTokens($number);
                } else {
                    $aiProvider->setTimeout($number);
                }
            }
        }
        if (isset($options['model'])) {
            $aiProvider->setModel((string)$options['model']);
        }
        return $aiProvider;
    }

    /** Send a previously inspected context. The caller owns its disclosure policy.
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     */
    public function analyzeQueryContext(array $context, ?string $provider = null, array $options = []): string
    {
        $aiProvider = $this->providerWithOptions($provider, $options);

        return $this->recordUsage($aiProvider, fn (): string => $aiProvider->analyzeQuery((string)($context['sql'] ?? ''), $context));
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
        $aiProvider = $this->providerWithOptions($provider, $options);

        $context = $this->contextBuilder->buildSchemaContext($tableName);

        return $this->recordUsage($aiProvider, fn (): string => $aiProvider->analyzeSchema($context['schema'] ?? [], $context));
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
        $aiProvider = $this->providerWithOptions($provider, $options);

        return $this->recordUsage($aiProvider, fn (): string => $aiProvider->suggestOptimizations($analysis, $context));
    }

    /**
     * Tokens used by the most recent request, or null when none was made or the provider did not report them.
     */
    public function getLastUsage(): ?AiUsage
    {
        return $this->lastUsage;
    }

    /**
     * Tokens used by all requests made through this service so far, for providers that report them.
     */
    public function getTotalUsage(): ?AiUsage
    {
        return $this->totalUsage;
    }

    /**
     * Run a request and note the tokens it used. A failed request used none that the provider reported.
     *
     * @param callable(): string $request
     */
    private function recordUsage(AiProviderInterface $provider, callable $request): string
    {
        $this->lastUsage = null;
        $answer = $request();
        $usage = $provider instanceof AiUsageReporting ? $provider->getLastUsage() : null;
        $this->lastUsage = $usage;
        if ($usage !== null) {
            $this->totalUsage = $this->totalUsage === null ? $usage : $this->totalUsage->plus($usage);
        }

        return $answer;
    }
}
