#!/usr/bin/env php
<?php

declare(strict_types=1);

use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\mcp\McpServer;
use tommyknocker\pdodb\connection\EnvLoader;
use tommyknocker\pdodb\PdoDb;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['run', 'check', 'env:', 'help']) ?: [];
if (isset($options['help'])) {
    echo "Usage: php scripts/test-ai-live.php [--check | --run] [--env=/path/to/private.env]\n";
    echo "Default: validate local settings without an API call. --run makes three live AI requests.\n";
    exit(0);
}
$run = array_key_exists('run', $options);
if ($run && (getenv('CI') !== false || getenv('GITHUB_ACTIONS') !== false)) {
    fwrite(STDERR, "Live AI requests are disabled in CI. Use the offline AI tests instead.\n");
    exit(2);
}
$envPath = $options['env'] ?? dirname(__DIR__) . '/.env.ai.local';
if (!is_string($envPath)) {
    fwrite(STDERR, "--env must be a file path.\n");
    exit(2);
}
if (array_key_exists('env', $options) && !is_file($envPath)) {
    fwrite(STDERR, "The selected private environment file does not exist.\n");
    exit(2);
}
if (is_file($envPath) && !EnvLoader::load($envPath)) {
    fwrite(STDERR, "Unable to load the local AI configuration.\n");
    exit(2);
}

try {
    $config = new AiConfig(['ai' => ['provider' => 'openrouter']]);
    $providerName = $config->getDefaultProvider();
    if (!in_array($providerName, ['openrouter', 'openai-compatible', 'openai'], true)) {
        throw new RuntimeException('This smoke test expects OpenRouter or an OpenAI-compatible provider.');
    }
    $model = $config->getProviderSetting($providerName, 'model');
    if (!$config->hasApiKey($providerName) || !is_string($model) || trim($model) === '') {
        throw new RuntimeException('Configure the provider key and an explicit model ID in .env.ai.local or the environment.');
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('The local smoke database requires pdo_sqlite.');
    }
    $maxTokens = max(64, min(512, (int)(getenv('PDODB_AI_LIVE_MAX_TOKENS') ?: 256)));
    $timeout = max(5, min(90, (int)(getenv('PDODB_AI_LIVE_TIMEOUT') ?: 45)));
    $requestOptions = ['temperature' => null, 'max_tokens' => $maxTokens];
    $reasoningEffort = getenv('PDODB_AI_LIVE_REASONING_EFFORT') ?: '';
    if ($reasoningEffort !== '') {
        if ($providerName !== 'openrouter' || !in_array($reasoningEffort, ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], true)) {
            throw new RuntimeException('PDODB_AI_LIVE_REASONING_EFFORT requires OpenRouter and a supported effort value.');
        }
        $requestOptions['reasoning'] = ['effort' => $reasoningEffort];
    }
    // Enforce the same limits for PHP, service and MCP paths, regardless of ambient settings.
    $prefix = 'PDODB_AI_' . strtoupper(str_replace('-', '_', $providerName));
    putenv($prefix . '_MAX_TOKENS=' . $maxTokens);
    putenv($prefix . '_TIMEOUT=' . $timeout);
    $db = new PdoDb('sqlite', ['path' => ':memory:', 'ai' => [
        'provider' => $providerName,
        'providers' => [$providerName => ['request_options' => $requestOptions]],
    ]]);
    $service = new AiAnalysisService($db);
    $service->getProvider($providerName); // Configuration validation only; no HTTP request.
    // Print neither credentials nor model-generated text / raw provider errors.
    echo "AI configuration is ready. Credentials are not displayed.\n";
    echo "Live run: 3 requests, up to {$maxTokens} output tokens each, {$timeout}s timeout per request.\n";
    if ($reasoningEffort !== '') {
        echo "OpenRouter reasoning effort: {$reasoningEffort}.\n";
    }
    if (!$run) {
        echo "Check only: no API requests were made. Run with --run to test the provider.\n";
        exit(0);
    }

    $db->rawQuery('CREATE TABLE ai_smoke_users (id INTEGER PRIMARY KEY, name TEXT, status TEXT)');
    $db->rawQuery('CREATE INDEX ai_smoke_status ON ai_smoke_users (status)');
    $db->find()->table('ai_smoke_users')->insert(['name' => 'Example User', 'status' => 'active']);

    $failureHint = '';
    $assertAnalysis = static function (string $text) use (&$failureHint): void {
        if (trim($text) === '' || str_starts_with($text, 'AI analysis unavailable:')) {
            if (str_contains($text, 'exhausted its output token limit before producing visible text')) {
                $failureHint = 'Output limit exhausted before visible text. For models that support it, set PDODB_AI_LIVE_REASONING_EFFORT=none or choose a model that can answer within the limit.';
            } elseif (preg_match('/\bHTTP ([1-5][0-9]{2})\b/', $text, $matches) === 1) {
                $failureHint = 'Provider returned HTTP ' . $matches[1] . '; check authentication, model availability and provider limits.';
            }

            throw new RuntimeException('AI analysis did not return a successful text response.');
        }
    };
    $stage = 'QueryBuilder';
    $result = $db->find()->from('ai_smoke_users')->where('id', 1)->explainAiAdvice();
    $assertAnalysis($result->aiAnalysis);
    if ($result->provider !== $providerName || $result->model !== $model) {
        throw new RuntimeException('Provider/model metadata did not match the requested configuration.');
    }
    echo "PASS QueryBuilder: schema + EXPLAIN + live AI response.\n";

    $stage = 'schema analysis';
    $assertAnalysis($service->analyzeSchema('ai_smoke_users'));
    echo "PASS schema analysis: live AI response.\n";

    $stage = 'MCP';
    $response = (new McpServer($db))->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'explain_plan', 'arguments' => [
            'sql' => 'SELECT * FROM ai_smoke_users WHERE status = ?', 'params' => ['active'], 'table' => 'ai_smoke_users',
        ]]]);
    $toolResult = $response['result'] ?? [];
    $data = (array)($toolResult['structuredContent'] ?? []);
    if (($toolResult['isError'] ?? false) && is_string($data['error'] ?? null)) {
        $assertAnalysis('AI analysis unavailable: ' . $data['error']);
    }
    if (isset($response['error']) || ($toolResult['isError'] ?? false) || ($data['provider'] ?? null) !== $providerName
        || ($data['model'] ?? null) !== $model || ($data['plan_source'] ?? null) !== 'database') {
        throw new RuntimeException('MCP did not return a successful AI report with database evidence.');
    }
    $assertAnalysis((string)($data['ai_analysis'] ?? ''));
    echo "PASS MCP: bound SQL + EXPLAIN + live AI response.\n";
    echo "Live smoke test passed. Only a synthetic in-memory SQLite database was used.\n";
} catch (Throwable $error) {
    if (isset($stage)) {
        fwrite(STDERR, "FAIL {$stage}: live request or response validation failed. No raw provider response was logged.\n");
        if (isset($failureHint) && $failureHint !== '') {
            fwrite(STDERR, $failureHint . "\n");
        } elseif ((int)$error->getCode() >= 400 && (int)$error->getCode() <= 599) {
            fwrite(STDERR, 'Provider returned HTTP ' . (int)$error->getCode() . ".\n");
        }
    } else {
        // Configuration errors below contain only our messages, not keys, URLs or provider output.
        $known = $error instanceof RuntimeException && !$error instanceof PDOException;
        fwrite(STDERR, $known ? $error->getMessage() . "\n" : "AI setup check failed; verify the endpoint, credentials and PHP extensions.\n");
    }
    exit(1);
}
