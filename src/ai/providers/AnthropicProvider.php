<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Anthropic (Claude) provider implementation.
 */
class AnthropicProvider extends BaseAiProvider
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';
    private const string DEFAULT_MODEL = 'claude-sonnet-5-5';
    private const float DEFAULT_TEMPERATURE = 0.7;
    private const int DEFAULT_MAX_TOKENS = 2000;
    private const string API_VERSION = '2023-06-01';
    private const string HEADER_API_KEY = 'x-api-key';
    private const string HEADER_VERSION = 'anthropic-version';
    private const string MESSAGE_ROLE_USER = 'user';
    private const string RESPONSE_KEY_CONTENT = 'content';
    private const string RESPONSE_KEY_TEXT = 'text';

    protected string $apiUrl = self::API_URL;

    protected function initializeDefaults(): void
    {
        $this->model = $this->config->getProviderSetting('anthropic', 'model', self::DEFAULT_MODEL);
        $this->temperature = (float)$this->config->getProviderSetting('anthropic', 'temperature', self::DEFAULT_TEMPERATURE);
        $this->maxTokens = (int)$this->config->getProviderSetting('anthropic', 'max_tokens', self::DEFAULT_MAX_TOKENS);
    }

    public function getProviderName(): string
    {
        return 'anthropic';
    }

    public function isAvailable(): bool
    {
        return $this->config->hasApiKey('anthropic');
    }

    protected function dispatchAnalysis(string $systemPrompt, string $userPrompt): string
    {
        return $this->callApi($systemPrompt, $userPrompt);
    }

    /**
     * Call Anthropic API.
     */
    protected function callApi(string $systemPrompt, string $userPrompt): string
    {
        $apiKey = $this->config->getApiKey('anthropic');
        if ($apiKey === null) {
            throw new QueryException('Anthropic API key not configured', 0);
        }

        $data = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $systemPrompt,
            'messages' => [
                [
                    'role' => self::MESSAGE_ROLE_USER,
                    'content' => $userPrompt,
                ],
            ],
            'temperature' => $this->temperature,
        ];

        if (preg_match('/^claude-(?:sonnet|opus|haiku)-(?:5(?:[.-]|$)|4-(?:[7-9]|[1-9][0-9])(?:[.-]|$))/', $this->model) === 1) {
            $this->requireTemperatureSupport();
            unset($data['temperature']);
            if (str_starts_with($this->model, 'claude-sonnet-5-5')) {
                $data['thinking'] = ['type' => 'between_tools'];
            }
        }

        $headers = [
            self::HEADER_API_KEY => $apiKey,
            self::HEADER_VERSION => self::API_VERSION,
        ];

        $response = $this->makeRequest($this->apiUrl, $data, $headers);

        foreach ($response[self::RESPONSE_KEY_CONTENT] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block[self::RESPONSE_KEY_TEXT] ?? null)) {
                return $block[self::RESPONSE_KEY_TEXT];
            }
        }

        throw new QueryException('Invalid response format from Anthropic API', 0);
    }

    protected function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new QueryException(
                'Anthropic provider is not available. Please configure PDODB_AI_API_KEY environment variable.',
                0
            );
        }
    }
}
