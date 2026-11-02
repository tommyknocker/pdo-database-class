<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

/**
 * A provider that reports how many tokens its last answer used. {@see BaseAiProvider} does; a provider written
 * against {@see AiProviderInterface} alone may not, which is why this is a separate interface.
 */
interface AiUsageReporting
{
    /**
     * Tokens used by the most recent request, or null when none was made or the provider did not say.
     */
    public function getLastUsage(): ?AiUsage;
}
