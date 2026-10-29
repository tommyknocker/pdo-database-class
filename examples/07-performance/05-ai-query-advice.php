<?php

declare(strict_types=1);

/** Three measured use cases. Offline by default; --ai explicitly makes three provider calls. */

use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\connection\EnvLoader;
use tommyknocker\pdodb\examples\QueryAdviceShowcase;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/support/QueryAdviceShowcase.php';

$options = getopt('', ['ai', 'env:', 'rows:', 'json', 'output:', 'help']) ?: [];
if (isset($options['help'])) {
    echo "Usage: php examples/07-performance/05-ai-query-advice.php [--ai] [--env=/private/file] [--rows=100000] [--json] [--output=/path/report.json]\n";
    echo "Default: synthetic SQLite in memory, real plans and measurements, no AI request.\n";
    echo "--ai makes three requests, each capped at 512 output tokens; private settings default to .env.ai.local.\n";
    exit(0);
}

try {
    $rows = filter_var($options['rows'] ?? '100000', FILTER_VALIDATE_INT);
    if ($rows === false || $rows < 10000 || $rows > 500000) {
        throw new RuntimeException('Use --rows between 10000 and 500000');
    }
    $withAi = isset($options['ai']);
    $ai = [];
    $maxTokens = 512;
    $timeout = 45;
    if ($withAi) {
        if (getenv('CI') !== false || getenv('GITHUB_ACTIONS') !== false) {
            throw new RuntimeException('Live showcase requests are disabled in CI; run the offline example instead');
        }
        $env = $options['env'] ?? dirname(__DIR__, 2) . '/.env.ai.local';
        if (!is_string($env) || (isset($options['env']) && !is_file($env))) {
            throw new RuntimeException('The selected private environment file does not exist');
        }
        if (is_file($env) && !EnvLoader::load($env)) {
            throw new RuntimeException('Unable to load the private environment file');
        }
        $config = new AiConfig(['ai' => ['provider' => 'openrouter']]);
        $provider = $config->getDefaultProvider();
        if (!in_array($provider, ['openrouter', 'openai-compatible', 'openai'], true)
            || !$config->hasApiKey($provider) || !is_string($config->getProviderSetting($provider, 'model')) || trim($config->getProviderSetting($provider, 'model')) === '') {
            throw new RuntimeException('Configure an OpenRouter/compatible provider key and explicit model ID in the environment or private file');
        }
        $maxTokens = max(64, min(512, (int)(getenv('PDODB_AI_LIVE_MAX_TOKENS') ?: 512)));
        $timeout = max(5, min(90, (int)(getenv('PDODB_AI_LIVE_TIMEOUT') ?: 45)));
        $requestOptions = ['temperature' => null, 'max_tokens' => $maxTokens];
        $effort = getenv('PDODB_AI_LIVE_REASONING_EFFORT') ?: '';
        if ($effort !== '') {
            if ($provider !== 'openrouter' || !in_array($effort, ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], true)) {
                throw new RuntimeException('Invalid OpenRouter reasoning effort setting');
            }
            $requestOptions['reasoning'] = ['effort' => $effort];
        }
        $ai = ['provider' => $provider, 'providers' => [$provider => ['request_options' => $requestOptions]]];
    }
    $request = 0;
    $progress = $withAi ? static function (string $title) use (&$request): void {
        ++$request;
        fwrite(STDERR, "AI {$request}/3: {$title}...\n");
    } : null;
    $report = (new QueryAdviceShowcase($rows, $ai, $progress))->run($withAi, $maxTokens, $timeout);
    if ($withAi) {
        $report['environment']['ai_requests'] = ['count' => 3, 'max_output_tokens_each' => $maxTokens, 'timeout_seconds_each' => $timeout];
    }
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (isset($options['output']) && (!is_string($options['output']) || file_put_contents($options['output'], $json) === false)) {
        throw new RuntimeException('Unable to write the report file');
    }
    if (isset($options['json'])) {
        echo $json;
    } else {
        echo "PDOdb query advice: {$rows} synthetic orders, 2000 customers, SQLite in memory\n";
        echo $withAi ? "Three live AI responses; at most {$maxTokens} output tokens each.\n" : "Offline mode: no model calls. Add --ai to request advice.\n";
        foreach ($report['cases'] as $case) {
            echo "\n{$case['title']}\n" . str_repeat('-', 60) . "\n";
            echo implode("\n", $case['before']['plan']) . "\n";
            echo 'Before: ' . $case['before']['median_ms'] . " ms median\n";
            if ($case['ai'] !== null) {
                echo "\nAI advice:\n" . $case['ai']['text'] . "\n";
            }
            if (isset($case['after'])) {
                echo "\nReviewed example change: " . $case['change_applied'] . "\n";
                echo implode("\n", $case['after']['plan']) . "\n";
                echo 'After: ' . $case['after']['median_ms'] . " ms median; identical result rows verified.\n";
            } else {
                echo $case['verification'] . "\n";
            }
        }
        echo "\nTimings describe this local fixture, not production latency or a guaranteed speedup.\n";
    }
} catch (Throwable $error) {
    // Never print raw HTTP bodies, credentials or connection configuration.
    fwrite(STDERR, $error instanceof RuntimeException && !$error instanceof PDOException ? $error->getMessage() . "\n" : "Showcase failed; check PHP extensions, local configuration and output path.\n");
    exit(1);
}
