<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\AiEndpoint;
use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * OpenAI (ChatGPT) provider implementation.
 */
class OpenAiProvider extends BaseAiProvider
{
    private const string API_URL = 'https://api.openai.com/v1/chat/completions';
    private const string DEFAULT_MODEL = 'gpt-5.6-luna';
    private const float DEFAULT_TEMPERATURE = 0.7;
    private const int DEFAULT_MAX_TOKENS = 2000;
    private const string HEADER_AUTHORIZATION = 'Authorization';
    private const string HEADER_BEARER_PREFIX = 'Bearer ';
    private const string MESSAGE_ROLE_SYSTEM = 'system';
    private const string MESSAGE_ROLE_USER = 'user';
    private const string RESPONSE_KEY_CHOICES = 'choices';
    private const string RESPONSE_KEY_MESSAGE = 'message';
    private const string RESPONSE_KEY_CONTENT = 'content';

    protected string $apiUrl = self::API_URL;

    protected function initializeDefaults(): void
    {
        $provider = $this->getProviderName();
        $defaultBaseUrl = match ($provider) {
            'openai' => 'https://api.openai.com/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            'deepseek' => 'https://api.deepseek.com',
            default => '',
        };
        $baseUrl = (string)$this->config->getProviderSetting($provider, 'base_url', $defaultBaseUrl);
        if ($baseUrl !== '') {
            AiEndpoint::assertValid($baseUrl);
        }
        // Preserve existing subclasses that override the protected endpoint property.
        $customSubclassEndpoint = in_array($provider, ['openai', 'deepseek'], true)
            && $this->apiUrl !== self::API_URL
            && $this->config->getProviderSetting($provider, 'base_url') === null;
        if (!$customSubclassEndpoint) {
            $this->apiUrl = $baseUrl === '' ? '' : rtrim($baseUrl, '/') . '/chat/completions';
        }
        $defaultModel = match ($provider) {
            'openrouter' => 'openai/' . self::DEFAULT_MODEL,
            'deepseek' => 'deepseek-flash',
            default => self::DEFAULT_MODEL,
        };
        $this->model = (string)$this->config->getProviderSetting($provider, 'model', $defaultModel);
        $this->temperature = (float)$this->config->getProviderSetting($provider, 'temperature', self::DEFAULT_TEMPERATURE);
        $this->maxTokens = (int)$this->config->getProviderSetting($provider, 'max_tokens', self::DEFAULT_MAX_TOKENS);
    }

    public function getProviderName(): string
    {
        return 'openai';
    }

    public function isAvailable(): bool
    {
        return $this->apiUrl !== '' && $this->config->hasApiKey($this->getProviderName());
    }

    protected function dispatchAnalysis(string $systemPrompt, string $userPrompt): string
    {
        return $this->callApi($systemPrompt, $userPrompt);
    }

    /**
     * Call OpenAI API.
     *
     * @param string $systemPrompt System prompt
     * @param string $userPrompt User prompt
     *
     * @return string AI response
     */
    protected function callApi(string $systemPrompt, string $userPrompt): string
    {
        $apiKey = $this->config->getApiKey($this->getProviderName());
        if ($apiKey === null) {
            throw new QueryException('OpenAI API key not configured', 0);
        }

        $data = [
            'model' => $this->model,
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

        $provider = $this->getProviderName();
        $reasoningRequiredForTemperature = false;
        if ($provider === 'openai' && preg_match('/^gpt-[56](?:[.\-]|$)/', $this->model) === 1) {
            unset($data['max_tokens']);
            $data['max_completion_tokens'] = $this->maxTokens;
            if ($this->temperatureExplicit && preg_match('/^gpt-5\.(?:2|4)(?!-pro(?:[.-]|$))(?:[.-]|$)/', $this->model) === 1) {
                $reasoningRequiredForTemperature = true;
                $data['reasoning_effort'] = 'none';
            } else {
                $this->requireTemperatureSupport();
                unset($data['temperature']);
            }
        }
        $requestOptions = $this->config->getProviderSetting($provider, 'request_options', []);
        if (!is_array($requestOptions) || array_key_exists('model', $requestOptions) || array_key_exists('messages', $requestOptions)
            || isset($requestOptions['stream']) && $requestOptions['stream'] !== false) {
            throw new QueryException('AI request_options must be an array and cannot override model/messages or enable streaming', 0);
        }
        if ($reasoningRequiredForTemperature && array_key_exists('reasoning_effort', $requestOptions) && $requestOptions['reasoning_effort'] !== 'none') {
            throw new \InvalidArgumentException('An explicit temperature requires reasoning_effort=none for this model.');
        }
        $data = array_filter(array_replace($data, $requestOptions), static fn ($value) => $value !== null);
        $headers = $this->config->getProviderSetting($provider, 'headers', []);
        if (!is_array($headers)) {
            throw new QueryException('AI headers must be an array of strings', 0);
        }
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value) || preg_match('/^[A-Za-z0-9-]+$/D', $name) !== 1
                || str_contains($value, "\r") || str_contains($value, "\n")
                || in_array(strtolower($name), ['authorization', 'host', 'content-length', 'content-type'], true)) {
                throw new QueryException('Invalid or reserved AI HTTP header', 0);
            }
        }
        $headers[self::HEADER_AUTHORIZATION] = self::HEADER_BEARER_PREFIX . $apiKey;

        $response = $this->makeRequest($this->apiUrl, $data, $headers);

        $content = $response[self::RESPONSE_KEY_CHOICES][0][self::RESPONSE_KEY_MESSAGE][self::RESPONSE_KEY_CONTENT] ?? null;
        if ((!is_string($content) || trim($content) === '')
            && ($response[self::RESPONSE_KEY_CHOICES][0]['finish_reason'] ?? null) === 'length') {
            throw new QueryException(
                'AI response exhausted its output token limit before producing visible text. Reduce reasoning effort or choose a model that can answer within the configured limit.',
                0
            );
        }
        if (!is_string($content)) {
            throw new QueryException(
                'Invalid response format from OpenAI API',
                0
            );
        }

        return $content;
    }

    /**
     * Ensure provider is available.
     *
     * @throws QueryException If provider is not available
     */
    protected function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new QueryException(
                'AI provider ' . $this->getProviderName() . ' is not available. Configure its API key and base_url.',
                0
            );
        }
    }
}
