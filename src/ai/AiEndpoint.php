<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Validation of provider endpoint URLs taken from configuration.
 */
final class AiEndpoint
{
    /**
     * Require an absolute http(s) URL without credentials, query or fragment.
     *
     * A scheme such as file:// or gopher:// would let a configured endpoint reach local files or internal
     * services; credentials and queries in the URL end up in logs and error messages.
     *
     * @throws QueryException If the URL is not acceptable
     */
    public static function assertValid(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new QueryException('AI base_url must be an absolute HTTP(S) URL without credentials, query or fragment', 0);
        }
    }
}
