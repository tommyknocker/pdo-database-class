<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

/** Test doubles scoped to the AI namespace and loaded in an isolated process. */
function http_clear_last_response_headers(): void
{
    $GLOBALS['ai_test_headers'] = null;
}

function http_get_last_response_headers(): ?array
{
    return $GLOBALS['ai_test_headers'];
}

function file_get_contents(string $url, bool $useIncludePath = false, mixed $context = null): string|false
{
    if (str_ends_with($url, '/chat/completions')) {
        $options = stream_context_get_options($context);
        $GLOBALS['ai_test_requests'][] = ['url' => $url, 'options' => $options];
        $GLOBALS['ai_test_headers'] = ['HTTP/1.1 200 OK'];
        $body = json_decode($options['http']['content'], true);
        if (($body['model'] ?? '') === 'test/structured-advice') {
            $advice = ['summary' => 'Review estimated scan', 'findings' => [['severity' => 'info', 'type' => 'scan', 'message' => 'Inspect the scan before adding an index', 'evidence' => ['/explain_plan/nodes/0/detail'], 'suggestion' => null, 'limitations' => ['No execution measurements']]]];
            return json_encode(['choices' => [['message' => ['content' => json_encode($advice)]]]]);
        }
        if (($body['model'] ?? '') === 'test/reasoning-budget') {
            return '{"choices":[{"finish_reason":"length","message":{"content":null,"reasoning":"test-only"}}]}';
        }
        return '{"choices":[{"message":{"content":"Test analysis"}}]}';
    }
    if ($url === 'https://example.test/error') {
        $GLOBALS['ai_test_headers'] = ['HTTP/1.1 429 Too Many Requests'];
        return '{"error":"limited"}';
    }
    if ($url === 'https://example.test/success') {
        $GLOBALS['ai_test_headers'] = ['HTTP/1.1 200 OK'];
        return '{"ok":true}';
    }
    return '{"ok":true}';
}
