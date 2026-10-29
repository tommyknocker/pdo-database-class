<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\AiConfig;

/** OpenAI-compatible Chat Completions endpoints, including OpenRouter. */
class OpenAiCompatibleProvider extends OpenAiProvider
{
    public function __construct(?AiConfig $config = null, protected string $providerName = 'openai-compatible')
    {
        parent::__construct($config);
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }
}
