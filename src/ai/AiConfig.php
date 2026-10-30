<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

/**
 * Configuration for AI providers.
 */
class AiConfig
{
    public const array PROVIDERS = ['openai', 'openai-compatible', 'openrouter', 'anthropic', 'google', 'microsoft', 'ollama', 'deepseek', 'yandex'];
    protected string $defaultProvider = 'openai';
    /** @var array<string, string> */
    protected array $apiKeys = [];
    /** @var array<string, array<string, mixed>> */
    protected array $providerSettings = [];
    protected ?string $ollamaUrl = null;

    /**
     * @param array<string, mixed>|null $config Configuration array (optional)
     */
    public function __construct(?array $config = null)
    {
        $this->loadFromConfig($config);
        $this->loadFromEnvironment();
        foreach ($this->providerSettings as $provider => $settings) {
            foreach ($settings as $key => $value) {
                $this->setProviderSetting($provider, $key, $value);
            }
        }
    }

    /**
     * Load configuration from config array.
     *
     * @param array<string, mixed>|null $config Configuration array
     */
    protected function loadFromConfig(?array $config): void
    {
        $ai = $config['ai'] ?? [];
        if (!is_array($ai)) {
            return;
        }
        $this->defaultProvider = mb_strtolower((string)($ai['provider'] ?? $this->defaultProvider), 'UTF-8');
        $provider = $this->defaultProvider;
        if (isset($ai['api_key'])) {
            $this->apiKeys[$provider] = (string)$ai['api_key'];
        }
        foreach (['model', 'base_url', 'folder_id', 'temperature', 'max_tokens', 'timeout', 'headers', 'request_options'] as $key) {
            if (isset($ai[$key])) {
                $this->providerSettings[$provider][$key] = $ai[$key];
            }
        }
        if ($provider === 'ollama' && isset($ai['base_url'])) {
            $this->ollamaUrl = (string)$ai['base_url'];
        }
    }

    /** Load environment settings for the selected provider only. */
    protected function loadFromEnvironment(): void
    {
        $provider = getenv('PDODB_AI_PROVIDER');
        if ($provider !== false && $provider !== '') {
            $this->defaultProvider = mb_strtolower($provider, 'UTF-8');
        }
        $provider = $this->defaultProvider;
        foreach (['API_KEY' => 'api_key', 'MODEL' => 'model', 'BASE_URL' => 'base_url',
            'FOLDER_ID' => 'folder_id',
            'TEMPERATURE' => 'temperature', 'MAX_TOKENS' => 'max_tokens', 'TIMEOUT' => 'timeout'] as $suffix => $key) {
            $value = getenv('PDODB_AI_' . $suffix);
            if ($value === false || $value === '') {
                continue;
            }
            if ($key === 'api_key') {
                $this->apiKeys[$provider] = $value;
            } else {
                $this->setProviderSetting($provider, $key, $value);
                if ($provider === 'ollama' && $key === 'base_url') {
                    $this->ollamaUrl = $value;
                }
            }
        }
    }

    protected function validateSetting(string $key, mixed $value): mixed
    {
        if ($key === 'temperature') {
            if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 2) {
                throw new \InvalidArgumentException('AI temperature must be a number between 0 and 2');
            }
            return (float)$value;
        }
        if (in_array($key, ['max_tokens', 'timeout'], true)) {
            $number = filter_var($value, FILTER_VALIDATE_INT);
            if ($number === false || $number < 1) {
                throw new \InvalidArgumentException("AI {$key} must be a positive integer");
            }
            return $number;
        }
        return $value;
    }

    /**
     * Get default provider name.
     */
    public function getDefaultProvider(): string
    {
        return $this->defaultProvider;
    }

    /**
     * Get API key for provider.
     */
    public function getApiKey(string $provider): ?string
    {
        return $this->apiKeys[mb_strtolower($provider, 'UTF-8')] ?? null;
    }

    /**
     * Set API key for provider.
     */
    public function setApiKey(string $provider, string $key): void
    {
        $this->apiKeys[mb_strtolower($provider, 'UTF-8')] = $key;
    }

    /**
     * Get Ollama URL.
     */
    public function getOllamaUrl(): string
    {
        return $this->ollamaUrl ?? 'http://localhost:11434';
    }

    /**
     * Set Ollama URL.
     */
    public function setOllamaUrl(string $url): void
    {
        $this->ollamaUrl = $url;
    }

    /**
     * Get provider setting.
     */
    public function getProviderSetting(string $provider, string $key, mixed $default = null): mixed
    {
        $provider = mb_strtolower($provider, 'UTF-8');
        return $this->providerSettings[$provider][$key] ?? $default;
    }

    /**
     * Set provider setting.
     */
    public function setProviderSetting(string $provider, string $key, mixed $value): void
    {
        $provider = mb_strtolower($provider, 'UTF-8');
        if (!isset($this->providerSettings[$provider])) {
            $this->providerSettings[$provider] = [];
        }
        $this->providerSettings[$provider][$key] = $value === null ? null : $this->validateSetting($key, $value);
    }

    /**
     * Check if provider has API key configured.
     */
    public function hasApiKey(string $provider): bool
    {
        return ($this->apiKeys[mb_strtolower($provider, 'UTF-8')] ?? '') !== '';
    }
}
