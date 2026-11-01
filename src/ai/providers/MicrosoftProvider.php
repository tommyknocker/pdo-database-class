<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\AiEndpoint;
use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Microsoft (Azure OpenAI/Copilot) provider implementation.
 */
class MicrosoftProvider extends BaseAiProvider
{
    private const string API_VERSION = '2024-10-21';
    private const float DEFAULT_TEMPERATURE = 0.7;
    private const int DEFAULT_MAX_TOKENS = 2000;
    private const string URL_PATH_DEPLOYMENTS = '/openai/deployments/';
    private const string URL_PATH_CHAT_COMPLETIONS = '/chat/completions';
    private const string URL_PARAM_API_VERSION = 'api-version=';
    private const string HEADER_API_KEY = 'api-key';
    private const string MESSAGE_ROLE_SYSTEM = 'system';
    private const string MESSAGE_ROLE_USER = 'user';
    private const string RESPONSE_KEY_CHOICES = 'choices';
    private const string RESPONSE_KEY_MESSAGE = 'message';
    private const string RESPONSE_KEY_CONTENT = 'content';

    protected string $apiUrl = '';
    protected string $apiVersion = self::API_VERSION;

    protected function initializeDefaults(): void
    {
        $endpoint = $this->config->getProviderSetting('microsoft', 'base_url', '');
        $deployment = $this->config->getProviderSetting('microsoft', 'model', '');
        $this->model = $deployment;

        if ($endpoint !== '') {
            AiEndpoint::assertValid((string)$endpoint);
        }
        if ($endpoint !== '' && $deployment !== '') {
            $this->apiUrl = rtrim($endpoint, '/') . self::URL_PATH_DEPLOYMENTS . urlencode($deployment) . self::URL_PATH_CHAT_COMPLETIONS . '?' . self::URL_PARAM_API_VERSION . $this->apiVersion;
        }

        $this->temperature = (float)$this->config->getProviderSetting('microsoft', 'temperature', self::DEFAULT_TEMPERATURE);
        $this->maxTokens = (int)$this->config->getProviderSetting('microsoft', 'max_tokens', self::DEFAULT_MAX_TOKENS);
    }

    public function getProviderName(): string
    {
        return 'microsoft';
    }

    public function isAvailable(): bool
    {
        return $this->config->hasApiKey('microsoft') && $this->apiUrl !== '';
    }

    protected function dispatchAnalysis(string $systemPrompt, string $userPrompt): string
    {
        return $this->callApi($systemPrompt, $userPrompt);
    }

    /**
     * Call Microsoft Azure OpenAI API.
     */
    protected function callApi(string $systemPrompt, string $userPrompt): string
    {
        $apiKey = $this->config->getApiKey('microsoft');
        if ($apiKey === null) {
            throw new QueryException('Microsoft API key not configured', 0);
        }

        if ($this->apiUrl === '') {
            throw new QueryException('Microsoft endpoint not configured', 0);
        }

        $data = [
            'messages' => [
                [
                    'role' => self::MESSAGE_ROLE_SYSTEM,
                    'content' => $systemPrompt,
                ],
                [
                    'role' => self::MESSAGE_ROLE_USER,
                    'content' => $userPrompt,
                ],
            ],
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
        ];

        $headers = [
            self::HEADER_API_KEY => $apiKey,
        ];

        $response = $this->makeRequest($this->apiUrl, $data, $headers);

        if (!isset($response[self::RESPONSE_KEY_CHOICES][0][self::RESPONSE_KEY_MESSAGE][self::RESPONSE_KEY_CONTENT])) {
            throw new QueryException(
                'Invalid response format from Microsoft API',
                0
            );
        }

        return (string)$response[self::RESPONSE_KEY_CHOICES][0][self::RESPONSE_KEY_MESSAGE][self::RESPONSE_KEY_CONTENT];
    }

    protected function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new QueryException(
                'Microsoft provider is not available. Please configure PDODB_AI_API_KEY, PDODB_AI_BASE_URL and PDODB_AI_MODEL.',
                0
            );
        }
    }
}
