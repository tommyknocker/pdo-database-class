<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

/**
 * Configuration for AI providers.
 */
class AiConfig
{
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
    }

    /**
     * Load configuration from config array.
     *
     * @param array<string, mixed>|null $config Configuration array
     */
    protected function loadFromConfig(?array $config): void
    {
        if ($config === null) {
            return;
        }

        $aiConfig = $config['ai'] ?? [];

        if (isset($aiConfig['provider'])) {
            $this->defaultProvider = mb_strtolower((string)$aiConfig['provider'], 'UTF-8');
        }

        if (isset($aiConfig['openai_key'])) {
            $this->apiKeys['openai'] = (string)$aiConfig['openai_key'];
        }

        if (isset($aiConfig['anthropic_key'])) {
            $this->apiKeys['anthropic'] = (string)$aiConfig['anthropic_key'];
        }

        if (isset($aiConfig['google_key'])) {
            $this->apiKeys['google'] = (string)$aiConfig['google_key'];
        }

        if (isset($aiConfig['microsoft_key'])) {
            $this->apiKeys['microsoft'] = (string)$aiConfig['microsoft_key'];
        }

        if (isset($aiConfig['ollama_url'])) {
            $this->ollamaUrl = (string)$aiConfig['ollama_url'];
        }

        // Load provider-specific settings
        if (isset($aiConfig['providers']) && is_array($aiConfig['providers'])) {
            foreach ($aiConfig['providers'] as $provider => $settings) {
                if (is_array($settings)) {
                    foreach ($settings as $key => $value) {
                        $this->setProviderSetting($provider, $key, $value);
                    }
                }
            }
        }
    }

    /**
     * Load configuration from environment variables.
     */
    protected function loadFromEnvironment(): void
    {
        $defaultProvider = getenv('PDODB_AI_PROVIDER');
        if ($defaultProvider !== false && $defaultProvider !== '') {
            $this->defaultProvider = mb_strtolower($defaultProvider, 'UTF-8');
        }

        $openaiKey = getenv('PDODB_AI_OPENAI_KEY');
        if ($openaiKey !== false && $openaiKey !== '') {
            $this->apiKeys['openai'] = $openaiKey;
        }

        $anthropicKey = getenv('PDODB_AI_ANTHROPIC_KEY');
        if ($anthropicKey !== false && $anthropicKey !== '') {
            $this->apiKeys['anthropic'] = $anthropicKey;
        }

        $googleKey = getenv('PDODB_AI_GOOGLE_KEY');
        if ($googleKey !== false && $googleKey !== '') {
            $this->apiKeys['google'] = $googleKey;
        }

        $microsoftKey = getenv('PDODB_AI_MICROSOFT_KEY');
        if ($microsoftKey !== false && $microsoftKey !== '') {
            $this->apiKeys['microsoft'] = $microsoftKey;
        }

        $ollamaUrl = getenv('PDODB_AI_OLLAMA_URL');
        if ($ollamaUrl !== false && $ollamaUrl !== '') {
            $this->ollamaUrl = $ollamaUrl;
        } else {
            $this->ollamaUrl = 'http://localhost:11434';
        }
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
        $this->providerSettings[$provider][$key] = $value;
    }

    /**
     * Check if provider has API key configured.
     */
    public function hasApiKey(string $provider): bool
    {
        return isset($this->apiKeys[mb_strtolower($provider, 'UTF-8')]);
    }
}
