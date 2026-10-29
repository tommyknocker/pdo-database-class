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
