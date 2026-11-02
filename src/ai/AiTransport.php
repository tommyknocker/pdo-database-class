<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Sends a JSON request to an AI provider's HTTP API and returns the decoded answer.
 *
 * Providers build the request and read the answer; how it travels is not their business. {@see StreamAiTransport} uses
 * PHP streams and needs no extra package. Pass another implementation (a PSR-18 client, a proxy, a recorded fake for
 * tests) to {@see AiAnalysisService} or to a provider's constructor.
 */
interface AiTransport
{
    /**
     * POST $data as JSON.
     *
     * @param string $url API endpoint URL
     * @param array<string, mixed> $data Request data
     * @param array<string, string> $headers Extra HTTP headers
     * @param int $timeout Seconds to wait for the answer
     *
     * @return array<string, mixed> Decoded JSON answer
     * @throws QueryException When the request fails, the API answers with an error status, or the answer is not JSON
     */
    public function post(string $url, array $data, array $headers, int $timeout): array;
}
