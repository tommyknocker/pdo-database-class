<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Sends requests with PHP streams (`allow_url_fopen`). Error bodies and URLs are never put into exception messages,
 * because they can echo credentials.
 */
final class StreamAiTransport implements AiTransport
{
    public function post(string $url, array $data, array $headers, int $timeout): array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw new QueryException('AI requests need allow_url_fopen=On in php.ini; it is disabled.', 0);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $this->buildHeaders($headers),
                'content' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);

        http_clear_last_response_headers();
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new QueryException('AI API request failed: unable to connect to provider', 0);
        }

        $httpCode = $this->getHttpResponseCode(http_get_last_response_headers() ?? []);

        if ($httpCode >= 400) {
            throw new QueryException(
                "AI API request failed with HTTP {$httpCode}",
                $httpCode
            );
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new QueryException(
                'Invalid JSON response from AI API',
                0
            );
        }

        return $decoded;
    }

    /**
     * Build HTTP headers string.
     *
     * @param array<string, string> $additionalHeaders Additional headers
     *
     * @return string Headers string
     */
    private function buildHeaders(array $additionalHeaders = []): string
    {
        $headers = array_merge([
            'Content-Type' => 'application/json',
        ], $additionalHeaders);

        $headerStrings = [];
        foreach ($headers as $key => $value) {
            $headerStrings[] = "{$key}: {$value}";
        }

        return implode("\r\n", $headerStrings);
    }

    /**
     * Get HTTP response code from response headers.
     *
     * @param array<int, string> $headers Response headers
     *
     * @return int HTTP status code
     */
    private function getHttpResponseCode(array $headers): int
    {
        if (empty($headers)) {
            return 0;
        }

        $statusLine = $headers[0];
        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches)) {
            return (int)$matches[1];
        }

        return 0;
    }
}
